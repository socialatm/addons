<?php


/**
 * Name: Diaspora Protocol
 * Description: Diaspora Protocol
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: none
 */

use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Lib\Activity;
use Zotlabs\Lib\Apps;
use Zotlabs\Lib\Crypto;
use Zotlabs\Lib\Keyutils;
use Zotlabs\Lib\Queue;
use Zotlabs\Lib\IConfig;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

// use the new federation protocol
define('DIASPORA_V2',1);

require_once('include/crypto.php');
require_once('include/items.php');
require_once('include/markdown.php');

require_once('addon/diaspora/inbound.php');
require_once('addon/diaspora/outbound.php');
require_once('addon/diaspora/util.php');


function diaspora_load() {

	Hook::register_array('addon/diaspora/diaspora.php', [
		'notifier_hub'                => 'diaspora_process_outbound',
		'notifier_process'            => 'diaspora_notifier_process',
		'federated_transports'        => 'diaspora_federated_transports',
		'permissions_create'          => 'diaspora_permissions_create',
		'permissions_update'          => 'diaspora_permissions_update',
		'module_loaded'               => 'diaspora_load_module',
		'follow_allow'                => 'diaspora_follow_allow',
		'post_local'                  => 'diaspora_post_local',
		'well_known'                  => 'diaspora_well_known',
		'create_identity'             => 'diaspora_create_identity',
		'profile_sidebar'             => 'diaspora_profile_sidebar',
		'discover_channel_webfinger'  => 'diaspora_discover',
		'import_author'               => 'diaspora_import_author',
		'markdown_to_bb_init'         => 'diaspora_markdown_to_bb_init',
		'bb_to_markdown_bb'           => 'diaspora_bb_to_markdown_bb',
		'service_plink'               => 'diaspora_service_plink',
		'import_foreign_channel_data' => 'diaspora_import_foreign_channel_data',
		'personal_xrd'                => 'diaspora_personal_xrd',
		'author_is_pmable'            => 'diaspora_author_is_pmable',
		'can_comment_on_post'         => 'diaspora_can_comment_on_post',
		'queue_deliver'               => 'diaspora_queue_deliver',
		'webfinger'                   => 'diaspora_webfinger',
		'channel_protocols'           => 'diaspora_channel_protocols',
		'fetch_provider'              => 'diaspora_fetch_provider',
		'encode_item_xchan'           => 'diaspora_encode_item_xchan',
		'direct_message_recipients'   => 'diaspora_direct_message_recipients',
		'get_actor_provider'          => 'diaspora_get_actor_provider',
		'get_cached_actor_provider'   => 'diaspora_get_cached_actor_provider'
	]);

	Route::register('addon/diaspora/Mod_Diaspora.php','diaspora');

}

function diaspora_unload() {
	Hook::unregister_by_file('addon/diaspora/diaspora.php');
	Route::unregister('addon/diaspora/Mod_Diaspora.php','diaspora');
}

function diaspora_plugin_admin(&$o) {
	if(! plugin_is_installed('statistics')) {
		$o = t('Please install the statistics addon to be able to configure a diaspora relay');
		return;
	}

	$t = get_markup_template("admin.tpl", "addon/diaspora/");

	$relay = get_config('diaspora', 'relay_handle', '');

	$o = replace_macros($t, array(
			'$submit' => t('Submit'),
			'$relay_handle' => array('relay_handle', t('Diaspora Relay Handle'), $relay, t('Address of a diaspora relay. Example: relay@diasporarelay.tld'))
	));
}

function diaspora_plugin_admin_post() {

	if(! plugin_is_installed('statistics'))
		return;

	$relay = ((x($_POST, 'relay_handle')) ? notags(trim($_POST['relay_handle'])) : '');

	if($relay) {
		$x = ['author' => [ 'address' => $relay, 'network' => 'diaspora' ], 'result' => false ];
		diaspora_import_author($x);

		if($x['result']) {
			set_config('diaspora','relay_handle',$x['result']);
			info( t('Settings updated.') . EOL);
		}
		else {
			notice( t('Diaspora relay could not be imported') . EOL);
		}
	}
	else
		set_config('diaspora', 'relay_handle', '');

}

function diaspora_get_actor_provider(&$arr) {

	if(!isset($arr['activity'])) {
		return;
	}

	if(!isset($arr['activity']['attachment'])) {
		return;
	}

	$diaspora_rawmsg = [];

	foreach($arr['activity']['attachment'] as $a) {
		if (
			isset($a['type']) && $a['type'] === 'PropertyValue' &&
			isset($a['name']) && $a['name'] === 'zot.diaspora.fields' &&
			isset($a['value'])
		) {
			$diaspora_rawmsg = $a['value'];
			break;
		}

	}

	if(!$diaspora_rawmsg) {
		return;
	}

	$r = find_diaspora_person_by_handle($diaspora_rawmsg['author']);

	if ($r) {
		$arr['actor'] = Activity::encode_person($r);
	}
}

function diaspora_get_cached_actor_provider(&$arr) {
	if(!$arr['id']) {
		return;
	}

	$r = q("SELECT * FROM xchan JOIN hubloc ON xchan_hash = hubloc_hash WHERE xchan_network IN ('diaspora', 'friendica-over-diaspora') AND hubloc_id_url = '%s' LIMIT 1",
		dbesc($arr['id'])
	);

	if ($r) {
		$arr['actor'] = Activity::encode_person($r[0]);
	}
}

function diaspora_fetch_provider($arr) {
	$url      = $arr['url'];
	$importer = App::get_channel();

	if (!Apps::addon_app_installed($importer['channel_id'], 'diaspora'))
		return;

	$parts      = parse_url($url);
	$path_parts = pathinfo($parts['path']);

	if (!in_array($path_parts['dirname'], ['/posts']))
		return;

	$id         = $path_parts['basename'];
	$thread_url = $parts['scheme'] . '://' . $parts['host'] . '/posts/' . $id;

	// fetch the json
	$x = z_fetch_url($thread_url, false, 1, ['headers' => ['Accept: application/json']]);

	if (!$x['success'])
		return;

	$thread_data = json_decode($x['body'], true);
	$guid        = $thread_data['guid'];
	$public      = $thread_data['public'];

	if (!$public)
		return;

	$return_guid = (($parts['fragment']) ? $parts['fragment'] : $guid);

	// We will first fetch the parent via the /fetch endpoint

	$fetch_url = $parts['scheme'] . '://' . $parts['host'] . '/fetch/post/' . $guid;
	$x = z_fetch_url($fetch_url);

	if (!$x['success'])
		return;

	$msg = diaspora_decode($importer, $x['body'], 'salmon');

	$oxml = parse_xml_string($msg['message'],false);
	if($oxml) {
		$msg['msg_type'] = strtolower($oxml->getName());
	}

	$pxml = sxml2array($oxml);
	if($pxml) {
		$msg['msg'] = $pxml;
	}

	$msg['public'] = $public;

	// dispatch toplevel post
	diaspora_dispatch($importer, $msg, true);

	// Here is the deal: there is no way to fetch comments or likes in diaspora.
	// There is however a workaround that is internally used by diaspora to fetch public content.
	// There are no signatures provided - so we can not check them.

	if ($thread_data && array_path_exists('interactions/comments', $thread_data)) {
		$comments = $thread_data['interactions']['comments'];
		foreach ($comments as $comment) {
			$msg = [];
			$msg['author']  = $comment['author']['diaspora_id'];
			$msg['msg_type'] = 'comment';
			$msg['msg'] = [
				'author'      => $comment['author']['diaspora_id'],
				'guid'        => $comment['guid'],
				'parent_guid' => $guid,
				'created_at'  => $comment['created_at'],
				'edited_at'   => $comment['edited_at'],
				'text'        => $comment['text']
			];
			diaspora_dispatch(App::get_channel(), $msg, true);
		}
	}

	if ($thread_data && array_path_exists('interactions/likes', $thread_data)) {
		$likes = $thread_data['interactions']['likes'];
		$msg   = [];
		foreach ($likes as $like) {
			$msg = [];
			$msg['author']  = $like['author']['diaspora_id'];
			$msg['msg_type'] = 'like';
			$msg['msg'] = [
				'positive'    => 'true', // this is not provided and MUST be a string
				'author'      => $like['author']['diaspora_id'],
				'guid'        => $like['guid'],
				'parent_guid' => $guid,
				'created_at'  => $like['created_at']
			];
			diaspora_dispatch(App::get_channel(), $msg, true);
		}
	}

	goaway(z_root() . '/hq/' . gen_link_id(z_root() . '/item/' . $return_guid));

}

function diaspora_author_is_pmable(&$b) {
	if($b['abook'] && (! intval($b['abook']['abook_not_here'])) && (strpos($b['xchan']['xchan_network'],'diaspora') !== false))
		$b['result'] = true;
}

function diaspora_federated_transports(&$x) {
	$x[] = 'Diaspora';
}

function diaspora_load_module(&$b) {
	if($b['module'] === 'receive') {
		require_once('addon/diaspora/Mod_Receive.php');
		$b['controller'] = new \Zotlabs\Module\Receive();
		$b['installed'] = true;
	}
	if($b['module'] === 'fetch') {
		require_once('addon/diaspora/Mod_Fetch.php');
		$b['controller'] = new \Zotlabs\Module\Fetch();
		$b['installed'] = true;
	}
}


function diaspora_well_known(&$b) {
	if(argc() > 1 && argv(1) === 'x-social-relay') {
		$disabled = (get_config('system','disable_discover_tab') || get_config('system','disable_diaspora_discover_tab'));
		$scope = 'all';
		$tags = get_config('diaspora','relay_tags');
		if($tags) {
			$disabled = false;

			// set diaspora.firehose if you want to receive all public diaspora relay posts
			// otherwise, only import posts with tags that have been followed by your site members

			if(! get_config('diaspora','firehose')) {
				$scope = 'tags';
			}
		}

		$arr = array(
			'subscribe' => (($disabled) ? false : true),
			'scope' => $scope,
			'tags' => (($tags) ? array_values($tags) : [])
		);

		header('Content-type: application/json');
		echo json_encode($arr);
		killme();

	}
}


function diaspora_channel_protocols(&$b) {

	if(Apps::addon_app_installed($b['channel_id'], 'diaspora'))
		$b['protocols'][] = 'diaspora';

}

function diaspora_personal_xrd(&$b) {

	if(! Apps::addon_app_installed($b['user']['channel_id'], 'diaspora'))
		return;

	$dspr = replace_macros(get_markup_template('xrd_diaspora.tpl','addon/diaspora'),
		[
			'$baseurl'   => z_root(),
			'$dspr_guid' => $b['user']['channel_guid'] . str_replace('.','',\App::get_hostname()),
			'$dspr_key'  => base64_encode(Keyutils::pemToRsa($b['user']['channel_pubkey']))
		]
	);

	$b['xml'] = str_replace('</XRD>',$dspr . "\n" . '</XRD>',$b['xml']);

}


function diaspora_webfinger(&$b) {

	if(! $b['channel'])
		return;

	if(! Apps::addon_app_installed($b['channel']['channel_id'], 'diaspora'))
		return;

	$b['result']['links'][] = [
		'rel'  => 'http://joindiaspora.com/seed_location',
		'type' => 'text/html',
		'href' => z_root()
	];

	$b['result']['properties']['http://purl.org/zot/federation'] .= ',diaspora';

	// Diaspora requires a salmon link.
	// Use this *only* if the gnusoc plugin is not installed and enabled

	if((! in_array('gnusoc',\App::$plugins)) || (! Apps::addon_app_installed($b['channel']['channel_id'], 'gnusoc'))) {
		$b['result']['links'][] = [
			'rel'  => 'salmon',
			'href' => z_root() . '/receive/users/' . $b['channel']['channel_guid'] . str_replace('.','',App::get_hostname())
		];
	}

}


function diaspora_permissions_create(&$b) {
	if($b['recipient']['xchan_network'] === 'diaspora' || $b['recipient']['xchan_network'] === 'friendica-over-diaspora') {

		$b['deliveries'] = diaspora_share($b['sender'],$b['recipient']);
		if($b['deliveries'])
			$b['success'] = 1;
	}
}

function diaspora_permissions_update(&$b) {
	if($b['recipient']['xchan_network'] === 'diaspora' || $b['recipient']['xchan_network'] === 'friendica-over-diaspora') {
		discover_by_webbie($b['recipient']['xchan_hash']);
		$b['success'] = 1;
	}
}

function diaspora_notifier_process(&$arr) {

	if (intval($arr['parent_item']['item_private']) === 2) {
		// Special handling for comments on diaspora conversations (direct messages)
		// originating from diaspora.
		// Those must be sent to all participants by the comment author.

		$fields = get_iconfig($arr['parent_item'], 'diaspora', 'fields');
		$hashes = [];

		if(is_array($fields) && isset($fields['participants'])) {
			$participants = explode(';', $fields['participants']);
			foreach($participants as $participant) {
				if ($participant === $arr['target_item']['author']['xchan_addr']) {
					continue;
				}
				$hashes[] = "'" . dbesc($participant) . "'";
			}
		}

		if($hashes) {
			$r = q("select * from xchan join hubloc on xchan_hash = hubloc_hash where
				xchan_hash in (" . implode(',', $hashes) . ") and
				xchan_network in ('diaspora', 'friendica-over-diaspora') and hubloc_deleted = 0 and hubloc_error = 0"
			);

			if (!$arr['top_level_post'] && in_array($arr['parent_item']['author']['xchan_network'], ['diaspora', 'friendica-over-diaspora'])) {
				// To allow sending of comments on diaspora direct messages to other zot6 channels
				// we need to send them via diaspora.
				// It is required to rewrite the hubloc_callback for that.

				$rz = q("select * from xchan join hubloc on xchan_hash = hubloc_hash where
					xchan_addr in (" . implode(',', $hashes) . ") and
					xchan_network = 'zot6' and hubloc_deleted = 0 and hubloc_error = 0"
				);

				$r1 = [];
				foreach($rz as $rzz) {
					$rzz['hubloc_callback'] = $rzz['hubloc_url'] . '/receive';
					$r1[] = $rzz;
				}

				$r = array_merge($r1, $r);
			}

			foreach($r as $contact) {
				// is $contact connected with this channel - and if the channel is cloned, also on this hub?
				$single = deliverable_singleton($arr['channel']['channel_id'], $contact);

				$qi = diaspora_send_mail($arr, $contact);
				if($qi)
					$arr['queued'][] = $qi;
			}
		}
	}

	// If target_item isn't set it's likely to be refresh packet.
	if(! ((array_key_exists('target_item',$arr)) && (is_array($arr['target_item'])))) {
		return;
	}

	// If item_wall doesn't exist, it's not a post - perhaps an email or other DB object
	if(! array_key_exists('item_wall',$arr['target_item']))
		return;

	// if it is a public post (reply, etc.), add the chosen relay channel to the recipients
	if(($arr['normal_mode']) && (! $arr['env_recips']) && (! $arr['private']) && (! $arr['upstream'])) {
		$relay = get_config('diaspora','relay_handle');
		if($relay) {
			$arr['recipients'][] = "'" . $relay . "'";
		}
	}

}


function diaspora_process_outbound(&$arr) {

/*

	We are passed the following array from the notifier, providing everything we need to make delivery decisions.

			$arr = array(
				'channel' => $channel,
				'upstream' => $upstream,
				'env_recips' => $env_recips,
				'packet_recips' => $packet_recips,
				'recipients' => $recipients,
				'item' => $item,
				'target_item' => $target_item,
				'hub' => $hub,
				'top_level_post' => $top_level_post,
				'private' => $private,
				'relay_to_owner' => $relay_to_owner,
				'uplink' => $uplink,
				'cmd' => $cmd,
				'mail' => $mail,
				'location' => $location,
				'normal_mode' => $normal_mode,
				'packet_type' => $packet_type,
				'walltowall' => $walltowall,
				'queued' => pass these queued items (outq_hash) back to notifier.php for delivery
			);
*/

	// we have already processed those earlier
	if (intval($arr['parent_item']['item_private']) === 2)
		return;

	if(! strstr($arr['hub']['hubloc_network'],'diaspora'))
		return;

	logger('upstream: ' . intval($arr['upstream']));
	//logger('notifier_array: ' . print_r($arr,true), LOGGER_ALL, LOG_INFO);

	// allow this to be set per message

	if(($arr['mail']) && intval($arr['item']['raw'])) {
		logger('Cannot send raw data to Diaspora mail service.');
		return;
	}

	if(array_key_exists('target_item',$arr) && is_array($arr['target_item'])) {
		if(intval($arr['target_item']['item_obscured'])) {
			logger('Cannot send raw data as a Diaspora activity.');
			return;
		}

		if(strpos($arr['target_item']['postopts'],'nodspr') !== false) {
			return;
		}
	}

	$allowed = Apps::addon_app_installed($arr['channel']['channel_id'], 'diaspora');

	if(! intval($allowed)) {
		logger('mod-diaspora: disallowed for channel ' . $arr['channel']['channel_name']);
		return;
	}

	if($arr['location'])
		return;

	$target_item = $arr['target_item'];

	if($target_item && array_key_exists('item_obscured',$target_item) && intval($target_item['item_obscured'])) {
		$key = get_config('system','prvkey');
		if($target_item['title'])
			$target_item['title'] = Crypto::unencapsulate(json_decode($target_item['title'],true),$key);
		if($target_item['body'])
			$target_item['body'] = Crypto::unencapsulate(json_decode($target_item['body'],true),$key);
	}

	$prv_recips = $arr['env_recips'];
	stringify_array_elms($prv_recips);

	// The Diaspora profile message is unusual and must be handled independently
	$is_profile = false;

	if($arr['cmd'] === 'refresh_all' && $arr['recipients']) {
		$is_profile = true;
		$profile_visible = perm_is_allowed($arr['channel']['channel_id'],'','view_profile');

		if(! $profile_visible) {
			$prv_recips = $arr['recipients'];
		}
	}


	if ($prv_recips) {

		// re-explode the recipients, but only for this hub/pod

		$r = q("select * from xchan left join hubloc on xchan_hash = hubloc_hash where hubloc_url = '%s'
			and xchan_hash in (" . implode(',', $prv_recips) . ") and xchan_network in ('diaspora', 'friendica-over-diaspora') ",
			dbesc($arr['hub']['hubloc_url'])
		);

		if (!$r) {
			logger('diaspora_process_outbound: no recipients');
			return;
		}

		$processed = [];

		foreach($r as $contact) {

			if(in_array($contact['hubloc_id_url'], $processed))
				continue;

			$processed[] = $contact['hubloc_id_url'];

			// is $contact connected with this channel - and if the channel is cloned, also on this hub?
			$single = deliverable_singleton($arr['channel']['channel_id'], $contact);

			if($arr['packet_type'] == 'refresh' && $single) {
				// This packet is sent privately to contacts, so we can always send the full profile (the last argument)
				$qi = diaspora_profile_change($arr['channel'],$contact,false,true);
				if($qi)
					$arr['queued'][] = $qi;
				return;
			}

			if(! $arr['normal_mode'])
				continue;

			// special handling for send_upstream to public post, not checked for $single
			// all other public posts processed as public batches further below

			if((! $arr['private']) && ($arr['upstream'])) {
				$qi = diaspora_send_upstream($target_item,$arr['channel'],$contact, true);
				if($qi)
					$arr['queued'][] = $qi;
				continue;
			}

			if(! $contact['xchan_pubkey'])
				continue;

			// singletons will be sent upstream regardless of $single state. They may be rejected.

			if(intval($target_item['item_deleted']) && ($arr['top_level_post'] || $arr['upstream'])) {
				$qi = diaspora_send_retraction($target_item,$arr['channel'],$contact);
				if($qi)
					$arr['queued'][] = $qi;
				continue;
			}

			if($arr['upstream']) {
				// send comments and likes to owner to relay
				$qi = diaspora_send_upstream($target_item,$arr['channel'],$contact,false,(($arr['uplink'] && !$arr['relay_to_owner']) ? true : false));
				if($qi)
					$arr['queued'][] = $qi;
				continue;
			}

			// downstream (private) posts

			if(! $single) {
				logger('Singleton private delivery ignored on this site.');
				continue;
			}

			if($arr['top_level_post']) {
				$qi = diaspora_send_status($target_item,$arr['channel'],$contact);
				if($qi) {
					foreach($qi as $q)
						$arr['queued'][] = $q;
				}
				continue;
			}
			else {
				// we are the relay - send comments, likes and relayable_retractions
				// (of comments and likes) to our conversants
				$qi = diaspora_send_downstream($target_item,$arr['channel'],$contact);
				if($qi)
					$arr['queued'][] = $qi;
				continue;
			}
		}
	}
	else {

		// public message

		$contact = $arr['hub'];

		if($arr['packet_type'] === 'keychange') {
			$target_item = get_pconfig($arr['channel']['channel_id'],'system','keychange');
			$qi = diaspora_send_migration($target_item,$arr['channel'],$contact,true);
			if($qi)
				$arr['queued'][] = $qi;
			return;
		}
		if(intval($target_item['item_deleted'])
			&& ($target_item['mid'] === $target_item['parent_mid'])) {
			// top-level retraction
			logger('delivery: diaspora retract: ' . $loc);
			$qi = diaspora_send_retraction($target_item,$arr['channel'],$contact,true);
			if($qi)
				$arr['queued'][] = $qi;
			return;
		}
		elseif($target_item['mid'] !== $target_item['parent_mid']) {
			// we are the relay - send comments, likes and relayable_retractions to our conversants
			logger('delivery: diaspora relay: ' . $loc);
			$qi = diaspora_send_downstream($target_item,$arr['channel'],$contact,true);
			if($qi)
				$arr['queued'][] = $qi;
			return;
		}
		elseif($arr['top_level_post']) {
			if(perm_is_allowed($arr['channel']['channel_id'],'','view_stream',false)) {
				logger('delivery: diaspora status: ' . $loc);
				$qi = diaspora_send_status($target_item,$arr['channel'],$contact,true);
				if($qi) {
					foreach($qi as $q)
						$arr['queued'][] = $q;
				}
				return;
			}
		}
	}

	if($is_profile) {

		// with either a public or private profile, send a profile message to the public endpoint of
		// each hub. $profile_visible indicates if the recipients can see all the data or a limited subset.
		// @todo also find any other Diaspora pods who should get this message.

		$contact = $arr['hub'];
		$single = deliverable_singleton($arr['channel']['channel_id'],$contact);

		if($arr['packet_type'] == 'refresh' && $single) {
			$qi = diaspora_profile_change($arr['channel'],$contact,true,$profile_visible);
			if($qi)
				$arr['queued'][] = $qi;
			return;
		}
	}

}





function diaspora_queue($owner,$contact,$slap,$public_batch,$message_id = '') {


	$allowed = Apps::addon_app_installed($owner['channel_id'], 'diaspora');

	if(! intval($allowed)) {
		return false;
	}

	if($public_batch)
		$dest_url = $contact['hubloc_callback'] . '/public';
	else
		$dest_url = $contact['hubloc_callback'] . '/users/' . $contact['hubloc_guid'];


	logger('diaspora_queue: URL: ' . $dest_url, LOGGER_DEBUG);

	if(intval(get_config('system','diaspora_test')) || intval(get_pconfig($owner['channel_id'],'system','diaspora_test'))) {
		logger('diaspora test mode - delivery disabled');
		return false;
	}

	$hash = new_uuid();

	logger('diaspora_queue: ' . $hash . ' ' . $dest_url, LOGGER_DEBUG);

	Queue::insert(array(
		'hash'       => $hash,
		'account_id' => $owner['channel_account_id'],
		'channel_id' => $owner['channel_id'],
		'driver'     => 'diaspora',
		'posturl'    => $dest_url,
		'notify'     => '',
		'msg'        => $slap
	));

	if($message_id && (! get_config('system','disable_dreport'))) {
		q("insert into dreport ( dreport_mid, dreport_site, dreport_recip, dreport_name, dreport_result, dreport_time, dreport_xchan, dreport_queue ) values ( '%s','%s','%s','%s','%s','%s','%s','%s' ) ",
			dbesc($message_id),
			dbesc($dest_url),
			dbesc($dest_url),
			dbesc($dest_url),
			dbesc('queued'),
			dbescdate(datetime_convert()),
			dbesc($owner['channel_hash']),
			dbesc($hash)
		);
	}

	return $hash;

}


function diaspora_follow_allow(&$b) {

	if($b['xchan']['xchan_network'] !== 'diaspora' && $b['xchan']['xchan_network'] !== 'friendica-over-diaspora')
		return;

	$allowed = Apps::addon_app_installed($b['channel_id'], 'diaspora');

	if($allowed === false)
		$allowed = 1;
	$b['allowed'] = $allowed;
	$b['singleton'] = 1;  // this network does not support channel clones
}


function diaspora_discover(&$b) {

	require_once('include/network.php');

	$webbie = $b['address'];

	if(! $webbie) {
		logger('no webbie: ' . print_r($b,true));
		return;
	}

	$protocol = $b['protocol'];
	if($protocol && strtolower($protocol) !== 'diaspora')
		return;


	$result = array();
	$network = null;
	$diaspora = false;

	$diaspora_base = '';
	$diaspora_guid = '';
	$diaspora_key = '';
	$guid = '';

	$dfrn = false;

	$x = $b['webfinger'];

	if($x && array_key_exists('links',$x) && $x['links']) {
		foreach($x['links'] as $link) {
			if(array_key_exists('rel',$link)) {

				if($link['rel'] === NAMESPACE_DFRN)
					$dfrn = escape_tags($link['href']);

				if($link['rel'] === 'http://joindiaspora.com/seed_location') {
					$diaspora_base = escape_tags($link['href']);
					$diaspora = true;
				}
				if($link['rel'] === 'http://joindiaspora.com/guid') {
					$diaspora_guid = escape_tags($link['href']);
					$diaspora = true;
				}
				if($link['rel'] === 'diaspora-public-key') {
					$diaspora_key = escape_tags(base64_decode($link['href']));
					if(strstr($diaspora_key,'RSA '))
						$pubkey = Keyutils::rsaToPem($diaspora_key);
					else
						$pubkey = $diaspora_key;
					$diaspora = true;
				}
				if($link['rel'] === 'http://microformats.org/profile/hcard')
					$hcard = escape_tags($link['href']);
				if($link['rel'] === 'http://webfinger.net/rel/profile-page')
					$profile = escape_tags($link['href']);
			}
		}
	}

/*

	if(! ($diaspora && $diaspora_base)) {
		$x = false;
	}

	if(! $x) {
		$x = old_webfinger($webbie);
	}

	if($x) {
		logger('old_webfinger: ' . print_r($x,true));
		foreach($x as $link) {
			if(is_array($link)) {
				if($link['@attributes']['rel'] === NAMESPACE_DFRN)
					$dfrn = escape_tags(unamp($link['@attributes']['href']));
				if($link['@attributes']['rel'] === 'http://microformats.org/profile/hcard')
					$hcard = escape_tags(unamp($link['@attributes']['href']));
				if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page')
					$profile = escape_tags(unamp($link['@attributes']['href']));
				if($link['@attributes']['rel'] === 'http://joindiaspora.com/seed_location') {
					$diaspora_base = escape_tags(unamp($link['@attributes']['href']));
					$diaspora = true;
				}

				if($link['@attributes']['rel'] === 'http://joindiaspora.com/guid') {
					$diaspora_guid = escape_tags(unamp($link['@attributes']['href']));
					$diaspora = true;
				}
				if($link['@attributes']['rel'] === 'diaspora-public-key') {
					$diaspora_key = escape_tags(base64_decode(unamp($link['@attributes']['href'])));
					if(strstr($diaspora_key,'RSA '))
						$pubkey = Keyutils::rsaToPem($diaspora_key);
					else
						$pubkey = $diaspora_key;
					$diaspora = true;
				}
			}
		}
	}
*/
	if($diaspora && $diaspora_base) {

		if($diaspora_guid)
			$guid = $diaspora_guid;

		$diaspora_base = trim($diaspora_base,'/');

		$notify = $diaspora_base . '/receive';

		if(strpos($webbie,'@')) {
			$addr = str_replace('acct:', '', $webbie);
			$hostname = substr($webbie,strpos($webbie,'@')+1);
		}

		if(! $addr) {
			logger('no address: ' . print_r($b,true));
			return;
		}

		$network = 'diaspora';
		// until we get a dfrn layer, we'll use diaspora protocols for Friendica,
		// but give it a different network so we can go back and fix these when we get proper support.
		// It really should be just 'friendica' but we also want to distinguish
		// between Friendica sites that we can use D* protocols with and those we can't.
		// Some Friendica sites will have Diaspora disabled.
		if($dfrn)
			$network = 'friendica-over-diaspora';
		if($hcard) {
			$vcard = scrape_vcard($hcard);
			$vcard['nick'] = substr($webbie,0,strpos($webbie,'@'));
			if(! $vcard['fn'])
				$vcard['fn'] = $webbie;
			if(($vcard['uid']) && (! $diaspora_guid))
				$diaspora_guid = $guid = $vcard['uid'];

			if($vcard['public_key']) {
				$diaspora_key = $vcard['public_key'];
				if(strstr($diaspora_key,'RSA '))
					$pubkey = Keyutils::rsaToPem($diaspora_key);
				else
					$pubkey = $diaspora_key;
			}

		}

		$r = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($addr)
		);

		/**
		 *
		 * Diaspora communications are notoriously unreliable and receiving profile update messages (indeed any messages)
		 * are pretty much random luck. We'll check the timestamp of the xchan_name_date at a higher level and refresh
		 * this record once a month; because if you miss a profile update message and they update their profile photo or name
		 * you're otherwise stuck with stale info until they change their profile again - which could be years from now.
		 *
		 */

		if($r) {
			$r = q("update xchan set xchan_name = '%s', xchan_network = '%s', xchan_name_date = '%s', xchan_pubkey = '%s' where xchan_hash = '%s'",
				dbesc($vcard['fn']),
				dbesc($network),
				dbescdate(datetime_convert()),
				dbesc($pubkey),
				dbesc($addr)
			);
		}
		else {
			$r = xchan_store_lowlevel(
				[
					'xchan_hash'         => escape_tags($addr),
					'xchan_guid'         => escape_tags($guid),
					'xchan_pubkey'       => escape_tags($pubkey),
					'xchan_addr'         => escape_tags($addr),
					'xchan_url'          => escape_tags($profile),
					'xchan_name'         => escape_tags($vcard['fn']),
					'xchan_name_date'    => datetime_convert(),
					'xchan_network'      => $network
				]
			);
		}

		$r = q("select * from hubloc where hubloc_hash = '%s' limit 1",
			dbesc($addr)
		);

		if(! $r) {
			$r = hubloc_store_lowlevel(
				[
					'hubloc_guid'     => escape_tags($guid),
					'hubloc_hash'     => escape_tags($addr),
					'hubloc_addr'     => escape_tags($addr),
					'hubloc_network'  => $network,
					'hubloc_url'      => trim($diaspora_base,'/'),
					'hubloc_host'     => escape_tags($hostname),
					'hubloc_callback' => $notify,
					'hubloc_updated'  => datetime_convert(),
					'hubloc_primary'  => 1,
					'hubloc_id_url'   => escape_tags($profile)
				]
			);
		}

		$photos = import_xchan_photo($vcard['photo'],$addr);
		$r = q("update xchan set xchan_photo_date = '%s', xchan_photo_l = '%s', xchan_photo_m = '%s', xchan_photo_s = '%s', xchan_photo_mimetype = '%s' where xchan_hash = '%s'",
			dbescdate($photos[5]),
			dbesc($photos[0]),
			dbesc($photos[1]),
			dbesc($photos[2]),
			dbesc($photos[3]),
			dbesc($addr)
		);

		$b['xchan']   = $addr;
		$b['success'] = true;
	}
}

function diaspora_post_local(&$item) {

	if (!Apps::addon_app_installed($item['uid'], 'diaspora'))
		return;

	require_once('include/markdown.php');

	$meta = null;

	// deal with conversation iconfig
	if($item['item_private'] === 2) {

		$author = channelx_by_hash($item['author_xchan']);
		$body = bb_to_markdown($item['body'], [ 'diaspora' ]);
		$conv = [];
		$guid = $item['uuid'];
		$conv_guid = 'conversation:' . $item['uuid'];

		if($item['mid'] !== $item['parent_mid']) {
			$parent = q("select * from item where mid = '%s' and uid = %d limit 1",
				dbesc($item['parent_mid']),
				intval($item['uid'])
			);

			$fields = get_iconfig($parent[0], 'diaspora', 'fields');
			if (!isset($fields['participants'])) {
				logger('no DM participants');
				return;
			}

			$conv_guid = $fields['guid'];
		}

		$message = [
				'guid'              => xmlify($item['uuid']),
				'text'              => xmlify($body),
				'created_at'        => datetime_convert('UTC','UTC', $item['created'], ATOM_TIME ),
				'author'            => xmlify($author['xchan_addr']),
				'conversation_guid' => xmlify($conv_guid)
		];

		if($item['mid'] === $item['parent_mid']) {
			$private = true;
			$receivers = expand_acl($item['allow_cid']);
			$hashes = [];

			foreach($receivers as $receiver) {
				$hashes[] = "'" . dbesc($receiver) . "'";
			}

			$p = dbq("select xchan_addr, xchan_hash, xchan_network from xchan where xchan_hash in (" . implode(',', $hashes) . ") and xchan_network in ('zot6', 'diaspora', 'friendica-over-diaspora')");
			$participants[] = $author['xchan_addr'];

			foreach($p as $pp) {
				if ($pp['xchan_network'] === 'zot6') {
					$protocols = get_xconfig($pp['xchan_hash'], 'system', 'protocols');
					if (strpos($protocols, 'diaspora') === false) {
						continue;
					}
				}
				$participants[] = $pp['xchan_addr'];
			}

			$conv = [
				'author' => xmlify($author['xchan_addr']),
				'guid' => xmlify('conversation:' . $item['uuid']),
				'subject' => (($item['title']) ? xmlify($item['title']) : xmlify(t('No subject'))),
				'created_at' => xmlify(datetime_convert('UTC','UTC',$item['created'],ATOM_TIME)),
				'participants' => xmlify(implode(';', $participants)),
				'message' => $message
			];
		}

		$meta = (($conv) ? $conv : $message);

		set_iconfig($item, 'diaspora', 'fields', $meta, true);

		return;

	}

	if($item['mid'] === $item['parent_mid'])
		return;

	$meta = null;

	// fetch the parent or parents

	$p = q("select * from item where mid = '%s' and uid = %d limit 1",
		dbesc($item['parent_mid']),
		intval($item['uid'])
	);
	if(! $p) {
		return;
	}

	$parent = $p[0];

	if($item['parent_mid'] !== $item['thr_parent']) {
		$t = q("select * from item where mid = '%s' and uid = %d limit 1",
			dbesc($item['thr_parent']),
			intval($item['uid'])
		);
		if(! $t) {
			return;
		}
		$thr_parent = $t[0];
	}


	$author = channelx_by_hash($item['author_xchan']);
	if($author) {

		$handle = channel_reddress($author);
		$meta = null;

		if(activity_match($item['verb'], [ ACTIVITY_LIKE, ACTIVITY_DISLIKE ])) {
			if(activity_match($item['obj_type'], [ ACTIVITY_OBJ_NOTE, ACTIVITY_OBJ_ACTIVITY, ACTIVITY_OBJ_COMMENT ])) {
				$meta = [
					'positive'        => (($item['verb'] === ACTIVITY_LIKE) ? 'true' : 'false'),
					'guid'            => $item['uuid'],
				];
				if(defined('DIASPORA_V2')) {
					$meta['author']      = $handle;
					$meta['parent_type'] = (($item['thr_parent'] === $item['parent_mid']) ? 'Post' : 'Comment');
					$meta['parent_guid'] = (($item['thr_parent'] === $item['parent_mid']) ? $parent['uuid'] : $thr_parent['uuid']);
				}
				else {
					$meta['diaspora_handle'] = $handle;
					$meta['target_type']     = 'Post';
					$meta['parent_guid']     = $parent['uuid'];
				}
			}
		}
		elseif(activity_match($item['verb'], [ ACTIVITY_ATTEND, ACTIVITY_ATTENDNO, ACTIVITY_ATTENDMAYBE ])) {
			if(activity_match($item['obj_type'], [ ACTIVITY_OBJ_NOTE ])) {
				$status = 'tentative';
				if(activity_match($item['verb'], [ ACTIVITY_ATTEND ]))
					$status = 'accepted';
				if(activity_match($item['verb'], [ ACTIVITY_ATTENDNO ]))
					$status = 'declined';

				$rawobj = ((is_array($item['obj'])) ? $item['obj'] : json_decode($item['obj'],true));
				if($rawobj) {
					$ev = bbtoevent($rawobj['content']);
					if($ev && $ev['hash'] && defined('DIASPORA_V2')) {
						$meta = [
							'author'      => $handle,
							'guid'        => $item['uuid'],
							'parent_guid' => $ev['hash'],
							'status'      => $status
						];
					}
				}
			}
		}
		else {
			$body = bb_to_markdown($item['body'], [ 'diaspora' ]);
			$meta = [
				'guid'            => $item['uuid'],
				'parent_guid'     => $parent['uuid'],
				'text'            => $body
			];

			if(defined('DIASPORA_V2')) {
				$meta['author']     = $handle;
				$meta['created_at'] = datetime_convert('UTC','UTC', $item['created'], ATOM_TIME );
				if($item['edited'] > $item['created']) {
					$meta['edited_at'] = datetime_convert('UTC','UTC', $item['edited'], ATOM_TIME );
				}
			}
		}

		if(! $meta)
			return;

		$meta['author_signature'] = diaspora_sign_fields($meta, $author['channel_prvkey']);
		if($item['author_xchan'] === $item['owner_xchan']) {
			$meta['parent_author_signature'] = diaspora_sign_fields($meta,$author['channel_prvkey']);
		}
	}

	if($meta)
		set_iconfig($item,'diaspora','fields', $meta, true);

}

function diaspora_create_identity($b) {
	if(get_config('system','diaspora_allowed')) {
		Apps::app_install($b, 'Diaspora Protocol');
	}
}

function diaspora_import_foreign_channel_data(&$data) {

	if(array_key_exists('user',$data) && array_key_exists('version',$data)) {
		require_once('addon/diaspora/import_diaspora.php');
		$data['handled'] = true;
		import_diaspora_account($data);
		return;
	}
}

function diaspora_profile_sidebar(&$x) {

	$profile = $x['profile'];

	if(! Apps::addon_app_installed($profile['channel_id'], 'diaspora'))
		return;

	$firstname = ((strpos($profile['channel_name'],' '))
		? trim(substr($profile['channel_name'],0,strpos($profile['channel_name'],' '))) : $profile['channel_name']);
	$lastname = (($firstname === $profile['channel_name'])
			? '' : trim(substr($profile['channel_name'],strlen($firstname))));

	$vcarddata = replace_macros(get_markup_template('diaspora_vcard.tpl','addon/diaspora'),
		[
			'$podloc'     => z_root(),
			'$guid'       => $profile['channel_guid'] . str_replace('.','',App::get_hostname()),
			'$pubkey'     => Keyutils::pemToRsa($profile['channel_pubkey']),
			'$searchable' => ((observer_prohibited()) ? 'false' : 'true'),
			'$nickname'   => $profile['channel_address'],
			'$fullname'   => $profile['channel_name'],
			'$firstname'  => $firstname,
			'$lastname'   => $lastname,
			'$photo300'   => z_root() . '/photo/profile/300/' . $profile['uid'] . '.jpg',
			'$photo100'   => z_root() . '/photo/profile/100/' . $profile['uid'] . '.jpg',
			'$photo50'    => z_root() . '/photo/profile/50/'  . $profile['uid'] . '.jpg',
		]
	);

	$x['entry'] = str_replace('<div class="hcard-addon"></div>',$vcarddata . '<div class="hcard-addon"></div>', $x['entry']);

}

function diaspora_encode_item_xchan(&$arr) {
	if($arr['encoded_xchan']['network'] !== 'diaspora')
		return;

	unset($arr['encoded_xchan']['url']);
}

function diaspora_import_author(&$b) {

	$x = $b['author'];

	if(isset($x['network']) && strpos($x['network'], 'diaspora') === false)
		return;

	if(!(isset($x['address']) && $x['address']))
		return;

	$r = q("select * from xchan where xchan_addr = '%s' limit 1",
		dbesc($x['address'])
	);
	if($r) {
		logger('in_cache: ' . $x['address'], LOGGER_DATA);
		$b['result'] = $r[0]['xchan_hash'];
		return;
	}

	if(discover_by_webbie($x['address'])) {
		$r = q("select xchan_hash from xchan where xchan_addr = '%s' limit 1",
			dbesc($x['address'])
		);
		if($r) {
			$b['result'] = $r[0]['xchan_hash'];
			return;
		}
	}

	return;

}


function diaspora_md_mention_callback($matches) {

    $webbie = $matches[2] . '@' . $matches[3];
    $link = '';
    if($webbie) {
        $r = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_addr = '%s' limit 1",
            dbesc($webbie)
        );
        if(! $r) {
            $x = discover_by_webbie($webbie);
            if($x) {
                $r = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_addr = '%s' limit 1",
                    dbesc($webbie)
                );
            }
        }
        if($r)
            $link = $r[0]['xchan_url'];
    }
    if(! $link)
        $link = 'https://' . $matches[3] . '/u/' . $matches[2];

    if($r && $r[0]['hubloc_network'] === 'zot6')
        return '@[zrl=' . $link . ']' . trim($matches[1]) . ((substr($matches[0],-1,1) === '+') ? '+' : '') . '[/zrl]' ;
    else
        return '@[url=' . $link . ']' . trim($matches[1]) . ((substr($matches[0],-1,1) === '+') ? '+' : '') . '[/url]' ;

}

function diaspora_md_mention_callback2($matches) {

    $webbie = $matches[1] . '@' . $matches[2];
    $link = '';
    if($webbie) {
        $r = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_addr = '%s' limit 1",
            dbesc($webbie)
        );
        if(! $r) {
            $x = discover_by_webbie($webbie);
            if($x) {
                $r = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_addr = '%s' limit 1",
                    dbesc($webbie)
                );
            }
        }
        if($r)
            $link = $r[0]['xchan_url'];
    }

    $name = (($r) ? $r[0]['xchan_name'] : $matches[1]);

    if(! $link)
        $link = 'https://' . $matches[2] . '/u/' . $matches[1];

    if($r && $r[0]['hubloc_network'] === 'zot6')
        return '@[zrl=' . $link . ']' . trim($name) . ((substr($matches[0],-1,1) === '+') ? '+' : '') . '[/zrl]' ;
    else
        return '@[url=' . $link . ']' . trim($name) . ((substr($matches[0],-1,1) === '+') ? '+' : '') . '[/url]' ;

}

function diaspora_forum_mention_callback($matches) {

    $webbie = $matches[1] . '@' . $matches[2];
    $link = '';
    if($webbie) {
        $r = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_addr = '%s' limit 1",
            dbesc($webbie)
        );
        if(! $r) {
            $x = discover_by_webbie($webbie);
            if($x) {
                $r = q("select * from hubloc left join xchan on hubloc_hash = xchan_hash where hubloc_addr = '%s' limit 1",
                    dbesc($webbie)
                );
            }
        }
        if($r)
            $link = $r[0]['xchan_url'];
    }

    $name = (($r) ? $r[0]['xchan_name'] : $matches[1]);

    if(! $link)
        $link = 'https://' . $matches[2] . '/u/' . $matches[1];

    if($r && $r[0]['hubloc_network'] === 'zot6')
        return '![zrl=' . $link . ']' . trim($name) . '[/zrl]' ;
    else
        return '![url=' . $link . ']' . trim($name) . '[/url]' ;

}



function diaspora_markdown_to_bb_init(&$x) {

	$s = $x['text'];
	if(! (array_key_exists('diaspora',$x['options']) && intval($x['options']['diaspora'])))
		return;

	// if empty link text replace with the url
	$s = preg_replace("/\[\]\((.*?)\)/ism",'[$1]($1)',$s);

	$s = preg_replace_callback("/\!*\[(.*?)\]\((.*?)\)/ism",'diaspora_markdown_media_cb',$s);

  	$s = preg_replace_callback('/\@\{(.+?)\; (.+?)\@(.+?)\}\+/','diaspora_md_mention_callback',$s);
	$s = preg_replace_callback('/\@\{(.+?)\; (.+?)\@(.+?)\}/','diaspora_md_mention_callback',$s);

	$s = preg_replace_callback('/\@\{(.+?)\@(.+?)\}\+/','diaspora_md_mention_callback2',$s);
	$s = preg_replace_callback('/\@\{(.+?)\@(.+?)\}/','diaspora_md_mention_callback2',$s);

	$s = preg_replace_callback('/\!\{(.+?)\@(.+?)\}/','diaspora_forum_mention_callback',$s);


	// replace diaspora://$author_handle/$post_type/$guid with a local representation.
	// Ideally we should eventually pass the author_handle and post_type to mod_display and from a hook
	// fetch the post from the source if it isn't already available locally.

	$s = preg_replace('#diaspora://(.*?)/(.*?)/([^\s\]]*)#ism', z_root() . '/display/$3', $s);

	$x['text'] = $s;

}


function diaspora_markdown_media_cb($matches) {

	$audios = [ '.mp3', '.ogg', '.oga', '.m4a' ];
	$videos = [ '.mp4', '.ogv', '.ogm', '.webm', '.opus' ];

	foreach($audios as $aud) {
		if(strpos(strtolower($matches[2]),$aud) !== false)
			return '[audio]' . $matches[2] . '[/audio]';
	}
	foreach($videos as $vid) {
		if(strpos(strtolower($matches[2]),$vid) !== false)
			return '[video]' . $matches[2] . '[/video]';
	}

	return $matches[0];

}

function diaspora_bb_to_markdown_bb(&$x) {

	if(! in_array('diaspora',$x['options']))
		return;

	$Text = $x['bbcode'];

	$Text = preg_replace_callback('/\@\!?\[([zu])rl\=(\w+.*?)\](\w+.*?)\[\/([zu])rl\]/i',
		'diaspora_bb_to_markdown_mention_callback', $Text);

	$Text = preg_replace_callback('/\!\[([zu])rl\=(\w+.*?)\](\w+.*?)\[\/([zu])rl\]/i',
		'diaspora_bb_to_markdown_fmention_callback', $Text);

	// strip map and embed tags, as the rendering is performed in bbcode() and the resulting output
	// is not compatible with Diaspora (at least in the case of openstreetmap and probably
	// due to the inclusion of an html iframe)

	$Text = preg_replace("/\[map\=(.*?)\]/ism", '$1', $Text);
	$Text = preg_replace("/\[map\](.*?)\[\/map\]/ism", '$1', $Text);

	$Text = preg_replace("/\[embed\](.*?)\[\/embed\]/ism", '$1', $Text);

	$x['bbcode'] = $Text;
}



function diaspora_bb_to_markdown_mention_callback($match) {

    $r = q("select xchan_addr, xchan_url from xchan where xchan_url = '%s' and xchan_network not in('rss', 'anon', 'unknown') order by xchan_addr desc",
        dbesc($match[2])
    );

    if($r) {
        $addr = (($r[0]['xchan_addr']) ? $r[0]['xchan_addr'] : $r[0]['xchan_url']);
        return '@{' . $addr . '}';
    }

    return '@' . $match[3];
}


function diaspora_bb_to_markdown_fmention_callback($match) {

    $r = q("select xchan_addr, xchan_url from xchan where xchan_url = '%s' and xchan_network not in('rss', 'anon', 'unknown')",
        dbesc($match[2])
    );

    if($r) {
        $addr = (($r[0]['xchan_addr']) ? $r[0]['xchan_addr'] : $r[0]['xchan_url']);
        return '!{' . $addr . '}';
    }

    return '!' . $match[3];
}

function diaspora_service_plink(&$b) {
	$contact = $b['xchan'];
	$url     = $b['url'];
	$guid    = $b['guid'];

	if($contact['xchan_network'] === 'diaspora')
		$b['plink'] = $url . '/posts/' . $guid;
	if($contact['xchan_network'] === 'friendica-over-diaspora')
		$b['plink'] = $url . '/display/' . $guid;


}

function diaspora_can_comment_on_post(&$b) {
	if(local_channel() && strpos($b['item']['comment_policy'],'diaspora') !== false) {
		$b['allowed'] = Apps::addon_app_installed(local_channel(), 'diaspora');
	}
}


function diaspora_queue_deliver(&$b) {
	$outq = $b['outq'];
	$base = $b['base'];
	$immediate = $b['immediate'];

	if($outq['outq_driver'] === 'diaspora') {
		$b['handled'] = true;
		$first_char = substr(trim($outq['outq_msg']),0,1);

		if($first_char === '{')
			$content_type = 'application/json';
		elseif($first_char === '<')
			$content_type = 'application/magic-envelope+xml';
		else
			$content_type = 'application/x-www-form-urlencoded';


		$retries = 0;
		$result = z_post_url($outq['outq_posturl'],$outq['outq_msg'],$retries,[ 'headers' => [ 'Content-type: ' . $content_type ]] );

		if($result['success'] && $result['return_code'] < 300) {
			logger('deliver: queue post success to ' . $outq['outq_posturl'], LOGGER_DEBUG);
			if($base) {
				q("update site set site_update = '%s', site_dead = 0 where site_url = '%s' ",
					dbescdate(datetime_convert()),
					dbesc($base)
				);
			}

			q("update dreport set dreport_result = '%s', dreport_time = '%s' where dreport_queue = '%s'",
				dbesc('accepted for delivery'),
				dbescdate(datetime_convert()),
				dbesc($outq['outq_hash'])
			);
			Queue::remove($outq['outq_hash']);

			// server is responding - see if anything else is going to this destination and is piled up
			// and try to send some more. We're relying on the fact that do_delivery() results in an
			// immediate delivery otherwise we could get into a queue loop.

			if(! $immediate) {
				$x = q("select outq_hash from outq where outq_posturl = '%s' and outq_delivered = 0",
					dbesc($outq['outq_posturl'])
				);

				$piled_up = array();
				if($x) {
					foreach($x as $xx) {
						 $piled_up[] = $xx['outq_hash'];
					}
				}
				if($piled_up) {

					// add a pre-deliver interval, this should not be necessary

					$interval = ((get_config('system','delivery_interval') !== false)
						? intval(get_config('system','delivery_interval')) : 2 );
					if($interval)
						@time_sleep_until(microtime(true) + (float) $interval);

					do_delivery($piled_up,true);
				}
			}
		}
		else {
			logger('diaspora_queue_deliver: queue post returned ' . $result['return_code'] . ' from ' . $outq['outq_posturl'], LOGGER_DEBUG);
			Queue::update($outq['outq_hash'], 10);
		}
	}
}


function diaspora_create_event($ev, $author) {

	require_once('include/html2plain.php');
	require_once('include/markdown.php');

	$ret = [];

	if(! ((is_array($ev)) && count($ev)))
		return null;

	$ret['author']  = $author;
	$ret['guid']    = $ev['event_hash'];
	$ret['summary'] = html2plain($ev['summary']);
	$ret['start']   = $ev['dtstart'];
	if(! $ev['nofinish'])
		$ret['end'] = $ev['dtend'];
	if(! $ev['adjust'])
		$ret['all_day'] = true;

	$ret['description'] = html2markdown($ev['description'] . (($ev['location']) ? "\n\n" . $ev['location'] : ''));
	if($ev['created'] !== $ev['edited'])
		$ret['edited_at'] = datetime_convert('UTC','UTC',$ev['edited'], ATOM_TIME);

	return $ret;


}

function diaspora_direct_message_recipients(&$arr) {
	$recips = null;
	$fields = IConfig::Get($arr['item'], 'diaspora', 'fields');
	if(isset($fields['participants'])) {
		$recips = explode(';', $fields['participants']);
	}

	if (is_array($recips)) {
		$arr['recips'] = $recips;
		$arr['column'] = 'xchan_addr';
	}
}
