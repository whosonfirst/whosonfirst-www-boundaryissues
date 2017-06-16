<?php
	include('include/init.php');

	login_ensure_loggedin();

	// Make sure the user has accepted the TOS
	users_ensure_terms_accepted("upload/");

	if ($_FILES["upload_file"]) {
		loadlib('api_wof');
		api_wof_upload();
		exit;
	}

	$crumb_upload_fallback = crumb_generate('wof.upload');
	$GLOBALS['smarty']->assign("crumb_upload_fallback", $crumb_upload_fallback);

	$crumb_upload_feature = crumb_generate('api', 'wof.upload_feature');
	$GLOBALS['smarty']->assign("crumb_upload_feature", $crumb_upload_feature);

	$crumb_upload_collection = crumb_generate('api', 'wof.upload_collection');
	$GLOBALS['smarty']->assign("crumb_upload_collection", $crumb_upload_collection);

	$crumb_upload_csv = crumb_generate('api', 'wof.upload_csv');
	$GLOBALS['smarty']->assign("crumb_upload_csv", $crumb_upload_csv);

	if (users_acl_check_access($GLOBALS['cfg']['user'], 'pipeline')) {
		$crumb_upload_zip = crumb_generate('api', 'wof.upload_zip');
		$GLOBALS['smarty']->assign("crumb_upload_zip", $crumb_upload_zip);

		$slack_handle = users_settings_get_single($GLOBALS['cfg']['user'], 'slack_handle');
		if ($slack_handle) {
			$GLOBALS['smarty']->assign('slack_handle', $slack_handle);
		}
	}

	$GLOBALS['smarty']->display('page_upload.txt');
	exit();
