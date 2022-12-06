<?php
/**
 * Name: Hide Aside
 * Description: Fade out aside areas after a while when using endless scroll
 * Version: 1.0
 * Author: Mario Vavti <mario@hub.somaton.com> 
 * Maintainer: Mario Vavti <mario@hub.somaton.com>
 * MinVersion: 4.7.10
 */


use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function hideaside_load() {
	Hook::register('page_end', 'addon/hideaside/hideaside.php', 'hideaside_page_end');
	Route::register('addon/hideaside/Mod_Hideaside.php', 'hideaside');
}

function hideaside_unload() {
	Hook::unregister('page_end', 'addon/hideaside/hideaside.php', 'hideaside_page_end');
	Route::unregister('addon/hideaside/Mod_Hideaside.php', 'hideaside');
}

function hideaside_page_end(&$str) {
	$uid = ((App::$profile_uid) ? App::$profile_uid : intval(local_channel()));

	if(Apps::addon_app_installed($uid, 'hideaside'))
		head_add_js('/addon/hideaside/view/js/hideaside.js', 1);
}
