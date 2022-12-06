<?php

/**
 * Name: Cards
 * Description: Create interactive personal planning cards
 * Version: 1.0
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Widget;
use Zotlabs\Module\Card_edit;

require_once('addon/cards/Mod_Cards.php');

function cards_load() {
	Hook::register('channel_apps', 'addon/cards/cards.php', 'cards_channel_apps');
	Hook::register('module_loaded', 'addon/cards/cards.php', 'cards_load_module');
	Hook::register('display_item', 'addon/cards/cards.php', 'cards_display_item');
	Hook::register('item_custom_display', 'addon/cards/cards.php', 'cards_item_custom_display');
	Hook::register('post_local', 'addon/cards/cards.php', 'cards_post_local');
	Widget::register('addon/cards/Widget/Cards_categories.php', 'cards_categories');
}

function cards_unload() {
	Hook::unregister('channel_apps', 'addon/cards/cards.php', 'cards_channel_apps');
	Hook::unregister('module_loaded', 'addon/cards/cards.php', 'cards_load_module');
	Hook::unregister('display_item', 'addon/cards/cards.php', 'cards_display_item');
	Hook::unregister('item_custom_display', 'addon/cards/cards.php', 'cards_item_custom_display');
	Hook::unregister('post_local', 'addon/cards/cards.php', 'cards_post_local');
	Hook::unregister('channel_activities_widget', 'addon/cards/cards.php', 'cards_channel_activities_widget');
	Widget::unregister('addon/cards/Widget/Cards_categories.php', 'cards_categories');
}

function cards_channel_apps(&$arr) {
	$uid = ((App::$profile_uid) ? App::$profile_uid : intval(local_channel()));

	if(! Apps::addon_app_installed($uid, 'cards'))
		return;

	$p = get_all_perms($uid, get_observer_hash());

	if (! $p['view_pages'])
		return;

	$arr['tabs'][] = [
		'label' => t('Cards'),
		'url'   => z_root() . '/cards/' . $arr['nickname'],
		'sel'   => ((argv(0) == 'cards') ? 'active' : ''),
		'title' => t('View Cards'),
		'id'    => 'cards-tab',
		'icon'  => 'list'
	];
}

function cards_load_module(&$arr) {
	if ($arr['module'] === 'card_edit') {
		require_once('addon/cards/Mod_Card_edit.php');
		$arr['controller'] = new Card_edit();
		$arr['installed']  = true;
	}
}

function cards_display_item(&$arr) {
	if (intval($arr['item']['item_type']) !== ITEM_TYPE_CARD) {
		return;
	}

	// rewrite edit link
	if (isset($arr['output']['edpost']) && intval($arr['item']['uid']) === local_channel()) {
		$arr['output']['edpost'] = [
			z_root() . '/card_edit/' . $arr['item']['id'],
			t('Edit')
		];
	}

	// rewrite conv link
	if (isset($arr['output']['conv'])) {
		$arr['output']['conv'] = [
			'href' => $arr['item']['plink'],
			'title' => t('View in context')
		];
	}
}

function cards_item_custom_display($target_item) {
	if (intval($target_item['item_type']) !== ITEM_TYPE_CARD) {
		return;
	}

	$x = channelx_by_n($target_item['uid']);

	$y = q("select iconfig.v from iconfig left join item on iconfig.iid = item.id
		where item.uid = %d and iconfig.cat = 'system' and iconfig.k = 'CARD' and item.id = %d limit 1",
		intval($target_item['uid']),
		intval($target_item['parent'])
	);

	if ($x && $y) {
		goaway(z_root() . '/cards/' . $x['channel_address'] . '/' . $y[0]['v']);
	}

	notice(t('Page not found.') . EOL);
	return EMPTY_STR;
}

function cards_post_local(&$arr) {
	if (intval($arr['item_type']) !== ITEM_TYPE_CARD) {
		return;
	}

	// rewrite category URLs
	if (is_array($arr['term'])) {
		$i = 0;
		foreach ($arr['term'] as $t) {
			if ($t['ttype'] === TERM_CATEGORY) {
				$arr['term'][$i]['url'] = str_replace('/channel/', '/cards/', $t['url']);
			}
			$i++;
		}
	}
}

function cards_channel_activities_widget(&$arr){

	if(! Apps::addon_app_installed($arr['channel']['channel_id'], 'cards')) {
		return;
	}

	$r = q("SELECT edited, plink, body, title FROM item WHERE uid = %d
		AND author_xchan = '%s'	AND item_type = 6
		AND item_thread_top = 1 AND item_deleted = 0
		ORDER BY edited DESC LIMIT %d",
		intval($arr['channel']['channel_id']),
		dbesc($arr['channel']['channel_hash']),
		intval($arr['limit'])
	);

	if (!$r) {
		return;
	}

	foreach($r as $rr) {
		$summary = html2plain(purify_html(bbcode($rr['body'], ['drop_media' => true, 'tryoembed' => false]), 85, true));
		if ($summary) {
			$summary = substr_words(htmlentities($summary, ENT_QUOTES, 'UTF-8', false), 85);
		}

		$i[] = [
			'url' => $rr['plink'],
			'title' => $rr['title'],
			'summary' => $summary,
			'footer' => datetime_convert('UTC', date_default_timezone_get(), $rr['edited'])
		];

	}

	$arr['activities']['cards'] = [
		'label' => t('Cards'),
		'icon' => 'list',
		'url' => z_root() . '/cards/' . $arr['channel']['channel_address'],
		'date' => $r[0]['edited'],
		'items' => $i,
		'tpl' => 'channel_activities.tpl'
	];

}
