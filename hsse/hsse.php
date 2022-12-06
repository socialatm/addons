<?php
/**
 * Name: Wysiwyg Status
 * Description: SCEditor implemented for simplified status editing on Hubzilla
 * Version: 1.0
 * Author: DM42.Net, LLC
 * Maintainer: devhubzilla@dm42.net
 * MinVersion: 3.7.4
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function hsse_load() {
	Hook::register('page_header', 'addon/hsse/hsse.php', 'Hsse::page_header',1,523);
	Hook::register('page_content', 'addon/hsse/hsse.php', 'Hsse::page_content',1,523);
	Hook::register('status_editor', 'addon/hsse/hsse.php', 'Hsse::status_editor',1,523);
	Route::register('addon/hsse/Mod_Hsse.php','hsse');
}

function hsse_unload() {
	Zotlabs\Extend\Hook::unregister_by_file('addon/hsse/hsse.php');
	Route::unregister('addon/hsse/Mod_Hsse.php','hsse');
}

class Hsse {

	public static function page_header(&$header) {
		if(! Apps::addon_app_installed(local_channel(), 'hsse')) {
			return;
		}
		head_add_js('/addon/hsse/sceditor/minified/sceditor.min.js');
		head_add_js('/addon/hsse/sceditor/minified/icons/monocons.js');
		head_add_js('/addon/hsse/sceditor/minified/formats/bbcode.js');

		head_add_css('/addon/hsse/sceditor/minified/themes/default.min.css');
		head_add_css('/addon/hsse/view/css/theme_override.css');
	}

	public static function status_editor(&$hook_arr) {
		if(! Apps::addon_app_installed(local_channel(), 'hsse')) {
			return;
		}

		$valid_modules = ['Network','Rpost','Editpost'];
		if (!in_array($hook_arr['module'],$valid_modules)) {
			return;
		}

		$x = $hook_arr['x'];
		$popup = $$hook_arr['popup'];

		$o = '';

		$c = channelx_by_n($x['profile_uid']);
		if($c && $c['channel_moved']) 
			return;

		$plaintext = true;

	//	if(feature_enabled(local_channel(),'richtext'))
	//		$plaintext = false;
	
		$feature_voting = feature_enabled($x['profile_uid'], 'consensus_tools');
		if(x($x, 'hide_voting'))
			$feature_voting = false;
		
		$feature_nocomment = feature_enabled($x['profile_uid'], 'disable_comments');
		if(x($x, 'disable_comments'))
			$feature_nocomment = false;
	
		$feature_expire = ((feature_enabled($x['profile_uid'], 'content_expire') && (! $webpage)) ? true : false);
		if(x($x, 'hide_expire'))
			$feature_expire = false;
	
		$feature_future = ((feature_enabled($x['profile_uid'], 'delayed_posting') && (! $webpage)) ? true : false);
		if(x($x, 'hide_future'))
			$feature_future = false;
	
		$geotag = (($x['allow_location']) ? replace_macros(get_markup_template('jot_geotag.tpl'), array()) : '');
		$setloc = t('Set your location');
		$clearloc = ((get_pconfig($x['profile_uid'], 'system', 'use_browser_location')) ? t('Clear browser location') : '');
		if(x($x, 'hide_location'))
			$geotag = $setloc = $clearloc = '';
	
		$mimetype = ((x($x,'mimetype')) ? $x['mimetype'] : 'text/bbcode');
	
		$mimeselect = ((x($x,'mimeselect')) ? $x['mimeselect'] : false);
		if($mimeselect)
			$mimeselect = mimetype_select($x['profile_uid'], $mimetype);
		else
			$mimeselect = '<input type="hidden" name="mimetype" value="' . $mimetype . '" />';
	
		$weblink = (($mimetype === 'text/bbcode') ? t('Insert web link') : false);
		if(x($x, 'hide_weblink'))
			$weblink = false;
		
		$embedPhotos = t('Embed (existing) photo from your photo albums');
	
		$writefiles = (($mimetype === 'text/bbcode') ? perm_is_allowed($x['profile_uid'], get_observer_hash(), 'write_storage') : false);
		if(x($x, 'hide_attach'))
			$writefiles = false;
	
		$layout = ((x($x,'layout')) ? $x['layout'] : '');
	
		$layoutselect = ((x($x,'layoutselect')) ? $x['layoutselect'] : false);
		if($layoutselect)
			$layoutselect = layout_select($x['profile_uid'], $layout);
		else
			$layoutselect = '<input type="hidden" name="layout_mid" value="' . $layout . '" />';
	
		if(array_key_exists('channel_select',$x) && $x['channel_select']) {
			require_once('include/channel.php');
			$id_select = identity_selector();
		}
		else
			$id_select = '';
	
		$webpage = ((x($x,'webpage')) ? $x['webpage'] : '');
	
		$reset = ((x($x,'reset')) ? $x['reset'] : '');
		
		$feature_auto_save_draft = ((feature_enabled($x['profile_uid'], 'auto_save_draft')) ? "true" : "false");
		
		$tpl = get_markup_template('hsse-header.tpl','addon/hsse/');
	
		App::$page['htmlhead'] .= replace_macros($tpl, array(
			'$baseurl' => z_root(),
			'$editselect' => (($plaintext) ? 'none' : '/(profile-jot-text|prvmail-text)/'),
			'$pretext' => ((x($x,'pretext')) ? $x['pretext'] : ''),
			'$geotag' => $geotag,
			'$nickname' => $x['nickname'],
			'$linkurl' => t('Please enter a link URL:'),
			'$term' => t('Tag term:'),
			'$whereareu' => t('Where are you right now?'),
			'$editor_autocomplete'=> ((x($x,'editor_autocomplete')) ? $x['editor_autocomplete'] : ''),
			'$bbco_autocomplete'=> ((x($x,'bbco_autocomplete')) ? $x['bbco_autocomplete'] : ''),
			'$modalchooseimages' => t('Choose images to embed'),
			'$modalchoosealbum' => t('Choose an album'),
			'$modaldiffalbum' => t('Choose a different album...'),
			'$modalerrorlist' => t('Error getting album list'),
			'$modalerrorlink' => t('Error getting photo link'),
			'$modalerroralbum' => t('Error getting album'),
			'$nocomment_enabled' => t('Comments enabled'),
			'$nocomment_disabled' => t('Comments disabled'),
			'$auto_save_draft' => $feature_auto_save_draft,
			'$reset' => $reset
		));
	
		$tpl = get_markup_template('hsse.tpl','addon/hsse/');
	
		$preview = t('Preview');
		if(x($x, 'hide_preview'))
			$preview = '';
	
		$defexpire = ((($z = get_pconfig($x['profile_uid'], 'system', 'default_post_expire')) && (! $webpage)) ? $z : '');
		if($defexpire)
			$defexpire = datetime_convert('UTC',date_default_timezone_get(),$defexpire,'Y-m-d H:i');
	
		$defpublish = ((($z = get_pconfig($x['profile_uid'], 'system', 'default_post_publish')) && (! $webpage)) ? $z : '');
		if($defpublish)
			$defpublish = datetime_convert('UTC',date_default_timezone_get(),$defpublish,'Y-m-d H:i');
	
		$cipher = get_pconfig($x['profile_uid'], 'system', 'default_cipher');
		if(! $cipher)
			$cipher = 'aes256';
	
		if(array_key_exists('catsenabled',$x))
			$catsenabled = $x['catsenabled'];
		else
			$catsenabled = ((feature_enabled($x['profile_uid'], 'categories') && (! $webpage)) ? 'categories' : '');
	
		// avoid illegal offset errors
		if(! array_key_exists('permissions',$x)) 
			$x['permissions'] = [ 'allow_cid' => '', 'allow_gid' => '', 'deny_cid' => '', 'deny_gid' => '' ];
	
		$jotplugins = '';
		call_hooks('jot_tool', $jotplugins);
	
		$jotnets = '';
		if(x($x,'jotnets')) {
			call_hooks('jot_networks', $jotnets);
		}
	
		$sharebutton = (x($x,'button') ? $x['button'] : t('Share'));
		$placeholdtext = (x($x,'content_label') ? $x['content_label'] : $sharebutton);
	
		$o .= replace_macros($tpl, array(
			'$return_path' => ((x($x, 'return_path')) ? $x['return_path'] : App::$query_string),
			'$action' =>  z_root() . '/item',
			'$share' => $sharebutton,
			'$placeholdtext' => $placeholdtext,
			'$webpage' => $webpage,
			'$placeholdpagetitle' => ((x($x,'ptlabel')) ? $x['ptlabel'] : t('Page link name')),
			'$pagetitle' => (x($x,'pagetitle') ? $x['pagetitle'] : ''),
			'$id_select' => $id_select,
			'$id_seltext' => t('Post as'),
			'$writefiles' => $writefiles,
			'$bold' => t('Bold'),
			'$italic' => t('Italic'),
			'$underline' => t('Underline'),
			'$quote' => t('Quote'),
			'$code' => t('Code'),
			'$attach' => t('Attach/Upload file'),
			'$weblink' => $weblink,
			'$embedPhotos' => $embedPhotos,
			'$embedPhotosModalTitle' => t('Embed an image from your albums'),
			'$embedPhotosModalCancel' => t('Cancel'),
			'$embedPhotosModalOK' => t('OK'),
			'$setloc' => $setloc,
			'$voting' => t('Toggle voting'),
			'$feature_voting' => $feature_voting,
			'$consensus' => ((array_key_exists('item',$x)) ? $x['item']['item_consensus'] : 0),
			'$nocommenttitle' => t('Disable comments'),
			'$nocommenttitlesub' => t('Toggle comments'),
			'$feature_nocomment' => $feature_nocomment,
			'$nocomment' => ((array_key_exists('item',$x)) ? $x['item']['item_nocomment'] : 0),
			'$clearloc' => $clearloc,
			'$title' => ((x($x, 'title')) ? htmlspecialchars($x['title'], ENT_COMPAT,'UTF-8') : ''),
			'$placeholdertitle' => ((x($x, 'placeholdertitle')) ? $x['placeholdertitle'] : t('Title (optional)')),
			'$catsenabled' => $catsenabled,
			'$category' => ((x($x, 'category')) ? $x['category'] : ''),
			'$placeholdercategory' => t('Categories (optional, comma-separated list)'),
			'$permset' => t('Permission settings'),
			'$ptyp' => ((x($x, 'ptyp')) ? $x['ptyp'] : ''),
			'$content' => ((x($x,'body')) ? htmlspecialchars($x['body'], ENT_COMPAT,'UTF-8') : ''),
			'$attachment' => ((x($x, 'attachment')) ? $x['attachment'] : ''),
			'$post_id' => ((x($x, 'post_id')) ? $x['post_id'] : ''),
			'$defloc' => $x['default_location'],
			'$visitor' => $x['visitor'],
			'$lockstate' => $x['lockstate'],
			'$acl' => $x['acl'],
			'$allow_cid' => acl2json($x['permissions']['allow_cid']),
			'$allow_gid' => acl2json($x['permissions']['allow_gid']),
			'$deny_cid' => acl2json($x['permissions']['deny_cid']),
			'$deny_gid' => acl2json($x['permissions']['deny_gid']),
			'$mimeselect' => $mimeselect,
			'$layoutselect' => $layoutselect,
			'$showacl' => ((array_key_exists('showacl', $x)) ? $x['showacl'] : true),
			'$bang' => $x['bang'],
			'$profile_uid' => $x['profile_uid'],
			'$preview' => $preview,
			'$source' => ((x($x, 'source')) ? $x['source'] : ''),
			'$jotplugins' => $jotplugins,
			'$jotnets' => $jotnets,
			'$jotnets_label' => t('Other networks and post services'),
			'$defexpire' => $defexpire,
			'$feature_expire' => $feature_expire,
			'$expires' => t('Set expiration date'),
			'$defpublish' => $defpublish,
			'$feature_future' => $feature_future,
			'$future_txt' => t('Set publish date'),
			'$feature_encrypt' => ((feature_enabled($x['profile_uid'], 'content_encrypt') && (! $webpage)) ? true : false),
			'$encrypt' => t('Encrypt text'),
			'$cipher' => $cipher,
			'$expiryModalOK' => t('OK'),
			'$expiryModalCANCEL' => t('Cancel'),
			'$expanded' => ((x($x, 'expanded')) ? $x['expanded'] : false),
			'$bbcode' => ((x($x, 'bbcode')) ? $x['bbcode'] : false),
			'$parent' => ((array_key_exists('parent',$x) && $x['parent']) ? $x['parent'] : 0),
			'$reset' => $reset,
                	'$is_owner' => ((local_channel() && (local_channel() == $x['profile_uid'])) ? true : false)

		));
	
		if ($popup === true) {
			$o = '<div id="jot-popup" style="display:none">' . $o . '</div>';
		}

		$hook_arr['editor_html'] = $o;	
		return;
	}
}
