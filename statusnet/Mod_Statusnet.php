<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

require_once('statusnet.php');

class Statusnet extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'statusnet'))
			return;

		check_form_security_token_redirectOnErr('/statusnet', 'statusnet');

		if(isset($_POST['statusnet-disconnect'])) {

			/***
			 * if the statusnet-disconnect checkbox is set, clear the statusnet configuration
			 */
			del_pconfig(local_channel(), 'statusnet', 'consumerkey');
			del_pconfig(local_channel(), 'statusnet', 'consumersecret');
			del_pconfig(local_channel(), 'statusnet', 'post_by_default');
			del_pconfig(local_channel(), 'statusnet', 'oauthtoken');
			del_pconfig(local_channel(), 'statusnet', 'oauthsecret');
			del_pconfig(local_channel(), 'statusnet', 'baseapi');
			del_pconfig(local_channel(), 'statusnet', 'post_taglinks');
			del_pconfig(local_channel(), 'statusnet', 'lastid');
			del_pconfig(local_channel(), 'statusnet', 'mirror_posts');
			del_pconfig(local_channel(), 'statusnet', 'intelligent_shortening');
		}
		else {


			if (isset($_POST['statusnet-preconf-apiurl'])) {


				/***
				 * If the user used one of the preconfigured GNU social server credentials
				 * use them. All the data are available in the global config.
				 * Check the API Url never the less and blame the admin if it's not working ^^
				 */
				$globalsn = get_config('statusnet', 'sites');
				foreach ( $globalsn as $asn) {
					if ($asn['apiurl'] == $_POST['statusnet-preconf-apiurl'] ) {
						$apibase = $asn['apiurl'];
						$x = z_fetch_url( $apibase . 'statusnet/version.xml', false, 0, array('novalidate' => true));						$c = $x['body'];
						if (strlen($c) > 0) {
							set_pconfig(local_channel(), 'statusnet', 'consumerkey', $asn['consumerkey'] );
							set_pconfig(local_channel(), 'statusnet', 'consumersecret', $asn['consumersecret'] );
							set_pconfig(local_channel(), 'statusnet', 'baseapi', $asn['apiurl'] );
							set_pconfig(local_channel(), 'statusnet', 'application_name', $asn['applicationname'] );
						}
						else {
							notice( t('Please contact your site administrator.<br />The provided API URL is not valid.').EOL.$asn['apiurl'].EOL );
						}
					}
				}
				goaway(z_root().'/statusnet');
			}
			else {

				if (isset($_POST['statusnet-consumersecret'])) {

					//  check if we can reach the API of the GNU social server
					//  we'll check the API Version for that, if we don't get one we'll try to fix the path but will
					//  resign quickly after this one try to fix the path ;-)
					$apibase = $_POST['statusnet-baseapi'];
					$x = z_fetch_url( $apibase . 'statusnet/version.xml', false, 0, array('novalidate' => true) );
					$c = $x['body'];
					if (strlen($c) > 0) {
						//  ok the API path is correct, let's save the settings
						set_pconfig(local_channel(), 'statusnet', 'consumerkey', $_POST['statusnet-consumerkey']);
						set_pconfig(local_channel(), 'statusnet', 'consumersecret', $_POST['statusnet-consumersecret']);
						set_pconfig(local_channel(), 'statusnet', 'baseapi', $apibase );
						set_pconfig(local_channel(), 'statusnet', 'application_name', $_POST['statusnet-applicationname'] );
					}
					else {
						//  the API path is not correct, maybe missing trailing / ?
						$apibase = $apibase . '/';

						$x = z_fetch_url( $apibase . 'statusnet/version.xml', false, 0, array('novalidate' => true) );
						$c = $x['body'];
						if (strlen($c) > 0) {
							//  ok the API path is now correct, let's save the settings
							set_pconfig(local_channel(), 'statusnet', 'consumerkey', $_POST['statusnet-consumerkey']);
							set_pconfig(local_channel(), 'statusnet', 'consumersecret', $_POST['statusnet-consumersecret']);
							set_pconfig(local_channel(), 'statusnet', 'baseapi', $apibase );
						}
						else {
							//  still not the correct API base, let's do noting
							notice( t('We could not contact the GNU social API with the Path you entered.').EOL );
						}
					}
					goaway(z_root().'/statusnet');
				}
				else {

					if (isset($_POST['statusnet-pin'])) {

						//  if the user supplied us with a PIN from GNU social, let the magic of OAuth happen
						$api	 = get_pconfig(local_channel(), 'statusnet', 'baseapi');
						$ckey	= get_pconfig(local_channel(), 'statusnet', 'consumerkey'  );
						$csecret = get_pconfig(local_channel(), 'statusnet', 'consumersecret' );
						//  the token and secret for which the PIN was generated were hidden in the settings
						//  form as token and token2, we need a new connection to Twitter using these token
						//  and secret to request a Access Token with the PIN
						$connection = new \StatusNetOAuth($api, $ckey, $csecret, $_POST['statusnet-token'], $_POST['statusnet-token2']);
						$token   = $connection->getAccessToken( $_POST['statusnet-pin'] );
						//  ok, now that we have the Access Token, save them in the user config
						set_pconfig(local_channel(),'statusnet', 'oauthtoken',  $token['oauth_token']);
						set_pconfig(local_channel(),'statusnet', 'oauthsecret', $token['oauth_token_secret']);
											set_pconfig(local_channel(),'statusnet', 'post_taglinks', 1);
						//  reload the Addon Settings page, if we don't do it see Bug #42
						goaway(z_root().'/statusnet');
					}
					else {
						//  if no PIN is supplied in the POST variables, the user has changed the setting
						//  to post a dent for every new __public__ posting to the wall
						set_pconfig(local_channel(),'statusnet','post_by_default',intval($_POST['statusnet-default']));
						set_pconfig(local_channel(),'statusnet','post_taglinks',intval($_POST['statusnet-sendtaglinks']));
						set_pconfig(local_channel(), 'statusnet', 'mirror_posts', intval($_POST['statusnet-mirror']));
						set_pconfig(local_channel(), 'statusnet', 'intelligent_shortening', intval($_POST['statusnet-shortening']));
						info( t('GNU social settings updated.') . EOL);
					}
				}
			}
		}
	}

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'statusnet')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('GNU-Social Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		/***
		 * 1) Check that we have a base api url and a consumer key & secret
		 * 2) If no OAuthtoken & stuff is present, generate button to get some
			 *	allow the user to cancel the connection process at this step
		 * 3) Checkbox for "Send public notices (respect size limitation)
		 */
		$api	 = get_pconfig(local_channel(), 'statusnet', 'baseapi');
		$ckey	= get_pconfig(local_channel(), 'statusnet', 'consumerkey' );
		$csecret = get_pconfig(local_channel(), 'statusnet', 'consumersecret' );
		$otoken  = get_pconfig(local_channel(), 'statusnet', 'oauthtoken'  );
		$osecret = get_pconfig(local_channel(), 'statusnet', 'oauthsecret' );
		$defenabled = get_pconfig(local_channel(),'statusnet','post_by_default');
		$defchecked = (($defenabled) ? 1 : false);
		//$shorteningenabled = get_pconfig(local_channel(),'statusnet','intelligent_shortening');
		//$shorteningchecked = (($shorteningenabled) ? 1 : false);

		if ( (!$ckey) && (!$csecret) ) {
			/***
			 * no consumer keys
			 */
			$globalsn = get_config('statusnet', 'sites');

			/***
			 * lets check if we have one or more globally configured GNU social
			 * server OAuth credentials in the configuration. If so offer them
			 * with a little explanation to the user as choice - otherwise
			 * ignore this option entirely.
			 */

			if (! $globalsn == null) {
				$sc .= '<h3>' . t('Globally Available GNU social OAuthKeys') . '</h3>';
				$sc .= '<div class="section-content-info-wrapper">';
				$sc .= t("There are preconfigured OAuth key pairs for some GNU social servers available. If you are using one of them, please use these credentials.<br />If not feel free to connect to any other GNU social instance \x28see below\x29.");
				$sc .= '</div>';

				foreach ($globalsn as $asn) {
					$sc .= replace_macros(get_markup_template('field_radio.tpl'), array(
						'$field'	=> array('statusnet-preconf-apiurl', $asn['sitename'], $asn['apiurl'], '')
					));
				}

				$sc .= '<div class=" settings-submit-wrapper">';
				$sc .= '<button type="submit" name="statusnet-submit" class="btn btn-primary" value="' . t('Submit') . '">' . t('Submit') . '</button>';
				$sc .= '</div>';

			}

			$sc .= '<h3>' . t('Provide your own OAuth Credentials') . '</h3>';
			$sc .= '<div class="section-content-info-wrapper">';
			$sc .= t('No consumer key pair for GNU social found. Register your Hubzilla Account as an desktop client on your GNU social account, copy the consumer key pair here and enter the API base root.<br />Before you register your own OAuth key pair ask the administrator if there is already a key pair for this Hubzilla installation at your favourite GNU social installation.');
			$sc .= '</div>';

			$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
				'$field'	=> array('statusnet-consumerkey', t('OAuth Consumer Key'), '', '')
			));

			$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
				'$field'	=> array('statusnet-consumersecret', t('OAuth Consumer Secret'), '', '')
			));

			$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
				'$field'	=> array('statusnet-baseapi', t("Base API Path"), '', t("Remember the trailing /"))
			));

			$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
				'$field'	=> array('statusnet-applicationname', t('GNU social application name'), '', '')
			));

		} else {
			/***
			 * ok we have a consumer key pair now look into the OAuth stuff
			 */
			if ( (!$otoken) && (!$osecret) ) {

				/***
				 * the user has not yet connected the account to GNU social
				 * get a temporary OAuth key/secret pair and display a button with
				 * which the user can request a PIN to connect the account to a
				 * account at statusnet
				 */
				$connection = new \StatusNetOAuth($api, $ckey, $csecret);
				$request_token = $connection->getRequestToken('oob');
				$token = $request_token['oauth_token'];

				/***
				 *  make some nice form
				 */
				$sc .= '<div class="section-content-info-wrapper">';
				$sc .= t('To connect to your GNU social account click the button below to get a security code from GNU social which you have to copy into the input box below and submit the form. Only your <strong>public</strong> posts will be posted to GNU social.');
				$sc .= '</div>';
				$sc .= '<a href="'.$connection->getAuthorizeURL($token,False).'" target="_statusnet"><img src="addon/statusnet/signinwithgnusocial.png" class="form-group" alt="'. t('Log in with GNU social') .'"></a>';

				$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
					'$field'	=> array('statusnet-pin', t('Copy the security code from GNU social here'), '', '')
				));

				$sc .= '<input id="statusnet-token" type="hidden" name="statusnet-token" value="'.$token.'" />';
				$sc .= '<input id="statusnet-token2" type="hidden" name="statusnet-token2" value="'.$request_token['oauth_token_secret'].'" />';

				$sc .= '<div class=" settings-submit-wrapper">';
				$sc .= '<button type="submit" name="statusnet-submit" class="btn btn-primary" value="' . t('Submit') . '">' . t('Submit') . '</button>';
				$sc .= '</div>';

				$sc .= '<h3>'.t('Cancel Connection Process').'</h3>';
				$sc .= '<div class="section-content-info-wrapper">';
				$sc .= t('Current GNU social API is').': ' . $api;
				$sc .= '</div>';

				$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
					'$field'	=> array('statusnet-disconnect', t('Cancel GNU social Connection'), '', '', array(t('No'),t('Yes')))
				));

			} else {

				/***
				 *  we have an OAuth key / secret pair for the user
				 *  so let's give a chance to disable the postings to statusnet
				 */
				$connection = new \StatusNetOAuth($api,$ckey,$csecret,$otoken,$osecret);
				$details = $connection->get('account/verify_credentials');

				$sc .= '<div id="statusnet-info" ><img id="statusnet-avatar" src="'.$details->profile_image_url.'" /><p id="statusnet-info-block">'. t('Currently connected to: ') .'<a href="'.$details->statusnet_profile_url.'" target="_statusnet">'.$details->screen_name.'</a><br /><em>'.$details->description.'</em></p></div>';
				$sc .= '<div class="clear"></div>';

				if (App::$user['hidewall']) {
					$sc .= '<div class="section-content-info-wrapper">';
					$sc .= t('<strong>Note</strong>: Due your privacy settings (<em>Hide your profile details from unknown viewers?</em>) the link potentially included in public postings relayed to GNU social will lead the visitor to a blank page informing the visitor that the access to your profile has been restricted.');
					$sc .= '</div>';
				}

				$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
					'$field'	=> array('statusnet-default', t('Post to GNU social by default'), $defchecked, t('If enabled your public postings will be posted to the associated GNU-social account by default'), array(t('No'),t('Yes')))
				));

				//FIXME: Doesn't seem to work. But maybe we don't want it all.
				//$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				//	'$field'	=> array('statusnet-shortening', t('Shortening method that optimizes the post'), $shorteningchecked, '', array(t('No'),t('Yes')))
				//));

				$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
					'$field'	=> array('statusnet-disconnect', t('Clear OAuth configuration'), '', '', array(t('No'),t('Yes')))
				));

			}

		}

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'statusnet',
			'$form_security_token' => get_form_security_token("statusnet"),
			'$title' => t('GNU-Social Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;

	}
}
