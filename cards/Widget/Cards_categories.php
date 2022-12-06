<?php

namespace Zotlabs\Widget;

use App;

class Cards_categories {

	function widget($arr) {

		if(argv(0) !== 'cards') {
			return EMPTY_STR;
		}

		if(!App::$profile['profile_uid']) {
			return EMPTY_STR;
		}

		if(!feature_enabled(App::$profile['profile_uid'], 'categories')) {
			return EMPTY_STR;
		}

		if(!perm_is_allowed(App::$profile['profile_uid'], get_observer_hash(), 'view_pages')) {
			return EMPTY_STR;
		}

		$cat = ((x($_REQUEST,'cat')) ? htmlspecialchars($_REQUEST['cat'], ENT_COMPAT,'UTF-8') : '');
		$srchurl = App::$argv[0] . '/' . App::$argv[1];
		$srchurl = rtrim(preg_replace('/cat\=[^\&].*?(\&|$)/is', '', $srchurl), '&');
		$srchurl = str_replace(['?f=','&f=', '/?'], ['', '', ''], $srchurl);

		return self::categories($srchurl, $cat);

	}

	function categories($baseurl, $selected = '') {

		$sql_extra = item_permissions_sql(App::$profile['profile_uid']);

		$item_normal = "and item.item_hidden = 0 and item.item_type = 6 and item.item_deleted = 0
			and item.item_unpublished = 0 and item.item_delayed = 0 and item.item_pending_remove = 0
			and item.item_blocked = 0 ";

		$terms = [];

		$r = q("select distinct(term.term)
			from term join item on term.oid = item.id
			where item.uid = %d
			and term.uid = item.uid
			and term.ttype = %d
			and term.otype = %d
			and item.owner_xchan = '%s'
			$item_normal
			$sql_extra
			order by term.term asc",
			intval(App::$profile['profile_uid']),
			intval(TERM_CATEGORY),
			intval(TERM_OBJ_POST),
			dbesc(App::$profile['channel_hash'])
		);

		if (!$r) {
			return EMPTY_STR;
		}

		foreach($r as $rr) {
			$terms[] = [
				'name' => $rr['term'],
				'selected' => (($selected == $rr['term']) ? 'selected' : '')
			];
		}

		return replace_macros(get_markup_template('categories_widget.tpl'), [
			'$title' => t('Categories'),
			'$desc' => '',
			'$sel_all' => (($selected == '') ? 'selected' : ''),
			'$all' => t('Everything'),
			'$terms' => $terms,
			'$base' => $baseurl,
		]);

	}
}
