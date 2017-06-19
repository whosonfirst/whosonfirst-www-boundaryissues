<?php

	// The 'photos' prefix here is temporary until we sort out the
	// requisite S3 permissions. (20170530/dphiffer)
	$GLOBALS['cfg']['wof_pipeline_base_path'] = 'photos/pipeline/';

	loadlib('wof_s3');
	loadlib('slack_bot');
	loadlib('wof_pipeline_utils');
	loadlib('wof_pipeline_neighbourhood');
	loadlib('wof_pipeline_remove_properties');

	########################################################################

	function wof_pipeline_create($upload, $meta) {

		$fn = "wof_pipeline_{$meta['type']}_defaults";
		if (function_exists($fn)) {
			$meta = $fn($meta);
		}

		$meta_json = json_encode($meta);
		$meta_json_esc = addslashes($meta_json);

		$now = date('Y-m-d H:i:s');

		if ($meta['slack_handle']) {
			users_settings_set($GLOBALS['cfg']['user'], 'slack_handle', $meta['slack_handle']);
		}

		$filename = $_FILES["upload_file"]['name'];
		if (! preg_match('/^[a-zA-Z0-9_-]+\.zip$/', $filename)) {
			return array(
				'ok' => 0,
				'error' => 'Invalid filename. Please use only alphanumerics, _ (underbar), or - (hyphen).'
			);
		}
		$filename_esc = addslashes($filename);

		$rsp = db_insert('boundaryissues_pipeline', array(
			'filename' => $filename_esc,
			'type' => $meta['type'],
			'meta' => $meta_json_esc,
			'phase' => 'pending',
			'repo' => $meta['repo'],
			'created' => $now,
			'updated' => $now
		));
		if (! $rsp['ok']) {
			return $rsp;
		}

		$pipeline_id = $rsp['insert_id'];

		wof_pipeline_log($pipeline_id, "Created pipeline $pipeline_id", $meta);
		$url = $GLOBALS['cfg']['abs_root_url'] . "pipeline/$pipeline_id/";
		slack_bot_msg("pending: <$url|$filename> ({$meta['type']} pipeline $pipeline_id)");

		// Ok, here is where we encode the URL into the DB record, since
		// we are going to need it later from the cron-run
		// process_pipeline.php (which doesn't know how to figure out
		// the proper abs_root_url. (20170601/dphiffer)
		db_update('boundaryissues_pipeline', array(
			'url' => $url
		), "id = $pipeline_id");

		return array(
			'ok' => 1,
			'pipeline_id' => $pipeline_id
		);
	}

	########################################################################

	function wof_pipeline_get($id) {
		$id = intval($id);
		$rsp = db_fetch("
			SELECT *
			FROM boundaryissues_pipeline
			WHERE id = $id
		");
		if (! $rsp['ok'] ||
		    ! $rsp['rows']) {
			return $rsp;
		}

		$pipeline = $rsp['rows'][0];
		$pipeline['meta'] = json_decode($pipeline['meta'], 'as hash');

		return array(
			'ok' => 1,
			'pipeline' => $pipeline
		);
	}

	########################################################################

	function wof_pipeline_upload_files($upload, $meta, $pipeline_id) {

		$dir = "{$GLOBALS['cfg']['wof_pipeline_base_path']}$pipeline_id/";

		// Upload zip file
		$data = file_get_contents($upload['tmp_name']);
		$path = "$dir{$upload['name']}";
		$args = array('acl' => rawurlencode('public-read'));
		$rsp = wof_s3_put($data, $path, $args);

		// Read contents of files from zip file
		$rsp = wof_pipeline_read_zip_contents($upload, $meta['files']);
		if (! $rsp['ok']) {
			return $rsp;
		}
		$contents = $rsp['contents'];

		// Upload each file
		foreach ($contents as $file => $data) {
			$path = "$dir$file";
			$rsp = wof_s3_put($data, $path);
			if (! $rsp['ok']) {
				return $rsp;
			}
		}

		$result = array(
			'ok' => 1,
			'url' => "http://{$GLOBALS['cfg']['aws']['s3_bucket']}.s3.amazonaws.com/$dir",
			'files' => $meta['files']
		);

		wof_pipeline_log($pipeline_id, "Uploaded files to S3", $result);

		return $result;
	}

	########################################################################

	function wof_pipeline_download_files($pipeline) {

		$pipeline_id = intval($pipeline['id']);
		$dir = "{$GLOBALS['cfg']['wof_pending_dir']}/pipeline/$pipeline_id/";
		if (! file_exists($dir)) {
			mkdir($dir, 0755, true);
		}

		$remote_dir = "{$GLOBALS['cfg']['wof_pipeline_base_path']}$pipeline_id/";

		foreach ($pipeline['meta']['files'] as $file) {
			$rsp = wof_s3_get("$remote_dir$file");
			if (! $rsp['ok']) {
				return $rsp;
			}
			file_put_contents("$dir$file", $rsp['body']);
		}

		$result = array(
			'ok' => 1,
			'dir' => $dir,
			'files' => $pipeline['meta']['files']
		);

		wof_pipeline_log($pipeline_id, "Downloaded files from S3", $result);

		return $result;
	}

	########################################################################

	function wof_pipeline_finish($pipeline, $phase) {
		wof_pipeline_phase($pipeline, $phase);
		wof_pipeline_cleanup($pipeline);

		$repo_path = wof_pipeline_repo_path($pipeline);
		$rsp = git_execute($repo_path, "checkout master");
		wof_pipeline_log($pipeline['id'], "Resetting {$pipeline['repo']} to master branch", $rsp);
	}

	########################################################################

	function wof_pipeline_validate_zip($upload, $meta = null) {

		if ($meta) {
			$meta = json_decode($meta, 'as hash');
		}

		$names = array();
		$basename = basename($upload['name'], '.zip');

		$fh = zip_open($upload['tmp_name']);

		while ($entry = zip_read($fh)) {
			$name = zip_entry_name($entry);
			if (! $meta && $name == "$basename/meta.json") {
				$json = zip_entry_read($entry);
				$meta = json_decode($json, 'as hash');
			} else if (preg_match("/^$basename\/([^\/]+\.(geojson|csv))\$/", $name, $matches)) {
				$names[] = $matches[1];
			}
		}

		$meta['files'] = $names;

		if (! $meta) {
			return array(
				'ok' => 0,
				'error' => 'No meta.json file found'
			);
		}

		if (! $meta['type']) {
			return array(
				'ok' => 0,
				'error' => "meta.json has no 'type' property"
			);
		}

		$fn = "wof_pipeline_{$meta['type']}_validate";
		if (function_exists($fn)) {
			$rsp = $fn($meta, $names);
			if (! $rsp['ok']) {
				return $rsp;
			}
		}

		$fn = "wof_pipeline_{$meta['type']}_repo";
		if (function_exists($fn)) {
			$rsp = $fn($upload, $names);
			if (! $rsp['ok']) {
				return $rsp;
			}
			$meta['repo'] = $rsp['repo'];
		} else if (! $meta['repo']) {
			return array(
				'ok' => 0,
				'error' => 'Could not determine repo for pipeline.'
			);
		}

		zip_close($fh);

		return array(
			'ok' => 1,
			'meta' => $meta
		);
	}

	########################################################################

	function wof_pipeline_read_zip_contents($upload, $files) {

		$basename = basename($upload['name'], '.zip');
		$fh = zip_open($upload['tmp_name']);
		$data = array();

		while ($entry = zip_read($fh)) {
			$path = zip_entry_name($entry);
			$name = preg_replace("/^$basename\//", '', $path);
			if (in_array($name, $files) || $name == 'meta.json') {
				$data[$name] = zip_entry_read($entry);
			}
		}

		zip_close($fh);

		return array(
			'ok' => 1,
			'contents' => $data
		);
	}

	########################################################################

	function wof_pipeline_log($pipeline_id, $summary, $details = '') {
		if (! is_scalar($details)) {
			$details = var_export($details, true);
		}
		$pipeline_id = intval($pipeline_id);
		$summary_esc = addslashes($summary);
		$details_esc = addslashes($details);
		$now = date('Y-m-d H:i:s');
		$rsp = db_insert('boundaryissues_pipeline_log', array(
			'pipeline_id' => $pipeline_id,
			'summary' => $summary_esc,
			'details' => $details_esc,
			'created_at' => $now
		));
		return $rsp;
	}

	########################################################################

	function wof_pipeline_log_dump($id) {

		$id = intval($id);
		$rsp = db_fetch("
			SELECT *
			FROM boundaryissues_pipeline_log
			WHERE pipeline_id = $id
			ORDER BY created_at
		");
		if (! $rsp['ok']) {
			return $rsp;
		}

		return array(
			'ok' => 1,
			'logs' => $rsp['rows']
		);
	}

	########################################################################

	function wof_pipeline_phase($pipeline, $phase) {

		$pipeline_id = intval($pipeline['id']);
		$phase_esc = addslashes($phase);
		$now = date('Y-m-d H:i:s');

		$rsp = db_update('boundaryissues_pipeline', array(
			'phase' => $phase_esc,
			'updated' => $now
		), "id = $pipeline_id");

		$notification = '';
		if ($phase == 'failed') {
			if ($pipeline['meta']['slack_handle']) {
				$notification = " @{$pipeline['meta']['slack_handle']}";
			} else {
				$notification = ' <!here>';
			}
		} else if ($phase == 'success') {
			if ($pipeline['meta']['slack_handle']) {
				$notification = " @{$pipeline['meta']['slack_handle']}";
			}
		}

		wof_pipeline_log($pipeline_id, "Phase set to $phase", $rsp);
		slack_bot_msg("$phase: <{$pipeline['url']}|{$pipeline['filename']}> ({$pipeline['type']} pipeline $pipeline_id)$notification");

		return $rsp;
	}

	########################################################################

	function wof_pipeline_cleanup($pipeline) {

		$meta = $pipeline['meta'];
		$zip_file = $pipeline['filename'];
		$files = array();

		$rsp = wof_pipeline_cleanup_file($pipeline, $zip_file);
		if (! $rsp['ok']) {
			return $rsp;
		}
		$files[] = $zip_file;

		$rsp = wof_pipeline_cleanup_file($pipeline, 'meta.json');
		if (! $rsp['ok']) {
			return $rsp;
		}
		$files[] = 'meta.json';

		foreach ($meta['files'] as $filename) {
			$rsp = wof_pipeline_cleanup_file($pipeline, $filename);
			if (! $rsp['ok']) {
				return $rsp;
			}
			$files[] = $filename;
		}

		$pipeline_id = intval($pipeline['id']);
		$local_dir = "{$GLOBALS['cfg']['wof_pending_dir']}/pipeline/$pipeline_id/";
		if (file_exists($local_dir)) {
			rmdir($local_dir);
		}

		$result = array(
			'ok' => 1,
			'files' => $files
		);
		wof_pipeline_log($pipeline_id, "Cleaned up files", $result);

		return $result;
	}

	########################################################################

	function wof_pipeline_cleanup_file($pipeline, $filename) {
		$pipeline_id = intval($pipeline['id']);
		$remote_dir = "{$GLOBALS['cfg']['wof_pipeline_base_path']}$pipeline_id/";
		$remote_path = "$remote_dir$filename";
		$rsp = wof_s3_delete($remote_path);

		$local_dir = "{$GLOBALS['cfg']['wof_pending_dir']}/pipeline/$pipeline_id/";
		$local_path = "$local_dir$filename";
		if (file_exists($local_path)) {
			unlink($local_path);
		}

		return $rsp;
	}

	########################################################################

	function wof_pipeline_next() {
		$rsp = db_fetch("
			SELECT *
			FROM boundaryissues_pipeline
			WHERE phase = 'pending'
			GROUP BY repo
			ORDER BY created
		");
		if (! $rsp['ok']) {
			return $rsp;
		}

		if (! $rsp['rows']) {
			return array(
				'ok' => 1,
				'next' => array()
			);
		}

		$pending = $rsp['rows'];
		$rsp = db_fetch("
			SELECT repo
			FROM boundaryissues_pipeline
			WHERE phase = 'in_progress'
			   OR phase = 'next'
			GROUP BY repo
		");
		if (! $rsp['ok']) {
			return $rsp;
		}

		$repo_locked = array();
		foreach ($rsp['rows'] as $in_progress) {
			$repo_locked[] = $in_progress['repo'];
		}

		$next = array();
		$ids = array();
		foreach ($pending as $pipeline) {
			if (in_array($pipeline['repo'], $repo_locked)) {
				continue;
			}
			$pipeline['meta'] = json_decode($pipeline['meta'], 'as hash');
			$next[] = $pipeline;
			$ids[] = intval($pipeline['id']);
		}

		$id_list = implode(', ', $ids);
		$now = date('Y-m-d H:i:s');
		$rsp = db_update('boundaryissues_pipeline', array(
			'phase' => 'next',
			'updated' => $now
		), "id IN ($id_list)");
		if (! $rsp['ok']) {
			return $rsp;
		}

		return array(
			'ok' => 1,
			'next' => $next
		);
	}

	# the end