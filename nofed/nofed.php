<?php

/**
 * Name: No Federation (nofed)
 * Description: Prevent posting from being federated to anybody. It will exist only on your channel page. 
 * Version: 1.0
 * Maintainer: none
 */
 
/*
 *   NoFed
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');

function nofed_load() {
	register_hook('feature_settings', 'addon/nofed/nofed.php', 'nofed_settings'); 
	register_hook('jot_networks',    'addon/nofed/nofed.php', 'nofed_jot_nets');

	Route::register('addon/nofed/Mod_Nofed.php', 'nofed');

	logger("loaded nofed");
}


function nofed_unload() {
	unregister_hook('feature_settings', 'addon/nofed/nofed.php', 'nofed_settings'); 
	unregister_hook('jot_networks',    'addon/nofed/nofed.php', 'nofed_jot_nets');

	Route::unregister('addon/nofed/Mod_Nofed.php', 'nofed');
}

function nofed_jot_nets(&$a,&$b) {
	if(! local_channel()) 
		return;

	if(! Apps::addon_app_installed(local_channel(), 'nofed'))
		return;

	$nofed_defpost = get_pconfig(local_channel(),'nofed','post_by_default');
	$selected = ((intval($nofed_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="nofed_enable"' . $selected . ' value="1" /> ' 
		. '<i class="fa fa-fw fa-paper-plane-o"></i> ' . t('Federate') . '</div>';
}

function nofed_post_local(&$a,&$b) {
	if($b['created'] != $b['edited'])
		return;

	if($b['mid'] !== $b['parent_mid'])
		return;

	if((local_channel()) && (local_channel() == $b['uid'])) {

		if($b['allow_cid'] || $b['allow_gid'] || $b['deny_cid'] || $b['deny_gid'])
			return;

		$nofed_post = Apps::addon_app_installed(local_channel(), 'nofed');
		if(! $nofed_post)
			return;

		$nofed_enable = (($nofed_post && x($_REQUEST,'nofed_enable')) ? intval($_REQUEST['nofed_enable']) : 0);

		// if API is used, default to the chosen settings
		if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'nofed','post_by_default')))
			$nofed_enable = 1;

       if($nofed_enable)
            return;

       if(strlen($b['postopts']))
           $b['postopts'] .= ',';
       $b['postopts'] .= 'nodeliver';
    }
}
