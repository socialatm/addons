<?php

/**
 * Name: Libertree Post feature
 * Description: Post to libertree accounts
 * Version: 1.0
 * Author: Tony Baldwin <https://red.free-haven.org/channel/tony>
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');

function libertree_load() {
    register_hook('post_local',           'addon/libertree/libertree.php', 'libertree_post_local');
    register_hook('notifier_normal',      'addon/libertree/libertree.php', 'libertree_send');
    register_hook('jot_networks',         'addon/libertree/libertree.php', 'libertree_jot_nets');

	Route::register('addon/libertree/Mod_Libertree.php', 'libertree');

}
function libertree_unload() {
    unregister_hook('post_local',       'addon/libertree/libertree.php', 'libertree_post_local');
    unregister_hook('notifier_normal',  'addon/libertree/libertree.php', 'libertree_send');
    unregister_hook('jot_networks',     'addon/libertree/libertree.php', 'libertree_jot_nets');

	Route::unregister('addon/libertree/Mod_Libertree.php', 'libertree');
}


function libertree_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'libertree'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

        $ltree_defpost = get_pconfig(local_channel(),'libertree','post_by_default');
        $selected = ((intval($ltree_defpost) == 1) ? ' checked="checked" ' : '');
        $b .= '<div class="profile-jot-net"><input type="checkbox" name="libertree_enable"' . $selected . ' value="1" /> <img src="addon/libertree/libertree.png" /> ' . t('Post to Libertree') . '</div>';
}

function libertree_post_local(&$a,&$b) {

	// This can probably be changed to allow editing by pointing to a different API endpoint

	if($b['edit'])
		return;

	if((! local_channel()) || (local_channel() != $b['uid']))
		return;

	if($b['item_private'] || ($b['mid'] != $b['parent_mid']))
		return;

	$ltree_post = Apps::addon_app_installed(local_channel(), 'libertree');

	$ltree_enable = (($ltree_post && x($_REQUEST,'libertree_enable')) ? intval($_REQUEST['libertree_enable']) : 0);

	if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'libertree','post_by_default')))
		$ltree_enable = 1;

    if(! $ltree_enable)
       return;

    if(strlen($b['postopts']))
       $b['postopts'] .= ',';
     $b['postopts'] .= 'libertree';
}




function libertree_send(&$a,&$b) {

    if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited']))
        return;

    if(! perm_is_allowed($b['uid'],'','view_stream',false))
	    return;

    if(! strstr($b['postopts'],'libertree'))
        return;

    if($b['parent'] != $b['id'])
        return;

    logger('libertree xpost invoked');

	$ltree_api_token = get_pconfig($b['uid'],'libertree','libertree_api_token');
	$ltree_url = get_pconfig($b['uid'],'libertree','libertree_url');
	$ltree_blog = "$ltree_url/api/v1/posts/create/?token=$ltree_api_token";
	$ltree_source = "[".App::$config['system']['sitename']."](".z_root().")";
	// $ltree_source = "Hubzilla";
	logger('sitename: ' . print_r($ltree_source,true));
	if($ltree_url && $ltree_api_token && $ltree_blog && $ltree_source) {

		require_once('include/markdown.php');
		$tag_arr = array();
		$tags = '';
		$x = preg_match_all('/\#\[(.*?)\](.*?)\[/',$b['tag'],$matches,PREG_SET_ORDER);

		if($x) {
			foreach($matches as $mtch) {
				$tag_arr[] = $mtch[2];
			}
		}
		if(count($tag_arr))
			$tags = implode(',',$tag_arr);

		$title = $b['title'];
		$body = $b['body'];
		// Insert a newline before and after a quote
		$body = str_ireplace("[quote", "\n\n[quote", $body);
		$body = str_ireplace("[/quote]", "[/quote]\n\n", $body);

		// Removal of tags and mentions
		// #-tags
		$body = preg_replace('/#\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', '#$2', $body);
 		// @-mentions
		$body = preg_replace('/@\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', '@$2', $body);

		// remove multiple newlines
		do {
			$oldbody = $body;
                        $body = str_replace("\n\n\n", "\n\n", $body);
                } while ($oldbody != $body);

		// convert to markdown
		$body = bb_to_markdown($body);

		// Adding the title
		if(strlen($title))
			$body = "## ".html_entity_decode($title)."\n\n".$body;


		$params = array(
			'text' => $body,
			'source' => $ltree_source
		//	'token' => $ltree_api_token
		);

		$level = 0;
		$result = z_post_url($ltree_blog,$params,$level,array('novalidate' => true));
		logger('libertree: ' . print_r($result,true));

	}
}

