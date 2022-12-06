<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Libsync;

class Superblock extends Controller {

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'superblock')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Superblock');
			return Apps::app_render($papp, 'module');
		}

		$words = get_pconfig(local_channel(),'system','blocked');

		//TODO: move this (config changes) to post()

		if(array_key_exists('block',$_GET) && $_GET['block']) {
			$r = q("select id from item where id = %d and author_xchan = '%s' limit 1",
				intval($_GET['item']),
				dbesc($_GET['block'])
			);
			if($r) {
				if(strlen($words))
					$words .= ',';
				$words .= trim($_GET['block']);
			}
			$config_changed = true;
		}

		if(array_key_exists('unblock',$_GET) && $_GET['unblock']) {
			if(check_form_security_token('superblock','sectok')) {
				$newlist = [];
				$list = explode(',',$words);
				if($list) {
					foreach($list as $li) {
						if($li !== $_GET['unblock']) {
							$newlist[] = $li;
						}
					}
				}

				$words = implode(',',$newlist);
			}
			$config_changed = true;
		}

		if($config_changed) {
			set_pconfig(local_channel(),'system','blocked',$words);
			Libsync::build_sync_packet(local_channel(), [ 'config' ]);

			info( t('superblock settings updated') . EOL );
		}

		if(! $words)
			$words = '';

		$list = explode(',',$words);
		stringify_array_elms($list,true);
		$query_str = implode(',',$list);
		if($query_str) {
			$r = q("select * from xchan where xchan_hash in ( " . $query_str . " ) and xchan_hash != '' ");
		}
		else
			$r = [];

		if($r) {
			for($x = 0; $x < count($r); $x ++) {
				$r[$x]['encoded_hash'] = urlencode($r[$x]['xchan_hash']);
			}
		}

		$tpl = get_markup_template('superblock_list.tpl','addon/superblock');

		$o = replace_macros($tpl, [
			'$blocked' => t('Currently blocked'),
			'$entries' => $r,
			'$nothing' => (($r) ? '' : t('No channels currently blocked')),
			'$token' => get_form_security_token('superblock'),
			'$remove' => t('Remove')
		]);

		return $o;

	}

}
