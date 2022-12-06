<?php

/**
 * Name: Livejournal Post feature
 * Description: Post to Livejournal
 * Version: 1.2
 * Author: Tony Baldwin <https://red.free-haven.org/channel/tony>
 * Author: Michael Johnston
 * Author: Cat Gray <https://free-haven.org/profile/catness>
 * Author: Max Kostikov <https://tiksi.net/channel/kostikov>
 * Maintainer: Max Kostikov <https://tiksi.net/channel/kostikov>
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');


function ljpost_load() {
	register_hook('post_local',		   'addon/ljpost/ljpost.php', 'ljpost_post_local');
	register_hook('notifier_normal',	  'addon/ljpost/ljpost.php', 'ljpost_send');
	register_hook('jot_networks',		 'addon/ljpost/ljpost.php', 'ljpost_jot_nets');

	Route::register('addon/ljpost/Mod_Ljpost.php', 'ljpost');
}


function ljpost_unload() {
	unregister_hook('post_local',	   'addon/ljpost/ljpost.php', 'ljpost_post_local');
	unregister_hook('notifier_normal',  'addon/ljpost/ljpost.php', 'ljpost_send');
	unregister_hook('jot_networks',	 'addon/ljpost/ljpost.php', 'ljpost_jot_nets');

	Route::unregister('addon/ljpost/Mod_Ljpost.php', 'ljpost');
}


function ljpost_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'ljpost'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

	$lj_defpost = get_pconfig(local_channel(),'ljpost','post_by_default');

	$selected = ((intval($lj_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="ljpost_enable" ' . $selected . ' value="1" /> <i class="fa fa-pencil-square-o fa-2x" aria-hidden="true"></i> ' . t('Post to Livejournal') . '</div>';
}


function ljpost_post_local(&$a,&$b) {

	// This can probably be changed to allow editing by pointing to a different API endpoint

	if($b['edit'])
		return;

	if($b['item_private'] || $b['parent'])
		return;

	$lj_wall2wall = (get_pconfig($b['uid'],'ljpost','post_wall2wall') ? 1 : false);
	if((! $lj_wall2wall) && ((! local_channel()) || (local_channel() != $b['uid'])))
		return;

	logger('Livejournal xpost invoked');

	$lj_enable = ((Apps::addon_app_installed($b['uid'], 'ljpost') && x($_REQUEST,'ljpost_enable')) ? intval($_REQUEST['ljpost_enable']) : false);

	if(($_REQUEST['api_source'] || $lj_wall2wall) && intval(get_pconfig($b['uid'],'ljpost','post_by_default')))
		$lj_enable = 1;

	if(! $lj_enable)
	   return;

	if(strlen($b['postopts']))
		$b['postopts'] .= ',';
	$b['postopts'] .= 'ljpost';
}


function ljpost_send(&$a,&$b) {

	if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited']))
		return;

	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;

	if(! strstr($b['postopts'],'ljpost'))
		return;

	if($b['parent'] != $b['id'])
		return;

	// Livejournal post in the LJ user's timezone.
	// Hopefully the person's Friendica account
	// will be set to the same thing.

	$tz = 'UTC';

	$x = q("select channel_timezone from channel where channel_id = %d limit 1",
		intval($b['uid'])
	);
	if($x && strlen($x[0]['channel_timezone']))
		$tz = $x[0]['channel_timezone'];

	$lj_username = get_pconfig($b['uid'],'ljpost','lj_username');
	$lj_password = unobscurify(get_pconfig($b['uid'],'ljpost','lj_password'));
	$lj_blog = 'https://www.livejournal.com/interface/xmlrpc';

	if($lj_username && $lj_password && $lj_blog) {

		require_once('include/bbcode.php');
		require_once('include/datetime.php');

		push_lang(($b['lang'] ? $b['lang'] : 'en'));

		// If this is other author post
		if($b['owner_xchan'] != $b['author_xchan']) {

			$r = q("SELECT * FROM xchan WHERE xchan_hash = '%s' LIMIT 1",
				dbesc($b['author_xchan'])
			);
			if($r)
				$b['body'] = "[b]" . t('Posted by') . " [zrl=" . $r[0]['xchan_url'] ."]" . $r[0]['xchan_name'] . "[/zrl][/b]\n\n" . $b['body'];
		}

		$title = $b['title'];
		// Replace URL bookmark
		$post = trim(str_replace("#^[", "&#128279 [", $b['body']));
		if(get_pconfig($b['uid'],'ljpost','post_source_url'))
		    $post .= " \n\n" . t('Source') . ": [url]" . $b['plink'] . "[/url]";
		$post = bbcode($post);
		$post = xmlify($post);

		$tags = ljpost_get_tags($b['tag']);

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
<member><name>username</name><value><string>$lj_username</string></value></member>
<member><name>password</name><value><string>$lj_password</string></value></member>
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

		logger('ljpost: data: ' . $xml, LOGGER_DATA);

		if($lj_blog !== 'test') {
			$recurse = 0;
			$x = z_post_url($lj_blog,$xml,$recurse,array('headers' => array("Content-Type: text/xml")));
		}

		logger('posted to Livejournal: ' . print_r($x,true), LOGGER_DEBUG);
	}
}

function ljpost_get_tags($post)
{
	preg_match_all("/\]([^\[#]+)\[/",$post,$matches);
	$tags = implode(', ',$matches[1]);
	return $tags;
}
