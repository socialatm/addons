<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Twitter extends Controller {

	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'twitter'))
			return;

		check_form_security_token_redirectOnErr('/twitter', 'twitter');

		if (isset($_POST['twitter-disconnect'])) {
			/***
			 * if the twitter-disconnect checkbox is set, clear the OAuth key/secret pair
			 * from the user configuration
			 */
			del_pconfig(local_channel(), 'twitter', 'consumerkey');
			del_pconfig(local_channel(), 'twitter', 'consumersecret');
			del_pconfig(local_channel(), 'twitter', 'oauthtoken');
			del_pconfig(local_channel(), 'twitter', 'oauthsecret');
			del_pconfig(local_channel(), 'twitter', 'post');
			del_pconfig(local_channel(), 'twitter', 'post_by_default');
			del_pconfig(local_channel(), 'twitter', 'post_taglinks');
			del_pconfig(local_channel(), 'twitter', 'lastid');
			del_pconfig(local_channel(), 'twitter', 'intelligent_shortening');
			del_pconfig(local_channel(), 'twitter', 'own_id');
		} else {
		if (isset($_POST['twitter-pin'])) {
			//  if the user supplied us with a PIN from Twitter, let the magic of OAuth happen
			logger('got a Twitter PIN');
			require_once('library/twitteroauth.php');
			$ckey    = get_config('twitter', 'consumerkey');
			$csecret = get_config('twitter', 'consumersecret');
			//  the token and secret for which the PIN was generated were hidden in the settings
			//  form as token and token2, we need a new connection to Twitter using these token
			//  and secret to request a Access Token with the PIN
			$connection = new \TwitterOAuth($ckey, $csecret, $_POST['twitter-token'], $_POST['twitter-token2']);
			$token   = $connection->getAccessToken( $_POST['twitter-pin'] );
			//  ok, now that we have the Access Token, save them in the user config
	 		set_pconfig(local_channel(),'twitter', 'oauthtoken',  $token['oauth_token']);
			set_pconfig(local_channel(),'twitter', 'oauthsecret', $token['oauth_token_secret']);
			set_pconfig(local_channel(),'twitter', 'post', 1);
			set_pconfig(local_channel(),'twitter', 'post_taglinks', 1);
			//  reload the Addon Settings page, if we don't do it see Friendica Bug #42
			goaway(z_root().'/twitter');
		} else {
			//  if no PIN is supplied in the POST variables, the user has changed the setting
			//  to post a tweet for every new __public__ posting to the wall
			set_pconfig(local_channel(),'twitter','post_by_default',intval($_POST['twitter-default']));
			set_pconfig(local_channel(),'twitter','post_taglinks',intval($_POST['twitter-sendtaglinks']));
			set_pconfig(local_channel(),'twitter', 'mirror_posts', intval($_POST['twitter-mirror']));
			set_pconfig(local_channel(),'twitter', 'intelligent_shortening', intval($_POST['twitter-shortening']));
			set_pconfig(local_channel(),'twitter', 'import', intval($_POST['twitter-import']));
			set_pconfig(local_channel(),'twitter', 'tweet_length', intval($_POST['twitter-length']));
			set_pconfig(local_channel(),'twitter', 'create_user', intval($_POST['twitter-create_user']));
			info( t('Twitter settings updated.') . EOL);
		}}
	}

	function get() {

		if(! local_channel())
		        return;

		if(! Apps::addon_app_installed(local_channel(), 'twitter')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Twitter Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		/***
		 * 1) Check that we have global consumer key & secret
		 * 2) If no OAuthtoken & stuff is present, generate button to get some
		 * 3) Checkbox for "Send public notices (140 chars only)
		 */
		$ckey    = get_config('twitter', 'consumerkey' );
		$csecret = get_config('twitter', 'consumersecret' );
		$otoken  = get_pconfig(local_channel(), 'twitter', 'oauthtoken'  );
		$osecret = get_pconfig(local_channel(), 'twitter', 'oauthsecret' );
		$defenabled = get_pconfig(local_channel(),'twitter','post_by_default');
		$defchecked = (($defenabled) ? 1 : false);
		//$shorteningenabled = get_pconfig(local_channel(),'twitter','intelligent_shortening');
		//$shorteningchecked = (($shorteningenabled) ? 1 : false);

		if ( (!$ckey) && (!$csecret) ) {
			/***
			 * no global consumer keys
			 * display warning and skip personal config
			 */
			$sc .= '<div class="section-content-danger-wrapper">';
			$sc .= t('No consumer key pair for Twitter found. Please contact your site administrator.');
			$sc .= '</div>';
		} else {
			/***
			 * ok we have a consumer key pair now look into the OAuth stuff
			 */
			if ( (!$otoken) && (!$osecret) ) {
				/***
				 * the user has not yet connected the account to twitter...
				 * get a temporary OAuth key/secret pair and display a button with
				 * which the user can request a PIN to connect the account to a
				 * account at Twitter.
				 */
				require_once('library/twitteroauth.php');
				$connection = new \TwitterOAuth($ckey, $csecret);
				$request_token = $connection->getRequestToken();
				$token = $request_token['oauth_token'];
				/***
				 *  make some nice form
				 */

				$sc .= '<div class="section-content-info-wrapper">';
				$sc .= t('At this Hubzilla instance the Twitter plugin was enabled but you have not yet connected your account to your Twitter account. To do so click the button below to get a PIN from Twitter which you have to copy into the input box below and submit the form. Only your <strong>public</strong> posts will be posted to Twitter.');
				$sc .= '</div>';
				$sc .= '<a href="'.$connection->getAuthorizeURL($token).'" target="_twitter"><img src="addon/twitter/lighter.png" class="form-group" alt="'.t('Log in with Twitter').'"></a>';

				$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
					'$field'	=> array('twitter-pin', t('Copy the PIN from Twitter here'), '', '')
				));

				$sc .= '<input id="twitter-token" type="hidden" name="twitter-token" value="'.$token.'" />';
				$sc .= '<input id="twitter-token2" type="hidden" name="twitter-token2" value="'.$request_token['oauth_token_secret'].'" />';
			} else {
				/***
				 *  we have an OAuth key / secret pair for the user
				 *  so let's give a chance to disable the postings to Twitter
				 */
				require_once('library/twitteroauth.php');
				$connection = new \TwitterOAuth($ckey,$csecret,$otoken,$osecret);
				$details = $connection->get('account/verify_credentials');
				$twitpic = $details->profile_image_url;
				if((strstr(z_root(),'https')) && (! strstr($twitpic,'https')))
					$twitpic = str_replace('http:','https:',$twitpic);

				$sc .= '<div id="twitter-info" ><img id="twitter-avatar" src="'.$twitpic.'" /><p id="twitter-info-block">'. t('Currently connected to: ') .'<a href="https://twitter.com/'.$details->screen_name.'" target="_twitter">'.$details->screen_name.'</a><br /><em>'.$details->description.'</em></p></div>';
				$sc .= '<div class="clear"></div>';
				//FIXME no hidewall in Red
				if (App::$user['hidewall']) {
					$sc .= '<div class="section-content-info-wrapper">';
					$sc .= t('<strong>Note:</strong> Due your privacy settings (<em>Hide your profile details from unknown viewers?</em>) the link potentially included in public postings relayed to Twitter will lead the visitor to a blank page informing the visitor that the access to your profile has been restricted.');
					$sc .= '</div>';
				}

				$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
					'$field'	=> array('twitter-length', t('Twitter post length'), get_pconfig(local_channel(),'twitter','tweet_length',140), t('Maximum tweet length'))
				));


				$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
					'$field'	=> array('twitter-default', t('Send public postings to Twitter by default'), $defchecked, t('If enabled your public postings will be posted to the associated Twitter account by default'), array(t('No'),t('Yes')))
				));

				//FIXME: Doesn't seem to work. But maybe we don't want this at all.
				//$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				//	'$field'	=> array('twitter-shortening', t('Shortening method that optimizes the tweet'), $shorteningchecked, '', array(t('No'),t('Yes')))
				//));

				$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
					'$field'	=> array('twitter-disconnect', t('Clear OAuth configuration'), '', '', array(t('No'),t('Yes')))
				));
			}
		}

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'twitter',
			'$form_security_token' => get_form_security_token("twitter"),
			'$title' => t('Twitter Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;

	}

}

