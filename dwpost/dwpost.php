<?php

/**
 * Name: Dreamwidth Post feature
 * Description: Post to Dreamwidth
 * Version: 1.1
 * Author: Tony Baldwin <https://red.free-haven.org/channel/tony>
 * Author: Michael Johnston
 * Author: Cat Gray <https://free-haven.org/profile/catness>
 * Author: Max Kostikov <https://tiksi.net/channel/kostikov>
 * Maintainer: ivan zlax <https://ussr.win/channel/zlax>
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');


function dwpost_load() {
	register_hook('post_local',           'addon/dwpost/dwpost.php', 'dwpost_post_local');
	register_hook('notifier_normal',      'addon/dwpost/dwpost.php', 'dwpost_send');
	register_hook('jot_networks',         'addon/dwpost/dwpost.php', 'dwpost_jot_nets');

	Route::register('addon/dwpost/Mod_Dwpost.php','dwpost');
}


function dwpost_unload() {
	unregister_hook('post_local',       'addon/dwpost/dwpost.php', 'dwpost_post_local');
	unregister_hook('notifier_normal',  'addon/dwpost/dwpost.php', 'dwpost_send');
	unregister_hook('jot_networks',     'addon/dwpost/dwpost.php', 'dwpost_jot_nets');

	Route::unregister('addon/dwpost/Mod_Dwpost.php','dwpost');
}


function dwpost_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'dwpost'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

	$dw_defpost = get_pconfig(local_channel(),'dwpost','post_by_default');

	$selected = ((intval($dw_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="dwpost_enable" ' . $selected . ' value="1" /> <i class="fa fa-send fa-2x" aria-hidden="true"></i> ' . t('Post to Dreamwidth') . '</div>';
}


function dwpost_post_local(&$a,&$b) {

	// This can probably be changed to allow editing by pointing to a different API endpoint

	if($b['edit'])
		return;

	if((! local_channel()) || (local_channel() != $b['uid']))
		return;

	if($b['item_private'] || $b['parent'])
		return;

	logger('Dreamwidth xpost invoked');

	$dw_post = Apps::addon_app_installed(local_channel(), 'dwpost');

	$dw_enable = (($dw_post && x($_REQUEST,'dwpost_enable')) ? intval($_REQUEST['dwpost_enable']) : 0);

	if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'dwpost','post_by_default')))
		$dw_enable = 1;

	if(! $dw_enable)
		return;

	if(strlen($b['postopts']))
		$b['postopts'] .= ',';

	$b['postopts'] .= 'dwpost';
}


function dwpost_send(&$a,&$b) {

	if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited']))
		return;

	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;

	if(! strstr($b['postopts'],'dwpost'))
		return;

	if($b['parent'] != $b['id'])
		return;

	// Dreamwidth post in the DW user's timezone.
	// Hopefully the person's Friendica account
	// will be set to the same thing.

	$tz = 'UTC';

	$x = q("select channel_timezone from channel where channel_id = %d limit 1",
		intval($b['uid'])
	);
	if($x && strlen($x[0]['channel_timezone']))
		$tz = $x[0]['channel_timezone'];

	$dw_username = get_pconfig($b['uid'],'dwpost','dw_username');
	$dw_password = unobscurify(get_pconfig($b['uid'],'dwpost','dw_password'));
	$dw_blog = 'https://www.dreamwidth.org/interface/xmlrpc';

	if($dw_username && $dw_password && $dw_blog) {

		require_once('include/bbcode.php');
		require_once('include/datetime.php');

		push_lang(($b['lang'] ? $b['lang'] : 'en'));

		$title = $b['title'];

		// Replace URL bookmark
		$post = trim(str_replace("#^[", "&#128279 [", $b['body']));

		// Add source URL
		if(get_pconfig($b['uid'],'dwpost','post_source_url')) {
			if(get_pconfig($b['uid'],'dwpost','post_source_urltext')) {
				$urltext = get_pconfig($b['uid'],'dwpost','post_source_urltext');
				$post .= "\n\n" . '[url=' . $b['plink'] . ']' . $urltext . '[/url]';
			}
			else
				$post .= "\n\n" . t('Source') . ": [url]" . $b['plink'] . "[/url]";
		}

		$post = bbcode($post);
		$post = xmlify($post);
		$tags = dwpost_get_tags($b['tag']);

		$date = datetime_convert('UTC',$tz,$b['created'],'Y-m-d H:i:s');
		$year = intval(substr($date,0,4));
		$mon  = intval(substr($date,5,2));
		$day  = intval(substr($date,8,2));
		$hour = intval(substr($date,11,2));
		$min  = intval(substr($date,14,2));

		$xml = <<< EOT
<?xml version="1.0" encoding="utf-8"?>
<methodCall><methodName>LJ.XMLRPC.postevent</methodName>
<params><param>
<value><struct>
<member><name>year</name><value><int>$year</int></value></member>
<member><name>mon</name><value><int>$mon</int></value></member>
<member><name>day</name><value><int>$day</int></value></member>
<member><name>hour</name><value><int>$hour</int></value></member>
<member><name>min</name><value><int>$min</int></value></member>
<member><name>event</name><value><string>$post</string></value></member>
<member><name>username</name><value><string>$dw_username</string></value></member>
<member><name>password</name><value><string>$dw_password</string></value></member>
<member><name>subject</name><value><string>$title</string></value></member>
<member><name>lineendings</name><value><string>unix</string></value></member>
<member><name>ver</name><value><int>1</int></value></member>
<member><name>props</name>
<value><struct>
<member><name>useragent</name><value><string>Friendica</string></value></member>
<member><name>taglist</name><value><string>$tags</string></value></member>
</struct></value></member>
</struct></value>
</param></params>
</methodCall>

EOT;

		logger('dwpost: data: ' . $xml, LOGGER_DATA);

		if($dw_blog !== 'test') {
			$recurse = 0;
			$x = z_post_url($dw_blog,$xml,$recurse,array('headers' => array("Content-Type: text/xml")));
		}

		logger('posted to dreamwidth: ' . print_r($x,true), LOGGER_DEBUG);
	}
}

function dwpost_get_tags($post) {
	preg_match_all("/\]([^\[#]+)\[/",$post,$matches);
	$tags = implode(', ',$matches[1]);
	return $tags;
}
