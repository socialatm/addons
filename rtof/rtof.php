<?php

/**
 * Name: Hubzilla-to-Friendica Connector (rtof)
 * Description: Relay public postings to a connected Friendica account
 * Version: 1.0
 * Maintainer: none
 */

/*
 *   Red to Friendica
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');

function rtof_load() {
	//  we need some hooks, for the configuration and for sending tweets
	register_hook('notifier_normal', 'addon/rtof/rtof.php', 'rtof_post_hook');
	register_hook('post_local', 'addon/rtof/rtof.php', 'rtof_post_local');
	register_hook('jot_networks',    'addon/rtof/rtof.php', 'rtof_jot_nets');

	Route::register('addon/rtof/Mod_Rtof.php','rtof');

	logger("loaded rtof");
}


function rtof_unload() {
	unregister_hook('notifier_normal', 'addon/rtof/rtof.php', 'rtof_post_hook');
	unregister_hook('post_local', 'addon/rtof/rtof.php', 'rtof_post_local');
	unregister_hook('jot_networks',    'addon/rtof/rtof.php', 'rtof_jot_nets');

	Route::unregister('addon/rtof/Mod_Rtof.php','rtof');

}

function rtof_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'rtof'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

	$rtof_defpost = get_pconfig(local_channel(),'rtof','post_by_default');
	$selected = ((intval($rtof_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="rtof_enable"' . $selected . ' value="1" /> '
		. '<i class="fa fa-fw fa-friendica"></i> ' . t('Post to Friendica') . '</div>';
}

function rtof_post_local(&$a,&$b) {
	if($b['created'] != $b['edited'])
		return;

	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;

	if((local_channel()) && (local_channel() == $b['uid']) && (! $b['item_private'])) {

		$rtof_post = Apps::addon_app_installed(local_channel(), 'rtof');
		$rtof_enable = (($rtof_post && x($_REQUEST,'rtof_enable')) ? intval($_REQUEST['rtof_enable']) : 0);

		// if API is used, default to the chosen settings
		if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'rtof','post_by_default')))
			$rtof_enable = 1;

		if(! $rtof_enable)
			return;

		if(strlen($b['postopts']))
			$b['postopts'] .= ',';

		$b['postopts'] .= 'rtof';
    }
}

function rtof_post_hook(&$a,&$b) {

	/**
	 * Post to Friendica
	 */

	// for now, just top level posts.

	if($b['mid'] != $b['parent_mid'])
		return;

	if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited']))
		return;


	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;


	if(! strstr($b['postopts'],'rtof'))
		return;

	logger('Hubzilla-to-Friendica post invoked');

	load_pconfig($b['uid'], 'rtof');


	$api      = get_pconfig($b['uid'], 'rtof', 'baseapi');
	if(substr($api,-1,1) != '/')
		$api .= '/';
	$username = get_pconfig($b['uid'], 'rtof', 'username');
	$password = unobscurify(get_pconfig($b['uid'], 'rtof', 'password'));

	$msg = $b['body'];

	$postdata = array('status' => $b['body'], 'title' => $b['title'], 'message_id' => $b['mid'], 'source' => 'Hubzilla');

	if(strlen($b['body'])) {
		$ret = z_post_url($api . 'statuses/update', $postdata, 0, array('http_auth' => $username . ':' . $password, 'novalidate' => 1));
		if($ret['success'])
			logger('rtof: returns: ' . print_r($ret['body'],true));
		else
			logger('rtof: z_post_url failed: ' . print_r($ret['debug'],true));
	}
}

