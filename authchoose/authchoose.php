<?php

/**
 * Name: Auth Choose
 * Description: Allow magic authentication only to websites of your immediate connections.
 * 
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function authchoose_load() {
	Hook::register('zid','addon/authchoose/authchoose.php','authchoose_zid');
	Route::register('addon/authchoose/Mod_Authchoose.php','authchoose');
}

function authchoose_unload() {
	Hook::unregister('zid','addon/authchoose/authchoose.php','authchoose_zid');
	Route::unregister('addon/authchoose/Mod_Authchoose.php','authchoose');
}

function authchoose_zid(&$x) {

	if(! Apps::addon_app_installed(local_channel(), 'authchoose'))
		return;

	$c = App::get_channel();
	if(! $c)
		return;

	// optional and undocumented - only authenticate to connections of a minimum closeness/affinity

	$closeness = intval(get_pconfig($c['channel_id'],'authchoose','closeness',99));

	static $friends = [];

	if(! array_key_exists($c['channel_id'],$friends)) {
		$r = q("select hubloc_url, abook_closeness from hubloc left join abook on hubloc_hash = abook_xchan where abook_channel = %d",
			intval($c['channel_id'])
		);
		if($r) {
			$friends[$c['channel_id']] = $r;
		}
	}
	if($friends[$c['channel_id']]) {
		foreach($friends[$c['channel_id']] as $n) {
			if(strpos($x['url'],$n['hubloc_url']) !== false && intval($n['abook_closeness']) <= $closeness) {
				return; 
			}
		}
		$x['result'] = $x['url'];
	}
}
