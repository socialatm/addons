<?php

/**
 * Name: Gallery
 * Description: Album gallery and photo viewer based on photoswipe
 * Version: 0.6
 * MinVersion: 3.8.8
 * Author: Mario
 * Maintainer: Mario
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;

require_once('addon/gallery/Mod_Gallery.php');

function gallery_load() {
	Hook::register('channel_apps', 'addon/gallery/gallery.php', 'gallery_channel_apps');
	Hook::register('photo_view_filter', 'addon/gallery/gallery.php', 'gallery_photo_view_filter');
	Hook::register('page_end', 'addon/gallery/gallery.php', 'gallery_page_end');
	Hook::register('prepare_body', 'addon/gallery/gallery.php', 'gallery_prepare_body', 1, 20);
}

function gallery_unload() {
	Hook::unregister('channel_apps', 'addon/gallery/gallery.php', 'gallery_channel_apps');
	Hook::unregister('photo_view_filter', 'addon/gallery/gallery.php', 'gallery_photo_view_filter');
	Hook::unregister('page_end', 'addon/gallery/gallery.php', 'gallery_page_end');
	Hook::unregister('prepare_body', 'addon/gallery/gallery.php', 'gallery_prepare_body');
}

function gallery_channel_apps(&$b) {
	$uid = ((App::$profile_uid) ? App::$profile_uid : intval(local_channel()));

	if(! Apps::addon_app_installed($uid, 'gallery'))
		return;

	$p = get_all_perms($uid, get_observer_hash());

	if (! $p['view_storage'])
		return;

	$b['tabs'][] = [
		'label' => t('Gallery'),
		'url'   => z_root() . '/gallery/' . $b['nickname'],
		'sel'   => ((argv(0) == 'gallery') ? 'active' : ''),
		'title' => t('Photo Gallery'),
		'id'    => 'gallery-tab',
		'icon'  => 'image'
	];
}

function gallery_supported_modules() {
	$modules = [
		'gallery',
		'photos',
		'network',
		'channel',
		'display',
		'hq',
		'pubstream'
	];

	return $modules;
}

function gallery_photo_view_filter(&$arr) {
	$uid = ((App::$profile_uid) ? App::$profile_uid : intval(local_channel()));

	if(! Apps::addon_app_installed($uid, 'gallery'))
		return;

	$arr['onclick'] = '$.get(\'gallery/' . $arr['nickname'] . '?f=&photo=' . $arr['raw_photo']['resource_id'] . '&type=' . $arr['raw_photo']['mimetype'] . '&width=' . $arr['raw_photo']['width'] . '&height=' . $arr['raw_photo']['height'] . '&title=' . (($arr['raw_photo']['description']) ? $arr['raw_photo']['description'] : $arr['raw_photo']['filename']) . '\',  function(data) { if(! $(\'#gallery-fullscreen-view\').length) { $(\'<div></div>\').attr(\'id\', \'gallery-fullscreen-view\').appendTo(\'body\'); } $(\'#gallery-fullscreen-view\').html(data); }); return false;';
}

function gallery_page_end(&$str) {
	$uid = ((App::$profile_uid) ? App::$profile_uid : intval(local_channel()));

	if(isset(App::$page['template']) && App::$page['template'] === 'none') {
		return;
	}

	if(! Apps::addon_app_installed($uid, 'gallery'))
		return;

	head_add_js('/addon/gallery/lib/photoswipe/dist/photoswipe.js', 1);
	head_add_js('/addon/gallery/lib/photoswipe/dist/photoswipe-ui-default.js', 1);
	head_add_js('/addon/gallery/view/js/gallery.js', 1);

	head_add_css('/addon/gallery/lib/photoswipe/dist/photoswipe.css');
	head_add_css('/addon/gallery/lib/photoswipe/dist/default-skin/default-skin.css');
	head_add_css('/addon/gallery/view/css/gallery.css');

	$tpl = get_markup_template('gallery_dom.tpl', 'addon/gallery');
	$str .= replace_macros($tpl, []);
}

function gallery_prepare_body(&$arr) {

	if(! $arr['item']['item_thread_top'])
		return;

	if($arr['item']['item_type'] != ITEM_TYPE_POST)
		return;

	$uid = ((App::$profile_uid) ? App::$profile_uid : intval(local_channel()));

	if(! Apps::addon_app_installed($uid, 'gallery'))
		return;

	$dom = new DOMDocument();

	$arr['html'] = mb_convert_encoding($arr['html'], 'HTML-ENTITIES', "UTF-8");

	// LIBXML_HTML_NOIMPLIED does not work well without a parent element.
	// We will add a parent div here and will remove it again later.
	@$dom->loadHTML('<div>' . $arr['html'] . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);

	$xp = new DOMXPath($dom);

	$nodes = $xp->query('node()');

	$img_nodes = 0;
	$i = 1;

	$div = $dom->createElement('div');

	foreach($nodes as $node) {
		if($node->nodeName == 'br') {
			$node->parentNode->removeChild($node);
			continue;
		}

		if(($node->nodeName == 'img') && (strpos($node->getAttribute('class'), 'smiley') === false)) {
			$img_nodes++;

			//wrap in div
			$div_clone = $div->cloneNode();
			$node->parentNode->replaceChild($div_clone,$node);
			$div_clone->appendChild($node);
		}

		if($node->nodeName == 'a' && !$node->textContent && $node->firstChild->nodeName == 'img')
			$img_nodes++;

		if(($i > $img_nodes) && ($node->nodeName != 'img' || ($node->nodeName == 'a' && !$node->textContent && $node->firstChild->nodeName == 'img')))
			break;

		$i++;
	}

	if(! $img_nodes)
		return;

	$nodes = $xp->query('a/img/.. | div/img/..');
	$id = $arr['item']['id'];
	$i = 0;

	$gallery_div = $dom->createElement('div');
	$gallery_div->setAttribute('id','gallery-wrapper-' . $id);
	$gallery_div->setAttribute('class','gallery-wrapper');

	$gallery_div_clone = $gallery_div->cloneNode();

	foreach($nodes as $node) {
		if($i == $img_nodes)
			break;

		$node->parentNode->replaceChild($gallery_div_clone,$node);
		$gallery_div_clone->appendChild($node);

		$i++;
	}

	switch($img_nodes) {
		case 1:
			$row_height = 300;
			$last_row = 'center';
			break;
		case 2:
			$row_height = 240;
			$last_row = 'justify';
			break;
		case 3:
			$row_height = 180;
			$last_row = 'justify';
			break;
		default:
			$row_height = 120;
			$last_row = 'justify';
	}

	$js = <<<EOF
		<script>
			if($('#wall-item-body-$id .btn-nsfw-wrap').length) {
				$('#wall-item-body-$id .btn-nsfw-wrap').on('click', function() {
					$('#gallery-wrapper-$id').height(39);
					galleryJustifyPhotos_$id();
				});
			}
			else {
				galleryJustifyPhotos_$id();
			}
			function galleryJustifyPhotos_$id() {
				justifiedGalleryActive = true;
				$('#gallery-wrapper-$id').justifiedGallery({
					captions: false,
					rowHeight: $row_height,
					maxRowHeight: divmore_height,
					lastRow: '$last_row',
					justifyThreshold: 0.5,
					border: 0,
					margins: 3,
					maxRowsCount: 1
				}).on('jg.complete', function(e){
					justifiedGalleryActive = false;
					setTimeout(scrollToItem, 100);
					if($('#gallery-wrapper-$id .jg-entry').length === 1) {
						$('#gallery-wrapper-$id').addClass('gallery-bg-grid');
					}
					if($('#gallery-wrapper-$id .jg-entry-visible').length < $('#gallery-wrapper-$id .jg-entry').length) {
						var more_count = $('#gallery-wrapper-$id .jg-entry').length - $('#gallery-wrapper-$id .jg-entry-visible').length;
						$('#gallery-wrapper-$id').append('<div id="jg-more-count-$id" class="jg-more-count">+ ' + more_count + ' <i class="fa fa-image"></i></div>');
					}
				}).on('jg.resize', function(e){
					if($('#gallery-wrapper-$id .jg-entry-visible').length < $('#gallery-wrapper-$id .jg-entry').length) {
						var more_count = $('#gallery-wrapper-$id .jg-entry').length - $('#gallery-wrapper-$id .jg-entry-visible').length;
						$('#jg-more-count-$id').html('+ ' + more_count + ' <i class="fa fa-image"></i>');
					}
				});

			}
		</script>
EOF;

	$arr['photo'] = $dom->saveHTML($gallery_div_clone) . $js;

	$gallery_div_clone->parentNode->removeChild($gallery_div_clone);

	// remove the parent div again
	$html = substr(trim($dom->saveHTML()),5,-6);

	$arr['html'] = $html;
}
