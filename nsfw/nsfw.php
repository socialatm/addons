<?php

/**
 * Name: NSFW
 * Description: Collapse posts with inappropriate content
 * Version: 1.0
 * Author: Mike Macgirvin <http://macgirvin.com/profile/mike>
 * Maintainer: Mike Macgirvin <mike@macgirvin.com>
 */


use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function nsfw_install() {
	Hook::register('prepare_body', 'addon/nsfw/nsfw.php', 'nsfw_prepare_body', 10);
	Route::register('addon/nsfw/Mod_Nsfw.php','nsfw');
}


function nsfw_uninstall() {
	Hook::unregister('prepare_body', 'addon/nsfw/nsfw.php', 'nsfw_prepare_body');
	Route::unregister('addon/nsfw/Mod_Nsfw.php','nsfw');
}


// This function isn't perfect and isn't trying to preserve the html structure - it's just a
// quick and dirty filter to pull out embedded photo blobs because 'nsfw' seems to come up
// inside them quite often. We don't need anything fancy, just pull out the data blob so we can
// check against the rest of the body.

function nsfw_extract_photos($body) {

	$new_body = '';

	$img_start = strpos($body,'src="data:');
	if(! $img_start)
		return $body;

	$img_end = (($img_start !== false) ? strpos(substr($body,$img_start),'>') : false);

	$cnt = 0;

	while($img_end !== false) {
		$img_end += $img_start;
		$new_body = $new_body . substr($body,0,$img_start);

		$cnt ++;
		$body = substr($body,0,$img_end);

		$img_start = strpos($body,'src="data:');
		$img_end = (($img_start !== false) ? strpos(substr($body,$img_start),'>') : false);

	}

	if(! $cnt)
		return $body;

	return $new_body;
}

function nsfw_prepare_body(&$b) {

	$words = 'nsfw,contentwarning';

	if(local_channel()) {
		$words = ((Apps::addon_app_installed(local_channel(),'nsfw')) ? get_pconfig(local_channel(),'nsfw','words',$words) : EMPTY_STR);
	}

	if($words) {
		$arr = explode(',',$words);
	}

	$found = false;

	if($arr) {

		$body = nsfw_extract_photos($b['html']);

		foreach($arr as $word) {
			$word = trim($word);
			$author = '';

			if(! strlen($word)) {
				continue;
			}

			if(strpos($word,'lang=') === 0) {
				if(! $b['item']['lang'])
					continue;
				$l = substr($word,5);
				if(strlen($l) && strcasecmp($l,$b['item']['lang']) !== 0)
					continue;
				$found = true;
				$orig_word = $word;
				break;
			}
			if(strpos($word,'lang!=') === 0) {
				if(! $b['item']['lang'])
					continue;
				$l = substr($word,6);
				if(strlen($l) && strcasecmp($l,$b['item']['lang']) === 0)
					continue;
				$found = true;
				$orig_word = $word;
				break;
			}

			$orig_word = $word;

			if(strpos($word,'::') !== false) {
				$author = substr($word,0,strpos($word,'::'));
				$word = substr($word,strpos($word,'::')+2);
			}
			if($author && (stripos($b['item']['author']['xchan_name'],$author) === false) && (stripos($b['item']['author']['xchan_addr'],$author) === false))
				continue;


			if(! $word)
				$found = true;

			if(strpos($word,'/') === 0) {
				if(preg_match($word,$body)) {
					$found = true;
					break;
				}
			}
			else {
				if(stristr($body,$word)) {
					$found = true;
					break;
				}
				if(isset($b['item']['term'])) {
					foreach($b['item']['term'] as $t) {
						if(stristr($t['term'],$word )) {
							$found = true;
							break;
						}
					}
				}
				if($found)
					break;
			}
		}
	}

	$ob_hash = get_observer_hash();
	if((! $ob_hash)
		&& (intval($b['item']['author']['xchan_censored']) || intval($b['item']['author']['xchan_selfcensored']))) {
		$found = true;
		$orig_word = t('Possible adult content');
	}
	if($found) {
		$rnd = random_string(8);

		$b['html'] = preg_replace('~<img[^>]*\K(?=src)~i','data-',$b['html']);

		if($b['photo']) {
			$b['photo'] = preg_replace('~<img[^>]*\K(?=src)~i','data-',$b['photo']);
			$onclick = 'onclick="datasrc2src(\'#nsfw-html-' . $rnd . ' img[data-src]\'); datasrc2src(\'#nsfw-photo-' . $rnd . ' img[data-src]\'); openClose(\'nsfw-html-' . $rnd . '\'); openClose(\'nsfw-photo-' . $rnd . '\');"';
		}
		else {
			$onclick = 'onclick="datasrc2src(\'#nsfw-html-' . $rnd . ' img[data-src]\'); openClose(\'nsfw-html-' . $rnd . '\');"';
		}

		$b['html'] = '<div class="text-center"><button id="nsfw-wrap-' . $rnd . '" class="btn btn-warning btn-nsfw-wrap" type="button" ' . $onclick . '>' . sprintf( t('%s - view'),$orig_word ) . '</button></div><div id="nsfw-html-' . $rnd . '" style="display: none; " class="no-collapse">' . $b['html'] . '</div>';
		$b['photo'] = (($b['photo']) ? '<div id="nsfw-photo-' . $rnd . '" style="display: none; " >' . $b['photo'] . '</div>' : '');
	}
}
