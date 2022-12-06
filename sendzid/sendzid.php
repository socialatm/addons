<?php


/**
 * Name: Send ZID
 * Description: Provides an optional feature to send your identity to all websites
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function sendzid_load() {
	Hook::register('zidify','addon/sendzid/sendzid.php','sendzid_zidify');
	Route::register('addon/sendzid/Mod_Sendzid.php','sendzid');
}

function sendzid_unload() {
	Hook::unregister_by_file('addon/sendzid/sendzid.php');
	Route::unregister('addon/sendzid/Mod_Sendzid.php','sendzid');
}

function sendzid_zidify(&$x) {
	if(local_channel() && Apps::addon_app_installed(local_channel(), 'sendzid')) {
		$x['zid'] = true;
	}
}
