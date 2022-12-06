<?php

use Zotlabs\Lib\Apps;
use Zotlabs\Lib\Crypto;

require_once('addon/diaspora/Receiver.php');


function diaspora_dispatch_public($msg) {

	$sys_disabled = false;

	if(get_config('system','disable_discover_tab') || get_config('system','disable_diaspora_discover_tab')) {
		$sys_disabled = true;
	}
	$sys = (($sys_disabled) ? null : get_sys_channel());

	if($sys) {
		$sys['system'] = true;
	}

	$r = [];

	if(get_config('diaspora', 'delivery_try_all')) {
		// Attempt delivery to anybody who has the 'Diaspora Protocol' app installed
		// This can be resource intensive on hubs with many channels
		$app_id = hash('whirlpool','Diaspora Protocol');
		$r = q("SELECT * from channel where channel_id in ( SELECT app_channel from app where app_channel != 0 and app_id = '%s' ) and channel_removed = 0 ",
			dbesc($app_id)
		);
	}
	else {
		if(isset($msg['msg']['parent_guid'])) {
			// If we have a parent, attempt delivery to anybody who owns the parent
			$r = q("SELECT * FROM channel WHERE channel_id IN ( SELECT uid FROM item WHERE uuid = '%s' ) AND channel_removed = 0",
				dbesc($msg['msg']['parent_guid'])
			);
		}
		else {
			// Attempt delivery to anybody who is connected with the sender
			$r = q("SELECT * from channel where channel_id in ( SELECT abook_channel from abook left join xchan on abook_xchan = xchan_hash WHERE xchan_network = 'diaspora' and xchan_hash = '%s') and channel_removed = 0",
				dbesc($msg['author'])
			);
		}
	}

	if($sys) {
		$r = array_merge($r,[$sys]);
	}

	if($r) {
		foreach($r as $rr) {
			logger('diaspora_public: delivering to: ' . $rr['channel_name'] . ' (' . $rr['channel_address'] . ') ');
			diaspora_dispatch($rr,$msg);
		}
	}
	else {
		logger('diaspora_public: no subscribers');
	}

}



function diaspora_dispatch($importer, $msg, $force = false) {

	$ret = 0;

	if(! array_key_exists('system',$importer))
		$importer['system'] = false;

	if(! array_key_exists('public',$msg))
		$msg['public'] = 0;

	$allowed = (($importer['system']) ? 1 : Apps::addon_app_installed($importer['channel_id'], 'diaspora'));

	if(! intval($allowed)) {
		logger('mod-diaspora: disallowed for channel ' . $importer['channel_name']);
		return;
	}


	switch($msg['msg_type']) {

		case 'request':
		case 'contact':
			$fn = 'request';
			break;
		case 'status_message':
			$fn = 'post';
			break;
		case 'profile':
			$fn = 'profile';
			break;
		case 'comment':
			$fn = 'comment';
			break;
		case 'like':
			$fn = 'like';
			break;
		case 'reshare':
			$fn = 'reshare';
			break;
		case 'retraction':
		case 'signed_retraction':
		case 'relayable_retraction':
			$fn = 'retraction';
			break;
		case 'photo':
			$fn = 'photo';
			break;
		case 'conversation':
			$fn = 'conversation';
			break;
		case 'message':
			$fn = 'message';
			break;
		case 'participation':
			$fn = 'participation';
			break;
		case 'event':
			$fn = 'event';
			break;
		case 'event_participation':
			$fn = 'event_participation';
			break;
		case 'account_deletion':
			$fn = 'account_deletion';
			break;
		case 'account_migration':
			$fn = 'account_migration';
			break;
		case 'poll_participation':
			$fn = 'poll_participation';
			break;
		default:
			logger('diaspora_dispatch: unknown message type: ' . print_r($msg['msg_type'],true));
			break;
	}

	$rec = new Diaspora_Receiver($importer, $msg, $force);
	$ret = $rec->$fn();

	return $ret;
}


function diaspora_is_blacklisted($s) {

	if(! check_siteallowed($s)) {
		logger('blacklisted site: ' . $s);
		return true;
	}

	if(! check_channelallowed($s)) {
		logger('blacklisted channel: ' . $s);
		return true;
	}

	return false;
}


/**
 *
 * diaspora_decode($importer,$xml,$format)
 *   array $importer -> from user table
 *   string $xml -> urldecoded Diaspora salmon
 *   string $format 'legacy', 'salmon', or 'json'
 *
 * Returns array
 * 'message' -> decoded Diaspora XML message
 * 'author' -> author diaspora handle
 * 'key' -> author public key (converted to pkcs#8)
 * 'format' -> 'legacy', 'json', or 'salmon'
 *
 * Author and key are used elsewhere to save a lookup for verifying replies and likes
 */


function diaspora_decode($importer,$xml,$format) {

	$public = false;

	if($format === 'json') {
		if(! $importer['channel_id']) {
			logger('Private encrypted message arrived on public channel.');
			http_status_exit(400);
		}
		$json = json_decode($xml,true);
		if($json['aes_key']) {
			$key_bundle = '';
			$result = openssl_private_decrypt(base64_decode($json['aes_key']),$key_bundle,$importer['channel_prvkey']);
			if(! $result) {
				logger('decrypting key_bundle for ' . $importer['channel_address'] . ' failed: ' . $json['aes_key'],LOGGER_NORMAL, LOG_ERR);
				http_status_exit(400);
			}
			$jkey = json_decode($key_bundle,true);
			$xml = AES256CBC_decrypt(base64_decode($json['encrypted_magic_envelope']),base64_decode($jkey['key']),base64_decode($jkey['iv']));
			if(! $xml) {
				logger('decrypting magic_envelope for ' . $importer['channel_address'] . ' failed: ' . $json['aes_key'],LOGGER_NORMAL, LOG_ERR);
				http_status_exit(400);
			}
		}
	}

	$basedom = parse_xml_string($xml,false);

	if($basedom === false) {
		logger('unparseable xml');
		http_status_exit(400);
	}

	if($format !== 'legacy') {
		$children = $basedom->children('http://salmon-protocol.org/ns/magic-env');
		$public = true;
		if($children->sig && $children->sig[0]->attributes() && $children->sig[0]->attributes()->key_id) {
			$author_link = str_replace('acct:','',base64url_decode($children->sig[0]->attributes()->key_id[0]));
		}
		else {
			$author_link = '';
		}

		/**
			SimpleXMLElement Object
			(
			    [encoding] => base64url
			    [alg] => RSA-SHA256
			    [data] => ((base64url-encoded payload message))
			    [sig] => ((the RSA-SHA256 signature of the above data))
			)
		**/
	}
	else {

		$children = $basedom->children('https://joindiaspora.com/protocol');

		if($children->header) {
			$public = true;
			$author_link = str_replace('acct:','',$children->header->author_id);
		}
		else {

			if(! $importer['channel_id']) {
				logger('Private encrypted message arrived on public channel.');
				http_status_exit(400);
			}

			$encrypted_header = json_decode(base64_decode($children->encrypted_header));
			$encrypted_aes_key_bundle = base64_decode($encrypted_header->aes_key);
			$ciphertext = base64_decode($encrypted_header->ciphertext);

			$outer_key_bundle = '';
			openssl_private_decrypt($encrypted_aes_key_bundle,$outer_key_bundle,$importer['channel_prvkey']);

			$j_outer_key_bundle = json_decode($outer_key_bundle);

			$outer_iv = base64_decode($j_outer_key_bundle->iv);
			$outer_key = base64_decode($j_outer_key_bundle->key);

			$decrypted = AES256CBC_decrypt($ciphertext,$outer_key,$outer_iv);

			/**
			 * $decrypted now contains something like
			 *
			 *  <decrypted_header>
			 *	 <iv>8e+G2+ET8l5BPuW0sVTnQw==</iv>
			 *	 <aes_key>UvSMb4puPeB14STkcDWq+4QE302Edu15oaprAQSkLKU=</aes_key>
			 ***** OBSOLETE
			 *	 <author>
			 *	   <name>Ryan Hughes</name>
			 *	   <uri>acct:galaxor@diaspora.pirateship.org</uri>
			 *	 </author>
			 ***** CURRENT/LEGACY
			 *	 <author_id>galaxor@diaspora.pirateship.org</author_id>
			 ***** END DIFFS
			 *  </decrypted_header>
			 */

			logger('decrypted: ' . $decrypted, LOGGER_DATA);
			$idom = parse_xml_string($decrypted,false);
			if($idom === false) {
				logger('failed to parse decrypted content');
				http_status_exit(400);
			}

			$inner_iv = base64_decode($idom->iv);
			$inner_aes_key = base64_decode($idom->aes_key);

			$author_link = str_replace('acct:','',$idom->author_id);
		}
	}

	$dom = $basedom->children(NAMESPACE_SALMON_ME);

	// figure out where in the DOM tree our data is hiding

	if($dom->provenance->data)
		$base = $dom->provenance;
	elseif($dom->env->data)
		$base = $dom->env;
	elseif($dom->data)
		$base = $dom;

	if(! $base) {
		logger('mod-diaspora: unable to locate salmon data in xml ', LOGGER_NORMAL, LOG_ERR);
		http_status_exit(400);
	}


	// Stash the signature away for now. We have to find their key or it won't be good for anything.
	$signature = base64url_decode($base->sig);

	// unpack the  data

	// strip whitespace so our data element will return to one big base64 blob
	$data = str_replace(array(" ","\t","\r","\n"),array("","","",""),$base->data);

	// stash away some other stuff for later

	$type     = $base->data[0]->attributes()->type[0];
	$encoding = $base->encoding;
	$alg      = $base->alg;

	$signed_data = $data  . '.' . base64url_encode($type,false) . '.' . base64url_encode($encoding,false) . '.' . base64url_encode($alg,false);


	// decode the data
	$data = base64url_decode($data);

	if(($format === 'legacy') && (! $public)) {
		// Decode the encrypted blob
		$final_msg = AES256CBC_decrypt(base64_decode($data),$inner_aes_key,$inner_iv);
	}
	else {
		$final_msg = $data;
	}

	if(! $author_link) {
		logger('mod-diaspora: Could not retrieve author URI.');
		http_status_exit(400);
	}

	// Once we have the author URI, go to the web and try to find their public key
	// (first this will look it up locally if it is in the fcontact cache)
	// This will also convert diaspora public key from pkcs#1 to pkcs#8

	logger('mod-diaspora: Fetching key for ' . $author_link );
 	$key = get_diaspora_key($author_link);

	if(! $key) {
		logger('mod-diaspora: Could not retrieve author key.', LOGGER_NORMAL, LOG_WARNING);
		http_status_exit(400);
	}

	$verify = Crypto::verify($signed_data,$signature,$key);

	if(! $verify) {
		logger('mod-diaspora: Message did not verify. Discarding.', LOGGER_NORMAL, LOG_ERR);
		http_status_exit(400);
	}

	logger('mod-diaspora: Message verified.');

	return array('message' => $final_msg, 'author' => $author_link, 'key' => $key, 'signature' => base64url_encode($signature), 'format' => $format);

}

