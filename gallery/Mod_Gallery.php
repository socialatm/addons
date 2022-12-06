<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;

require_once('include/attach.php');

class Gallery extends \Zotlabs\Web\Controller {

	function init() {

		if(observer_prohibited()) {
			return;
		}

		if(argc() > 1) {
			$nick = argv(1);

			profile_load($nick);

			$channelx = channelx_by_nick($nick);

			if(! $channelx)
				return;

			App::$data['channel'] = $channelx;

			$observer = App::get_observer();
			App::$data['observer'] = $observer;

			App::$page['htmlhead'] .= "<script> var profile_uid = " . ((App::$data['channel']) ? App::$data['channel']['channel_id'] : 0) . "; </script>" ;

		}

		return;

	}

	function post() {
		$items = self::get_album_items($_POST);
		json_return_and_die($items);
	}

	function get() {

		if(! App::$profile) {
			notice( t('Requested profile is not available.') . EOL );
			App::$error = 404;
			return;
		}

		if(! Apps::addon_app_installed(App::$profile_uid, 'gallery')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Gallery');
			return Apps::app_render($papp, 'module');
		}

		nav_set_selected('Gallery');

		$owner_uid = App::$data['channel']['channel_id'];
		$sql_extra = permissions_sql($owner_uid, get_observer_hash(), 'attach');

		$json_photo = '';
		$json_album = '';

		$photo = (($_GET['photo']) ? true : false);
		if($photo) {

			$ph = photo_factory('');
			$phototypes = $ph->supportedTypes();

			$photo_arr[0]['resource_id'] = $_GET['photo'];
			$photo_arr[0]['osrc'] = z_root() . '/photo/' . $_GET['photo'] . '-0' . '.' . $phototypes[$_GET['type']];
			$photo_arr[0]['src'] = z_root() . '/photo/' . $_GET['photo'] . '-0' . '.' . $phototypes[$_GET['type']];
			$photo_arr[0]['msrc'] = z_root() . '/photo/' . $_GET['photo'] . '-1' . '.' . $phototypes[$_GET['type']];
			$photo_arr[0]['w'] = $_GET['width'];
			$photo_arr[0]['h'] = $_GET['height'];
			$photo_arr[0]['title'] = $_GET['title'];
			$json_photo = json_encode($photo_arr);
		}
		else {
			if(argc() > 2) {
				$album_name = argv(2);

				for ($i = 3; $i < argc(); $i++) {
				    $album_name .= '/' . argv($i);
				}

				$r = q("select hash from attach where is_dir = 1 and uid = %d and display_path = '%s' $sql_extra limit 1",
					intval($owner_uid),
					dbesc($album_name)
				);

				if($r) {
					$arr['album_id'] = $r[0]['hash'];

					$album_items = self::get_album_items($arr);
					$json_album = json_encode($album_items);
				}
			}

			$unsafe = ((array_key_exists('unsafe', $_GET) && $_GET['unsafe']) ? 1 : 0);

			$r = q("select display_path, hash from attach where is_dir = 1 and uid = %d $sql_extra order by display_path asc",
				intval($owner_uid)
			);

			$items = [];

			if($r) {
				foreach($r as $rr) {
					if(attach_can_view_folder($owner_uid, get_observer_hash(), $rr['folder'])) {
						$x = q("SELECT photo.album, attach.folder, photo.resource_id, photo.width, photo.height FROM photo
							LEFT JOIN attach ON attach.folder = '%s' AND photo.resource_id = attach.hash WHERE
							attach.uid = %d AND photo.photo_usage = %s AND photo.is_nsfw = %d AND photo.imgscale = 1 $sql_extra ORDER BY photo.created DESC LIMIT 1",
							dbesc($rr['hash']),
							intval($owner_uid),
							intval(PHOTO_NORMAL),
							intval($unsafe)
						);
						if($x) {
							$items[] = [ 'album' => $x[0]['album'] ? $x[0]['album'] : '/', 'folder' => $x[0]['folder'], 'resource_id' => $x[0]['resource_id'], 'width' => $x[0]['width'], 'height' => $x[0]['height'] ];
						}
					}
				}
			}
		}

		$tpl = get_markup_template('gallery.tpl', 'addon/gallery');
		$o = replace_macros($tpl, [
			'$title' => t('Gallery'),
			'$albums' => $items,
			'$channel_nick' => json_encode(App::$data['channel']['channel_address']),
			'$channel_name' => json_encode(App::$data['channel']['channel_name']),
			'$channel_url' => json_encode(App::$data['channel']['xchan_url']),
			'$observer_name' => json_encode(App::$data['observer']['xchan_name']),
			'$observer_url' => json_encode(App::$data['observer']['xchan_url']),
			'$unsafe' => $unsafe,
			'$json' => (($photo) ? $json_photo : $json_album),
			'$aj' => $photo
		]);

		if($photo) {
			echo $o;
			killme();
		}

		return $o;
	}

	function get_album_items($arr) {
		$owner_uid = App::$data['channel']['channel_id'];

		if(! attach_can_view_folder($owner_uid, get_observer_hash(), $arr['album_id']))
			return;

		$ph = photo_factory('');
		$phototypes = $ph->supportedTypes();

		$sql_extra = permissions_sql($owner_uid, get_observer_hash(), 'attach');

		$unsafe = ((array_key_exists('unsafe', $arr) && $arr['unsafe']) ? 1 : 0);

		$r = q("SELECT p.resource_id, p.width, p.height, p.display_path, p.mimetype, p.imgscale, p.description, p.created FROM photo p INNER JOIN
			(SELECT photo.resource_id, photo.imgscale FROM photo left join attach on attach.folder = '%s' and photo.resource_id = attach.hash WHERE attach.uid = %d AND photo.imgscale = 1 AND photo.photo_usage = %d AND photo.is_nsfw = %d $sql_extra GROUP BY photo.resource_id, photo.imgscale) ph
			ON (p.resource_id = ph.resource_id AND p.imgscale = ph.imgscale)
			ORDER BY created DESC",
			dbesc($arr['album_id']),
			intval($owner_uid),
			intval(PHOTO_NORMAL),
			intval($unsafe)
		);

		$i = 0;
		foreach($r as $rr) {
			$title = (($rr['description']) ? '<strong>' . $rr['description'] . '</strong><br>' . $rr['display_path'] : $rr['display_path']);

			$items[$i]['resource_id'] = $rr['resource_id'];
			$items[$i]['osrc'] = z_root() . '/photo/' . $rr['resource_id'] . '-0' . '.' . $phototypes[$rr['mimetype']];
			$items[$i]['src'] = z_root() . '/photo/' . $rr['resource_id'] . '-1' . '.' . $phototypes[$rr['mimetype']];
			$items[$i]['msrc'] = z_root() . '/photo/' . $rr['resource_id'] . '-3' . '.' . $phototypes[$rr['mimetype']];
			$items[$i]['w'] = $rr['width'];
			$items[$i]['h'] = $rr['height'];
			$items[$i]['title'] = $title;
			$i++;
		}

		return $items;
	}

}
