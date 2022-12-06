<?php

/**
 * Name: Fediwordle
 * Description: A distributed word game inspired by wordle
 * Version: 1.0
 * Author: Mario Vavti
 */


use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;
use Zotlabs\Daemon\Master;


function fediwordle_install() {
	Hook::register('post_local', 'addon/fediwordle/fediwordle.php', 'fediwordle_post_local');
	Hook::register('notifier_process', 'addon/fediwordle/fediwordle.php', 'fediwordle_notifier_process');
	Route::register('addon/fediwordle/Mod_Fediwordle.php', 'fediwordle');

}

function fediwordle_uninstall() {
	Hook::unregister('post_local', 'addon/fediwordle/fediwordle.php', 'fediwordle_post_local');
	Hook::unregister('notifier_process', 'addon/fediwordle/fediwordle.php', 'fediwordle_notifier_process');
	Route::unregister('addon/fediwordle/Mod_Fediwordle.php', 'fediwordle');
}

function fediwordle_post_local(&$arr) {

	if(!Apps::addon_app_installed(local_channel(), 'fediwordle')) {
		return;
	}

	if (strpos($arr['body'], '[/wordle]') === false) {
		return;
	}

	$match = [];

	preg_match('/\[wordle\](.*?)\[\/wordle\]/ism', $arr['body'], $match);

	$word = $match[1];
	$replace = fediwordle_replace($word);

	$arr['body'] = str_replace($match[0], $replace, $arr['body']);

	$iconfig = [
		'word' => strtoupper($word),
		'chars' => [],
		'?_chars' => [],
		'x_chars' => [],
		'round' => 0
	];

	set_iconfig($arr, 'fediwordle', 'word', $iconfig);

}

function fediwordle_notifier_process($arr) {

	$channel = $arr['channel'];
	$item = $arr['target_item'];
	$parent = $arr['parent_item'];

	// A cheap check if the parent body contains fediwordle emojis before checking anything else
	if (strpos($parent['body'], '🔵🔵🔵') === false) {
		return;
	}

	if(!Apps::addon_app_installed($channel['channel_id'], 'fediwordle')) {
		return;
	}

	if (!in_array($arr['cmd'], ['relay', 'comment-import']) || $arr['relay_to_owner']) {
		return;
	}

	if ($item['verb'] !== ACTIVITY_POST)
		return;

	// it's a toplevel post - dismiss
	if ($item['id'] === $item['parent']) {
		return;
	}

	// it's not a direct descendent of the toplevel post - dismiss
	if ($item['thr_parent'] !== $parent['mid']) {
		return;
	}

	// if it's not a wall item it does not belong to us - dismiss
	if (!$item['item_wall']) {
		return;
	}

	// check if there is an iconfig to process
	$iconfig = get_iconfig($parent, 'fediwordle', 'word');

	if (!$iconfig) {
		return;
	}

/*
	if ($iconfig['round'] > strlen($iconfig['word'])) {
		del_iconfig($parent['id'], 'fediwordle', 'word');
		return;
	}
*/

	// if it's our own comment - dismiss to avoid looping
	if($item['author_xchan'] === $parent['owner_xchan']) {
		return;
	}

	// Remove possible mentions
	$answer = preg_replace('/@*\[([zu])rl(.*?)\](.*?)\[\/([zu])rl\]/ism', '' ,$item['body']);
	$answer = strtoupper(trim($answer));

	$result = fediwordle_prepare_result($answer, $iconfig);
	if ($result['success']) {
		del_iconfig($parent['id'], 'fediwordle', 'word');
	}
	else {
		$result['iconfig']['round']++;
		set_iconfig($parent['id'], 'fediwordle', 'word', $result['iconfig']);
	}

	$body = '';
	$body .= (($result['success']) ? 'Well done, ' : '');
	$body .= '@{' . (($item['author']['xchan_address']) ? $item['author']['xchan_address'] : $item['author']['xchan_url']) . '}';
	$body .= (($result['success']) ? '! ' : ' ');
	$body .= $result['body'];
	$body .= (($result['success']) ? ' ✨✨✨' : '');

	if (!$result['error']) {
		$body .= "\r\n\r\n";
		$body .= '🟢 ' . implode(' ', $result['iconfig']['chars']) . "\r\n";
		$body .= '🟡 ' . implode(' ', array_unique($result['iconfig']['?_chars'])) . "\r\n";
		$body .= '🔴 ' . implode(' ', $result['iconfig']['x_chars']) . "\r\n";
	}

	if ($result['success'] /* || $iconfig['round'] == strlen($iconfig['word']) */) {
		$body .= "\r\n";
		$body .= '--- GAME OVER ---';
	}

	$tags = linkify_tags($body, $parent['uid']);
	$post_tags = [];

	// TODO: fix taging

	if ($tags) {
		foreach ($tags as $tag) {
			$success = $tag['success'];
			if ($success['replaced']) {
				$post_tags[] = [
					'uid'   => $parent['uid'],
					'ttype' => $success['termtype'],
					'otype' => TERM_OBJ_POST,
					'term'  => $success['term'],
					'url'   => $success['url']
				];
			}
		}
	}

	$arr = [];

	$arr['uid'] = $parent['uid'];
	$arr['aid'] = $parent['aid'];
	$arr['uuid'] = item_message_id();
	$arr['mid'] = z_root() . '/item/' . $arr['uuid'];
	$arr['parent_mid'] = $parent['mid'];
	$arr['thr_parent'] = $item['mid'];

	$arr['owner_xchan'] = $parent['owner_xchan'];
	$arr['author_xchan'] = $parent['author_xchan'];

	$arr['body'] = $body;
	$arr['term'] = $post_tags;
	$arr['item_wall'] = 1;
	$arr['item_origin'] = 1;

	call_hooks('post_local', $arr);
	$post = item_store($arr);

	Master::Summon(['Notifier', 'comment-new', $post['item_id']]);

}

function fediwordle_replace($str) {
	$replace = '';

	for ($i = 0; $i < strlen($str); $i++)
		$replace .= '🔵';

	return $replace;
}

function fediwordle_prepare_result($answer, $iconfig) {
	$ret = [
		'success' => true,
		'error' => false,
		'body' => '',
		'iconfig' => $iconfig
	];

	if(strlen($answer) !==  strlen($iconfig['word'])) {
		$ret['body'] = t('ERROR: word length is not correct!');
		$ret['success'] = false;
		$ret['error'] = true;
		return $ret;
	}

	$answer_arr = str_split($answer);
	$word_arr = str_split($iconfig['word']);
	$char_count_arr = $process_char_count_arr = array_count_values($word_arr);

	foreach ($answer_arr as $i => $char) {
		if (empty($ret['iconfig']['chars'][$i])) {
			$ret['iconfig']['chars'][$i] = ' _ ';
		}

		if ($word_arr[$i] === $char && $process_char_count_arr[$char] > 0) {
			$res[$i] = '🟢';
			$ret['iconfig']['chars'][$i] = $char;

			if (isset($process_char_count_arr[$char])) {
				$process_char_count_arr[$char]--;
			}

			if (!$process_char_count_arr[$char]) {
				if (($key = array_search($char, $ret['iconfig']['?_chars'])) !== false) {
					unset($ret['iconfig']['?_chars'][$key]);
				}
			}
		}
	}

	$res_char_count_arr = array_count_values($ret['iconfig']['chars']);

	foreach ($answer_arr as $i => $char) {
		//$ret['iconfig']['?_chars'][$i] = '';

		if (in_array($char, $word_arr) && !isset($res[$i]) && $process_char_count_arr[$char] > 0) {
			$res[$i] = '🟡';

			if ($res_char_count_arr[$char] !== $char_count_arr[$char] && !in_array($char, $ret['iconfig']['?_chars'])) {
				$ret['iconfig']['?_chars'][] = $char;
			}

			$ret['success'] = false;
		}
		if (isset($process_char_count_arr[$char])) {
			$process_char_count_arr[$char]--;
		}
	}

	foreach ($answer_arr as $i => $char) {
		if (!isset($res[$i])) {
			$res[$i] = '🔵';
			if (!in_array($char, $word_arr) && !in_array($char, $ret['iconfig']['x_chars'])) {
				$ret['iconfig']['x_chars'][] = $char;
			}
			$ret['success'] = false;
		}
	}

	ksort($res);
	$ret['body'] = implode('', $res);

	return $ret;

}
