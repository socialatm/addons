<?php
/**
 * Name: Skeleton
 * Description: A skeleton for plugins, you can copy/paste
 * Version: 0.1
 * Depends: Core
 * Recommends: None
 * Category: Example
 * Author: ken restivo <ken@restivo.org>
 * Maintainer: ken restivo <ken@restivo.org>
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function skeleton_load(){
	Hook::register('construct_page', 'addon/skeleton/skeleton.php', 'skeleton_construct_page');
	Route::register('addon/skeleton/Mod_Skeleton.php','skeleton');
}


function skeleton_unload(){
	Hook::unregister('construct_page', 'addon/skeleton/skeleton.php', 'skeleton_construct_page');
	Route::unregister('addon/skeleton/Mod_Skeleton.php','skeleton');
}

function skeleton_construct_page(&$b){
	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(),'skeleton'))
		return;

	$some_setting = get_pconfig(local_channel(), 'skeleton','some_setting');

	// Whatever you put in settings, will show up on the left nav of your pages.
	$b['layout']['region_aside'] .= '<div>' . htmlentities($some_setting) .  '</div>';
}
