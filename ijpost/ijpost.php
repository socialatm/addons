<?php

/**
 * Name: Insanejournal Post feature
 * Description: Post to Insanejournal
 * Version: 1.0
 * Author: Tony Baldwin <https://red.free-haven.org/channel/tony>
 * Author: Michael Johnston
 * Author: Cat Gray <https://free-haven.org/profile/catness>
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');

function ijpost_load() {
	register_hook('post_local',           'addon/ijpost/ijpost.php', 'ijpost_post_local');
	register_hook('notifier_normal',      'addon/ijpost/ijpost.php', 'ijpost_send');
	register_hook('jot_networks',         'addon/ijpost/ijpost.php', 'ijpost_jot_nets');

	Route::register('addon/ijpost/Mod_Ijpost.php','ijpost');
}
function ijpost_unload() {
	unregister_hook('post_local',       'addon/ijpost/ijpost.php', 'ijpost_post_local');
	unregister_hook('notifier_normal',  'addon/ijpost/ijpost.php', 'ijpost_send');
	unregister_hook('jot_networks',     'addon/ijpost/ijpost.php', 'ijpost_jot_nets');

	Route::unregister('addon/ijpost/Mod_Ijpost.php','ijpost');
}


function ijpost_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'ijpost'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

        $ij_defpost = get_pconfig(local_channel(),'ijpost','post_by_default');
        $selected = ((intval($ij_defpost) == 1) ? ' checked="checked" ' : '');
        $b .= '<div class="profile-jot-net"><input type="checkbox" name="ijpost_enable" ' . $selected . ' value="1" /> <i class="fa fa-meh-o fa-2x" aria-hidden="true"></i> ' . t('Post to Insane Journal') . '</div>';
}

function ijpost_post_local(&$a,&$b) {

	// This can probably be changed to allow editing by pointing to a different API endpoint

	if($b['edit'])
		return;

	if((! local_channel()) || (local_channel() != $b['uid']))
		return;

	if($b['item_private'] || $b['parent'])
		return;

	$ij_post = Apps::addon_app_installed(local_channel(), 'ijpost');

	$ij_enable = (($ij_post && x($_REQUEST,'ijpost_enable')) ? intval($_REQUEST['ijpost_enable']) : 0);

	if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'ijpost','post_by_default')))
		$ij_enable = 1;

	if(! $ij_enable)
		return;

	if(strlen($b['postopts']))
		$b['postopts'] .= ',';

	$b['postopts'] .= 'ijpost';
}




function ijpost_send(&$a,&$b) {

    if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited']))
        return;

	if(! perm_is_allowed($b['uid'],'','view_stream',false))
		return;

    if(! strstr($b['postopts'],'ijpost'))
        return;

    if($b['parent'] != $b['id'])
        return;

	logger('Insanejournal xpost invoked');

	// insanejournal post in the LJ user's timezone.
	// Hopefully the person's Friendica account
	// will be set to the same thing.

	$tz = 'UTC';

	$x = q("select channel_timezone from channel where channel_id = %d limit 1",
		intval($b['uid'])
	);
	if($x && strlen($x[0]['channel_timezone']))
		$tz = $x[0]['channel_timezone'];

	$ij_username = get_pconfig($b['uid'],'ijpost','ij_username');
	$ij_password = unobscurify(get_pconfig($b['uid'],'ijpost','ij_password'));
	$ij_blog = 'http://www.insanejournal.com/interface/xmlrpc';

	if($ij_username && $ij_password && $ij_blog) {

		require_once('include/bbcode.php');
		require_once('include/datetime.php');

		$title = $b['title'];
		$post = bbcode($b['body']);
		$post = xmlify($post);
		$tags = ijpost_get_tags($b['tag']);

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
<member><name>username</name><value><string>$ij_username</string></value></member>
<member><name>password</name><value><string>$ij_password</string></value></member>
<member><name>subject</name><value><string>$title</string></value></member>
<member><name>lineendings</name><value><string>unix</string></value></member>
<member><name>ver</name><value><int>1</int></value></member>
<member><name>props</name>
<value><struct>
<member><name>useragent</name><value><string>Hubzilla</string></value></member>
<member><name>taglist</name><value><string>$tags</string></value></member>
</struct></value></member>
</struct></value>
</param></params>
</methodCall>

EOT;

		logger('ijpost: data: ' . $xml, LOGGER_DATA);

		if($ij_blog !== 'test')
			$x = z_post_url($ij_blog,$xml,array('headers' => array("Content-Type: text/xml")));
		logger('posted to insanejournal: ' . print_r($x,true), LOGGER_DEBUG);

	}
}

function ijpost_get_tags($post)
{
	preg_match_all("/\]([^\[#]+)\[/",$post,$matches);
	$tags = implode(', ',$matches[1]);
	return $tags;
}
