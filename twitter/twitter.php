<?php
/**
 * Name: Twitter Connector
 * Description: Relay public postings to a connected Twitter account
 * Version: 1.3.3
 * Author: Tobias Diekershoff <https://f.diekershoff.de/profile/tobias>
 * Author: Michael Vogel <https://pirati.ca/profile/heluecht>
 * Author: Mike Macgirvin <https://zothub.com/channel/mike>
 * Maintainer: Max Kostikov <https://tiksi.net/channel/kostikov>
 *
 * Copyright (c) 2011-2013 Tobias Diekershoff, Michael Vogel
 * Copyright (c) 2013-2021 Hubzilla Developers
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *    * Redistributions of source code must retain the above copyright notice,
 *     this list of conditions and the following disclaimer.
 *    * Redistributions in binary form must reproduce the above
 *    * copyright notice, this list of conditions and the following disclaimer in
 *      the documentation and/or other materials provided with the distribution.
 *    * Neither the name of the <organization> nor the names of its contributors
 *      may be used to endorse or promote products derived from this software
 *      without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
 * OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */
 
/*   Twitter Plugin for Hubzilla
 *
 *   Author: Tobias Diekershoff
 *           tobias.diekershoff@gmx.net
 *
 *   License:3-clause BSD license
 *
 *   Configuration:
 *     To use this plugin you need a OAuth Consumer key pair (key & secret)
 *     you can get it from Twitter at https://twitter.com/apps
 *
 *     Register your Hubzilla site as "Client" application with "Read & Write" access
 *     we do not need "Twitter as login". When you've registered the app you get the
 *     OAuth Consumer key and secret pair for your application/site.
 *
 *     Activate the plugin from the plugins section of your admin panel.  When you have
 *     done so, add your consumer key and consumer secret in the Plugin Features section 
 *     of the admin page. A link to this section will appear on the sidebar of the admin page
 *     called 'twitter'.
 *
 *   Alternatively: (old way - may not work any more)
 *     Add this key pair to your global .htconfig.php or use the admin panel.
 *
 *     App::$config['twitter']['consumerkey'] = 'your consumer_key here';
 *     App::$config['twitter']['consumersecret'] = 'your consumer_secret here';
 *
 *     Requirements: PHP5, curl [Slinky library]
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

define('TWITTER_DEFAULT_POLL_INTERVAL', 5); // given in minutes

function twitter_load() {
	//  we need some hooks, for the configuration and for sending tweets
	register_hook('post_local', 'addon/twitter/twitter.php', 'twitter_post_local');
	register_hook('notifier_normal', 'addon/twitter/twitter.php', 'twitter_post_hook');
	register_hook('jot_networks', 'addon/twitter/twitter.php', 'twitter_jot_nets');

	Route::register('addon/twitter/Mod_Twitter.php', 'twitter');

	logger("installed twitter");
}


function twitter_unload() {
	unregister_hook('post_local', 'addon/twitter/twitter.php', 'twitter_post_local');
	unregister_hook('notifier_normal', 'addon/twitter/twitter.php', 'twitter_post_hook');
	unregister_hook('jot_networks', 'addon/twitter/twitter.php', 'twitter_jot_nets');

	Route::unregister('addon/twitter/Mod_Twitter.php', 'twitter');

	logger("uninstalled twitter");
}


function twitter_jot_nets(&$a,&$b) {
	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(), 'twitter'))
		return;

	if(! perm_is_allowed(local_channel(),'','view_stream',false))
		return;

	$tw_defpost = get_pconfig(local_channel(),'twitter','post_by_default');
	$selected = ((intval($tw_defpost) == 1) ? ' checked="checked" ' : '');
	$b .= '<div class="profile-jot-net"><input type="checkbox" name="twitter_enable"' . $selected . ' value="1" /> <i class="fa fa-twitter fa-2x" aria-hidden="true"></i> ' . t('Post to Twitter') . '</div>';
}


function twitter_post_local(&$a,&$b) {

	if($b['edit'])
		return;

	if($b['item_private'] || $b['parent'] || $b['cancel'] == 1)
		return;

	$twitter_post = Apps::addon_app_installed($b['uid'], 'twitter');
	$twitter_enable = ($twitter_post && x($_REQUEST,'twitter_enable') ? intval($_REQUEST['twitter_enable']) : 0);

	// if API is used, default to the chosen settings
	if($_REQUEST['api_source'] && intval(get_pconfig($b['uid'],'twitter','post_by_default')))
		$twitter_enable = 1;

	if(! $twitter_enable)
		return;

	if(strlen($b['postopts']))
		$b['postopts'] .= ',';
	$b['postopts'] .= 'twitter';

}


/**
 * @brief Shorten URL using Slinky library
 */
if (! function_exists('short_link')) {
function short_link ($url) {
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


/**
 * @brief Cut message and add link
 */
function twitter_short_message(&$msg, $link, $limit, $shortlink = true) {
	
	if ($shortlink && strlen($link) > 20)
			$link = short_link($link);
	
	if (strlen($msg . " " . $link) > $limit) {
		$msg = substr($msg, 0, ($limit - strlen($link)));
		if (substr($msg, -1) != "\n")
			$msg = rtrim(substr($msg, 0, strrpos($msg, " ")), "?.,:;!-") . "...";
	}
	
	$msg .= " " . $link;
}


/**
 * @brief Shorten message intelligent using bulit-in functions
 */
function twitter_shortenmsg($b) {
	require_once("include/api.php");
	require_once("include/bbcode.php");
	require_once("include/html2plain.php");

	// Give Twitter extra 10 chars to be sure that post will fit to their limits
	$max_char = get_pconfig($b['uid'], 'twitter', 'tweet_length', 280) - 10;

	// Add title at the top if exist
	$body = $b["body"];
	if ($b["title"] != "")
		$body = $b["title"] . " : \n" . $body;
	
	// Check if this is a reshare
	if(preg_match("/\[share author='([^\']+)/i", $body, $matches)) {
		$author = str_replace("+", " ", $matches[1]);
		$body = preg_replace("/\[share[^\]]+\](.*)\[\/share\]/ism", "&#9851; $author\n\n$1", $body);
	}

	// Looking for the first image
	$image = '';
	if(preg_match("/\[[zi]mg(=[0-9]+x[0-9]+)?\]([^\[]+)/is", $body, $matches)) {
	    if($matches[1]) {
	        $sizes = array_map('intval', explode('x', substr($matches[1],1)));
	        if($sizes[0] >= 480)
	            $image = html_entity_decode($matches[2]);
	    }
	}
	
	// Choose first URL 
	$link = '';
	if (preg_match('/\[url=|\[o?embed\](https?\:\/\/[^\]\[]+)/is', $body, $matches))
	    $link = html_entity_decode($matches[1]);

	// Add some newlines so that the message could be cut better
	$body = str_replace(array("[quote", "[/quote]"), array("\n[quote", "[/quote]\n"), $body);

	// Remove URL bookmark
	$body = str_replace("#^[", "&#128279 [", $body);

	// At first convert the text to html
	$msg = bbcode($body, [ 'tryoembed' => false ]);

	// Then convert it to plain text
	$msg = trim(html2plain($msg, 0, true));
	$msg = html_entity_decode($msg, ENT_QUOTES, 'UTF-8');
	
	// Remove URLs
	$msg = preg_replace("/https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,\@]+/", "", $msg);

	// Removing multiple newlines
	while (strpos($msg, "\n\n\n") !== false)
		$msg = str_replace("\n\n\n", "\n\n", $msg);
	
	// Removing multiple spaces
	while (strpos($msg, "  ") !== false)
		$msg = str_replace("  ", " ", $msg);
	
	$msg = trim(urldecode($msg));
	
	// Add URL if exist and no image found
	if (empty($image) && $link != '')
		twitter_short_message($msg, $link, $max_char - 23);
	
	$msglink = $b["plink"];

	// If the message is short enough we send it and embed a picture if necessary it
	if (strlen($msg . " " . $msglink) <= $max_char)
			return([ "msg" => $msg . " " . $msglink, "image" => $image ]);

	// Shorten message
	twitter_short_message($msg, $msglink, $max_char);

	return([ "msg" => $msg, "image" => $image ]);
}


function twitter_action($a, $uid, $pid, $action) {

	$ckey    = get_config('twitter', 'consumerkey');
	$csecret = get_config('twitter', 'consumersecret');
	$otoken  = get_pconfig($uid, 'twitter', 'oauthtoken');
	$osecret = get_pconfig($uid, 'twitter', 'oauthsecret');

	require_once("addon/twitter/codebird.php");

	$cb = \Codebird\Codebird::getInstance();
	$cb->setConsumerKey($ckey, $csecret);
	$cb->setToken($otoken, $osecret);

	$post = array('id' => $pid);

	logger("twitter_action '" . $action . "' ID: " . $pid . " data: " . print_r($post, true), LOGGER_DATA);

	switch ($action) {
		case "delete":
			$result = $cb->statuses_destroy($post);
			break;
		case "like":
			$result = $cb->favorites_create($post);
			break;
		case "unlike":
			$result = $cb->favorites_destroy($post);
			break;
	}
	logger("twitter_action '" . $action . "' send, result: " . print_r($result, true), LOGGER_DEBUG);
}


function twitter_post_hook(&$a,&$b) {

	/**
	 * Post to Twitter
	 */

	if (! Apps::addon_app_installed($b['uid'], 'twitter'))
		return;
		
	if (! is_item_normal($b) || $b['item_private'] || ($b['created'] !== $b['edited']))
		return;
		
	if (! perm_is_allowed($b['uid'], '', 'view_stream', false))
		return;

	if (strpos($b['mid'], z_root() . '/item/') === 0 && ! strstr($b['postopts'], 'twitter'))
		return;

	if (strpos($b['mid'], z_root() . '/item/') === false && (strstr($b['postopts'], 'twitter') || ! boolval(get_pconfig($b['uid'], 'twitter', 'post_by_default'))))
		return;

	if ($b['parent'] !== $b['id'])
		return;
		
	logger('twitter post invoked');

	load_pconfig($b['uid'], 'twitter');

	$ckey    = get_config('twitter', 'consumerkey');
	$csecret = get_config('twitter', 'consumersecret');
	$otoken  = get_pconfig($b['uid'], 'twitter', 'oauthtoken');
	$osecret = get_pconfig($b['uid'], 'twitter', 'oauthsecret');
	$intelligent_shortening = get_pconfig($b['uid'], 'twitter', 'intelligent_shortening');

	// Global setting overrides this
	if (get_config('twitter','intelligent_shortening'))
		$intelligent_shortening = get_config('twitter','intelligent_shortening');

	if($ckey && $csecret && $otoken && $osecret) {
		logger('twitter: we have customer key and oauth stuff, going to send.', LOGGER_DEBUG);

//		// If it's a repeated message from twitter then do a native retweet and exit
//		if (twitter_is_retweet($a, $b['uid'], $b['body']))
//			return;

		require_once('include/bbcode.php');

		// In theory max char is 140 but T. uses t.co to make links 
		// longer so we give them 10 characters extra
		if (!$intelligent_shortening) {
			$max_char = intval(get_pconfig($b['uid'],'twitter','tweet_length',280)) - 10; // max. length for a tweet-
			
			// we will only work with up to two times the length of the dent 
			// we can later send to Twitter. This way we can "gain" some 
			// information during shortening of potential links but do not 
			// shorten all the links in a 200000 character long essay.
			if (! $b['title']=='')
				$tmp = $b['title'] . " : \n". $b['body']; //substr($tmp, 0, 4 * $max_char);
			else
				$tmp = $b['body']; //substr($b['body'], 0, 3 * $max_char);
				
			// if [url=bla][img]blub.png[/img][/url] get blub.png
			$tmp = preg_replace( '/\[url\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\]\[img\](\\w+.*?)\\[\\/img\]\\[\\/url\]/i', '$2', $tmp);
			$tmp = preg_replace( '/\[zrl\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\]\[zmg\](\\w+.*?)\\[\\/zmg\]\\[\\/zrl\]/i', '$2', $tmp);
				
			// preserve links to images, videos and audios
			$tmp = preg_replace( '/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism', '$3', $tmp);
			$tmp = preg_replace( '/\[\\/?img(\\s+.*?\]|\])/i', '', $tmp);
			$tmp = preg_replace( '/\[zmg\=([0-9]*)x([0-9]*)\](.*?)\[\/zmg\]/ism', '$3', $tmp);
			$tmp = preg_replace( '/\[\\/?zmg(\\s+.*?\]|\])/i', '', $tmp);
			$tmp = preg_replace( '/\[\\/?video(\\s+.*?\]|\])/i', '', $tmp);
			$tmp = preg_replace( '/\[\\/?youtube(\\s+.*?\]|\])/i', '', $tmp);
			$tmp = preg_replace( '/\[\\/?vimeo(\\s+.*?\]|\])/i', '', $tmp);
			$tmp = preg_replace( '/\[\\/?audio(\\s+.*?\]|\])/i', '', $tmp);
			
			$linksenabled = get_pconfig($b['uid'],'twitter','post_taglinks');
			
			// if a #tag is linked, don't send the [url] over to SN
			// that is, don't send if the option is not set in the
			// connector settings
			if ($linksenabled=='0') {
				// #-tags
				$tmp = preg_replace( '/#\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', '#$2', $tmp);
				// @-mentions
				$tmp = preg_replace( '/@\[url\=(\w+.*?)\](\w+.*?)\[\/url\]/i', '@$2', $tmp);
				$tmp = preg_replace( '/#\[zrl\=(\w+.*?)\](\w+.*?)\[\/zrl\]/i', '#$2', $tmp);
				// @-mentions
				$tmp = preg_replace( '/@\[zrl\=(\w+.*?)\](\w+.*?)\[\/zrl\]/i', '@$2', $tmp);
			}
			
			$tmp = preg_replace( '/\[url\=(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)\](\w+.*?)\[\/url\]/i', '$2 $1', $tmp);
			
			// find all http or https links in the body of the entry and
			// apply the shortener if the link is longer then 20 characters
			if (strlen($tmp) > $max_char && $max_char > 0) {
				preg_match_all ( '/(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/i', $tmp, $allurls);
				foreach ($allurls as $url) {
					foreach ($url as $u) {
						if (strlen($u) > 20) {
							$sl = short_link($u);
							$tmp = str_replace( $u, $sl, $tmp );
						}
					}
				}
			}
					
			// ok, all the links we want to send out are save, now strip 
			// away the remaining bbcode
			//$msg = strip_tags(bbcode($tmp, false, false));
			$msg = bbcode($tmp, false, false);
			$msg = str_replace(array('<br>','<br />'),"\n",$msg);
			$msg = strip_tags($msg);

			// quotes not working - let's try this
			$msg = html_entity_decode($msg);
			if (( strlen($msg) > $max_char) && $max_char > 0) {
				$shortlink = short_link( $b['plink'] );
				// the new message will be shortened such that "... $shortlink"
				// will fit into the character limit
				$msg = nl2br(substr($msg, 0, $max_char-strlen($shortlink) - 4));
        	                $msg = str_replace(array('<br>','<br />'),' ',$msg);
	                        $e = explode(' ', $msg);
	                        //  remove the last word from the cut down message to 
	                        //  avoid sending cut words to the MicroBlog
	                        array_pop($e);
	                        $msg = implode(' ', $e);
				$msg .= '... ' . $shortlink;
			}

			$msg = trim($msg);
			$image = "";
		} 
		else {
			$msgarr = twitter_shortenmsg($b);
			$msg = $msgarr["msg"];
			$image = $msgarr["image"];
		}

		// Tweet it!
		if(strlen($msg)) {

			require_once("addon/twitter/codebird.php");

			$cb = \Codebird\Codebird::getInstance();
			$cb->setConsumerKey($ckey, $csecret);
			$cb->setToken($otoken, $osecret);
			$cb->setTimeout(intval(get_config('system','curl_timeout', 30)) * 1000); // in ms
			
			$post = [ 'status' => $msg ];

			// Post image if provided
			if(! empty($image)) {
			    try {
				    $result = $cb->media_upload([ 'media' => $image ]);
			    }
			    catch (Exception $e) {
			        logger('Image upload to Twitter failed with error "' . $e->getMessage() . '"', LOG_INFO);
			    }
			    if ($result->httpstatus == 200)
			        $post['media_ids'] = $result->media_id_string;
			}
			
			$result = $cb->statuses_update($post);

//			if ($iscomment)
//				$post["in_reply_to_status_id"] = substr($orig_post["uri"], 9);

			logger('Tweet send result: ' . print_r((array)$result, true), LOGGER_DEBUG);
			
			if ($result->httpstatus != 200) {
				logger('Send to Twitter failed with HTTP status code ' . $result->httpstatus . '; error message: "' . print_r($result->errors, true) . '"');
				
			logger('twitter post completed');

//				// Workaround: Remove the picture link so that the post can be reposted without it
//				$msg .= " ".$image;
//				$image = "";
//			} 
//			elseif ($iscomment) {
//				logger('twitter_post: Update extid '.$result->id_str." for post id ".$b['id']);
//				q("UPDATE item SET extid = '%s', body = '%s' WHERE id = %d",
//					dbesc("twitter::".$result->id_str),
//					dbesc($result->text),
//					intval($b['id'])
//				);
			}
		}
	}
}


function twitter_plugin_admin_post(){
	$consumerkey	=	((x($_POST,'consumerkey'))		? notags(trim($_POST['consumerkey']))	: '');
	$consumersecret	=	((x($_POST,'consumersecret'))	? notags(trim($_POST['consumersecret'])): '');
	set_config('twitter','consumerkey',$consumerkey);
	set_config('twitter','consumersecret',$consumersecret);
	info( t('Settings updated.'). EOL );
}


function twitter_plugin_admin(&$o){
logger('Twitter admin');
	$t = get_markup_template( "admin.tpl", "addon/twitter/" );

	$o = replace_macros($t, array(
		'$submit' => t('Submit Settings'),
		// name, label, value, help, [extra values]
		'$consumerkey' => array('consumerkey', t('Consumer Key'),  get_config('twitter', 'consumerkey' ), ''),
                '$consumersecret' => array('consumersecret', t('Consumer Secret'),  get_config('twitter', 'consumersecret' ), '')
	));
}


function twitter_expand_entities($a, $body, $item, $no_tags = false, $dontincludemedia) {
	require_once("include/oembed.php");

	$tags = "";

	if (isset($item->entities->urls)) {
		$type = "";
		$footerurl = "";
		$footerlink = "";
		$footer = "";

		foreach ($item->entities->urls AS $url) {
			if ($url->url AND $url->expanded_url AND $url->display_url) {

				$expanded_url = twitter_original_url($url->expanded_url);

				$oembed_data = oembed_fetch_url($expanded_url);

				// Quickfix: Workaround for URL with "[" and "]" in it
				if (strpos($expanded_url, "[") OR strpos($expanded_url, "]"))
					$expanded_url = $url->url;

				if ($type == "")
					$type = $oembed_data['type'];

				if ($oembed_data['type'] == "video") {
					$body = str_replace($url->url,
							"[video]".$expanded_url."[/video]", $body);
					$dontincludemedia = true;
				} elseif (($oembed_data['type'] == "photo") AND isset($oembed_data['url']) AND !$dontincludemedia) {
					$body = str_replace($url->url,
							"[url=".$expanded_url."][img]".$oembed_data['url']."[/img][/url]",
							$body);
					$dontincludemedia = true;
				} elseif ($oembed_data['type'] != "link")
					$body = str_replace($url->url,
							"[url=".$expanded_url."]".$expanded_url."[/url]",
							$body);
							//"[url=".$expanded_url."]".$url->display_url."[/url]",
				else {
					$img_str = fetch_url($expanded_url, true, $redirects, 4);

					$tempfile = tempnam(get_config("system","temppath"), "cache");
					file_put_contents($tempfile, $img_str);
					$mime = image_type_to_mime_type(exif_imagetype($tempfile));
					unlink($tempfile);

					if (substr($mime, 0, 6) == "image/") {
						$type = "photo";
						$body = str_replace($url->url, "[img]".$expanded_url."[/img]", $body);
						$dontincludemedia = true;
					} else {
						$type = $oembed_data->type;
						$footerurl = $expanded_url;
						$footerlink = "[url=".$expanded_url."]".$expanded_url."[/url]";
						//$footerlink = "[url=".$expanded_url."]".$url->display_url."[/url]";

						$body = str_replace($url->url, $footerlink, $body);
					}
				}
			}
		}

		if ($footerurl != "")
			$footer = twitter_siteinfo($footerurl, $dontincludemedia);

		if (($footerlink != "") AND (trim($footer) != "")) {
			$removedlink = trim(str_replace($footerlink, "", $body));

			if (strstr($body, $removedlink))
				$body = $removedlink;

			$body .= "\n\n[class=type-".$type."]".$footer."[/class]";
		}

		if ($no_tags)
			return(array("body" => $body, "tags" => ""));

		$tags_arr = array();

		foreach ($item->entities->hashtags AS $hashtag) {
			$url = "#[url=".z_root()."/search?tag=".rawurlencode($hashtag->text)."]".$hashtag->text."[/url]";
			$tags_arr["#".$hashtag->text] = $url;
			$body = str_replace("#".$hashtag->text, $url, $body);
		}

		foreach ($item->entities->user_mentions AS $mention) {
			$url = "@[url=https://twitter.com/".rawurlencode($mention->screen_name)."]".$mention->screen_name."[/url]";
			$tags_arr["@".$mention->screen_name] = $url;
			$body = str_replace("@".$mention->screen_name, $url, $body);
		}

		// it seems as if the entities aren't always covering all mentions. So the rest will be checked here
	        $tags = get_tags($body);

        	if(count($tags)) {
			foreach($tags as $tag) {
				if (strstr(trim($tag), " "))
					continue;

	                        if(strpos($tag,'#') === 0) {
        	                        if(strpos($tag,'[url='))
                	                        continue;

					// don't link tags that are already embedded in links

					if(preg_match('/\[(.*?)' . preg_quote($tag,'/') . '(.*?)\]/',$body))
						continue;
					if(preg_match('/\[(.*?)\]\((.*?)' . preg_quote($tag,'/') . '(.*?)\)/',$body))
						continue;

					$basetag = str_replace('_',' ',substr($tag,1));
					$url = '#[url='.z_root().'/search?tag='.rawurlencode($basetag).']'.$basetag.'[/url]';
					$body = str_replace($tag,$url,$body);
					$tags_arr["#".$basetag] = $url;
					continue;
				} elseif(strpos($tag,'@') === 0) {
        	                        if(strpos($tag,'[url='))
                	                        continue;

					$basetag = substr($tag,1);
					$url = '@[url=https://twitter.com/'.rawurlencode($basetag).']'.$basetag.'[/url]';
					$body = str_replace($tag,$url,$body);
					$tags_arr["@".$basetag] = $url;
				}
			}
		}


		$tags = implode(',',$tags_arr);

	}
	return(array("body" => $body, "tags" => $tags));
}
