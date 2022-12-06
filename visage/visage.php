<?php
/**
 * Name: Visage
 * Description: Who viewed my channel/profile
 * Version: 1.0
 * Author: Mike Macgirvin <mike@zothub.com>
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function visage_load() {
	register_hook('magic_auth_success', 'addon/visage/visage.php', 'visage_magic_auth');
	Route::register('addon/visage/Mod_Visage.php','visage');
}


function visage_unload() {
	unregister_hook('magic_auth_success', 'addon/visage/visage.php', 'visage_magic_auth');
	Route::unregister('addon/visage/Mod_Visage.php','visage');
}

function visage_magic_auth($a, &$b) {

//	logger('visage: ' . print_r($b,true));

	if($_SERVER['HTTP_DNT'] === '1' || intval($_SESSION['DNT']))
		return;

	if((! strstr($b['url'],'/channel/')) && (! strstr($b['url'],'/profile/'))) {
//		logger('visage: exiting: ' . $b['url']);
		return;
	}

	$p = preg_match('/http(.*?)(channel|profile)\/(.*?)($|[\/\?\&])/',$b['url'],$matches);
	if(! $p) {
//		logger('visage: no matching pattern');
		return;
	}

//	logger('visage: matches ' . print_r($matches,true));
	
	$nick = $matches[3];

	$c = q("select channel_id, channel_hash from channel where channel_address = '%s' limit 1",
		dbesc($nick)
	);

	if(! $c)
		return;

	if(! Apps::addon_app_installed($c[0]['channel_id'], 'visage'))
		return;

	$x = get_pconfig($c[0]['channel_id'],'visage','visitors');
	if(! is_array($x))
		$n = array(array($b['xchan']['xchan_hash'],datetime_convert()));
	else {
		$n = array();

		for($z = ((count($x) > 24) ? count($x) - 24 : 0); $z < count($x); $z ++)
			if($x[$z][0] != $b['xchan']['xchan_hash'])
				$n[] = $x[$z];
		$n[] = array($b['xchan']['xchan_hash'],datetime_convert());
	}

//	logger('visage set: ' . print_r($n,true));

	set_pconfig($c[0]['channel_id'],'visage','visitors',$n);
	return;

}
