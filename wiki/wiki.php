<?php

/**
 * Name: Wiki
 * Description: A simple yet powerful wiki
 * Version: 1.0
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Widget;

require_once('addon/wiki/Mod_Wiki.php');
require_once('addon/wiki/Lib/NativeWiki.php');
require_once('addon/wiki/Lib/NativeWikiPage.php');

function wiki_load() {
	Hook::register('channel_activities_widget', 'addon/wiki/wiki.php', 'wiki_channel_activities_widget');
	Widget::register('addon/wiki/Widget/Wiki_pages.php', 'wiki_pages');
}

function wiki_unload() {
	Hook::unregister('channel_activities_widget', 'addon/wiki/wiki.php', 'wiki_channel_activities_widget');
	Widget::unregister('addon/wiki/Widget/Wiki_pages.php', 'wiki_pages');
}

function wiki_channel_activities_widget(&$arr){

	if(! Apps::addon_app_installed($arr['channel']['channel_id'], 'wiki')) {
		return;
	}

	$r = q("SELECT id, changed, resource_id FROM item WHERE uid = %d
		AND author_xchan = '%s' AND resource_type = 'nwiki'
		AND item_deleted = 0
		ORDER BY changed DESC LIMIT %d",
		intval($arr['channel']['channel_id']),
		dbesc($arr['channel']['channel_hash']),
		intval($arr['limit'])
	);

	if (!$r) {
		return;
	}

	foreach($r as $rr) {
		$x = q("SELECT body FROM item WHERE resource_type = 'nwikipage' AND resource_id = '%s' AND uid = %d AND title = 'Home' ORDER by revision DESC LIMIT 1",
			dbesc($rr['resource_id']),
			intval($arr['channel']['channel_id'])
		);

		$summary = html2plain(purify_html(bbcode($x[0]['body'], ['drop_media' => true, 'tryoembed' => false])), 85, true);
		if ($summary) {
			$summary = substr_words(htmlentities($summary, ENT_QUOTES, 'UTF-8', false), 85);
		}

		$raw_name = get_iconfig($rr['id'], 'wiki', 'rawName');
		$url = z_root() . '/wiki/' . $arr['channel']['channel_address'] . '/' . $raw_name;

		$i[] = [
			'url' => $url,
			'title' => $raw_name,
			'summary' => $summary,
			'footer' => datetime_convert('UTC', date_default_timezone_get(), $rr['changed'])
		];
	}


	$arr['activities']['wiki'] = [
		'label' => t('Wikis'),
		'icon' => 'pencil-square-o',
		'url' => z_root() . '/wiki/' . $arr['channel']['channel_address'],
		'date' => $r[0]['changed'],
		'items' => $i,
		'tpl' => 'channel_activities.tpl'
	];
}
