<?php


/**
 * Name: superblock
 * Description: block channels
 * Version: 2.0
 * Author: Mike Macgirvin
 * Maintainer: Mike Macgirvin <mike@macgirvin.com>
 * MinVersion: 1.1.3
 */

/**
 * This function uses some helper code in include/conversation; which handles filtering item authors.
 * Those function should ultimately be moved to this plugin.
 *
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Route;

function superblock_load() {

	register_hook('conversation_start', 'addon/superblock/superblock.php', 'superblock_conversation_start');
	register_hook('thread_author_menu', 'addon/superblock/superblock.php', 'superblock_item_photo_menu');
	register_hook('enotify_store', 'addon/superblock/superblock.php', 'superblock_enotify_store');
	register_hook('enotify_format', 'addon/superblock/superblock.php', 'superblock_enotify_format');
	register_hook('messages_widget', 'addon/superblock/superblock.php', 'superblock_messages_widget');
	register_hook('item_store', 'addon/superblock/superblock.php', 'superblock_item_store');
	register_hook('directory_item', 'addon/superblock/superblock.php', 'superblock_directory_item');
	register_hook('api_format_items', 'addon/superblock/superblock.php', 'superblock_api_format_items');
	register_hook('stream_item', 'addon/superblock/superblock.php', 'superblock_stream_item');
	register_hook('post_mail', 'addon/superblock/superblock.php', 'superblock_post_mail');
	register_hook('activity_widget', 'addon/superblock/superblock.php', 'superblock_activity_widget');
	Route::register('addon/superblock/Mod_Superblock.php','superblock');

}


function superblock_unload() {

	unregister_hook('conversation_start', 'addon/superblock/superblock.php', 'superblock_conversation_start');
	unregister_hook('thread_author_menu', 'addon/superblock/superblock.php', 'superblock_item_photo_menu');
	unregister_hook('enotify_store', 'addon/superblock/superblock.php', 'superblock_enotify_store');
	unregister_hook('enotify_format', 'addon/superblock/superblock.php', 'superblock_enotify_format');
	unregister_hook('messages_widget', 'addon/superblock/superblock.php', 'superblock_messages_widget');
	unregister_hook('item_store', 'addon/superblock/superblock.php', 'superblock_item_store');
	unregister_hook('directory_item', 'addon/superblock/superblock.php', 'superblock_directory_item');
	unregister_hook('api_format_items', 'addon/superblock/superblock.php', 'superblock_api_format_items');
	unregister_hook('stream_item', 'addon/superblock/superblock.php', 'superblock_stream_item');
	unregister_hook('post_mail', 'addon/superblock/superblock.php', 'superblock_post_mail');
	unregister_hook('activity_widget', 'addon/superblock/superblock.php', 'superblock_activity_widget');
	Route::unregister('addon/superblock/Mod_Superblock.php','superblock');

}



class Superblock {

	private $list = [];

	function __construct($channel_id) {
		$cnf = get_pconfig($channel_id,'system','blocked');
		if(! $cnf)
			return;
		$this->list = explode(',',$cnf);
	}

	function get_list() {
		return $this->list;
	}

	function match($n) {
		if(! $this->list)
			return false;

		//foreach($this->list as $l) {
		//	if(trim($n) === trim($l)) {
		//		return true;
		//	}
		//}

		if (in_array($n, $this->list)) {
			return true;
		}

		return false;
	}

}

function superblock_stream_item(&$a,&$b) {
	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(), 'superblock'))
		return;

	$sb = new Superblock(local_channel());

	$found = false;

	if(is_array($b['item']) && (! $found)) {
		if($sb->match($b['item']['author_xchan']))
			$found = true;
		elseif($sb->match($b['item']['owner_xchan']))
			$found = true;
	}

	if($b['item']['children']) {
		for($d = 0; $d < count($b['item']['children']); $d ++) {
			if($sb->match($b['item']['children'][$d]['owner_xchan']))
				$b['item']['children'][$d]['blocked'] = true;
			elseif($sb->match($b['item']['children'][$d]['author_xchan']))
				$b['item']['children'][$d]['blocked'] = true;
		}
	}

	if($found) {
		$b['item']['blocked'] = true;
	}

}


function superblock_item_store(&$a,&$b) {

	if(! Apps::addon_app_installed($b['uid'], 'superblock'))
		return;

	if(! $b['item_wall'])
		return;

	$sb = new Superblock($b['uid']);

	$found = false;

	if($sb->match($b['owner_xchan']))
		$found = true;
	elseif($sb->match($b['author_xchan']))
		$found = true;

	if($found) {
		$b['cancel'] = true;
	}
	return;
}

function superblock_post_mail(&$a,&$b) {

	if(! Apps::addon_app_installed($b['channel_id'], 'superblock'))
		return;

	$sb = new Superblock($b['channel_id']);

	$found = false;

	if($sb->match($b['from_xchan']))
		$found = true;

	if($found) {
		$b['cancel'] = true;
	}
	return;
}

function superblock_enotify_store(&$a,&$b) {

	if(! Apps::addon_app_installed($b['uid'], 'superblock'))
		return;

	$sb = new Superblock($b['uid']);

	$found = false;

	if($sb->match($b['sender_hash']))
		$found = true;

	if(is_array($b['parent_item']) && (! $found)) {
		if($sb->match($b['parent_item']['owner_xchan']))
			$found = true;
		elseif($sb->match($b['parent_item']['author_xchan']))
			$found = true;
	}

	if($found) {
		$b['abort'] = true;
	}
}


function superblock_enotify_format(&$a,&$b) {

	if (!Apps::addon_app_installed($b['uid'], 'superblock')) {
		return;
	}

	$sb = new Superblock($b['uid']);

	$found = false;

	if($sb->match($b['hash']))
		$found = true;

	if($found) {
		$b['display'] = false;
	}
}

function superblock_messages_widget(&$a,&$b) {
	if (!Apps::addon_app_installed($b['uid'], 'superblock')) {
		return;
	}

	$sb = new Superblock($b['uid']);

	if ($sb->match($b['owner_xchan']) || $sb->match($b['author_xchan'])) {
		$b['cancel'] = true;
	}
}

function superblock_api_format_items(&$a,&$b) {

	if(! Apps::addon_app_installed($b['api_user'], 'superblock'))
		return;

	$sb = new Superblock($b['api_user']);
	$ret = [];

	for($x = 0; $x < count($b['items']); $x ++) {

		$found = false;

		if($sb->match($b['items'][$x]['owner_xchan']))
			$found = true;
		elseif($sb->match($b['items'][$x]['author_xchan']))
			$found = true;

		if(! $found)
			$ret[] = $b['items'][$x];
	}

	$b['items'] = $ret;

}


function superblock_directory_item(&$a,&$b) {

	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(), 'superblock'))
		return;

	$sb = new Superblock(local_channel());

	$found = false;

	if($sb->match($b['entry']['hash'])) {
		$found = true;
	}

	if($found) {
		unset($b['entry']);
	}
}


function superblock_activity_widget(&$a,&$b) {

	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(), 'superblock'))
		return;

	$sb = new Superblock(local_channel());

	$found = false;

	if($b['entries']) {
		$output = [];
		foreach($b['entries'] as $x) {
			if(! $sb->match($x['author_xchan'])) {
				$output[] = $x;
			}
		}
		$b['entries'] = $output;
	}
}


function superblock_conversation_start(&$a,&$b) {

	if(!local_channel()) {
		return;
	}

	if(! Apps::addon_app_installed(local_channel(), 'superblock'))
		return;

	$words = get_pconfig(local_channel(),'system','blocked');
	if($words) {
		App::$data['superblock'] = explode(',',$words);
	}

	if(! array_key_exists('htmlhead',App::$page))
		App::$page['htmlhead'] = '';

	App::$page['htmlhead'] .= <<< EOT

<script>
function superblockBlock(author,item) {
	$.get('superblock?f=&item=' + item + '&block=' +author, function(data) {
		location.reload(true);
	});
}
</script>

EOT;

}

function superblock_item_photo_menu(&$a,&$b) {

	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(), 'superblock'))
		return;

	$blocked = false;
	$author = $b['item']['author_xchan'];
	$item = $b['item']['id'];

	if(App::$channel['channel_hash'] == $author)
		return;

	if(is_array(App::$data['superblock'])) {
		foreach(App::$data['superblock'] as $bloke) {
			if(link_compare($bloke,$author)) {
				$blocked = true;
				break;
			}
		}
	}

	if($blocked)
		return;

	$b['menu'][] = [
		'menu' => 'superblock',
		'title' => t('Block Completely'),
		'icon' => 'fw',
		'action' => 'superblockBlock(\'' . $author . '\',' . $item . '); return false;',
		'href' => '#'
	];
}
