<?php

namespace Zotlabs\Module;


/**
 * Diaspora endpoint
 */

use App;
use Zotlabs\Web\Controller;

require_once('include/crypto.php');


class Receive extends Controller {

	function post() {

		$public = false;
		$importer = null;

		logger('diaspora_receive: ' . print_r(App::$argv, true), LOGGER_DEBUG, LOG_INFO);

		if((argc() == 2) && (argv(1) === 'public')) {
			$public = true;
		}
		else {

			if(argc() != 3 || argv(1) !== 'users')
				http_status_exit(500);

			$guid = argv(2);

			// So that the Diaspora GUID will work with nomadic identity, we append
			// the hostname but remove any periods so that it doesn't mess up URLs.
			// (This was an occasional issue with message_ids that include the hostname,
			// and we learned from that experience).
			// Without the hostname bit the Diaspora side would not be able to share
			// with two channels which have the same GUID (e.g. channel clones). In this step
			// we're stripping the hostname part which Diaspora thinks is our GUID so
			// that we can locate our channel by the channel_guid. On our network,
			// channel clones have the same GUID even if they are on different sites.

			$hn = str_replace('.','',App::get_hostname());
			if(($x = strpos($guid,$hn)) > 0)
				$guid = substr($guid,0,$x);

			$r = q("SELECT * FROM channel left join xchan on channel_hash = xchan_hash WHERE channel_guid = '%s' AND channel_removed = 0 LIMIT 1",
				dbesc($guid)
			);

			if(! $r)
				http_status_exit(500);

			$importer = $r[0];
		}

		logger('mod-diaspora: receiving post', LOGGER_DEBUG, LOG_INFO);

		// Diaspora traditionally urlencodes or base64 encodes things a superfluous number of times.
		// The legacy format is double url-encoded for an unknown reason. At the time of this writing
		// the new formats have not yet been seen crossing the wire, so we're being proactive and decoding
		// until we see something reasonable. Once we know how many times we are expected to decode we can
		// refine this.

		if(isset($_POST['xml'])) {
			$xml = ltrim($_POST['xml']);
			$format = 'legacy';
			// PHP performed the first decode when populating the $_POST variable.
			// Here we do the second - which has been required since 2010-2011.
			if(substr($xml,0,1) !== '<')
				$xml = ltrim(urldecode($xml));
		}
		else {
			$xml = ltrim(file_get_contents('php://input'));
			$format = 'bis';
			$decode_counter = 0;
			while($decode_counter < 3) {
				if((substr($xml,0,1) === '{') || (substr($xml,0,1) === '<'))
					break;
				$decode_counter ++;
				$xml = ltrim(urldecode($xml));
			}
			logger('decode_counter: ' . $decode_counter, LOGGER_DEBUG, LOG_INFO);
		}

		if($format === 'bis') {
			switch(substr($xml,0,1)) {
				case '{':
					$format = 'json';
					break;
				case '<':
					$format = 'salmon';
					break;
				default:
					break;
			}
		}

		logger('diaspora salmon format: ' . $format, LOGGER_DEBUG, LOG_INFO);

		logger('mod-diaspora: new salmon ' . $xml, LOGGER_DATA);

		if((! $xml) || ($format === 'bis'))
			http_status_exit(500);

		logger('mod-diaspora: message is okay', LOGGER_DEBUG);

		$msg = diaspora_decode($importer,$xml,$format);

		logger('mod-diaspora: decoded', LOGGER_DEBUG);

		logger('mod-diaspora: decoded msg: ' . print_r($msg,true), LOGGER_DATA);

		if(! is_array($msg))
			http_status_exit(500);

		$host = substr($msg['author'],strpos($msg['author'],'@')+1);
		$ssl = ((array_key_exists('HTTPS',$_SERVER) && strtolower($_SERVER['HTTPS']) === 'on') ? true : false);
		$url = (($ssl) ? 'https://' : 'http://') . $host;

		q("UPDATE site SET site_dead = 0, site_update = '%s' WHERE site_type = %d AND site_url = '%s' AND site_update < %s - INTERVAL %s",
			dbesc(datetime_convert()),
			intval(SITE_TYPE_NOTZOT),
			dbesc($url),
			db_utcnow(),
			db_quoteinterval('1 DAY')
		);

		/**
		 * xml2array is based on libxml(/expat?) which loses whitespace in Cyrillic text presented as HTML entities
		 * Here is a test string. The first space character is nearly always lost in parsing when included in an XML tagged structure.
		 * Spaces after punctuation seem to be preserved. We've ruled out character encoding/charset specification issues but
		 * clearly there is a character encoding/charset issue involved. When called with a custom libxml parser, the content is mangled
		 * before it ever reaches a parsing callback.
		 * &#x41D;&#x430;&#x447;&#x430;&#x43B; &#x437;&#x430;&#x43C;&#x435;&#x447;&#x430;&#x442;&#x44C;, &#x447;&#x442;&#x43E; &#x43F;&#x43E;&#x440;&#x43D;&#x43E;&#x441;&#x43F;&#x430;&#x43C; &#x434;&#x43E;&#x431;&#x440;&#x430;&#x43B;&#x441;&#x44F; &#x434;&#x43E; &#x444;&#x435;&#x434;&#x435;&#x440;&#x430;&#x442;&#x438;&#x432;&#x43D;&#x44B;&#x445; &#x441;&#x435;&#x442;&#x435;&#x439;. &#x41D;&#x430;&#x43F;&#x440;&#x438;&#x43C;&#x435;&#x440;, &#x43A; &#x43D;&#x435;&#x441;&#x43A;&#x43E;&#x43B;&#x44C;&#x43A;&#x438;&#x43C; &#x43F;&#x43E;&#x441;&#x442;&#x430;&#x43C;
		 * parse_xml_string() uses simplexml and doesn't have this issue, but cannot be easily used with XML that provides more structure
		 * (attributes and namespaces). Therefore we can't easily use this to parse salmon magic envelopes. At this higher level, we can
		 * use xml2array() however because the unicode content has been base64'd and doesn't trigger the bug.
		 * @FIXME
		 * We're using parse_xml_string() with some additional hacks to make the output resemble the simple case of xml2array()
		 * Ideally we^H^Hyou should figure out what's wrong with libxml and figure out how to get the correct output from xml2array()
		 * or get the root cause fixed upstream in libxml and php. Change here and in diaspora/util.php
		 */

		$oxml = parse_xml_string($msg['message'],false);

		if($oxml)
			$msg['msg_type'] = strtolower($oxml->getName());

		$pxml = sxml2array($oxml);

		if($pxml)
			$msg['msg'] = $pxml;

		if($public)
			$msg['public'] = true;

		$msg['msg_author_key'] = get_diaspora_key($msg['msg']['author']);

		logger('mod-diaspora: dispatching', LOGGER_DEBUG);

		$ret = 0;
		if($public)
			diaspora_dispatch_public($msg);
		else
			$ret = diaspora_dispatch($importer,$msg);

		http_status_exit(($ret) ? $ret : 200);

	}
}

