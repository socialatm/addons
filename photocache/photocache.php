<?php
/**
 * Name: Photo Cache
 * Description: Local photo cache implementation
 * Version: 0.2.16
 * Author: Max Kostikov <https://tiksi.net/channel/kostikov>
 * Maintainer: Max Kostikov <https://tiksi.net/channel/kostikov>
 * MinVersion: 3.9.5
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

use Zotlabs\Lib\Hashpath;

require_once('include/photo/photo_driver.php');

function photocache_load() {

	Hook::register('cache_mode_hook', 'addon/photocache/photocache.php', 'photocache_mode');
	Hook::register('cache_url_hook', 'addon/photocache/photocache.php', 'photocache_url');
	Hook::register('cache_body_hook', 'addon/photocache/photocache.php', 'photocache_body');
	Route::register('addon/photocache/Mod_Photocache.php', 'photocache');

	logger('Photo Cache is loaded');
}


function photocache_unload() {

	Hook::unregister('cache_mode_hook', 'addon/photocache/photocache.php', 'photocache_mode');
	Hook::unregister('cache_url_hook', 'addon/photocache/photocache.php', 'photocache_url');
	Hook::unregister('cache_body_hook', 'addon/photocache/photocache.php', 'photocache_body');
	Route::unregister('addon/photocache/Mod_Photocache.php', 'photocache');

	$x = q("UPDATE photo SET expires = '%s' WHERE photo_usage = %d",
	    dbesc(NULL_DATE),
	    intval(PHOTO_CACHE)
	);
	logger('All cached photos was marked as expired and will be removed', LOGGER_DEBUG);

	logger('Photo Cache is unloaded');
}


/*
 * @brief Returns array of current photo cache settings
 *
 * @param string $v
 * @return mixed array
 *
 */
function photocache_mode(&$v) {

	$v['on'] = true;
	$v['age'] = photocache_mode_key('age');
	$v['minres'] = photocache_mode_key('minres');
	$v['grid'] = photocache_mode_key('grid');
	$v['exp'] = photocache_mode_key('exp');
	$v['leak'] = photocache_mode_key('leak');
}


/*
 * @brief Returns current photo cache setting by its key
 *
 * @param string $key
 * @return mixed content | int 0 if not found
 *
 */
function photocache_mode_key($key) {
	switch($key) {
		case 'age':
			$x = intval(get_config('system','photo_cache_time'));
			return ($x ? $x : 86400);
			break;
		case 'minres':
			$x = intval(get_config('system','photo_cache_minres'));
			return ($x ? $x : 1024);
			break;
		case 'grid':
			return boolval(get_config('system','photo_cache_grid', 0));
			break;
		case 'exp':
			return boolval(get_config('system','photo_cache_ownexp', 0));
			break;
		case 'leak':
			return boolval(get_config('system','photo_cache_leak', 0));
			break;
		default:
			return 0;
	}
}


 /*
 * @brief Is this host in the Grid?
 *
 * @param string $url
 * @return boolean
 *
 */
function photocache_isgrid($url) {

	if(photocache_mode_key('grid'))
		return false;

	return is_matrix_url($url);
}


/*
 * @brief Returns hash string by URL
 *
 * @param string $str
 * @param string $alg default sha256
 * @return string
 *
 */
function photocache_hash($str, $alg = 'sha256') {

	return hash($alg, $str);
}


/*
 * @brief Proceed message and replace URL for cached photos
 *
 * @param array $s
 * * 'body' => string
 * * 'uid' => int
 * @return array $s
 *
 */
 function photocache_body(&$s) {

	if(! $s['uid'])
		return;

	if(! Apps::addon_app_installed($s['uid'],'photocache'))
		return;

	$x = channelx_by_n($s['uid']);
	if(! $x)
		return logger('invalid channel ID received ' . $s['uid'], LOGGER_DEBUG);

	$matches = null;
	$cnt = preg_match_all("/\<img(.+?)src=([\"'])(https?\:.*?)\\2(.*?)\>/", $s['body'], $matches, PREG_SET_ORDER);
	if($cnt) {
		$ph = photo_factory('');
		foreach ($matches as $match) {
		    $match[3] = trim($match[3]);
			if(photocache_isgrid($match[3]))
				continue;

			logger('uid: ' . $s['uid'] . '; url: ' . $match[3], LOGGER_DEBUG);

			$hash = photocache_hash(preg_replace('|^https?://|' ,'' , $match[3]));
			$resid = photocache_hash($s['uid'] . $hash);
			$r = q("SELECT * FROM photo WHERE xchan = '%s' AND photo_usage = %d AND uid = %d LIMIT 1",
				dbesc($hash),
				intval(PHOTO_CACHE),
				intval($s['uid'])
			);
			if(! $r) {
				// Create new empty link. Data will be fetched on link open.
				$r = [
					'aid' => $x['channel_account_id'],
					'uid' => $s['uid'],
					'xchan' => $hash,
					'resource_id' => $resid,
					'created' => datetime_convert(),
					'expires' => dbesc(NULL_DATE),
					'mimetype' => '',
					'photo_usage' => PHOTO_CACHE,
					'os_storage' => 1,
					'display_path' => $match[3]
				];
				if(! $ph->save($r, true))
					logger('can not create new link in database', LOGGER_DEBUG);
			}
			$s['body'] = str_replace($match[3], z_root() . '/photo/' . $resid, $s['body']);
			logger('local resource id ' . $resid . '; xchan: ' . $hash . '; url: ' . $match[3]);
		}
	}
}


/*
 * @brief Fetch or renew cached photo
 *
 * @param array $cache
 * * 'status' => boolean
 * * 'item' => array
 * @return array of result
 *
 */
function photocache_url(&$cache = []) {

	if(! local_channel() && ! photocache_mode_key('leak'))
		return;

	if(empty($cache))
		return logger('undefined resource', LOGGER_DEBUG, LOG_INFO);

	$cache_mode = [];
	photocache_mode($cache_mode);

	$minres = intval(get_pconfig($cache['item']['uid'], 'photocache', 'cache_minres'));
	if($minres == 0)
		$minres = $cache_mode['minres'];

	logger('info: processing ' . $cache['item']['resource_id'] . ' (' . $cache['item']['display_path'] .') for ' . $cache['item']['uid']  . ' (min. ' . $minres . ' px)', LOGGER_DEBUG);

	if($cache['item']['height'] == 0) {
		// If new resource id
		$k = q("SELECT * FROM photo WHERE xchan = '%s' AND photo_usage = %d AND height > 0 ORDER BY filesize DESC LIMIT 1",
			dbesc($cache['item']['xchan']),
			intval(PHOTO_CACHE)
		);
		if($k) {
			// If photo already was cached for other user just duplicate it
			if(($k[0]['height'] >= $minres || $k[0]['width'] >= $minres) && $k[0]['filesize'] > 0) {
				$cache['item']['os_syspath'] = dbunescbin($k[0]['content']);
				$cache['item']['filesize'] = $k[0]['filesize'];
			}
			$cache['item']['description'] = $k[0]['description'];
			$cache['item']['edited'] = $k[0]['edited'];
			$cache['item']['expires'] = $k[0]['expires'];
			$cache['item']['mimetype'] = $k[0]['mimetype'];
			$cache['item']['height'] = $k[0]['height'];
			$cache['item']['width'] = $k[0]['width'];
			$ph = photo_factory('');
			if(! $ph->save($cache['item'], true))
				return logger('could not duplicate cached URL ' . $cache['item']['display_path'] . ' for ' . $cache['item']['uid'], LOGGER_DEBUG);

			logger('info: duplicate ' . $cache['item']['resource_id'] . ' data from cache for ' . $k[0]['uid'], LOGGER_DEBUG);
		}
	}

	$exp = strtotime($cache['item']['expires']) + date('Z');
	// fetch the image if the cache has expired or we need to cache and it has not yet been done
	$url = (($cache['item']['height'] == 0) || ((($cache['item']['height'] >= $minres || $cache['item']['width'] >= $minres) && ($exp - 60 < time() || $cache['item']['filesize'] == 0))) ? html_entity_decode($cache['item']['display_path'], ENT_QUOTES) : '');

	if($url) {
		// Get data from remote server
		$hdrs = [];
		if($cache['item']['filesize'] > 0) {
			$hdrs[] = "If-Modified-Since: " . gmdate("D, d M Y H:i:s", $exp) . " GMT";
			if(! empty($cache['item']['description']))
				$hdrs[] = "If-None-Match: " . $cache['item']['description'];
		}
		$i = z_fetch_url($url, true, 0, [ 'headers' => $hdrs ]);

		if((! $i['success']) && $i['return_code'] != 304)
			return logger('photo could not be fetched (HTTP code ' . $i['return_code'] . ')', LOGGER_DEBUG);

		$hdrs = [];
		$h = explode("\n", $i['header']);
		foreach ($h as $l) {
			if (strpos($l, ':') === false) {
				continue;
			}

			list($t,$v) = array_map("trim", explode(":", trim($l), 2));
			$hdrs[strtolower($t)] = $v;
		}

		if(array_key_exists('expires', $hdrs)) {
			$expires = strtotime($hdrs['expires']);
			if($expires - 60 < time())
				return logger('fetched item expired ' . $hdrs['expires'], LOGGER_DEBUG);
		}

		$cc = '';
		if(array_key_exists('cache-control', $hdrs))
			$cc = $hdrs['cache-control'];
		if(strpos($cc, 'no-store'))
			return logger('caching prohibited by remote host directive ' . $cc, LOGGER_DEBUG);
		if(strpos($cc, 'no-cache'))
			$expires = time() + 60;
		if(! isset($expires)){
			if($cache_mode['exp'])
				$ttl = $cache_mode['age'];
			else
				$ttl = (preg_match('/max-age=(\d+)/i', $cc, $o) ? intval($o[1]) : $cache_mode['age']);
			$expires = time() + $ttl;
		}

		$maxexp = time() + 86400 * get_config('system','default_expire_days', 30);
		if($expires > $maxexp)
			$expires = $maxexp;

		$cache['item']['expires'] = gmdate('Y-m-d H:i:s', $expires);

		if(array_key_exists('last-modified', $hdrs))
			$cache['item']['edited'] = gmdate('Y-m-d H:i:s', strtotime($hdrs['last-modified']));

		if($i['success']) {
			// New data received (HTTP 200)
			$type = guess_image_type($cache['item']['display_path'], $i);
			if(strpos($type, 'image') === false)
				return logger('wrong image type detected ' . $type, LOGGER_DEBUG);
			$cache['item']['mimetype'] = $type;

			$ph = photo_factory($i['body'], $type);

			if(! is_object($ph))
				return logger('photo processing failure', LOGGER_DEBUG);

			if($ph->is_valid()) {
				$orig_width = $ph->getWidth();
				$orig_height = $ph->getHeight();

				$oldsize = $cache['item']['filesize'];

				if($orig_width > 1024 || $orig_height > 1024) {
					$ph->scaleImage(1024);
					logger('photo resized: ' . $orig_width . '->' . $ph->getWidth() . 'w ' . $orig_height . '->' . $ph->getHeight() . 'h', LOGGER_DEBUG);
				}

				$cache['item']['width'] = $ph->getWidth();
				$cache['item']['height'] = $ph->getHeight();
				$cache['item']['description'] = (array_key_exists('etag', $hdrs) ? $hdrs['etag'] : '');

				$k = ((($cache['item']['width'] >= $minres || $cache['item']['height'] >= $minres) && $oldsize == 0) ? true : false);

				if($k || $oldsize != 0) {
				    $cache['item']['filesize'] = strlen($ph->imageString());
				    $os_path = Hashpath::path($cache['item']['xchan'], 'store/[data]/[cache]', 2, 1);
				    $path = dirname($os_path);
				    $cache['item']['os_syspath'] = $os_path;
				    if(! is_dir($path))
				        if(! os_mkdir($path, STORAGE_DEFAULT_PERMISSIONS, true))
				            return logger('could not create path ' . $path, LOGGER_DEBUG);
				    if(is_file($os_path))
				        @unlink($os_path);
				    if(! $ph->saveImage($os_path))
				        return logger('could not save file ' . $os_path, LOGGER_DEBUG);

					logger('image saved: ' . $os_path . '; ' . $cache['item']['mimetype'] . ', ' . $cache['item']['width'] . 'w x ' . $cache['item']['height'] . 'h, ' . $cache['item']['filesize'] . ' bytes', LOGGER_DEBUG);
				}

				if($k) {
					// if this is first seen image
					if(! $ph->save($cache['item'], true))
						logger('can not save image in database', LOGGER_DEBUG);
				}
				elseif($oldsize != 0 && $oldsize != $cache['item']['filesize']) {
					// update
					$x = q("UPDATE photo SET filesize = %d WHERE xchan = '%s' AND photo_usage = %d AND filesize > 0",
						intval($cache['item']['filesize']),
						dbesc($cache['item']['xchan']),
						intval(PHOTO_CACHE)
					);
				}
			}
			else
			    return logger('fetched photo ' . $url . ' is invalid', LOGGER_DEBUG);
		}

		// Update metadata on any change (including HTTP 304)
		$x = q("UPDATE photo SET height = %d, width = %d, description = '%s', edited = '%s', expires = '%s' WHERE xchan = '%s' AND photo_usage = %d AND height > 0",
			intval($cache['item']['height']),
			intval($cache['item']['width']),
			dbesc($cache['item']['description']),
			dbescdate(($cache['item']['edited'] ? $cache['item']['edited'] : datetime_convert())),
			dbescdate($cache['item']['expires']),
			dbesc($cache['item']['xchan']),
			intval(PHOTO_CACHE)
		);
	}

	if($cache['item']['filesize'] > 0)
		$cache['status'] = true;


	logger('info: ' . $cache['item']['display_path'] . ($cache['status'] ? ' is cached as ' . $cache['item']['resource_id'] . ' for ' . $cache['item']['uid'] : ' is not cached'));
}
