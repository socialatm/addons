<?php

use Zotlabs\Access\PermissionRoles;
use Zotlabs\Access\Permissions;
use Zotlabs\Lib\Crypto;
use Zotlabs\Lib\Enotify;
use Zotlabs\Lib\MessageFilter;
use Zotlabs\Lib\Libsync;
use Zotlabs\Lib\AccessList;
use Zotlabs\Daemon\Master;

class Diaspora_Receiver {

	protected $importer;
	protected $xmlbase;
	protected $msg;
	protected $force;

	function __construct($importer, $msg, $force) {
		$this->importer = $importer;
		$this->xmlbase = $msg['msg'];
		$this->msg = $msg;

		// WARNING: $force should REALLY only be true if manually importing content!!!
		// No permissions are checked, for comments and likes no signatures are verified.
		$this->force = $force;
	}

	function request() {

		/* sender is now sharing with recipient */

		$sender_handle    = $this->get_author();
		$recipient_handle = $this->get_recipient();

		if(array_key_exists('following',$this->xmlbase) && array_key_exists('sharing',$this->xmlbase)) {
			$following = (($this->get_property('following')) === 'true' ? true : false);
			$sharing   = (($this->get_property('sharing'))   === 'true' ? true : false);
		}
		else {
			$following = true;
			$sharing   = true;
		}

		if((! $sender_handle) || (! $recipient_handle))
			return;


		// Do we already have an abook record?

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$sender_handle);

		if(! ($following || $sharing)) {
			contact_remove($this->importer['channel_id'],$contact['abook_id']);
			return;
		}

		// Please note some permissions such as PERMS_R_PAGES are impossible for Disapora.
		// They cannot currently authenticate to our system.

		$role = get_pconfig($this->importer['channel_id'], 'system', 'permissions_role', 'personal');
		$x = PermissionRoles::role_perms($role);

		$their_perms = Permissions::FilledPerms($x['perms_connect']);

		if(! $sharing) {
			$their_perms['view_stream'] = 0;
		}

		if(! $following) {
			$their_perms['send_stream'] = 0;
		}

		if($contact && $contact['abook_id']) {

			// perhaps we were already sharing with this person. Now they're sharing with us.
			// That makes us friends. Maybe.

			foreach($their_perms as $k => $v)
				set_abconfig($this->importer['channel_id'],$contact['abook_xchan'],'their_perms',$k,$v);

			$abook_instance = $contact['abook_instance'];

			if(strpos($abook_instance,z_root()) === false) {
				if($abook_instance)
					$abook_instance .= ',';
				$abook_instance .= z_root();

				$r = q("update abook set abook_instance = '%s', abook_not_here = 0 where abook_id = %d and abook_channel = %d",
					dbesc($abook_instance),
					intval($contact['abook_id']),
					intval($this->importer['channel_id'])
				);
			}
			return;
		}

		$ret = find_diaspora_person_by_handle($sender_handle);

		if((! $ret) || (! strstr($ret['xchan_network'],'diaspora'))) {
			logger('diaspora_request: Cannot resolve diaspora handle ' . $sender_handle . ' for ' . $recipient_handle);
			return;
		}

		$p = Permissions::connect_perms($this->importer['channel_id']);
		$my_perms  = $p['perms'];
		$automatic = $p['automatic'];

		$closeness = get_pconfig($this->importer['channel_id'],'system','new_abook_closeness');
		if($closeness === false)
			$closeness = 80;

		$r = abook_store_lowlevel(
			[
				'abook_account'   => intval($this->importer['channel_account_id']),
				'abook_channel'   => intval($this->importer['channel_id']),
				'abook_xchan'     => $ret['xchan_hash'],
				'abook_closeness' => intval($closeness),
				'abook_created'   => datetime_convert(),
				'abook_updated'   => datetime_convert(),
				'abook_connected' => datetime_convert(),
				'abook_dob'       => NULL_DATE,
				'abook_pending'   => intval(($automatic) ? 0 : 1),
				'abook_instance'  => z_root(),
				'abook_role'      => $role
			]
		);

		if($my_perms)
			foreach($my_perms as $k => $v)
				set_abconfig($this->importer['channel_id'],$ret['xchan_hash'],'my_perms',$k,$v);

		if($their_perms)
			foreach($their_perms as $k => $v)
				set_abconfig($this->importer['channel_id'],$ret['xchan_hash'],'their_perms',$k,$v);


		if($r) {
			logger("New Diaspora introduction received for {$this->importer['channel_name']}");

			$new_connection = q("select * from abook left join xchan on abook_xchan = xchan_hash left join hubloc on hubloc_hash = xchan_hash where abook_channel = %d and abook_xchan = '%s' order by abook_created desc limit 1",
				intval($this->importer['channel_id']),
				dbesc($ret['xchan_hash'])
			);
			if($new_connection) {

				if($following && $sharing) {
					Enotify::submit(
						[
							'type'	       => NOTIFY_INTRO,
							'from_xchan'   => $ret['xchan_hash'],
							'to_xchan'     => $this->importer['channel_hash'],
							'link'         => z_root() . '/connections#' . $new_connection[0]['abook_id'],
						]
					);
				}

				if($my_perms && $automatic) {
					// Send back a sharing notification to them
					$x = diaspora_share($this->importer,$new_connection[0]);
					if($x)
						Zotlabs\Daemon\Master::Summon(array('Deliver',$x));

				}

				$clone = array();
				foreach($new_connection[0] as $k => $v) {
					if(strpos($k,'abook_') === 0) {
						$clone[$k] = $v;
					}
				}
				unset($clone['abook_id']);
				unset($clone['abook_account']);
				unset($clone['abook_channel']);

				$abconfig = load_abconfig($this->importer['channel_id'],$clone['abook_xchan']);

				if($abconfig)
					$clone['abconfig'] = $abconfig;

				Libsync::build_sync_packet($this->importer['channel_id'], [ 'abook' => array($clone) ] );

			}
		}

		// find the abook record we just created

		$contact_record = diaspora_get_contact_by_handle($this->importer['channel_id'],$sender_handle);

		if(! $contact_record) {
			logger('diaspora_request: unable to locate newly created contact record.');
			return;
		}

		/* If there is a default group for this channel and friending is automatic, add this member to it */

		if($this->importer['channel_default_group'] && $automatic) {
			$g = AccessList::by_hash($this->importer['channel_id'],$this->importer['channel_default_group']);
			if($g)
				AccessList::member_add($this->importer['channel_id'],'',$contact_record['xchan_hash'],$g['id']);
		}

		return;
	}


	function post() {

		$guid            = notags($this->get_property('guid'));
		$diaspora_handle = notags($this->get_author());
		$app             = notags($this->get_property('provider_display_name'));
		$raw_location    = $this->get_property('location');


		if ($diaspora_handle != $this->msg['author']) {
			logger('diaspora_post: Potential forgery. Message handle is not the same as envelope sender.');
			return 202;
		}

		$xchan = find_diaspora_person_by_handle($diaspora_handle);

		if (!$xchan) {
			logger('Cannot resolve diaspora handle ' . $diaspora_handle);
			return;
		}

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'], $diaspora_handle);

		if (!$app) {
			if (strstr($xchan['xchan_network'], 'friendica'))
				$app = 'Friendica';
			else
				$app = 'Diaspora';
		}

		$created = notags($this->get_property('created_at'));
		$edited  = notags($this->get_property('edited_at'));
		$private = (($this->get_property('public') === 'false') ? 1 : 0);
		$updated = false;
		$orig_id = null;


		$r = q("SELECT id, edited FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($guid)
		);


		if ($r) {

			$edited_str = datetime_convert('UTC', 'UTC', (($edited) ? $edited : $created));
			if ($edited_str > $r[0]['edited']) {
				$updated = true;
				$orig_id = $r[0]['id'];
			}
			else {
				logger('diaspora_post: message exists: ' . $guid);
				return;
			}
		}


		$body = markdown_to_bb($this->get_body(), false, ['diaspora' => true, 'preserve_lf' => true]);


		// photo could be a single photo or an array of photos.
		// Turn singles into an array of one.

		$photos = $this->get_property('photo');
		if (is_array($photos) && !empty($photos['guid']))
			$photos = [$photos];

		if ($photos) {
			$tmp = '';
			foreach ($photos as $ph) {
				// If there are multiple photos we get an array of objects here.
				// Typecast them to array here to make sure we deal with an array in any case.
				$ph = (array)$ph;
				if ((!$ph['remote_photo_path']) || (strpos($ph['remote_photo_path'], 'http') !== 0))
					continue;
				$tmp .= '[img]' . $ph['remote_photo_path'] . $ph['remote_photo_name'] . '[/img]' . "\n\n";
			}

			$body = $tmp . $body;
		}

		$event = $this->get_property('event');
		if (is_array($event) && !empty($event['guid'])) {
			$ev  = [];
			$dts = $this->get_property('start', $event);
			if ($dts) {
				$ev['dtstart'] = datetime_convert('UTC', 'UTC', $dts);
			}
			$dte = $this->get_property('end', $event);
			if ($dte) {
				$ev['dtend'] = datetime_convert('UTC', 'UTC', $dte);
			}
			else {
				$ev['nofinish'] = true;
			}

			// if an event is created we will use the author of the post.

//			$ev_author = notags($this->get_author($event));
//			$ev_xchan = find_diaspora_person_by_handle($ev_author);
			$ev['event_hash']  = notags($this->get_property('guid', $event));
			$ev['summary']     = escape_tags($this->get_property('summary', $event));
			$ev['adjust']      = (($this->get_property('all_day', $event)) ? false : true);
			$ev_timezone       = notags($this->get_property('timezone', $event));
			$ev['description'] = markdown_to_bb($this->get_property('description', $event), false, ['diaspora' => true]);
			$ev_loc            = $this->get_property('location', $event);
			if ($ev_loc) {
				$ev_address = escape_tags($this->get_property('address', $ev_loc));
				$ev_lat     = notags($this->get_property('lat', $ev_loc));
				$ev_lon     = notags($this->get_property('lon', $ev_loc));
			}
			$ev['location'] = '';
			if ($ev_address) {
				$ev['location'] .= '[map]' . $ev_address . '[/map]' . "\n\n";
			}
			if (!(is_null($ev_lat) || is_null($ev_lon))) {
				$ev['location'] .= '[map=' . $ev_lat . ',' . $ev_lon . ']';
			}

			if ($ev['start'] && $ev['event_hash'] && $ev['summary']) {
				$body .= format_event_bbcode($ev);
			}
			set_iconfig($datarray, 'system', 'event_id', $ev['event_hash'], true);
		}

		$maxlen = get_max_import_size();

		if ($maxlen && mb_strlen($body) > $maxlen) {
			$body = mb_substr($body, 0, $maxlen, 'UTF-8');
			logger('message length exceeds max_import_size: truncated');
		}

		$datarray = [];

		// Look for tags and linkify them
		$results = linkify_tags($body, $this->importer['channel_id'], false);

		$datarray['term'] = [];

		if ($results) {
			foreach ($results as $result) {
				$success = $result['success'];
				if ($success['replaced']) {
					$datarray['term'][] = [
						'uid'   => $this->importer['channel_id'],
						'ttype' => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					];
				}
			}
		}

		$found_tags    = false;
		$followed_tags = get_pconfig($this->importer['channel_id'], 'diaspora', 'followed_tags');
		if ($followed_tags && $datarray['term']) {
			foreach ($datarray['term'] as $t) {
				if (in_array(mb_strtolower($t['term']), array_map('mb_strtolower', $followed_tags))) {
					$found_tags = true;
					break;
				}
			}
		}


		$cnt = preg_match_all('/@\[url=(.*?)\](.*?)\[\/url\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$cnt = preg_match_all('/@\[zrl=(.*?)\](.*?)\[\/zrl\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$cnt = preg_match_all('/\!\[url=(.*?)\](.*?)\[\/url\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_FORUM,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$cnt = preg_match_all('/\!\[zrl=(.*?)\](.*?)\[\/zrl\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_FORUM,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}


		$plink = service_plink($xchan, $guid);

		if (is_array($raw_location)) {
			if (!empty($raw_location['address']))
				$datarray['location'] = unxmlify($raw_location['address']);
			if (!empty($raw_location['lat']) && !empty($raw_location['lng']))
				$datarray['coord'] = floatval(unxmlify($raw_location['lat']))
					. ' ' . floatval(unxmlify($raw_location['lng']));
		}

		$datarray['aid']  = $this->importer['channel_account_id'];
		$datarray['uid']  = $this->importer['channel_id'];
		$datarray['verb'] = ACTIVITY_POST;
		$datarray['mid']  = $datarray['parent_mid'] = z_root() . '/item/' . $guid;
		$datarray['uuid'] = $guid;

		if ($updated) {
			$datarray['changed'] = $datarray['edited'] = $edited;
		}
		else {
			$datarray['changed'] = $datarray['created'] = $datarray['edited'] = datetime_convert('UTC', 'UTC', $created);
		}

		if ($orig_id) {
			$datarray['id'] = $orig_id;
		}

		$datarray['item_private']    = $private;
		$datarray['plink']           = $plink;
		$datarray['author_xchan']    = $xchan['xchan_hash'];
		$datarray['owner_xchan']     = $xchan['xchan_hash'];
		$datarray['body']            = $body;
		$datarray['app']             = $app;
		$datarray['item_unseen']     = 1;
		$datarray['item_thread_top'] = 1;

		$tgroup = tgroup_check($this->importer['channel_id'], $datarray);

		if ((!$this->importer['system']) && (!perm_is_allowed($this->importer['channel_id'], $xchan['xchan_hash'], 'send_stream')) && (!$tgroup) && (!$found_tags) && (!$this->force)) {
			logger('diaspora_post: Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}

		if ($this->importer['system']) {
			$incl = get_config('system','pubstream_incl');
			$excl = get_config('system','pubstream_excl');

			if(($incl || $excl) && !MessageFilter::evaluate($datarray, $incl, $excl)) {
				logger('diaspora_reshare: filtering this author.');
				return 202;
			}
		}

		// Diaspora allows anybody to comment on public posts in theory
		// In fact the comment will be rejected unless it is correctly signed

		if ($this->importer['system'] || $this->msg['public']) {
			$datarray['comment_policy'] = 'network: diaspora';
		}

		if (($contact) && (!post_is_importable($this->importer['channel_id'], $datarray, [$contact])) && (!$this->force)) {
			logger('diaspora_post: filtering this author.');
			return 202;
		}

		if ($updated) {
			$result = item_store_update($datarray);
		}
		else {
			$result = item_store($datarray);
		}

		if ($result['success']) {
			sync_an_item($this->importer['channel_id'], $result['item_id']);
			if ($this->force)
				diaspora_send_participation($this->importer, $xchan, $result['item']);

			return 200;
		}

		return 202;

	}


	function reshare() {

		logger('diaspora_reshare: init: ' . print_r($this->xmlbase,true), LOGGER_DATA);

		$guid = notags($this->get_property('guid'));
		$diaspora_handle = notags($this->get_author());

		$text = notags($this->get_property('text'));

		if($diaspora_handle != $this->msg['author']) {
			logger('Potential forgery. Message handle is not the same as envelope sender.');
			return 202;
		}

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$diaspora_handle);

		if(!$contact && $this->force)
			$contact = find_diaspora_person_by_handle($diaspora_handle);

		if(! $contact )
			return;

		$r = q("SELECT id FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($guid)
		);
		if($r) {
			logger('diaspora_reshare: message exists: ' . $guid);
			return;
		}

		$orig_author = notags($this->get_root_author());
		$orig_guid = notags($this->get_property('root_guid'));

		$orig_author_xchan = find_diaspora_person_by_handle($orig_author);

		if($orig_author_xchan['xchan_network'] === 'zot6')
			$orig_url_arg = 'display';
		else
			$orig_url_arg = 'posts';

		$source_url = 'https://' . substr($orig_author,strpos($orig_author,'@')+1) . '/fetch/post/' . $orig_guid ;
		$orig_url = 'https://'.substr($orig_author,strpos($orig_author,'@')+1).'/'.$orig_url_arg.'/'.$orig_guid;

		if($text)
			$text = markdown_to_bb($text, false, [ 'diaspora' => true, 'preserve_lf' => true ]) . "\n";
		else
			$text = '';


		$source_xml = get_diaspora_reshare_xml($source_url);

		if(is_array($source_xml) && $source_xml['status_message']) {
			$body = markdown_to_bb($this->get_body($source_xml['status_message']), false, [ 'diaspora' => true, 'preserve_lf' => true ]);

			$orig_author = $this->get_author($source_xml['status_message']);
			$orig_guid   = notags($this->get_property('guid',$source_xml['status_message']));

			// Check for one or more embedded photo objects

			if(isset($source_xml['status_message']['photo'])) {
				$photos = $source_xml['status_message']['photo'];
				if(!empty($photos['remote_photo_path'])) {
					$photos = [ $photos ];
				}
				if($photos) {
					foreach($photos as $ph) {
						if($ph['remote_photo_path'] && $ph['remote_photo_name']) {
							$remote_photo_path = notags($this->get_property('remote_photo_path',$ph));
							$remote_photo_name = notags($this->get_property('remote_photo_name',$ph));
							$body = $body . "\n" . '[img]' . $remote_photo_path . $remote_photo_name . '[/img]' . "\n";
							logger('reshare: embedded picture link found: '.$body, LOGGER_DEBUG);
						}
					}
				}
			}

		}
		else {
			logger('diaspora_reshare: no status_message');
			return;
		}

		$maxlen = get_max_import_size();

		if($maxlen && mb_strlen($body) > $maxlen) {
			$body = mb_substr($body,0,$maxlen,'UTF-8');
			logger('message length exceeds max_import_size: truncated');
		}

		$person = find_diaspora_person_by_handle($orig_author);

		if($person) {
			$orig_author_name  = $person['xchan_name'];
			$orig_author_link  = $person['xchan_url'];
			$orig_author_photo = $person['xchan_photo_m'];
		}

		$created = $this->get_property('created_at');
		$private = (($this->get_property('public') === 'false') ? 1 : 0);

		$datarray = array();

		// Look for tags and linkify them
		$results = linkify_tags($body, $this->importer['channel_id'], false);

		$datarray['term'] = array();

		if($results) {
			foreach($results as $result) {
				$success = $result['success'];
				if($success['replaced']) {
					$datarray['term'][] = array(
						'uid'   => $this->importer['channel_id'],
						'ttype' => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					);
				}
			}
		}

		$cnt = preg_match_all('/@\[url=(.*?)\](.*?)\[\/url\]/ism',$body,$matches,PREG_SET_ORDER);
		if($cnt) {
			foreach($matches as $mtch) {
				$datarray['term'][] = array(
					'uid'   => $this->importer['channel_id'],
					'ttype'  => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				);
			}
		}

		$cnt = preg_match_all('/@\[zrl=(.*?)\](.*?)\[\/zrl\]/ism',$body,$matches,PREG_SET_ORDER);
		if($cnt) {
			foreach($matches as $mtch) {
				$datarray['term'][] = array(
					'uid'   => $this->importer['channel_id'],
					'ttype'  => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				);
			}
		}

		$newbody = $text . "[share author='" . urlencode($orig_author_name)
			. "' profile='" . $orig_author_link
			. "' avatar='"  . $orig_author_photo
			. "' link='"    . $orig_url
			. "' auth='"    . 'false'
			. "' posted='"  . datetime_convert('UTC','UTC',$this->get_property('created_at',$source_xml['status_message']))
			. "' message_id='" . $this->get_property('guid',$source_xml['status_message'])
	 		. "']" . $body . "[/share]";


		$plink = service_plink($contact,$guid);
		$datarray['aid'] = $this->importer['channel_account_id'];
		$datarray['uid'] = $this->importer['channel_id'];
		$datarray['mid'] = $datarray['parent_mid'] = z_root() . '/activity/' . $guid;
		$datarray['uuid'] = $guid;
		$datarray['changed'] = $datarray['created'] = $datarray['edited'] = datetime_convert('UTC','UTC',$created);
		$datarray['item_private'] = $private;
		$datarray['plink'] = $plink;
		$datarray['owner_xchan'] = $contact['xchan_hash'];
		$datarray['author_xchan'] = $contact['xchan_hash'];
		$datarray['body'] = $newbody;
		$datarray['app']  = 'Diaspora';

		// Diaspora allows anybody to comment on public posts in theory
		// In fact the comment will be rejected unless it is correctly signed

		if($this->importer['system'] || $this->msg['public']) {
			$datarray['comment_policy'] = 'network: diaspora';
		}

		$tgroup = tgroup_check($this->importer['channel_id'],$datarray);

		if((! $this->importer['system']) && (! perm_is_allowed($this->importer['channel_id'],$contact['xchan_hash'],'send_stream')) && (! $tgroup) && (! $this->force)) {
			logger('diaspora_reshare: Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}
		if ($this->importer['system']) {
			$incl = get_config('system','pubstream_incl');
			$excl = get_config('system','pubstream_excl');

			if(($incl || $excl) && !MessageFilter::evaluate($datarray, $incl, $excl)) {
				logger('diaspora_reshare: filtering this author.');
				return 202;
			}
		}

		if(! post_is_importable($this->importer['channel_id'], $datarray, [$contact]) && !$this->force) {
			logger('diaspora_reshare: filtering this author.');
			return 202;
		}

		$result = item_store($datarray);

		if($result['success']) {
			sync_an_item($this->importer['channel_id'],$result['item_id']);
			if($this->force)
				diaspora_send_participation($this->importer, $contact, $result['item']);

			return 200;
		}

		return 202;
	}


	function comment() {

		$guid = notags($this->get_property('guid'));
		if (!$guid) {
			logger('diaspora_comment: missing guid' . print_r($this->msg, true), LOGGER_DEBUG);
			return;
		}

		$parent_guid = notags($this->get_property('parent_guid'));
		if (!$parent_guid) {
			logger('diaspora_comment: missing parent_guid' . print_r($this->msg, true), LOGGER_DEBUG);
			return;
		}

		$diaspora_handle = notags($this->get_author());
		if (!$diaspora_handle) {
			logger('diaspora_comment: missing author' . print_r($this->msg, true), LOGGER_DEBUG);
			return;
		}

		$created_at              = ((!empty($this->xmlbase['created_at'])) ? datetime_convert('UTC', 'UTC', $this->get_property('created_at')) : datetime_convert());
		$edited_at               = ((!empty($this->xmlbase['edited_at'])) ? datetime_convert('UTC', 'UTC', $this->get_property('edited_at')) : $created_at);
		$thr_parent              = ((!empty($this->xmlbase['thread_parent_guid'])) ? notags($this->get_property('thread_parent_guid')) : '');
		$text                    = $this->get_body();
		$author_signature        = notags($this->get_property('author_signature'));
		$parent_author_signature = notags($this->get_property('parent_author_signature'));

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($parent_guid)
		);
		if (!$r) {
			logger('diaspora_comment: parent item not found: parent: ' . $parent_guid . ' item: ' . $guid);
			return;
		}

		$parent_item = $r[0];

		if (intval($parent_item['item_nocomment']) || $parent_item['comment_policy'] === 'none'
			|| ($parent_item['comments_closed'] > NULL_DATE && $parent_item['comments_closed'] < datetime_convert())) {
			logger('diaspora_comment: comments disabled for post ' . $parent_item['mid']);
			return;
		}

		// does the parent originate from this site?
		$local_parent_item = (strpos($parent_item['plink'], z_root()) === 0);

		$parent_owner_uid  = null;
		if ($local_parent_item) {
			// find the owner channel_id
			$r = q("SELECT uid FROM item WHERE item_origin = 1 AND uuid = '%s' LIMIT 1",
				dbesc($parent_guid)
			);
			if ($r)
				$parent_owner_uid = $r[0]['uid'];
		}

		$xchan = find_diaspora_person_by_handle($diaspora_handle);

		if (!$xchan) {
			logger('Cannot resolve diaspora handle ' . $diaspora_handle);
			return;
		}

		$contact = diaspora_get_contact_by_handle((($parent_owner_uid) ? $parent_owner_uid : $this->importer['channel_id']), $this->msg['author']);

		if (is_array($contact)) {
			$abook_contact = true;
		}
		else {
			$contact       = find_diaspora_person_by_handle($this->msg['author']);
			$abook_contact = false;
		}

		$pub_comment = 1;

		// By default comments on public posts are allowed from anybody on Diaspora. That is their policy.
		// If the parent item originates from this hub we can over-ride the default comment policy.

		if ($parent_owner_uid)
			$pub_comment = get_pconfig($parent_owner_uid, 'system', 'diaspora_public_comments', 1);

		if (intval($parent_item['item_private']))
			$pub_comment = 0;

		$editing = false;
		$orig_id = null;

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($guid)
		);
		if ($r) {
			if ($edited_at > $r[0]['edited']) {
				$editing = true;
				$orig_id = $r[0]['id'];
			}
			else {
				logger('duplicate comment : ' . $guid);
				return;
			}
		}

		/* How Diaspora performs comment signature checking:

	   - If an item has been sent by the comment author to the top-level post owner to relay on
	     to the rest of the contacts on the top-level post, the top-level post owner should check
	     the author_signature, then create a parent_author_signature before relaying the comment on
	   - If an item has been relayed on by the top-level post owner, the contacts who receive it
	     check only the parent_author_signature. Basically, they trust that the top-level post
	     owner has already verified the authenticity of anything he/she sends out
	   - In either case, the signature that get checked is the signature created by the person
	     who sent the pseudo-salmon
		*/


		/* WARN: As a side effect of this, all of $this->xmlbase will now be unxmlified */

		if ($this->xmlbase) {
			$unxml = [];
			foreach ($this->xmlbase as $k => $v) {
				if ($k === 'diaspora_handle')
					$k = 'author';
				if (is_string($v))
					$v = unxmlify($v);
				$unxml[$k] = $v;
			}
		}

		// $this->force is true on manual content import.
		// This way there are no signatures provided for comments.

		if ($parent_author_signature && !$this->force) {
			// If a parent_author_signature exists, then we've received the comment
			// relayed from the top-level post owner *or* it is legacy protocol.

			$key = $this->msg['key'];

			$x = diaspora_verify_fields($unxml, $parent_author_signature, $key);
			if (!$x) {
				logger('diaspora_comment: top-level owner verification failed.');
				return;
			}
		}
		elseif (!$this->force) {

			// the comment is being sent to the owner to relay
			// *or* there is no parent signature because it is the new format

			$key = $this->msg['msg_author_key'];

			if ($this->importer['system'] && $this->msg['format'] === 'legacy') {
				// don't relay to the sys channel
				logger('diaspora_comment: relay to sys channel blocked.');
				return;
			}

			// Note: Diaspora verifies both signatures. We only verify the
			// author_signature when relaying.
			//
			// If there's no parent_author_signature, then we've received the comment
			// from the comment creator. In that case, the person is commenting on
			// our post, so he/she must be a contact of ours and his/her public key
			// should be in $this->msg['key']

			if (!$author_signature) {
				if ($parent_item['owner_xchan'] !== $this->msg['author']) {
					logger('author signature required and not present');
					return;
				}
			}

			if ($author_signature || $this->msg['type'] === 'legacy') {
				$x = diaspora_verify_fields($unxml, $author_signature, $key);
				if (!$x) {
					logger('diaspora_comment: comment author verification failed.');
					return;
				}
			}

			// No parent_author_signature, so let's assume we're relaying the post. Create one.
			// in the V2 protocol we don't create a parent_author_signature as the salmon
			// magic envelope we will send is signed and verified.

			// if(! defined('DIASPORA_V2'))
			$unxml['parent_author_signature'] = diaspora_sign_fields($unxml, $this->importer['channel_prvkey']);

		}

		// Phew! Everything checks out. Now create an item.

		// Find the original comment author information.
		// We need this to make sure we display the comment author
		// information (name and avatar) correctly.

		if (strcasecmp($diaspora_handle, $this->msg['author']) == 0)
			$person = $contact;
		else
			$person = $xchan;

		if (!is_array($person)) {
			logger('diaspora_comment: unable to find author details');
			return;
		}

		$body = markdown_to_bb($text, false, ['diaspora' => true]);

		$maxlen = get_max_import_size();

		if ($maxlen && mb_strlen($body) > $maxlen) {
			$body = mb_substr($body, 0, $maxlen, 'UTF-8');
			logger('message length exceeds max_import_size: truncated');
		}

		$datarray = [];

		// Look for tags and linkify them
		$results = linkify_tags($body, $this->importer['channel_id'], false);

		$datarray['term'] = [];

		if ($results) {
			foreach ($results as $result) {
				$success = $result['success'];
				if ($success['replaced']) {
					$datarray['term'][] = [
						'uid'   => $this->importer['channel_id'],
						'ttype' => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					];
				}
			}
		}

		$cnt = preg_match_all('/@\[url=(.*?)\](.*?)\[\/url\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$cnt = preg_match_all('/@\[zrl=(.*?)\](.*?)\[\/zrl\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		if ($orig_id && $editing) {
			$datarray['id'] = $orig_id;
		}

		$datarray['aid']        = $this->importer['channel_account_id'];
		$datarray['uid']        = $this->importer['channel_id'];
		$datarray['verb']       = ACTIVITY_POST;
		$datarray['mid']        = z_root() . '/item/' . $guid;
		$datarray['uuid']       = $guid;
		$datarray['parent_mid'] = $parent_item['mid'];
		$datarray['thr_parent'] = $thr_parent;

		// use a URI for thr_parent if we have it

		if (strpos($parent_item['mid'], '/') !== false && $datarray['thr_parent'] === basename($parent_item['mid'])) {
			$datarray['thr_parent'] = $parent_item['mid'];
		}

		// set the route to that of the parent so downstream hubs won't reject it.
		$datarray['route']           = $parent_item['route'];
		$datarray['changed']         = $datarray['edited'] = $edited_at;
		$datarray['created']         = $created_at;
		$datarray['item_private']    = $parent_item['item_private'];
		$datarray['owner_xchan']     = $parent_item['owner_xchan'];
		$datarray['author_xchan']    = $person['xchan_hash'];
		$datarray['body']            = $body;
		$datarray['item_thread_top'] = 0;

		if (strstr($person['xchan_network'], 'friendica'))
			$app = 'Friendica';
		elseif ($person['xchan_network'] == 'diaspora')
			$app = 'Diaspora';
		else
			$app = '';

		$datarray['app'] = $app;


		// So basically if something arrives at the sys channel it's by definition public and we allow it.
		// If the parent was public, we allow it.
		// In all other cases, honour the permissions for this Diaspora connection

		$tgroup = tgroup_check($this->importer['channel_id'], $datarray);

		// If it's a comment to one of our own posts, check if the commenter has permission to comment.
		// We should probably check send_stream permission if the stream owner isn't us,
		// but we did import the parent post so at least at that time we did allow it and
		// the check would nearly always be superfluous and redundant.

		if ($parent_item['owner_xchan'] === $this->importer['channel_hash']) {
			$allowed = perm_is_allowed($this->importer['channel_id'], $xchan['xchan_hash'], 'post_comments');

			// let the plugin setting (Allow any Diaspora member to comment on your public posts)
			// over-ride possibly more loose channel permission limits (anyone on the internet).
			if (!$pub_comment && !$abook_contact)
				$allowed = false;
		}
		else {
			$allowed = true;
			if (!$pub_comment && !$abook_contact)
				$allowed = false;
		}

		if (!$allowed && !$tgroup) {
			logger('diaspora_comment: Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}

		if ($this->importer['system']) {
			$incl = get_config('system','pubstream_incl');
			$excl = get_config('system','pubstream_excl');

			if(($incl || $excl) && !MessageFilter::evaluate($datarray, $incl, $excl)) {
				logger('diaspora_reshare: filtering this author.');
				return 202;
			}
		}

		if (($contact) && (!post_is_importable($this->importer['channel_id'], $datarray, [$contact])) && (!$this->force)) {
			logger('diaspora_post: filtering this author.');
			return 202;
		}

		set_iconfig($datarray, 'diaspora', 'fields', $unxml, true);

		if ($editing) {
			$result = item_store_update($datarray);
		}
		else {
			$result = item_store($datarray);
		}

		if ($result && $result['success'])
			$message_id = $result['item_id'];

		if ($parent_item['owner_xchan'] === $this->importer['channel_hash']) {
			// We are the owner of this conversation, so send all received comments back downstream
			Zotlabs\Daemon\Master::Summon(['Notifier', 'comment-import', $message_id]);
		}

		if ($result['success']) {
			$r = q("select * from item where id = %d limit 1",
				intval($result['item_id'])
			);
			if ($r) {
				send_status_notifications($result['item_id'], $r[0]);
				sync_an_item($this->importer['channel_id'], $result['item_id']);
				return 200;
			}
		}

		return 202;
	}


	function conversation() {

		$guid = notags($this->get_property('guid'));
		$subject = notags($this->get_property('subject'));
		$diaspora_handle = notags($this->get_author());
		$participant_handles = notags($this->get_participants());
		$created_at = datetime_convert('UTC','UTC',notags($this->get_property('created_at')));

		$parent_uri = $guid;

		$messages = [ $this->xmlbase['message'] ];

		if(! count($messages)) {
			logger('diaspora_conversation: empty conversation');
			return;
		}

		// check if we already have the conversation item
		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s'",
			intval($this->importer['channel_id']),
			dbesc($guid)
		);

		if($r) {
			logger('diaspora_conversation: duplicate conversation', LOGGER_DEBUG);
			return 202;
		}

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$this->msg['author']);
		if(! $contact) {
			logger('diaspora_conversation: cannot find contact: ' . $this->msg['author']);
			return;
		}

		if(! perm_is_allowed($this->importer['channel_id'], $contact['xchan_hash'], 'post_mail')) {
			logger('diaspora_conversation: Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}

		// In order to fit the diaspora conversation into the item structure
		// we need to make the first message of the conversation the toplevel item.
		// The guid of this first message will be exchanged with the conversation guid.
		// According to the spec the first message must have the same author as the conversation.
		// The real guid will be stuffed away in iconfig.

		// TODO: deal with multiple messages in the conversation.

		$conv_item = $messages[0];

		$body = markdown_to_bb($conv_item['text'], false, ['diaspora' => true, 'preserve_lf' => true]);

		$maxlen = get_max_import_size();

		if ($maxlen && mb_strlen($body) > $maxlen) {
			$body = mb_substr($body, 0, $maxlen, 'UTF-8');
			logger('message length exceeds max_import_size: truncated');
		}

		$datarray = [];

		// Look for tags and linkify them
		$results = linkify_tags($body, $this->importer['channel_id'], false);

		$datarray['term'] = [];

		if ($results) {
			foreach ($results as $result) {
				$success = $result['success'];
				if ($success['replaced']) {
					$datarray['term'][] = [
						'uid'   => $this->importer['channel_id'],
						'ttype' => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					];
				}
			}
		}

		$cnt = preg_match_all('/@\[url=(.*?)\](.*?)\[\/url\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$cnt = preg_match_all('/@\[zrl=(.*?)\](.*?)\[\/zrl\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$datarray['aid']             = $this->importer['channel_account_id'];
		$datarray['uid']             = $this->importer['channel_id'];
		$datarray['verb']            = ACTIVITY_POST;
		$datarray['mid']             = z_root() . '/item/' . $guid;
		$datarray['parent_mid']      = z_root() . '/item/' . $guid;
		$datarray['uuid']            = $guid;
		$datarray['changed']         = $created_at;
		$datarray['created']         = $created_at;
		$datarray['edited']          = $created_at;
		$datarray['item_private']    = 2;
		$datarray['plink']           = service_plink($contact, $guid);
		$datarray['author_xchan']    = $contact['xchan_hash'];
		$datarray['owner_xchan']     = $contact['xchan_hash'];
		$datarray['body']            = $body;
		$datarray['title']           = $subject;
		$datarray['app']             = $app;
		$datarray['item_unseen']     = 1;
		$datarray['item_thread_top'] = 1;

		if (strstr($contact['xchan_network'], 'friendica'))
			$app = 'Friendica';
		elseif ($contact['xchan_network'] === 'diaspora')
			$app = 'Diaspora';
		else
			$app = '';

		$datarray['app'] = $app;

		if ($contact && !post_is_importable($this->importer['channel_id'], $datarray, [$contact])) {
			logger('diaspora_post: filtering this author.');
			return 202;
		}

		set_iconfig($datarray, 'diaspora', 'fields', $this->xmlbase, true);

		$result = item_store($datarray);

		if ($result['success']) {
			sync_an_item($this->importer['channel_id'], $result['item_id']);
			return 200;
		}

		return 202;

	}


	function message() {

		$msg_guid = notags(unxmlify($this->xmlbase['guid']));
		$msg_parent_guid = notags(unxmlify($this->xmlbase['parent_guid']));
		$msg_parent_author_signature = notags(unxmlify($this->xmlbase['parent_author_signature']));
		$msg_author_signature = notags(unxmlify($this->xmlbase['author_signature']));
		$msg_text = unxmlify($this->xmlbase['text']);
		$msg_created_at = datetime_convert('UTC','UTC',notags(unxmlify($this->xmlbase['created_at'])));
		$msg_diaspora_handle = notags($this->get_author());
		$msg_conversation_guid = notags(unxmlify($this->xmlbase['conversation_guid']));

		$xchan = find_diaspora_person_by_handle($msg_diaspora_handle);

		if(! perm_is_allowed($this->importer['channel_id'], $xchan['xchan_hash'], 'post_mail')) {
			logger('Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}

		if (strpos($msg_conversation_guid, 'conversation:') === 0)
			$msg_conversation_guid = substr($msg_conversation_guid, 13);

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s'",
			intval($this->importer['channel_id']),
			dbesc($msg_guid)
		);

		if($r) {
			logger('DM duplicate message', LOGGER_DEBUG);
			return 202;
		}

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s'",
			intval($this->importer['channel_id']),
			dbesc($msg_conversation_guid)
		);

		if(!$r) {
			logger('DM parent not found', LOGGER_DEBUG);
			return 202;
		}

		$parent_item = $r[0];

		if (intval($parent_item['item_nocomment']) || $parent_item['comment_policy'] === 'none'
			|| ($parent_item['comments_closed'] > NULL_DATE && $parent_item['comments_closed'] < datetime_convert())) {
			logger('diaspora_comment: comments disabled for post ' . $parent_item['mid']);
			return;
		}

		$datarray = [];
		$body = markdown_to_bb($msg_text, false, [ 'diaspora' => true, 'preserve_lf' => true ]);

		// Look for tags and linkify them
		$results = linkify_tags($body, $this->importer['channel_id'], false);

		$datarray['term'] = [];

		if ($results) {
			foreach ($results as $result) {
				$success = $result['success'];
				if ($success['replaced']) {
					$datarray['term'][] = [
						'uid'   => $this->importer['channel_id'],
						'ttype' => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					];
				}
			}
		}

		$cnt = preg_match_all('/@\[url=(.*?)\](.*?)\[\/url\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$cnt = preg_match_all('/@\[zrl=(.*?)\](.*?)\[\/zrl\]/ism', $body, $matches, PREG_SET_ORDER);
		if ($cnt) {
			foreach ($matches as $mtch) {
				$datarray['term'][] = [
					'uid'   => $this->importer['channel_id'],
					'ttype' => TERM_MENTION,
					'otype' => TERM_OBJ_POST,
					'term'  => $mtch[2],
					'url'   => $mtch[1]
				];
			}
		}

		$datarray['aid']          = $this->importer['channel_account_id'];
		$datarray['uid']          = $this->importer['channel_id'];
		$datarray['verb']         = ACTIVITY_POST;
		$datarray['mid']          = z_root() . '/item/' . $msg_guid;
		$datarray['uuid']         = $msg_guid;
		$datarray['parent_mid']   = $parent_item['mid'];
		$datarray['thr_parent']   = $parent_item['mid'];
		$datarray['route']        = $parent_item['route'];
		$datarray['changed']      = $msg_created_at;
		$datarray['created']      = $msg_created_at;
		$datarray['edited']       = $msg_created_at;
		$datarray['item_private'] = $parent_item['item_private'];
		$datarray['owner_xchan']  = $parent_item['owner_xchan'];
		$datarray['author_xchan'] = $xchan['xchan_hash'];
		$datarray['body']         = $body;

		if (strstr($xchan['xchan_network'], 'friendica'))
			$app = 'Friendica';
		elseif ($xchan['xchan_network'] === 'diaspora')
			$app = 'Diaspora';
		else
			$app = '';

		$datarray['app'] = $app;

		set_iconfig($datarray, 'diaspora', 'fields', $this->xmlbase, true);

		$result = item_store($datarray);

		if ($result['success']) {
			send_status_notifications($result['item_id'], $result['item']);
			sync_an_item($this->importer['channel_id'], $result['item_id']);
			return 200;
		}

		return 202;

	}


	function photo() {

		// Probably not used any more

		logger('diaspora_photo: init',LOGGER_DEBUG);

		$remote_photo_path = notags(unxmlify($this->xmlbase['remote_photo_path']));

		$remote_photo_name = notags(unxmlify($this->xmlbase['remote_photo_name']));

		$status_message_guid = notags(unxmlify($this->xmlbase['status_message_guid']));

		$guid = notags(unxmlify($this->xmlbase['guid']));

		$diaspora_handle = notags($this->get_author());

		$public = notags(unxmlify($this->xmlbase['public']));

		$created_at = notags(unxmlify($this->xmlbase['created_at']));

		logger('diaspora_photo: status_message_guid: ' . $status_message_guid, LOGGER_DEBUG);

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$this->msg['author']);
		if(! $contact) {
			logger('diaspora_photo: contact record not found: ' . $this->msg['author'] . ' handle: ' . $diaspora_handle);
			return;
		}

		if((! $this->importer['system']) && (! perm_is_allowed($this->importer['channel_id'],$contact['xchan_hash'],'send_stream'))) {
			logger('diaspora_photo: Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($status_message_guid)
		);
		if(! $r) {
			logger('diaspora_photo: attempt = ' . $attempt . '; status message not found: ' . $status_message_guid . ' for photo: ' . $guid);
			return;
		}

	//	$parent_item = $r[0];

	//	$link_text = '[img]' . $remote_photo_path . $remote_photo_name . '[/img]' . "\n";

	//	$link_text = scale_external_images($link_text, true,
	//									   array($remote_photo_name, 'scaled_full_' . $remote_photo_name));

	//	if(strpos($parent_item['body'],$link_text) === false) {
	//		$r = q("update item set body = '%s', visible = 1 where id = %d and uid = %d",
	//			dbesc($link_text . $parent_item['body']),
	//			intval($parent_item['id']),
	//			intval($parent_item['uid'])
	//		);
	//	}

		return;
	}

	function like() {
		$guid = notags($this->get_property('guid'));
		if (!$guid) {
			logger('diaspora_like: missing guid' . print_r($this->msg, true), LOGGER_DEBUG);
			return;
		}

		$parent_guid = notags($this->get_property('parent_guid'));
		if (!$parent_guid) {
			logger('diaspora_like: missing parent_guid' . print_r($this->msg, true), LOGGER_DEBUG);
			return;
		}

		$diaspora_handle = notags($this->get_author());
		if (!$diaspora_handle) {
			logger('diaspora_like: missing author' . print_r($this->msg, true), LOGGER_DEBUG);
			return;
		}

		$target_type             = notags($this->get_ptype());
		$positive                = notags($this->get_property('positive'));
		$author_signature        = notags($this->get_property('author_signature'));
		$parent_author_signature = $this->get_property('parent_author_signature');

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($parent_guid)
		);
		if (!$r) {
			logger('diaspora_comment: parent item not found: parent: ' . $parent_guid . ' item: ' . $guid);
			return;
		}

		$parent_item = $r[0];

		if (intval($parent_item['item_nocomment']) || $parent_item['comment_policy'] === 'none'
			|| ($parent_item['comments_closed'] > NULL_DATE && $parent_item['comments_closed'] < datetime_convert())) {
			logger('diaspora_comment: comments disabled for post ' . $parent_item['mid']);
			return;
		}

		// does the parent originate from this site?
		$local_parent_item = (strpos($parent_item['plink'], z_root()) === 0);

		$parent_owner_uid  = null;
		if ($local_parent_item) {
			// find the owner channel_id
			$r = q("SELECT uid FROM item WHERE item_origin = 1 AND uuid = '%s' LIMIT 1",
				dbesc($parent_guid)
			);
			if ($r)
				$parent_owner_uid = $r[0]['uid'];
		}

		$xchan = find_diaspora_person_by_handle($diaspora_handle);

		if (!$xchan) {
			logger('Cannot resolve diaspora handle ' . $diaspora_handle);
			return;
		}

		$contact = diaspora_get_contact_by_handle((($parent_owner_uid) ? $parent_owner_uid : $this->importer['channel_id']), $this->msg['author']);

		if (is_array($contact)) {
			$abook_contact = true;
		}
		else {
			$contact       = find_diaspora_person_by_handle($this->msg['author']);
			$abook_contact = false;
		}

		$pub_comment = 1;

		// By default comments on public posts are allowed from anybody on Diaspora. That is their policy.
		// If the parent item originates from this hub we can over-ride the default comment policy.

		if ($parent_owner_uid)
			$pub_comment = get_pconfig($parent_owner_uid, 'system', 'diaspora_public_comments', 1);

		if (intval($parent_item['item_private']))
			$pub_comment = 0;

		// If it's a like to one of our own posts, check if the liker has permission to like.
		// We should probably check send_stream permission if the stream owner isn't us,
		// but we did import the parent post so at least at that time we did allow it and
		// the check would nearly always be superfluous and redundant.

		if ($parent_item['owner_xchan'] === $this->importer['channel_hash']) {
			$allowed = perm_is_allowed($this->importer['channel_id'], $xchan['xchan_hash'], 'post_comments');

			// let the plugin setting (Allow any Diaspora member to comment/like your public posts)
			// over-ride possibly more loose channel permission limits (anyone on the internet).
			if (!$pub_comment && !$abook_contact)
				$allowed = false;
		}
		else {
			$allowed = true;
			if (!$pub_comment && !$abook_contact)
				$allowed = false;
		}

		if (!$allowed) {
			logger('diaspora_like: Ignoring this author.', LOGGER_DEBUG);
			return 202;
		}

		$r = q("SELECT * FROM item WHERE uid = %d AND uuid = '%s' LIMIT 1",
			intval($this->importer['channel_id']),
			dbesc($guid)
		);
		if ($r) {
			if ($positive === 'true') {
				logger('diaspora_like: duplicate like: ' . $guid);
				return;
			}

			// Note: I don't think "Like" objects with positive = "false" are ever actually used
			// It looks like "RelayableRetractions" are used for "unlike" instead

			if ($positive === 'false') {
				logger('diaspora_like: received a like with positive set to "false"...ignoring');
				// perhaps call drop_item()
				// FIXME--actually don't unless it turns out that Diaspora does indeed send out "false" likes
				//  send notification via proc_run()
				return;
			}
		}

		$i = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($parent_item['author_xchan'])
		);
		if ($i)
			$item_author = $i[0];

		// Note: I don't think "Like" objects with positive = "false" are ever actually used
		// It looks like "RelayableRetractions" are used for "unlike" instead

		if ($positive === 'true') {
			$activity = ACTIVITY_LIKE;
			$bodyverb = t('%1$s likes %2$s\'s %3$s');
		}
		else {
			$activity = ACTIVITY_DISLIKE;
			$bodyverb = t('%1$s dislikes %2$s\'s %3$s');
		}

		if ($parent_author_signature && !$this->force) {
			// If a parent_author_signature exists, then we've received the like
			// relayed from the top-level post owner.

			$key = $this->msg['key'];

			$x = diaspora_verify_fields($this->xmlbase, $parent_author_signature, $key);
			if (!$x) {
				logger('diaspora_like: top-level owner verification failed.');
				return;
			}
		}
		elseif (!$this->force) {

			// If there's no parent_author_signature, then we've received the like
			// from the like creator. In that case, the person is "like"ing
			// our post, so he/she must be a contact of ours and his/her public key
			// should be in $this->msg['key']

			$key = $this->msg['msg_author_key'];

			$x = diaspora_verify_fields($this->xmlbase, $author_signature, $key);
			if (!$x) {
				logger('diaspora_like: author verification failed.');
				return;
			}

			if (defined('DIASPORA_V2'))
				$this->xmlbase['parent_author_signature'] = diaspora_sign_fields($this->xmlbase, $this->importer['channel_prvkey']);
		}

		logger('diaspora_like: signature check complete.', LOGGER_DEBUG);

		// Phew! Everything checks out. Now create an item.

		// Find the original comment author information.
		// We need this to make sure we display the comment author
		// information (name and avatar) correctly.
		if (strcasecmp($diaspora_handle, $this->msg['author']) == 0)
			$person = $contact;
		else {
			$person = find_diaspora_person_by_handle($diaspora_handle);

			if (!is_array($person)) {
				logger('diaspora_like: unable to find author details');
				return;
			}
		}

		$post_type = (($parent_item['resource_type'] === 'photo') ? t('photo') : t('status'));
		$links     = [['rel' => 'alternate', 'type' => 'text/html', 'href' => $parent_item['plink']]];
		$objtype   = (($parent_item['resource_type'] === 'photo') ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE);
		$object    = \Zotlabs\Lib\Activity::fetch_item(['id' => $parent_item['mid']]);

		$arr               = [];
		$arr['uid']        = $this->importer['channel_id'];
		$arr['aid']        = $this->importer['channel_account_id'];
		$arr['mid']        = z_root() . '/activity/' . $guid;
		$arr['uuid']       = $guid;
		$arr['parent_mid'] = $parent_item['mid'];

		if ($parent_item['uuid'] !== $parent_guid) {
			$arr['thr_parent'] = $parent_guid;

			// use a URI for thr_parent if we have it
			if (strpos($parent_item['mid'], '/') !== false && $arr['thr_parent'] === basename($parent_item['mid'])) {
				$arr['thr_parent'] = $parent_item['mid'];
			}

		}

		$arr['owner_xchan']  = $parent_item['owner_xchan'];
		$arr['author_xchan'] = $person['xchan_hash'];
//		$ulink               = '[url=' . $item_author['xchan_url'] . ']' . $item_author['xchan_name'] . '[/url]';
//		$alink               = '[url=' . $parent_item['author']['xchan_url'] . ']' . $parent_item['author']['xchan_name'] . '[/url]';
//		$plink               = '[url=' . z_root() . '/display/' . $guid . ']' . $post_type . '[/url]';
//		$arr['body']         = sprintf($bodyverb, $ulink, $alink, $plink);

		$arr['app'] = 'Diaspora';

		// set the route to that of the parent so downstream hubs won't reject it.
		$arr['route'] = $parent_item['route'];

		$arr['item_private'] = $parent_item['item_private'];
		$arr['verb']         = $activity;
		$arr['obj_type']     = $objtype;
		$arr['obj']          = $object;

		if ($this->xmlbase) {
			$unxml = [];
			foreach ($this->xmlbase as $k => $v) {
				if ($k === 'diaspora_handle')
					$k = 'author';
				if ($k === 'target_type')
					$k = 'parent_type';
				if (is_string($v))
					$v = unxmlify($v);
				$unxml[$k] = $v;
			}
		}

		set_iconfig($arr, 'diaspora', 'fields', $unxml, true);

		$result = item_store($arr);

		if ($result['success']) {
			// if the message isn't already being relayed, notify others
			// the existence of parent_author_signature means the parent_author or owner
			// is already relaying. The parent_item['origin'] indicates the message was created on our system

			if (intval($parent_item['item_origin']) && (!$parent_author_signature))
				Master::Summon(['Notifier', 'comment-import', $result['item_id']]);
			sync_an_item($this->importer['channel_id'], $result['item_id']);

			return 200;
		}

		return 202;
	}

	function retraction() {


		$guid = notags($this->get_target_guid());
		$diaspora_handle = notags($this->get_author());
		$type = notags($this->get_type());

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$diaspora_handle);
		if(! $contact)
			return;

		if($type === 'Person' || $type === 'Contact') {
			contact_remove($this->importer['channel_id'],$contact['abook_id']);
		}
		elseif(($type === 'Post') || ($type === 'StatusMessage') || ($type === 'Comment') || ($type === 'Like')) {
			$r = q("select * from item where uuid = '%s' and uid = %d limit 1",
				dbesc($guid),
				intval($this->importer['channel_id'])
			);

			if($r) {

				// by default only delete your copy of the item without propagating it further

				$stage = DROPITEM_NORMAL;

				if($type === 'Comment' || $type === 'Like') {

					// If we are the conversation owner, propagate the delete elsewhere

					$p = q("select * from item where uuid = '%s' and uid = %d",
						dbesc($r[0]['parent_mid']),
						intval($this->importer['channel_id'])
					);
					if($p && $p[0]['owner_xchan'] === $this->importer['channel_hash']) {
						$stage = DROPITEM_PHASE1;
					}
				}

				if(link_compare($r[0]['author_xchan'],$contact['xchan_hash'])
					|| link_compare($r[0]['owner_xchan'],$contact['xchan_hash'])) {
					drop_item($r[0]['id'],false, $stage);

					// notification is not done in drop_item() unless the process is interactive
					// so call it now

					if($stage === DROPITEM_PHASE1) {
						Zotlabs\Daemon\Master::Summon( [ 'Notifier','drop',$r[0]['id'] ] );
					}
				}
			}
		}

		return 202;
	}

	function signed_retraction() {

		// obsolete - see https://github.com/SuperTux88/diaspora_federation/issues/27


		$guid = notags($this->get_target_guid());
		$diaspora_handle = notags($this->get_author());
		$type = notags($this->get_type());
		$sig = notags(unxmlify($this->xmlbase['target_author_signature']));

		$parent_author_signature = (($this->xmlbase['parent_author_signature']) ? notags(unxmlify($this->xmlbase['parent_author_signature'])) : '');

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$diaspora_handle);
		if(! $contact) {
			logger('diaspora_signed_retraction: no contact ' . $diaspora_handle . ' for ' . $this->importer['channel_id']);
			return;
		}


		$signed_data = $guid . ';' . $type ;
		$key = $this->msg['key'];

		/* How Diaspora performs relayable_retraction signature checking:

	   - If an item has been sent by the item author to the top-level post owner to relay on
	     to the rest of the contacts on the top-level post, the top-level post owner checks
	     the author_signature, then creates a parent_author_signature before relaying the item on
	   - If an item has been relayed on by the top-level post owner, the contacts who receive it
	     check only the parent_author_signature. Basically, they trust that the top-level post
	     owner has already verified the authenticity of anything he/she sends out
	   - In either case, the signature that get checked is the signature created by the person
	     who sent the salmon
		*/

		if($parent_author_signature) {

			$parent_author_signature = base64_decode($parent_author_signature);

			if(! Crypto::verify($signed_data,$parent_author_signature,$key,'sha256')) {
				logger('diaspora_signed_retraction: top-level post owner verification failed');
				return;
			}
		}
		else {

			$sig_decode = base64_decode($sig);

			if(! Crypto::verify($signed_data,$sig_decode,$key,'sha256')) {
				logger('diaspora_signed_retraction: retraction owner verification failed.' . print_r($this->msg,true));
				return;
			}
		}

		if($type === 'StatusMessage' || $type === 'Comment' || $type === 'Like') {
			$r = q("select * from item where uuid = '%s' and uid = %d limit 1",
				dbesc($guid),
				intval($this->importer['channel_id'])
			);
			if($r) {
				if($r[0]['author_xchan'] == $contact['xchan_hash']) {

					drop_item($r[0]['id'],false, DROPITEM_PHASE1);

					// Now check if the retraction needs to be relayed by us
					//
					// The first item in the item table with the parent id is the parent. However, MySQL doesn't always
					// return the items ordered by item.id, in which case the wrong item is chosen as the parent.
					// The only item with parent and id as the parent id is the parent item.
					$p = q("select item_flags from item where parent = %d and id = %d limit 1",
						$r[0]['parent'],
						$r[0]['parent']
					);
					if($p) {
						if(intval($p[0]['item_origin']) && (! $parent_author_signature)) {

							// the existence of parent_author_signature would have meant the parent_author or owner
							// is already relaying.

							logger('diaspora_signed_retraction: relaying relayable_retraction');
							Zotlabs\Daemon\Master::Summon(array('Notifier','drop',$r[0]['id']));
						}
					}
				}
			}
		}
		else
			logger('diaspora_signed_retraction: unknown type: ' . $type);

		return 202;

	}

	function profile() {

		$diaspora_handle = notags($this->get_author());

		logger('xml: ' . print_r($this->xmlbase,true), LOGGER_DEBUG);

		if($diaspora_handle != $this->msg['author']) {
			logger('diaspora_post: Potential forgery. Message handle is not the same as envelope sender.');
			return 202;
		}

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$diaspora_handle);
		if(! $contact)
			return;

		if($contact['abook_blocked']) {
			logger('diaspora_profile: Ignoring this author.');
			return 202;
		}

		// full_name added to protocol 2018-04
		if(!empty($this->xmlbase['full_name'])) {
			$name = unxmlify($this->xmlbase['full_name']);
		}
		else {
			$name = unxmlify($this->xmlbase['first_name'] . (($this->xmlbase['last_name']) ? ' ' . $this->xmlbase['last_name'] : ''));
		}
		$image_url = unxmlify($this->xmlbase['image_url']);
		$birthday = unxmlify($this->xmlbase['birthday']);
		$edited = datetime_convert('UTC','UTC',$this->xmlbase['edited_at']);

		$handle_parts = explode("@", $diaspora_handle);
		if($name === '') {
			$name = $handle_parts[0];
		}

		if( preg_match("|^https?://|", $image_url) === 0) {
			$image_url = "http://" . $handle_parts[1] . $image_url;
		}

		require_once('include/photo/photo_driver.php');

		if($edited > $contact['xchan_photo_date']) {
		    $images = import_xchan_photo($image_url,$contact['xchan_hash']);
		    $newimg = true;
		} else {
		    $images = array($contact['xchan_photo_l'],$contact['xchan_photo_m'],$contact['xchan_photo_s'],$contact['xchan_photo_mimetype']);
		    $newimg = false;
		}

		$r = q("update xchan set xchan_name = '%s', xchan_name_date = '%s', xchan_photo_date = '%s', xchan_photo_l = '%s', xchan_photo_m = '%s', xchan_photo_s = '%s', xchan_photo_mimetype = '%s' where xchan_hash = '%s'",
		    dbesc($name),
		    dbesc(($name != $contact['xchan_name'] ? $edited : $contact['xchan_name_date'])),
		    dbesc(($newimg ? $edited : $contact['xchan_photo_date'])),
		    dbesc($images[0]),
		    dbesc($images[1]),
		    dbesc($images[2]),
		    dbesc($images[3]),
		    dbesc($contact['xchan_hash'])
		);

		// Somebody is sending us birthday arrays.

		if(is_array($birthday)) {
			logger('Illegal input: Diaspora birthday is an array. ' . print_r($this->xmlbase,true));
			return 202;
		}

		// Generic birthday. We don't know the timezone. The year is irrelevant.

		if(intval(substr($birthday,0,4)) <= 1004)
			$birthday = '1901-' . substr($birthday,5);


		$birthday = str_replace('1000','1901',$birthday);

		$birthday = datetime_convert('UTC','UTC',$birthday,'Y-m-d');

		// this is to prevent multiple birthday notifications in a single year
		// if we already have a stored birthday and the 'm-d' part hasn't changed, preserve the entry, which will preserve the notify year

		// @fixme Diaspora birthdays are not currently stored and $contact['bd'] does not exist in the current implementation
		// This represents legacy code from Friendica where Diaspora birthdays were stored and managed separately from Friendica birthdays due to
		// incompatible differences in implementation.
		// It may be possible to implement a similar scheme going forward using abconfg or xconfig for platform dependent storage.

		//		if(substr($birthday,5) === substr($contact['bd'],5))
		//			$birthday = $contact['bd'];

		return;

	}


	function event() {

		$diaspora_handle = notags($this->get_author());

		// not currently handled

		// it isn't clear if we will ever receive an event outside a post/status_message context
		// and if we do it will be missing important stuff like creation date
		// and there will also be no way of tying it to a message-id to check for duplication

		// log what we do have and parse whatever we are able
		// so at least we won't have to start from scratch when somebody inevitably complains.

		logger('event: ' . print_r($this->xmlbase,true), LOGGER_DATA);

		$datarray = [];
		$ev = [];

		$dts = $this->get_property('start');
		if($dts) {
			$ev['dtstart'] = datetime_convert('UTC','UTC', $dts);
		}
		$dte = $this->get_property('end');
		if($dte) {
			$ev['dtend'] = datetime_convert('UTC','UTC', $dte);
		}
		else {
			$ev['nofinish'] = true;
		}

		$ev['event_hash'] = notags($this->get_property('guid'));
		$ev['summary'] = escape_tags($this->get_property('summary'));
		$ev['adjust'] = (($this->get_property('all_day')) ? false : true);
		$ev_timezone = notags($this->get_property('timezone'));
		$ev['description'] = markdown_to_bb($this->get_property('description'), false, [ 'diaspora' => true ]);
		$ev_loc = $this->get_property('location');
		if($ev_loc) {
			$ev_address = escape_tags($this->get_property('address',$ev_loc));
			$ev_lat = notags($this->get_property('lat',$ev_loc));
			$ev_lon = notags($this->get_property('lon',$ev_loc));
		}
		$ev['location'] = '';
		if($ev_address) {
			$ev['location'] .=  '[map]' . $ev_address . '[/map]' . "\n\n";
		}
		if(! (is_null($ev_lat) || is_null($ev_lon))) {
			$ev['location'] .= '[map=' . $ev_lat . ',' . $ev_lon . ']';
		}

		if($ev['start'] && $ev['event_hash'] && $ev['summary']) {
			$datarray['body'] = format_event_bbcode($ev);
		}

		set_iconfig($datarray,'system','event_id',$ev['event_hash'],true);

	}

	function event_participation() {

		logger('event_participation: ' . print_r($this->xmlbase,true), LOGGER_DATA);

		$guid = notags($this->get_property('guid'));
		$parent_guid = notags($this->get_property('parent_guid'));
		$diaspora_handle = notags($this->get_author());
		$status = notags($this->get_property('status'));

		switch ($status) {
			case 'accepted':
				$activity = ACTIVITY_ATTEND;
				break;
			case 'declined':
				$activity = ACTIVITY_ATTENDNO;
				break;
			case 'tentative':
			default:
				$activity = ACTIVITY_ATTENDMAYBE;
				break;
		}


		$author_signature = notags($this->get_property('author_signature'));
		$parent_author_signature = $this->get_property('parent_author_signature');

		$edited = false;
		$edited_at = notags($this->get_property('edited_at'));
		if($edited_at)
			$edited = datetime_convert($edited_at);

		$xchan = find_diaspora_person_by_handle($diaspora_handle);

		if(! $xchan) {
			logger('Cannot resolve diaspora handle ' . $diaspora_handle);
			return;
		}

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$this->msg['author']);

		$pubcomment = get_pconfig($this->importer['channel_id'],'system','diaspora_public_comments',1);

		// by default comments on public posts are allowed from anybody on Diaspora. That is their policy.
		// Once this setting is set to something we'll track your preference and it will over-ride the default.

		if(($pubcomment) && (! $contact))
			$contact = find_diaspora_person_by_handle($this->msg['author']);

		// find a message owned by this channel and holding the referenced event

		$r = q("select item.* from item left join iconfig on iconfig.iid = item.id where cat = 'system' and k = 'event_id' and v = '%s' and item.uid = %d limit 1",
			dbesc($parent_guid),
			intval($this->importer['channel_id'])
		);

		if(! $r) {
			logger('event ' . $parent_guid . ' not found.');
			return;
		}
		$item_id = $r[0]['id'];



		xchan_query($r);
		$parent_item = $r[0];

		if(intval($parent_item['item_nocomment']) || $parent_item['comment_policy'] === 'none'
			|| ($parent_item['comments_closed'] > NULL_DATE && $parent_item['comments_closed'] < datetime_convert())) {
			logger('comments disabled for post ' . $parent_item['mid']);
			return;
		}

		$orig_item = false;

		$r = q("SELECT * FROM item WHERE verb in ( '%s', '%s' , '%s') and uid = %d and parent_mid = '%s' and author_xchan = '%s'",
			dbesc(ACTIVITY_ATTEND),
			dbesc(ACTIVITY_ATTENDNO),
			dbesc(ACTIVITY_ATTENDMAYBE),
			intval($this->importer['channel_id']),
			dbesc($parent_item['mid']),
			dbesc($xchan['xchan_hash'])
		);

		if($r) {
			foreach($r as $rv) {
				if(($edited) && ($rv['uuid'] === $guid)) {
					if($edited > $rv['created']) {
						$orig_item = $rv;
						continue;
					}
					else {
						return;
					}
				}
				drop_item($rv['id'],false);
			}
		}

		$i = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($parent_item['author_xchan'])
		);
		if($i)
			$item_author = $i[0];


		$key = $this->msg['key'];

		if($parent_author_signature) {
			// If a parent_author_signature exists, then we've received the like
			// relayed from the top-level post owner.

			$x = diaspora_verify_fields($this->xmlbase,$parent_author_signature,$key);
			if(! $x) {
				logger('diaspora_like: top-level owner verification failed.');
				return;
			}
		}
		else {

			// If there's no parent_author_signature, then we've received the like
			// from the like creator. In that case, the person is "like"ing
			// our post, so he/she must be a contact of ours and his/her public key
			// should be in $this->msg['key']

			$x = diaspora_verify_fields($this->xmlbase,$author_signature,$key);
			if(! $x) {
				logger('diaspora_like: author verification failed.');
				return;
			}

			if(defined('DIASPORA_V2'))
				$this->xmlbase['parent_author_signature'] = diaspora_sign_fields($this->xmlbase,$this->importer['channel_prvkey']);
		}

		logger('diaspora_like: signature check complete.',LOGGER_DEBUG);

		// Phew! Everything checks out. Now create an item.

		// Find the original author information.
		// We need this to make sure we display the author
		// information (name and avatar) correctly.

		if(strcasecmp($diaspora_handle,$this->msg['author']) == 0)
			$person = $contact;
		else {
			$person = find_diaspora_person_by_handle($diaspora_handle);

			if(! is_array($person)) {
				logger('diaspora_like: unable to find author details');
				return;
			}
		}

		$uri = $diaspora_handle . ':' . $guid;


		$post_type = ('event');

		$links = array(array('rel' => 'alternate','type' => 'text/html', 'href' => $parent_item['plink']));
		$objtype = (($item['resource_type'] === 'photo') ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE );

		if($objtype === ACTIVITY_OBJ_NOTE && (! intval($item['item_thread_top'])))
			$objtype = ACTIVITY_OBJ_COMMENT;

		$body = $parent_item['body'];

		$object = json_encode(array(
			'type'    => $post_type,
			'id'	  => $parent_item['mid'],
			'parent'  => (($parent_item['thr_parent']) ? $parent_item['thr_parent'] : $parent_item['parent_mid']),
			'link'	  => $links,
			'title'   => $parent_item['title'],
			'content' => $parent_item['body'],
			'created' => $parent_item['created'],
			'edited'  => $parent_item['edited'],
			'author'  => array(
				'name'     => $item_author['xchan_name'],
				'address'  => $item_author['xchan_addr'],
				'guid'     => $item_author['xchan_guid'],
				'guid_sig' => $item_author['xchan_guid_sig'],
				'link'     => array(
					array('rel' => 'alternate', 'type' => 'text/html', 'href' => $item_author['xchan_url']),
					array('rel' => 'photo', 'type' => $item_author['xchan_photo_mimetype'], 'href' => $item_author['xchan_photo_m'])
				),
			),
		));


		if($activity === ACTIVITY_ATTEND)
			$bodyverb = t('%1$s is attending %2$s\'s %3$s');
		if($activity === ACTIVITY_ATTENDNO)
			$bodyverb = t('%1$s is not attending %2$s\'s %3$s');
		if($activity === ACTIVITY_ATTENDMAYBE)
			$bodyverb = t('%1$s may attend %2$s\'s %3$s');

		$arr = array();

		$arr['uid'] = $this->importer['channel_id'];
		$arr['aid'] = $this->importer['channel_account_id'];
		$arr['mid'] = z_root() . '/activity/' . $guid;
		$arr['uuid'] = $guid;

		$arr['parent_mid'] = $parent_item['mid'];

		if($parent_item['uuid'] !== $parent_guid)
			$arr['thr_parent'] = $parent_guid;

		$arr['owner_xchan'] = $parent_item['owner_xchan'];
		$arr['author_xchan'] = $person['xchan_hash'];

		$ulink = '[url=' . $item_author['xchan_url'] . ']' . $item_author['xchan_name'] . '[/url]';
		$alink = '[url=' . $parent_item['author']['xchan_url'] . ']' . $parent_item['author']['xchan_name'] . '[/url]';
		$plink = '[url='. z_root() .'/display/'.$guid.']'.$post_type.'[/url]';
		$arr['body'] =  sprintf( $bodyverb, $ulink, $alink, $plink );

		$arr['app']  = 'Diaspora';

		// set the route to that of the parent so downstream hubs won't reject it.
		$arr['route'] = $parent_item['route'];

		$arr['item_private'] = $parent_item['item_private'];
		$arr['verb'] = $activity;
		$arr['obj_type'] = $objtype;
		$arr['obj'] = $object;

		if($this->xmlbase) {
			$unxml = [];
			foreach($this->xmlbase as $k => $v) {
				if($k === 'diaspora_handle')
					$k = 'author';
				if($k === 'target_type')
					$k = 'parent_type';
				if(is_string($v))
					$v = unxmlify($v);
				$unxml[$k] = $v;
			}
		}

		set_iconfig($arr,'diaspora','fields',$unxml,true);

		if($orig_item)
			$result = item_store_update($arr);
		else
			$result = item_store($arr);

		if($result['success']) {
			// if the message isn't already being relayed, notify others
			// the existence of parent_author_signature means the parent_author or owner
			// is already relaying. The parent_item['origin'] indicates the message was created on our system

			if(intval($parent_item['item_origin']) && (! $parent_author_signature))
				Zotlabs\Daemon\Master::Summon(array('Notifier','comment-import',$result['item_id']));
			sync_an_item($this->importer['channel_id'],$result['item_id']);
		}

		return;

	}



	function participation() {

		$diaspora_handle = notags($this->get_author());
		$type = notags($this->get_ptype());

		// not currently handled

		logger('participation: ' . print_r($this->xmlbase,true), LOGGER_DATA);


	}

	function poll_participation() {

		$diaspora_handle = notags($this->get_author());

		// not currently handled

		logger('poll_participation: ' . print_r($this->xmlbase,true), LOGGER_DATA);

	}

	function account_deletion() {

		$diaspora_handle = notags($this->get_author());

		// not currently handled

		logger('account_deletion: ' . print_r($this->xmlbase,true), LOGGER_DATA);


	}


	function account_migration() {

		$diaspora_handle = notags($this->get_author());
		$old_identity = $this->get_property('old_identity');

		if(! $old_identity) {
			$old_identity = $diaspora_handle;
		}

		$profile = $this->xmlbase['profile'];
		if(! $profile) {
			return;
		}

		logger('xml: ' . print_r($this->xmlbase,true), LOGGER_DEBUG);

		if($this->msg['format'] === 'legacy') {
			return;
		}

		if($diaspora_handle != $this->msg['author']) {
			logger('Potential forgery. Message handle is not the same as envelope sender.');
			return 202;
		}

		$new_handle = notags($this->get_author($profile));

		$signed_text = 'AccountMigration:' . $old_identity . ':' . $new_handle;

		$signature = $this->get_property('signature');

		if(! $signature) {
			logger('signature not found.');
			return 202;
		}
		$signature = str_replace(array(" ","\t","\r","\n"),array("","","",""),$signature);

		$contact = diaspora_get_contact_by_handle($this->importer['channel_id'],$old_identity);
		if(! $contact) {
			logger('connection not found.');
			return 202;
		}

		$new_contact = find_diaspora_person_by_handle($new_handle);

		if(! $new_contact) {
			logger('new handle not found.');
			return 202;
		}

		$sig_decode = base64_decode($signature);

		// check signature against old and new identities. Either can sign this message.

		if(! (Crypto::verify($signed_text,$sig_decode,$new_contact['xchan_pubkey'],'sha256')
			|| Crypto::verify($signed_text,$sig_decode,$contact['xchan_pubkey'],'sha256'))) {
			logger('message verification failed.');
			return 202;
		}


		$name = unxmlify($this->get_property('first_name',$profile) . (($this->get_property('last_name',$profile)) ? ' ' . $this->get_property('last_name',$profile) : ''));
		$image_url = unxmlify($this->get_property('image_url',$profile));
		$birthday = unxmlify($this->get_property('birthday',$profile));

		$handle_parts = explode("@", $new_handle);
		if($name === '') {
			$name = $handle_parts[0];
		}

		if( preg_match("|^https?://|", $image_url) === 0) {
			$image_url = "http://" . $handle_parts[1] . $image_url;
		}

		require_once('include/photo/photo_driver.php');

		$images = import_xchan_photo($image_url,$new_contact['xchan_hash']);

		// Generic birthday. We don't know the timezone. The year is irrelevant.

		$birthday = str_replace('1000','1901',$birthday);

		$birthday = datetime_convert('UTC','UTC',$birthday,'Y-m-d');

		// this is to prevent multiple birthday notifications in a single year
		// if we already have a stored birthday and the 'm-d' part hasn't changed, preserve the entry, which will preserve the notify year
		// currently not implemented

		if(substr($birthday,5) === substr($new_contact['bd'],5))
			$birthday = $new_contact['bd'];

		$r = q("update xchan set xchan_name = '%s', xchan_name_date = '%s', xchan_photo_date = '%s', xchan_photo_l = '%s', xchan_photo_m = '%s', xchan_photo_s = '%s', xchan_photo_mimetype = '%s' where xchan_hash = '%s'",
			dbesc($name),
			dbesc(datetime_convert()),
			dbesc($images[5]),
			dbesc($images[0]),
			dbesc($images[1]),
			dbesc($images[2]),
			dbesc($images[3]),
			dbesc($new_contact['xchan_hash'])
		);

		$r = q("update abook set abook_xchan = '%s' where abook_xchan = '%s' and abook_channel = %d",
			dbesc($new_contact['xchan_hash']),
			dbesc($contact['xchan_hash']),
			intval($this->importer['channel_id'])
		);

		$r = q("update pgrp_member set xchan = '%s' where xchan = '%s' and uid = %d",
			dbesc($new_contact['xchan_hash']),
			dbesc($contact['xchan_hash']),
			intval($this->importer['channel_id'])
		);

		// @todo also update private conversational items with the old xchan_hash in an allow_cid or deny_cid acl
		// Not much point updating other DB objects which wouldn't have been visible without remote authentication
		// to begin with.

		return;

	}



	function get_author($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['diaspora_handle']))
			return unxmlify($xml['diaspora_handle']);
		elseif(!empty($xml['sender_handle']))
			return unxmlify($xml['sender_handle']);
		elseif(!empty($xml['author']))
			return unxmlify($xml['author']);
		else
			return '';
	}

	function get_root_author($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['root_diaspora_id']))
			return unxmlify($xml['root_diaspora_id']);
		elseif(!empty($xml['root_author']))
			return unxmlify($xml['root_author']);
		else
			return '';
	}


	function get_participants($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['participant_handles']))
			return unxmlify($xml['participant_handles']);
		elseif(!empty($xml['participants']))
			return unxmlify($xml['participants']);
		else
			return '';
	}

	function get_ptype($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['target_type']))
			return unxmlify($xml['target_type']);
		elseif(!empty($xml['parent_type']))
			return unxmlify($xml['parent_type']);
		else
			return '';
	}

	function get_type($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['target_type']))
			return unxmlify($xml['target_type']);
		elseif(!empty($xml['type']))
			return unxmlify($xml['type']);
		else
			return '';
	}


	function get_target_guid($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['post_guid']))
			return unxmlify($xml['post_guid']);
		elseif(!empty($xml['target_guid']))
			return unxmlify($xml['target_guid']);
		elseif(!empty($xml['guid']))
			return unxmlify($xml['guid']);
		else
			return '';
	}


	function get_recipient($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['recipient_handle']))
			return unxmlify($xml['recipient_handle']);
		elseif(!empty($xml['recipient']))
			return unxmlify($xml['recipient']);
		else
			return '';
	}

	function get_body($xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml['raw_message']))
			return unxmlify($xml['raw_message']);
		elseif(!empty($xml['text']))
			return unxmlify($xml['text']);
		else
			return '';
	}

	function get_property($property,$xml = []) {
		if(! $xml)
			$xml = $this->xmlbase;

		if(!empty($xml[$property])) {
			if(is_array($xml[$property])) {
				return $xml[$property];
			}
			else {
				return unxmlify($xml[$property]);
			}
		}
		return '';
	}


}
