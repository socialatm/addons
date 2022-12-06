<?php


use Zotlabs\Lib\Crypto;
use Zotlabs\Lib\Libzot;

function diaspora_handle_from_contact($contact_hash) {

	logger("diaspora_handle_from_contact: contact id is " . $contact_hash, LOGGER_DEBUG);

	$r = q("SELECT xchan_addr from xchan where xchan_hash = '%s' limit 1",
		dbesc($contact_hash)
	);
	if($r) {
		return $r[0]['xchan_addr'];
	}
	return false;
}

function diaspora_get_contact_by_handle($uid,$handle) {

	if(! $handle) {
		logger('diaspora_get_contact_by_handle: no handle provided');
		return false;
	}

	if(diaspora_is_blacklisted($handle))
		return false;

	require_once('include/channel.php');

	$sys = get_sys_channel();
	if(($sys) && ($sys['channel_id'] == $uid)) {
		$r = q("SELECT * FROM xchan where xchan_addr = '%s' and xchan_network != 'activitypub'",
			dbesc($handle)
		);
	}
	else {
		$r = q("SELECT * FROM abook left join xchan on xchan_hash = abook_xchan where xchan_addr = '%s' and abook_channel = %d and xchan_network != 'activitypub'",
			dbesc($handle),
			intval($uid)
		);
	}

	return (($r) ? Libzot::zot_record_preferred($r) : false);
}

function find_diaspora_person_by_handle($handle) {

	$person = false;
	$refresh = false;

	if(! $handle) {
		logger('find_diaspora_person_by_handle: no handle provided');
		return false;
	}

	if(diaspora_is_blacklisted($handle))
		return false;

	// Use join instead of left join in this query to make sure we only get entries where both tables are populated
	$r = q("select * from xchan join hubloc on xchan_hash = hubloc_hash where xchan_addr = '%s' and xchan_network != 'activitypub'",
		dbesc(str_replace('acct:','',$handle))
	);

	if($r) {
		$person = Libzot::zot_record_preferred($r);
		logger('find_diaspora_person_by handle: in cache ' . print_r($r,true), LOGGER_DATA, LOG_DEBUG);
		if(in_array($person['hubloc_network'], ['diaspora', 'friendica-over-diaspora']) && $person['xchan_name_date'] < datetime_convert('UTC','UTC', 'now - 1 month') || $person['xchan_pubkey'] === '') {
			logger('Updating Diaspora cached record for ' . $handle);
			$refresh = true;
		}
	}

	if((! $person) || ($refresh)) {

		// try webfinger. Make sure to distinguish between diaspora,
		// hubzilla w/diaspora protocol and friendica w/diaspora protocol.

		$result = discover_by_webbie($handle);
		if($result) {

			$r = q("select * from xchan join hubloc on xchan_hash = hubloc_hash where xchan_addr = '%s' and xchan_network != 'activitypub'",
				dbesc(str_replace('acct:','',$handle))
			);

			if($r) {
				$person = Libzot::zot_record_preferred($r);
				logger('find_diaspora_person_by handle: discovered ' . print_r($r,true), LOGGER_DATA, LOG_DEBUG);
			}
		}
	}

	return $person;
}


function get_diaspora_key($handle) {
	logger('Fetching diaspora key for: ' . $handle, LOGGER_DEBUG);
	$r = find_diaspora_person_by_handle($handle);
	return(($r) ? $r['xchan_pubkey'] : '');
}



/**
 * Some utility functions for processing the Diaspora comment virus.
 *
 */




function diaspora_sign_fields($fields,$prvkey) {

	if(! $fields)
		return '';

	$n = array();
	foreach($fields as $k => $v) {
		if($k !== 'author_signature' && $k !== 'parent_author_signature')
			$n[$k] = $v;
	}

	$s = implode(';',$n);
	logger('signing_string: ' . $s);
	return base64_encode(Crypto::sign($s,$prvkey));

}


function diaspora_verify_fields($fields,$sig,$pubkey) {

	if(! $fields)
		return false;

	$n = array();
	foreach($fields as $k => $v) {
		if($k !== 'author_signature' && $k !== 'parent_author_signature')
			$n[$k] = $v;
	}

	$s = implode(';',$n);
	logger('signing_string: ' . $s);
	return Crypto::verify($s,base64_decode($sig),$pubkey);

}

function diaspora_fields_to_xml($fields) {

	if(! $fields)
		return '';
	$s = '';
	foreach($fields as $k => $v) {
		$s .= '<' . $k . '>' . xmlify($v) . '</' . $k . '>' . "\n";
	}
	return rtrim($s);
}


function diaspora_build_relay_tags() {

	$alltags = array();

	$r = q("select * from pconfig where cat = 'diaspora' and k = 'followed_tags'");
	if($r) {
		foreach($r as $rr) {
			if(preg_match('|^a:[0-9]+:{.*}$|s',$rr['v'])) {
				$x = unserialize($rr['v']);
				if($x && is_array($x))
					$alltags = array_values(array_unique(array_merge($alltags,$x)));
			}
		}
	}
	set_config('diaspora','relay_tags',$alltags);
	// Now register to pick up any changes
	$url = "https://the-federation.info/register/" . App::get_hostname();
	$ret = z_fetch_url($url);

}


function diaspora_magic_env($channel,$msg) {

	$data        = preg_replace('/\s+/','', base64url_encode($msg));

	$keyhash     = base64url_encode(channel_reddress($channel));
	$type        = 'application/xml';
	$encoding    = 'base64url';
	$algorithm   = 'RSA-SHA256';
	$precomputed = '.YXBwbGljYXRpb24vYXRvbSt4bWw=.YmFzZTY0dXJs.UlNBLVNIQTI1Ng==';
	$signature   = base64url_encode(Crypto::sign($data . $precomputed, $channel['channel_prvkey']));

	return replace_macros(get_markup_template('magicsig.tpl','addon/diaspora'),
		[
			'$data'      => $data,
			'$encoding'  => $encoding,
			'$algorithm' => $algorithm,
			'$keyhash'   => $keyhash,
			'$signature' => $signature
		]
	);
}

function diaspora_share_unsigned(&$item,$author) {

	if(! $author) {
		$r = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($item['author_xchan'])
		);
		if($r) {
			$author = $r[0];
		}
		else {
			return;
		}
	}

	// post will come across with the owner's identity. Throw a preamble onto the post to indicate the true author.
	$item['body'] = "\n\n"
		. '[quote]'
		. '[img]' . $author['xchan_photo_s'] . '[/img]'
		. ' '
		. '[url=' . $author['xchan_url'] . '][b]' . $author['xchan_name'] . '[/b][/url]' . "\n\n"
		. $item['body']
		. '[/quote]';

}


function diaspora_build_status($item,$owner) {

	$myaddr = channel_reddress($owner);

	if($item['mid'] !== $item['parent_mid']) {
		logger('attempted to send a comment as a top-level post');
		return '';
	}

	if(($item['item_wall']) && ($item['owner_xchan'] != $item['author_xchan'])) {
		if(get_pconfig($owner['channel_id'],'diaspora','sign_unsigned')) {
			diaspora_share_unsigned($item,(($item['author']) ? $item['author'] : null));
		}
		else {
			logger('cannot sign wall-to-wall post for Diaspora');
			return '';
		}
	}

	$images = array();

	$body = ((isset($item['title'])) ? '[h3]' . $item['title'] . '[/h3]' . "\n" : '');
	$body .= ((isset($item['summary'])) ? '[h5]' . $item['summary'] . '[/h5]' . "\n" : '');
	$body .= $item['body'];

	if (strpos($body, '[/crypt]') !== false) {
		$body = preg_replace_callback("/\[crypt (.*?)\](.*?)\[\/crypt\]/ism", 'bb_parse_b64_crypt', $body);
	}

	$body = bb_to_markdown($body, [ 'diaspora' ]);
	$ev = bbtoevent($item['body']);

	$ev_obj = null;

	if($ev) {
		$ev_obj = diaspora_create_event($ev, $myaddr);
		$body = $ev_obj['summary'];
	}

	$poll = '';

	$public = (($item['item_private']) ? 'false' : 'true');

	$created = datetime_convert('UTC','UTC',$item['created'],'Y-m-d H:i:s \U\T\C');

	$created_at = datetime_convert('UTC','UTC',$item['created'],ATOM_TIME);
	$edited_at  = datetime_convert('UTC','UTC',$item['edited'],ATOM_TIME);

	if(defined('DIASPORA_V2')) {

		// common attributes

		$arr = [
			'author'     => $myaddr,
			'guid'       => $item['uuid'],
			'created_at' => $created_at,
		];

		if($edited_at > $created_at)
			$arr['edited_at'] = $edited_at;


		if($ev_obj) {
			$arr['event'] = $ev_obj;
		}

		// context specific attributes

		if((! $item['item_private']) && ($ret = diaspora_is_reshare($item['body']))) {
			$arr['root_author'] = $ret['root_handle'];
			$arr['root_guid']   = $ret['root_guid'];
			$msg = arrtoxml('reshare', $arr);
		}
		elseif((! $item['item_private']) && ($ret = diaspora_is_repeat($item))) {
			$arr['root_author'] = $ret['root_handle'];
			$arr['root_guid']   = $ret['root_guid'];
			$msg = arrtoxml('reshare', $arr);
		}
		else {
			$arr['public'] = $public;
			$arr['text']   = $body;

			if($item['app'])
				$arr['provider_display_name'] = $item['app'];

			if($item['location'] || $item['coord']) {
				//@TODO once we figure out if they will accept location and coordinates separately,
				// at present it seems you need both and fictitious locations aren't acceptable
			}

			$msg = arrtoxml('status_message', $arr);
		}
	}
	else {

		// Old style messages using templates - Detect a share element and do a reshare

		if((! $item['item_private']) && ($ret = diaspora_is_reshare($item['body']))) {
			$msg = replace_macros(get_markup_template('diaspora_reshare.tpl','addon/diaspora'),
				[
					'$root_handle' => xmlify($ret['root_handle']),
					'$root_guid' => $ret['root_guid'],
					'$guid' => $item['uuid'],
					'$handle' => xmlify($myaddr),
					'$public' => $public,
					'$created' => $created,
					'$provider' => (($item['app']) ? $item['app'] : t('$projectname'))
				]
			);
		}
		else {
			$msg = replace_macros(get_markup_template('diaspora_post.tpl','addon/diaspora'),
				[
					'$body' => xmlify($body),
					'$guid' => $item['uuid'],
					'$poll' => $poll,
					'$handle' => xmlify($myaddr),
					'$public' => $public,
					'$created' => $created,
					'$provider' => (($item['app']) ? $item['app'] : t('$projectname'))
				]
			);
		}
	}

	return $msg;
}



function get_diaspora_reshare_xml($url,$recurse = 0) {

	$x = z_fetch_url($url);
	if(! $x['success'])
		$x = z_fetch_url(str_replace('https://','http://',$url));

	if($x['success']) {
		// it is (hopefully) a magic envelope
		$basedom = parse_xml_string($x['body'],false);
		if($basedom === false) {
			logger('no xml found');
			return false;
		}

		$children = $basedom->children('http://salmon-protocol.org/ns/magic-env');
		$author_link = str_replace('acct:','',base64url_decode($children->sig[0]->attributes()->key_id[0]));
		$dom = $basedom->children(NAMESPACE_SALMON_ME);

		if($dom->provenance->data)
			$base = $dom->provenance;
		elseif($dom->env->data)
			$base = $dom->env;
		elseif($dom->data)
			$base = $dom;

		if(! $base) {
			logger('unable to locate salmon data in xml ', LOGGER_NORMAL, LOG_ERR);
			return false;
		}


		// Stash the signature away for now. We have to find their key or it won't be good for anything.
		$signature = base64url_decode($base->sig);

		$data = str_replace(array(" ","\t","\r","\n"),array("","","",""),$base->data);

		$type     = $base->data[0]->attributes()->type[0];
		$encoding = $base->encoding;
		$alg      = $base->alg;

		$signed_data = $data  . '.' . base64url_encode($type,false) . '.'
			. base64url_encode($encoding,false) . '.' . base64url_encode($alg,false);

		// decode the data
		$data = base64url_decode($data);

	   if(! $author_link) {
			logger('Could not retrieve author URI.');
			return false;
		}

		// Once we have the author URI, go to the web and try to find their public key
		// (first this will look it up locally if it is in the fcontact cache)
		// This will also convert diaspora public key from pkcs#1 to pkcs#8

		logger('Fetching key for ' . $author_link );
		$key = get_diaspora_key($author_link);

		if(! $key) {
			logger('Could not retrieve author key.', LOGGER_NORMAL, LOG_WARNING);
			return false;
		}

		$verify = Crypto::verify($signed_data,$signature,$key);

		if(! $verify) {
			logger('Message did not verify. Discarding.', LOGGER_NORMAL, LOG_ERR);
			return false;
		}

		logger('Message verified.');

		$body = $data;

	}

	logger('get_diaspora_reshare_xml: source: ' . $body, LOGGER_DEBUG);

	$oxml = parse_xml_string($body,false);

	if(! $oxml) {
		logger('get_diaspora_reshare_xml: unparseable result from ' . $url);
		return '';
	}


	$pxml = sxml2array($oxml);
	$source_xml = [ strtolower($oxml->getName()) => $pxml ];

	// $source_xml = xml2array($body,false,0,'tag');

	if(! $source_xml) {
		logger('get_diaspora_reshare_xml: unparseable result from ' . $url);
		return '';
	}

	if($source_xml) {
		if(array_key_exists('xml',$source_xml) && array_key_exists('post',$source_xml['xml']))
			$source_xml = $source_xml['xml']['post'];
	}

	if($source_xml['status_message']) {
		return $source_xml;
	}

	// see if it's a reshare of a reshare

	if($source_xml['reshare'])
		$xml = $source_xml['reshare'];
	else
		return false;

	if($xml['root_author'] && $xml['root_guid'] && $recurse < 15) {
		$orig_author = notags(unxmlify($xml['root_author']));
		$orig_guid = notags(unxmlify($xml['root_guid']));
		$source_url = 'https://' . substr($orig_author,strpos($orig_author,'@') + 1) . '/fetch/post/' . $orig_guid ;
		$y = get_diaspora_reshare_xml($source_url,$recurse + 1);
		if($y)
			return $y;
	}
	return false;
}

