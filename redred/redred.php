<?php

/**
 * Name: Hubzilla Crosspost Connector (redred)
 * Description: Relay public postings to another Redmatrix/Hubzilla channel
 * Version: 1.0
 * Maintainer: none
 */

/*
 *   Hubzilla to Hubzilla
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');

function redred_load() {
	//  we need some hooks, for the configuration and for sending tweets
	register_hook('notifier_normal', 'addon/redred/redred.php', 'redred_post_hook');
	register_hook('post_local', 'addon/redred/redred.php', 'redred_post_local');
	register_hook('jot_networks',    'addon/redred/redred.php', 'redred_jot_nets');

	Route::register('addon/redred/Mod_Redred.php','redred');

	logger("loaded redred");
}


function redred_unload() {
	unregister_hook('notifier_normal', 'addon/redred/redred.php', 'redred_post_hook');
	unregister_hook('post_local', 'addon/redred/redred.php', 'redred_post_local');
	unregister_hook('jot_networks',    'addon/redred/redred.php', 'redred_jot_nets');

	Route::unregister('addon/redred/Mod_Redred.php','redred');
}

function redred_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'redred'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

	$redred_defpost = get_pconfig(local_channel(),'redred','post_by_default');
	$selected = ((intval($redred_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="redred_enable"' . $selected . ' value="1" /> '
		. '<i class="fa fa-fw fa-hubzilla"></i> ' . t('Post to Hubzilla') . '</div>';
}



function redred_post_local(&$a,&$b) {
	if($b['created'] != $b['edited'])
		return;

	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;

	if((local_channel()) && (local_channel() == $b['uid']) && (! $b['item_private'])) {

		$redred_post = Apps::addon_app_installed(local_channel(), 'redred');
		$redred_enable = (($redred_post && x($_REQUEST,'redred_enable')) ? intval($_REQUEST['redred_enable']) : 0);

		// if API is used, default to the chosen settings
		if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'redred','post_by_default')))
			$redred_enable = 1;

		if(! $redred_enable)
			return;

		if(strlen($b['postopts']))
			$b['postopts'] .= ',';

		$b['postopts'] .= 'redred';
	}
}


function redred_post_hook(&$a,&$b) {

	/**
	 * Post to Red
	 */

	// for now, just top level posts.

	if($b['mid'] != $b['parent_mid'])
		return;

	if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited']))
		return;


	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;


	if(! strstr($b['postopts'],'redred'))
		return;

	logger('Red-to-Red post invoked');

	load_pconfig($b['uid'], 'redred');


	$api      = get_pconfig($b['uid'], 'redred', 'baseapi');
	if(substr($api,-1,1) != '/')
		$api .= '/';
	$username = get_pconfig($b['uid'], 'redred', 'username');
	$password = unobscurify(get_pconfig($b['uid'], 'redred', 'password'));
	$channel  = get_pconfig($b['uid'], 'redred', 'channel');

	$msg = $b['body'];

	$postdata = array('status' => $b['body'], 'title' => $b['title'], 'channel' => $channel);

	if(strlen($b['body'])) {
		$ret = z_post_url($api . 'statuses/update', $postdata, 0, array('http_auth' => $username . ':' . $password));
		if($ret['success'])
			logger('redred: returns: ' . print_r($ret['body'],true));
		else
			logger('redred: z_post_url failed: ' . print_r($ret['debug'],true));
	}
}

