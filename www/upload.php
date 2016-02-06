<?php
	include('include/init.php');

	login_ensure_loggedin();

	if ($_FILES["upload_file"]) {
		loadlib('api_wof');
		api_wof_upload();
		exit;
	}

	$crumb_upload_fallback = crumb_generate('wof.upload');
	$GLOBALS['smarty']->assign("crumb_upload_fallback", $crumb_upload_fallback);

	$crumb_upload = crumb_generate('api', 'wof.upload');
	$GLOBALS['smarty']->assign("crumb_upload", $crumb_upload);

	$GLOBALS['smarty']->display('page_upload.txt');
	exit();