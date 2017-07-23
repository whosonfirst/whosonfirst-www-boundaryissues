<?php

	loadlib('wof_utils');
	loadlib('wof_geojson');
	loadlib('wof_pipeline_utils');

	########################################################################

	function wof_pipeline_merge_pr_defaults($meta) {
		$defaults = array(
			'branch_merge' => true,
			'user_confirmation' => true
		);
		return array_merge($defaults, $meta);
	}

	########################################################################

	function wof_pipeline_merge_pr_validate($meta, $names) {

		if (! $meta['repo']) {
			return array(
				'ok' => 0,
				'error' => 'No repo specified.'
			);
		}

		if (! $meta['pr_number']) {
			return array(
				'ok' => 0,
				'error' => 'No pr_number specified.'
			);
		}

		return array(
			'ok' => 1
		);
	}

	########################################################################

	function wof_pipeline_merge_pr($pipeline, $dry_run = false) {

		$repo_data_path = wof_pipeline_repo_path($pipeline);
		$repo_path = dirname($repo_data_path);

		$updated = array();

		if ($GLOBALS['cfg']['github_token'] == 'READ-FROM-SECRETS') {
			return array(
				'ok' => 0,
				'error' => "Please configure 'github_token'"
			);
		}

		$owner = $GLOBALS['cfg']['wof_github_owner'];
		$repo = $pipeline['meta']['repo'];
		$number = $pipeline['meta']['pr_number'];
		$rsp = github_api_call('GET', "repos/$owner/$repo/pulls/$number", $GLOBALS['cfg']['github_token']);

		$branch = $rsp['rsp']['head']['ref'];

		return array(
			'ok' => 1,
			'branch' => $branch,
			'updated' => $updated
		);
	}