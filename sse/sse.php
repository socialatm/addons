<?php


/**
 * Name: SSE Notifications
 * Description: Server sent events notifications
 * Version: 1.0
 * Author: Mario Vavti
 * Maintainer: Mario Vavti <mario@hub.somaton.com>
 * MinVersion: 4.7
 */

use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;
use Zotlabs\Lib\Enotify;
use Zotlabs\Lib\XConfig;

function sse_load() {
	Hook::register('item_stored', 'addon/sse/sse.php', 'sse_item_stored');
	Hook::register('event_store_event_end', 'addon/sse/sse.php', 'sse_event_store_event_end');
	Hook::register('enotify_store_end', 'addon/sse/sse.php', 'sse_enotify_store_end');
	Hook::register('permissions_create', 'addon/sse/sse.php', 'sse_permissions_create');
}

function sse_unload() {
	Hook::unregister('item_stored', 'addon/sse/sse.php', 'sse_item_stored');
	Hook::unregister('event_store_event_end', 'addon/sse/sse.php', 'sse_event_store_event_end');
	Hook::unregister('enotify_store_end', 'addon/sse/sse.php', 'sse_enotify_store_end');
	Hook::unregister('permissions_create', 'addon/sse/sse.php', 'sse_permissions_create');
}

function sse_item_stored($item) {

	if(! $item['uid'])
		return;

	if(! is_item_normal($item))
		return;

	$is_file = in_array($item['obj_type'], ['Document', 'Video', 'Audio', 'Image']);

	$item_uid = $item['uid'];

	$sys = false;
	$channel = [];

	if(is_sys_channel($item_uid)) {
		$sys = true;

		$hashes = q("SELECT xchan FROM xconfig WHERE cat = 'sse' AND k ='timestamp' and %s > %s - INTERVAL %s UNION SELECT channel_hash FROM channel WHERE channel_removed = 0",
			db_str_to_date('v'),
			db_utcnow(),
			db_quoteinterval('15 MINUTE')
		);
		$hashes = flatten_array_recursive($hashes);
	}
	else {
		$channel = channelx_by_n($item_uid);
		$hashes = [$channel['channel_hash']];
	}

	if(! $hashes)
		return;

	$r[0] = $item;
	xchan_query($r);

	foreach($hashes as $hash) {

		if (!$hash) {
			continue;
		}

		if($sys) {
			$current_channel = channelx_by_hash($hash);
			$item_uid = $current_channel ? $current_channel['channel_id'] : $item_uid;
		}

		$vnotify = get_pconfig($item_uid, 'system', 'vnotify', -1);

		if(in_array($item['verb'], [ACTIVITY_LIKE, ACTIVITY_DISLIKE]) && !($vnotify & VNOTIFY_LIKE))
			continue;

		if(in_array($item['verb'], [ACTIVITY_DISLIKE]) && !feature_enabled($item_uid, 'dislike'))
			continue;

		if($item['obj_type'] === ACTIVITY_OBJ_FILE && !($vnotify & VNOTIFY_FILES))
			continue;

		if($hash === $item['author_xchan'])
			continue;

		XConfig::Load($hash);

		$t = XConfig::Get($hash, 'sse', 'timestamp', NULL_DATE);

		if(datetime_convert('UTC', 'UTC', $t) < datetime_convert('UTC', 'UTC', '- 30 seconds')) {
			XConfig::Set($hash, 'sse', 'notifications', []);
		}

		XConfig::Set($hash, 'sse', 'lock', 1);

		$x = XConfig::Get($hash, 'sse', 'notifications', []);

		// this is neccessary for Enotify::format() to calculate the right time and language
		if($sys && isset($current_channel['channel_timezone'])) {
			date_default_timezone_set($current_channel['channel_timezone']);
		}

		if ($channel && isset($channel['channel_timezone'])) {
			date_default_timezone_set($channel['channel_timezone']);
		}

		push_lang(XConfig::Get($hash, 'sse', 'language', 'en'));

		if($sys) {
			if(($vnotify & VNOTIFY_PUBS || $sys) && !$is_file  && intval($item['item_private']) === 0)
				$x['pubs']['notifications'][] = Enotify::format($r[0]);
		}
		else {
			if(($vnotify & VNOTIFY_CHANNEL) && $item['item_wall'] && !$is_file && in_array(intval($item['item_private']), [0, 1]))
				$x['home']['notifications'][] = Enotify::format($r[0]);

			if(($vnotify & VNOTIFY_NETWORK) && !$item['item_wall'] && !$is_file && in_array(intval($item['item_private']), [0, 1]))
				$x['network']['notifications'][] = Enotify::format($r[0]);

			if(($vnotify & VNOTIFY_MAIL) && !$is_file && intval($item['item_private']) === 2)
				$x['dm']['notifications'][] = Enotify::format($r[0]);

			if(($vnotify & VNOTIFY_FILES) && $is_file)
				$x['files']['notifications'][] = Enotify::format($r[0]);
		}

		pop_lang();

		if(isset($x['network']['notifications']))
			$x['network']['count'] = count($x['network']['notifications']);

		if(isset($x['dm']['notifications']))
			$x['dm']['count'] = count($x['dm']['notifications']);

		if(isset($x['home']['notifications']))
			$x['home']['count'] = count($x['home']['notifications']);

		if(isset($x['pubs']['notifications']))
			$x['pubs']['count'] = count($x['pubs']['notifications']);

		if(isset($x['files']['notifications']))
			$x['files']['count'] = count($x['files']['notifications']);

		XConfig::Set($hash, 'sse', 'timestamp', datetime_convert());
		XConfig::Set($hash, 'sse', 'notifications', $x);
		XConfig::Set($hash, 'sse', 'lock', 0);

	}

}

function sse_event_store_event_end($item) {

	if($item['etype'] === 'task')
		return;

	if(! $item['uid'])
		return;

	$item_uid = $item['uid'];

	$channel = channelx_by_n($item_uid);

	if(! $channel)
		return;

	$vnotify = get_pconfig($channel['channel_id'], 'system', 'vnotify', -1);
	if(! ($vnotify & VNOTIFY_EVENT))
		return;

	XConfig::Load($channel['channel_hash']);

	$t = XConfig::Get($channel['channel_hash'], 'sse', 'timestamp', NULL_DATE);

	if(datetime_convert('UTC', 'UTC', $t) < datetime_convert('UTC', 'UTC', '- 30 seconds')) {
		XConfig::Set($channel['channel_hash'], 'sse', 'notifications', []);
	}

	$xchan = q("SELECT * FROM xchan WHERE xchan_hash = '%s'",
		dbesc($item['event_xchan'])
	);

	$x = XConfig::Get($channel['channel_hash'], 'sse', 'notifications', []);

	$rr = array_merge($item, $xchan[0]);

	// this is neccessary for Enotify::format() to calculate the right time and language
	date_default_timezone_set($channel['channel_timezone']);
	push_lang(XConfig::Get($channel['channel_hash'], 'sse', 'language', 'en'));
	$x['all_events']['notifications'][] = Enotify::format_all_events($rr);
	pop_lang();

	if(is_array($x['all_events']['notifications']))
		$x['all_events']['count'] = count($x['all_events']['notifications']);

	XConfig::Set($channel['channel_hash'], 'sse', 'timestamp', datetime_convert());
	XConfig::Set($channel['channel_hash'], 'sse', 'notifications', $x);

}

function sse_enotify_store_end($item) {

	$channel = channelx_by_n($item['uid']);

	if(! $channel)
		return;

	$vnotify = get_pconfig($channel['channel_id'], 'system', 'vnotify', -1);
	if(! ($vnotify & VNOTIFY_SYSTEM))
		return;

	XConfig::Load($channel['channel_hash']);

	$t = XConfig::Get($channel['channel_hash'], 'sse', 'timestamp', NULL_DATE);

	if(datetime_convert('UTC', 'UTC', $t) < datetime_convert('UTC', 'UTC', '- 30 seconds')) {
		XConfig::Set($channel['channel_hash'], 'sse', 'notifications', []);
	}

	$x = XConfig::Get($channel['channel_hash'], 'sse', 'notifications', []);

	// this is neccessary for Enotify::format_notify() to calculate the right time and language
	date_default_timezone_set($channel['channel_timezone']);
	push_lang(XConfig::Get($channel['channel_hash'], 'sse', 'language', 'en'));
	$x['notify']['notifications'][] = Enotify::format_notify($item);
	pop_lang();

	if(is_array($x['notify']['notifications']))
		$x['notify']['count'] = count($x['notify']['notifications']);

	XConfig::Set($channel['channel_hash'], 'sse', 'timestamp', datetime_convert());
	XConfig::Set($channel['channel_hash'], 'sse', 'notifications', $x);

}

function sse_permissions_create($item) {

	$channel = channelx_by_hash($item['recipient']['xchan_hash']);

	if(! $channel)
		return;

	$vnotify = get_pconfig($channel['channel_id'], 'system', 'vnotify', -1);
	if(! ($vnotify & VNOTIFY_SYSTEM))
		return;

	XConfig::Load($channel['channel_hash']);

	$t = XConfig::Get($channel['channel_hash'], 'sse', 'timestamp', NULL_DATE);

	if(datetime_convert('UTC', 'UTC', $t) < datetime_convert('UTC', 'UTC', '- 30 seconds')) {
		XConfig::Set($channel['channel_hash'], 'sse', 'notifications', []);
	}

	$x = XConfig::Get($channel['channel_hash'], 'sse', 'notifications', []);

	// this is neccessary for Enotify::format_notify() to calculate the right time
	date_default_timezone_set($channel['channel_timezone']);
	push_lang(XConfig::Get($channel['channel_hash'], 'sse', 'language', 'en'));
	$x['intros']['notifications'][] = Enotify::format_intros($item['sender']);
	pop_lang();

	if(is_array($x['intros']['notifications']))
		$x['intros']['count'] = count($x['intros']['notifications']);

	XConfig::Set($channel['channel_hash'], 'sse', 'timestamp', datetime_convert());
	XConfig::Set($channel['channel_hash'], 'sse', 'notifications', $x);

}
