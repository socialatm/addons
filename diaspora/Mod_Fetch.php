<?php

namespace Zotlabs\Module;

use Zotlabs\Lib\Apps;

class Fetch extends \Zotlabs\Web\Controller {

	function init() {

		if ((argc() != 3) || (! in_array(argv(1), [ 'post', 'status_message', 'reshare' ] ))) {
			http_status_exit(404,'Not found');
		}

		$guid = argv(2);


		// Fetch the item
		$item = q("SELECT * from item where uuid = '%s' and item_private = 0 and mid = parent_mid limit 1",
			dbesc($guid)
		);
		if(! $item) {
			http_status_exit(404,'Not found');
		}

		xchan_query($item);
		$item = fetch_post_tags($item,true);

		$channel = channelx_by_hash($item[0]['author_xchan']);
		if(! $channel) {

			// see if the content owner enabled the Diaspora forgery mechanism

			$owner = channelx_by_hash($item[0]['owner_xchan']);
			if(($owner) && ($item[0]['item_wall']) && ($item[0]['owner_xchan'] != $item[0]['author_xchan'])) {
				if(get_pconfig($owner['channel_id'],'diaspora','sign_unsigned')) {
					diaspora_share_unsigned($item[0],(($item[0]['author']) ? $item[0]['author'] : null));
					$channel = $owner;
				}
			}
		}

		if(! $channel) {
			$r = q("select * from xchan where xchan_hash = '%s' limit 1",
				dbesc($item[0]['author_xchan'])
			);

			// We cannot serve this request - redirect if there is some small chance the author's site might provide Diaspora protocol support.
			// We're taking a chance on the zot connections but at worst case they will return not found when they get the request if the channel does not
			// support the Diaspora protocol.

			if($r && in_array($r[0]['xchan_network'],[ 'diaspora','friendica-over-diaspora','zot6' ])) {
				$url = $r[0]['xchan_url'];
				if(strpos($url,z_root()) === false) {
					$m = parse_url($url);
					goaway($m['scheme'] . '://' . $m['host'] . (($m['port']) ? ':' . $m['port'] : '')
						. '/fetch/' . argv(1) . '/' . argv(2));
				}
			}

			// otherwise we cannot serve this request and cannot find anybody who can

			http_status_exit(404,'Not found');
		}

		if(! Apps::addon_app_installed($channel['channel_id'], 'diaspora')) {
			http_status_exit(404,'Not found');
		}

		$status = diaspora_build_status($item[0],$channel);

		header("Content-type: application/magic-envelope+xml; charset=utf-8");
		echo diaspora_magic_env($channel,$status);

		killme();
	}
}

