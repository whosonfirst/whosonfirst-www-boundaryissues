<?php

	include('include/init.php');
	loadlib('wof_utils');
	loadlib('wof_photos');

	$crumb_venue_fallback = crumb_generate('wof.save');
	$GLOBALS['smarty']->assign("crumb_save_fallback", $crumb_venue_fallback);

	$wof_id = get_int64('id');

	$path = wof_utils_find_id($wof_id);
	if (! $path){
		error_404();
	}

	$geojson = file_get_contents($path);
	$feature = json_decode($geojson, 'as hash');
	$props = $feature['properties'];
	$concordances = $props['wof:concordances'];

	if ($GLOBALS['cfg']['user']){
		$crumb_save = crumb_generate('api', 'wof.save');
		$GLOBALS['smarty']->assign('crumb_save', $crumb_save);
	}

	$GLOBALS['smarty']->assign('wof_id', $wof_id);
	$GLOBALS['smarty']->assign('wof_name', $feature['properties']['wof:name']);

	if ($concordances['woe:id']){
		$rsp = wof_photos_flickr_search($concordances['woe:id']);
		if ($rsp['ok']){
			$GLOBALS['smarty']->assign_by_ref('flickr_photos', $rsp['photos']);
		}
	}

	$GLOBALS['smarty']->display('page_photos.txt');
	exit();
