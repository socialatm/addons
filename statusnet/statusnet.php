<?php
/**
 * Name: GNU social Connector
 * Description: Relay public postings to a connected GNU social account (formerly StatusNet)
 * Version: 1.0.5
 * Author: Tobias Diekershoff <http://diekershoff.homeunix.net/friendika/profile/tobias>
 * Author: Michael Vogel <https://pirati.ca/profile/heluecht>
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Lib\Queue;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

require_once('include/permissions.php');

/*   GNU social Plugin for Hubzilla
 *
 *   Author: Tobias Diekershoff
 *           tobias.diekershoff@gmx.net
 *
 *   License:3-clause BSD license
 *
 *   Configuration:
 *     After activating this addon, your user can configure their Twitter account settings
 *     from "Settings -> Feature settings".
 *
 *     Requirements: PHP5, curl [Slinky library]
 *
 *     Documentation: http://diekershoff.homeunix.net/redmine/wiki/friendikaplugin/StatusNet_Plugin
 */

/***
 * We have to alter the TwitterOAuth class a little bit to work with any GNU social/StatusNet
 * installation abroad. Basically it's only make the API path variable and be happy.
 *
 * Thank you guys for the Twitter compatible API!
 */

define('STATUSNET_DEFAULT_POLL_INTERVAL', 5); // given in minutes

require_once('library/twitteroauth.php');

class StatusNetOAuth extends TwitterOAuth {
	function get_maxlength() {
		$config = $this->get($this->host . 'statusnet/config.json');
		return $config->site->textlimit;
	}
	function accessTokenURL()  { return $this->host.'oauth/access_token'; }
	function authenticateURL() { return $this->host.'oauth/authenticate'; }
	function authorizeURL() { return $this->host.'oauth/authorize'; }
	function requestTokenURL() { return $this->host.'oauth/request_token'; }
	function __construct($apipath, $consumer_key, $consumer_secret, $oauth_token = NULL, $oauth_token_secret = NULL) {
		parent::__construct($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
		$this->host = $apipath;
	}
  /**
   * Make an HTTP request
   *
   * @return API results
   *
   * Copied here from the twitteroauth library and complemented by applying the proxy settings of Hubzilla
   */

function http($url, $method, $postfields = NULL) {
	$this->http_info = array();
	$ci = curl_init();
	/* Curl settings */
	$prx = get_config('system','proxy');
	if(strlen($prx)) {
		curl_setopt($ci, CURLOPT_HTTPPROXYTUNNEL, 1);
		curl_setopt($ci, CURLOPT_PROXY, $prx);
		$prxusr = get_config('system','proxyuser');
		if(strlen($prxusr))
			curl_setopt($ci, CURLOPT_PROXYUSERPWD, $prxusr);
	}
	curl_setopt($ci, CURLOPT_USERAGENT, $this->useragent);
	curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connecttimeout);
	curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout);
	curl_setopt($ci, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ci, CURLOPT_HTTPHEADER, array('Expect:'));
	curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, $this->ssl_verifypeer);
	curl_setopt($ci, CURLOPT_HEADERFUNCTION, array($this, 'getHeader'));
	curl_setopt($ci, CURLOPT_HEADER, FALSE);

	switch ($method) {
	  case 'POST':
		curl_setopt($ci, CURLOPT_POST, TRUE);
		if (!empty($postfields)) {
		  curl_setopt($ci, CURLOPT_POSTFIELDS, $postfields);
		}
		break;
	  case 'DELETE':
		curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'DELETE');
		if (!empty($postfields)) {
		  $url = "{$url}?{$postfields}";
		}
	}

	curl_setopt($ci, CURLOPT_URL, $url);
	$response = curl_exec($ci);
	$this->http_code = curl_getinfo($ci, CURLINFO_HTTP_CODE);
	$this->http_info = array_merge($this->http_info, curl_getinfo($ci));
	$this->url = $url;
	curl_close ($ci);
	return $response;
  }
}

function statusnet_load() {
	//  we need some hooks, for the configuration and for sending tweets
	register_hook('notifier_normal', 'addon/statusnet/statusnet.php', 'statusnet_post_hook');
	register_hook('post_local', 'addon/statusnet/statusnet.php', 'statusnet_post_local');
	register_hook('jot_networks',	'addon/statusnet/statusnet.php', 'statusnet_jot_nets');
	register_hook('cron', 'addon/statusnet/statusnet.php', 'statusnet_cron');
	register_hook('queue_deliver', 'addon/statusnet/statusnet.php', 'statusnet_queue_deliver');

	Route::register('addon/statusnet/Mod_Statusnet.php','statusnet');

	logger("loaded statusnet");
}


function statusnet_unload() {
	unregister_hook('notifier_normal', 'addon/statusnet/statusnet.php', 'statusnet_post_hook');
	unregister_hook('post_local', 'addon/statusnet/statusnet.php', 'statusnet_post_local');
	unregister_hook('jot_networks',	'addon/statusnet/statusnet.php', 'statusnet_jot_nets');
	unregister_hook('cron', 'addon/statusnet/statusnet.php', 'statusnet_cron');
	unregister_hook('queue_deliver', 'addon/statusnet/statusnet.php', 'statusnet_queue_deliver');

	Route::unregister('addon/statusnet/Mod_Statusnet.php','statusnet');
}

function statusnet_jot_nets(&$a,&$b) {
	if(! Apps::addon_app_installed(local_channel(), 'statusnet'))
		return;

	if((! local_channel()) || (! perm_is_allowed(local_channel(),'','view_stream',false)))
		return;

	$statusnet_defpost = get_pconfig(local_channel(),'statusnet','post_by_default');
	$selected = ((intval($statusnet_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="statusnet_enable"' . $selected . ' value="1" /> '
		. '<i class="fa fa-fw fa-gnu-social"></i> ' . t('Post to GNU social') . '</div>';
}

function statusnet_post_local(&$a,&$b) {
	if($b['edit'])
		return;

	if((local_channel()) && (local_channel() == $b['uid']) && (! $b['item_private'])) {

		$statusnet_post = Apps::addon_app_installed(local_channel(), 'statusnet');
		$statusnet_enable = (($statusnet_post && x($_REQUEST,'statusnet_enable')) ? intval($_REQUEST['statusnet_enable']) : 0);

		// if API is used, default to the chosen settings
		if($_REQUEST['api_source'] && intval(get_pconfig(local_channel(),'statusnet','post_by_default')))
			$statusnet_enable = 1;

	   if(! $statusnet_enable)
			return;

	   if(strlen($b['postopts']))
		   $b['postopts'] .= ',';
	   $b['postopts'] .= 'statusnet';
	}
}

if (! function_exists( 'short_link' )) {
function short_link($url) {
	require_once('library/slinky.php');
	$slinky = new Slinky( $url );
	$yourls_url = get_config('yourls','url1');
	if ($yourls_url) {
			$yourls_username = get_config('yourls','username1');
			$yourls_password = get_config('yourls', 'password1');
			$yourls_ssl = get_config('yourls', 'ssl1');
			$yourls = new Slinky_YourLS();
			$yourls->set( 'username', $yourls_username );
			$yourls->set( 'password', $yourls_password );
			$yourls->set( 'ssl', $yourls_ssl );
			$yourls->set( 'yourls-url', $yourls_url );
			$slinky->set_cascade( array( $yourls, new Slinky_UR1ca(), new Slinky_Trim(), new Slinky_IsGd(), new Slinky_TinyURL() ) );
	}
	else {
			// setup a cascade of shortening services
			// try to get a short link from these services
			// in the order ur1.ca, trim, id.gd, tinyurl
			$slinky->set_cascade( array( new Slinky_UR1ca(), new Slinky_Trim(), new Slinky_IsGd(), new Slinky_TinyURL() ) );
	}
	return $slinky->short();
} };

function statusnet_shortenmsg($b, $max_char) {
	require_once("include/bbcode.php");
	require_once("include/html2plain.php");

	// Looking for the first image
	$image = '';
	if(preg_match("/\[[zi]mg\=([0-9]*)x([0-9]*)\](.*?)\[\/[zi]mg\]/is",$b['body'],$matches))
		$image = $matches[3];

	if ($image == '')
		if(preg_match("/\[[zi]mg\](.*?)\[\/[zi]mg\]/is",$b['body'],$matches))
			$image = $matches[1];

    // @fixme for zmg
	$multipleimages = (strpos($b['body'], "[img") != strrpos($b['body'], "[img"));

	// When saved into the database the content is sent through htmlspecialchars
	// That means that we have to decode all image-urls
	$image = htmlspecialchars_decode($image);

	$body = $b["body"];
	if ($b["title"] != "")
		$body = $b["title"]."\n\n".$body;

	if (strpos($body, "[bookmark") !== false) {
		// splitting the text in two parts:
		// before and after the bookmark
		$pos = strpos($body, "[bookmark");
		$body1 = substr($body, 0, $pos);
		$body2 = substr($body, $pos);

		// Removing all quotes after the bookmark
		// they are mostly only the content after the bookmark.
		$body2 = preg_replace("/\[quote\=([^\]]*)\](.*?)\[\/quote\]/ism",'',$body2);
		$body2 = preg_replace("/\[quote\](.*?)\[\/quote\]/ism",'',$body2);
		$body = $body1.$body2;
	}

	// Add some newlines so that the message could be cut better
	$body = str_replace(array("[quote", "[bookmark", "[/bookmark]", "[/quote]"),
				array("\n[quote", "\n[bookmark", "[/bookmark]\n", "[/quote]\n"), $body);

	// remove the recycle signs and the names since they aren't helpful on twitter
	// recycle 1
	$recycle = html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8');
	$body = preg_replace( '/'.$recycle.'\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', "\n", $body);
	// recycle 2 (Test)
	$recycle = html_entity_decode("&#x25CC; ", ENT_QUOTES, 'UTF-8');
	$body = preg_replace( '/'.$recycle.'\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', "\n", $body);

	// remove the share element
	$body = preg_replace("/\[share(.*?)\](.*?)\[\/share\]/ism","\n\n$2\n\n",$body);

	// At first convert the text to html
	$html = bbcode($body, [ 'tryoembed' => false ]);

	// Then convert it to plain text
	//$msg = trim($b['title']." \n\n".html2plain($html, 0, true));
	$msg = trim(html2plain($html, 0, true));
	$msg = html_entity_decode($msg,ENT_QUOTES,'UTF-8');

	// Removing multiple newlines
	while (strpos($msg, "\n\n\n") !== false)
		$msg = str_replace("\n\n\n", "\n\n", $msg);

	// Removing multiple spaces
	while (strpos($msg, "  ") !== false)
		$msg = str_replace("  ", " ", $msg);

	$msg = str_replace('#^http','http', $msg);

	$origmsg = $msg;

	// Removing URLs
	$msg = preg_replace('/(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/i', "", $msg);

	$msg = trim($msg);

	$link = '';
	// look for bookmark-bbcode and handle it with priority
	if(preg_match("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/is",$b['body'],$matches))
		$link = $matches[1];

	$multiplelinks = (strpos($b['body'], "[bookmark") != strrpos($b['body'], "[bookmark"));

	// If there is no bookmark element then take the first link
	if ($link == '') {
		$links = collecturls($html);
		if (sizeof($links) > 0) {
			reset($links);
			$link = current($links);
		}
		$multiplelinks = (sizeof($links) > 1);
	}

	$msglink = "";
	if ($multiplelinks)
		$msglink = $b["plink"];
	else if ($link != "")
		$msglink = $link;
	else if ($multipleimages)
		$msglink = $b["plink"];
	else if ($image != "")
		$msglink = $image;

	if (($msglink == "") and strlen($msg) > $max_char)
		$msglink = $b["plink"];

	// If the message is short enough then don't modify it. (if the link exists in the original message)
	if ((strlen(trim($origmsg)) <= $max_char) AND (strpos($origmsg, $msglink) OR ($msglink == "")))
		return(array("msg"=>trim($origmsg), "image"=>""));

	if (strlen($msglink) > 20)
		$msglink = short_link($msglink);

	if (strlen(trim($msg." ".$msglink)) > $max_char) {
		$msg = substr($msg, 0, $max_char - (strlen($msglink)));
		$lastchar = substr($msg, -1);
		$msg = substr($msg, 0, -1);
		$pos = strrpos($msg, "\n");
		if ($pos > 0)
			$msg = substr($msg, 0, $pos);
		else if ($lastchar != "\n")
			$msg = substr($msg, 0, -3)."...";
	}
	$msg = str_replace("\n", " ", $msg);

	// Removing multiple spaces - again
	while (strpos($msg, "  ") !== false)
		$msg = str_replace("  ", " ", $msg);

	return(array("msg"=>trim($msg." ".$msglink), "image"=>$image));
}

function statusnet_post_hook(&$a,&$b) {

	/**
	 * Post to statusnet
	 */

	if(! strstr($b['postopts'],'statusnet')) {
		logger('crosspost not enabled.');
		return;
	}

	if((! is_item_normal($b)) || $b['item_private'] || ($b['created'] !== $b['edited'])) {
		logger('not a usable post. ' . print_r($b,true),LOGGER_DEBUG);
		return;
	}

	if(! perm_is_allowed($b['uid'],'','view_stream',false)) {
		logger('permissions prevent crossposting.',LOGGER_DEBUG);
		return;
	}


	if($b['parent'] != $b['id']) {
		logger('not a top level post.', LOGGER_DEBUG);
		return;
	}

	// if posts comes from statusnet don't send it back
	if($b['app'] == "StatusNet") {
		logger('potential recursion. Crosspost ignored.');
		return;
	}

	logger('statusnet post invoked');

	load_pconfig($b['uid'], 'statusnet');

	$api	 = get_pconfig($b['uid'], 'statusnet', 'baseapi');
	$ckey	= get_pconfig($b['uid'], 'statusnet', 'consumerkey');
	$csecret = get_pconfig($b['uid'], 'statusnet', 'consumersecret');
	$otoken  = get_pconfig($b['uid'], 'statusnet', 'oauthtoken');
	$osecret = get_pconfig($b['uid'], 'statusnet', 'oauthsecret');
	$intelligent_shortening = get_pconfig($b['uid'], 'statusnet', 'intelligent_shortening');

	// Global setting overrides this
	if (get_config('statusnet','intelligent_shortening'))
		$intelligent_shortening = get_config('statusnet','intelligent_shortening');

	if($ckey && $csecret && $otoken && $osecret) {

		require_once('include/bbcode.php');
		$dent = new StatusNetOAuth($api,$ckey,$csecret,$otoken,$osecret);
				$max_char = $dent->get_maxlength(); // max. length for a dent
				// we will only work with up to two times the length of the dent
				// we can later send to GNU social. This way we can "gain" some
				// information during shortening of potential links but do not
				// shorten all the links in a 200000 character long essay.

		$tempfile = "";
		$intelligent_shortening = get_config('statusnet','intelligent_shortening');
		if (!$intelligent_shortening) {
					if (! $b['title']=='') {
				$tmp = $b['title'].": \n".$b['body'];
	//					$tmp = substr($tmp, 0, 4*$max_char);
					} else {
						$tmp = $b['body']; // substr($b['body'], 0, 3*$max_char);
					}
					// if [url=bla][img]blub.png[/img][/url] get blub.png
					$tmp = preg_replace( '/\[url\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\]\[img\](\\w+.*?)\\[\\/img\]\\[\\/url\]/i', '$2', $tmp);
					$tmp = preg_replace( '/\[zrl\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\]\[zmg\](\\w+.*?)\\[\\/zmg\]\\[\\/zrl\]/i', '$2', $tmp);
					// preserve links to images, videos and audios
					$tmp = preg_replace( '/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism', '$3', $tmp);
					$tmp = preg_replace( '/\[\\/?img(\\s+.*?\]|\])/i', '', $tmp);
					$tmp = preg_replace( '/\[zmg\=([0-9]*)x([0-9]*)\](.*?)\[\/zmg\]/ism', '$3', $tmp);
					$tmp = preg_replace( '/\[\\/?zmg(\\s+.*?\]|\])/i', '', $tmp);
					$tmp = preg_replace( '/\[\\/?video(\\s+.*?\]|\])/i', '', $tmp);
					$tmp = preg_replace( '/\[\\/?audio(\\s+.*?\]|\])/i', '', $tmp);
					$linksenabled = get_pconfig($b['uid'],'statusnet','post_taglinks');
					// if a #tag is linked, don't send the [url] over to SN
					// that is, don't send if the option is not set in the
					// connector settings
					if ($linksenabled=='0') {
				// #-tags
				$tmp = preg_replace( '/#\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', '#$2', $tmp);
				// @-mentions
				$tmp = preg_replace( '/@\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', '@$2', $tmp);
                // #-tags
				$tmp = preg_replace( '/#\[zrl\=(\w+.*?)\](\w+.*?)\[\/zrl\]/i', '#$2', $tmp);
				// @-mentions
				$tmp = preg_replace( '/@\[zrl\=(\w+.*?)\](\w+.*?)\[\/zrl\]/i', '@$2', $tmp);
				// recycle 1

				$recycle = html_entity_decode("&#x2672; ", ENT_QUOTES, 'UTF-8');
				$tmp = preg_replace( '/'.$recycle.'\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', $recycle.'$2', $tmp);
				// recycle 2 (test)
				$recycle = html_entity_decode("&#x25CC; ", ENT_QUOTES, 'UTF-8');
				$tmp = preg_replace( '/'.$recycle.'\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', $recycle.'$2', $tmp);
					}
					// preserve links to webpages
					$tmp = preg_replace( '/\[url\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\](\w+.*?)\[\/url\]/i', '$2 $1', $tmp);
					$tmp = preg_replace( '/\[zrl\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\](\w+.*?)\[\/zrl\]/i', '$2 $1', $tmp);
					// find all http or https links in the body of the entry and
					// apply the shortener if the link is longer then 20 characters
					if (( strlen($tmp)>$max_char ) && ( $max_char > 0 )) {
						preg_match_all ( '/(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/i', $tmp, $allurls  );
						foreach ($allurls as $url) {
							foreach ($url as $u) {
								if (strlen($u)>20) {
									$sl = short_link($u);
									$tmp = str_replace( $u, $sl, $tmp );
								}
							}
						}
					}
					// ok, all the links we want to send out are save, now strip
					// away the remaining bbcode

			$msg = bbcode($tmp, [ 'tryoembed' => false, 'cache' => true ]);

			$msg = str_replace(array('<br>','<br />'),"\n",$msg);
			$msg = strip_tags($msg);

			// quotes not working - let's try this
			$msg = html_entity_decode($msg);

			if (( strlen($msg) > $max_char) && $max_char > 0) {
				$shortlink = short_link( $b['plink'] );
				// the new message will be shortened such that "... $shortlink"
				// will fit into the character limit
				$msg = nl2br(substr($msg, 0, $max_char-strlen($shortlink)-4));
							$msg = str_replace(array('<br>','<br />'),' ',$msg);
							$e = explode(' ', $msg);
							//  remove the last word from the cut down message to
							//  avoid sending cut words to the MicroBlog
							array_pop($e);
							$msg = implode(' ', $e);
				$msg .= '... ' . $shortlink;
			}

			$msg = trim($msg);
			$postdata = array('status' => $msg);
		} else {
			$msgarr = statusnet_shortenmsg($b, $max_char);
			$msg = $msgarr["msg"];
			$image = $msgarr["image"];
			if ($image != "") {
				$x = z_fetch_url($image,true,0,array('novalidate' => true));
				if($x['success']) {
					$imagedata = $x['body'];
					$tempfile = tempnam(get_config("system","temppath"), "upload");
					file_put_contents($tempfile, $imagedata);
					$postdata = array("status"=>$msg, "media"=>"@".$tempfile);
				}
			} else
				$postdata = array("status"=>$msg);
		}

		// and now dent it :-)
		if(strlen($msg)) {
			$result = $dent->post('statuses/update', $postdata);
			logger('statusnet_post send, result: ' . print_r($result, true).
				   "\nmessage: ".$msg, LOGGER_DEBUG);
			logger("Original post: ".print_r($b, true)."\nPost Data: ".print_r($postdata, true), LOGGER_DEBUG);
			if ($result->error) {
				logger('Send to GNU social failed: queued."' . $result->error . '"');
				// @fixme - unable to queue media uploads
				if(! $image) {
					Queue::insert(array(
						'hash' => random_string(),
						'account_id' => $b['aid'],
						'channel_id' => $b['uid'],
						'driver'     => 'statusnet',
						'posturl'    => $api,
						'msg'        => $msg
					));
				}
			}
		}
		if ($tempfile != "")
			unlink($tempfile);
	}
}


function statusnet_queue_deliver(&$a,&$b) {

	$outq = $b['outq'];

	if($outq['outq_driver'] !== 'statusnet')
		return;

	$ckey	 = get_pconfig($outq['outq_channel'], 'statusnet', 'consumerkey');
	$csecret = get_pconfig($outq['outq_channel'], 'statusnet', 'consumersecret');
	$otoken  = get_pconfig($outq['outq_channel'], 'statusnet', 'oauthtoken');
	$osecret = get_pconfig($outq['outq_channel'], 'statusnet', 'oauthsecret');

	if($ckey && $csecret && $otoken && $osecret) {
		$dent = new StatusNetOAuth($api,$ckey,$csecret,$otoken,$osecret);
		if($outq['outq_msg']) {
			$result = $dent->post('statuses/update', array('status' => $outq['outq_msg']));
			if ($result->error) {
				logger('Send to GNU social failed: "' . $result->error . '"');
				Queue::update($outq['outq_hash'], 10);
			}
			else {
				logger('statusnet_post send, result: ' . print_r($result, true)
					. "\nmessage: " . $outq['outq_msg'], LOGGER_DEBUG);
				Queue::remove($outq['outq_hash']);
			}
		}
	}

	$b['handled'] = true;
}

function statusnet_plugin_admin_post(){

	$sites = array();

	foreach($_POST['sitename'] as $id=>$sitename){
		$sitename=trim($sitename);
		$apiurl=trim($_POST['apiurl'][$id]);
		$secret=trim($_POST['secret'][$id]);
		$key=trim($_POST['key'][$id]);
				$applicationname = ((x($_POST, 'applicationname')) ? notags(trim($_POST['applicationname'][$id])):'');
		if ($sitename!="" &&
			$apiurl!="" &&
			$secret!="" &&
			$key!="" &&
			!x($_POST['delete'][$id])){

				$sites[] = Array(
					'sitename' => $sitename,
					'apiurl' => $apiurl,
					'consumersecret' => $secret,
					'consumerkey' => $key,
										'applicationname' => $applicationname
				);
		}
	}

	$sites = set_config('statusnet','sites', $sites);

}

function statusnet_plugin_admin(&$o){

	$sites = get_config('statusnet','sites');
	$sitesform=array();
	if (is_array($sites)){
		foreach($sites as $id=>$s){
			$sitesform[] = Array(
				'sitename' => Array("sitename[$id]", "Site name", $s['sitename'], ""),
				'apiurl' => Array("apiurl[$id]", "Api url", $s['apiurl'], ""),
				'secret' => Array("secret[$id]", "Secret", $s['consumersecret'], ""),
				'key' => Array("key[$id]", "Key", $s['consumerkey'], ""),
				'applicationname' => Array("applicationname[$id]", "Application name", $s['applicationname'], ""),
				'delete' => Array("delete[$id]", "Delete", False , "Check to delete this preset"),
			);
		}
	}
	/* empty form to add new site */
	$id++;
	$sitesform[] = Array(
		'sitename' => Array("sitename[$id]", t("Site name"), "", ""),
		'apiurl' => Array("apiurl[$id]", t("API URL"), "", ""),
		'secret' => Array("secret[$id]", t("Consumer Secret"), "", ""),
		'key' => Array("key[$id]", t("Consumer Key"), "", ""),
		'applicationname' => Array("applicationname[$id]", t("Application name"), "", ""),
	);

	$t = get_markup_template( "admin.tpl", "addon/statusnet/" );
	$o = replace_macros($t, array(
		'$submit' => t('Submit'),
		'$sites' => $sitesform,
	));
}

function statusnet_cron($a,$b) {
	$last = get_config('statusnet','last_poll');

	$poll_interval = intval(get_config('statusnet','poll_interval'));
	if(! $poll_interval)
		$poll_interval = STATUSNET_DEFAULT_POLL_INTERVAL;

	if($last) {
		$next = $last + ($poll_interval * 60);
		if($next > time()) {
			logger('statusnet: poll intervall not reached');
			return;
		}
	}
	logger('statusnet: cron_start', LOGGER_DEBUG);

	$rand = db_getfunc('rand');

	$r = q("SELECT * FROM pconfig WHERE cat = 'statusnet' AND k = 'mirror_posts' AND v = '1' ORDER BY $rand ");
	if(count($r)) {
		foreach($r as $rr) {
			logger('statusnet: fetching for user '.$rr['uid']);
			statusnet_fetchtimeline($a, $rr['uid']);
		}
	}

	logger('statusnet: cron_end', LOGGER_DEBUG);

	set_config('statusnet','last_poll', time());
}

function statusnet_fetchtimeline($a, $uid) {
	$ckey	= get_pconfig($uid, 'statusnet', 'consumerkey');
	$csecret = get_pconfig($uid, 'statusnet', 'consumersecret');
	$api	 = get_pconfig($uid, 'statusnet', 'baseapi');
	$otoken  = get_pconfig($uid, 'statusnet', 'oauthtoken');
	$osecret = get_pconfig($uid, 'statusnet', 'oauthsecret');
	$lastid  = get_pconfig($uid, 'statusnet', 'lastid');

		//  get the application name for the SN app
		//  1st try personal config, then system config and fallback to the
		//  hostname of the node if neither one is set.
		$application_name  = get_pconfig( $uid, 'statusnet', 'application_name');
		if ($application_name == "")
			$application_name  = get_config('statusnet', 'application_name');
	if ($application_name == "")
		$application_name = App::get_hostname();

	$connection = new StatusNetOAuth($api, $ckey,$csecret,$otoken,$osecret);

	$parameters = array("exclude_replies" => true, "trim_user" => true, "contributor_details" => false, "include_rts" => false);

	$first_time = ($lastid == "");

	if ($lastid <> "")
		$parameters["since_id"] = $lastid;

	$items = $connection->get('statuses/user_timeline', $parameters);

	if (!is_array($items))
		return;

	$posts = array_reverse($items);

		if (count($posts)) {
			foreach ($posts as $post) {
		if ($post->id > $lastid)
			$lastid = $post->id;

		if ($first_time)
			continue;

		if (is_object($post->retweeted_status))
			continue;

		if ($post->in_reply_to_status_id != "")
			continue;

		if (!strpos($post->source, $application_name)) {

			$_REQUEST["type"] = "wall";
			$_REQUEST["api_source"] = true;
			$_REQUEST["profile_uid"] = $uid;
			$_REQUEST["source"] = "StatusNet";

			//$_REQUEST["date"] = $post->created_at;

			$_REQUEST["body"] = $post->text;
			if (is_string($post->place->name))
				$_REQUEST["location"] = $post->place->name;

			if (is_string($post->place->full_name))
				$_REQUEST["location"] = $post->place->full_name;

			if (is_array($post->geo->coordinates))
				$_REQUEST["coord"] = $post->geo->coordinates[0]." ".$post->geo->coordinates[1];

			if (is_array($post->coordinates->coordinates))
				$_REQUEST["coord"] = $post->coordinates->coordinates[1]." ".$post->coordinates->coordinates[0];

			//print_r($_REQUEST);
			if ($_REQUEST["body"] != "") {
				logger('statusnet: posting for user '.$uid);
				$mod = new Zotlabs\Module\Item();
				$mod->post();

			}
				}
			}
	}
	set_pconfig($uid, 'statusnet', 'lastid', $lastid);
}

