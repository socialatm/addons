<?php


namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

// Sign in logic
class SocialAuthSignin extends Controller {

	function getAuth($provider) {
		require_once __DIR__ . '/include/config.php';
		$providers = \SocialAuthConfig::getConfiguredProviders();
		logger('Configured providers: ' . print_r($providers, true), LOGGER_DEBUG);

		logger('Request provider = '. $provider, LOGGER_DEBUG);

		// Check if provider is supported
		if (! in_array($provider, $providers) ) {
			logger('Provider "'. $provider . '" not supported - ABORT', LOGGER_DEBUG);
			goaway(z_root());
		}

		$config = \SocialAuthConfig::getProviderConfig( $provider );

		if ( \SocialAuthConfig::isCustomProvider( $provider ) ) {

			logger('Custom provider '. $provider . ' detected!', LOGGER_DEBUG);

			// custom provider
			require_once __DIR__ . '/include/CustomOAuth2.php';

			$auth = new \Hybridauth\Hybridauth( $config );
		} else {
			// built-in provider
			$auth = new \Hybridauth\Hybridauth( $config );
		}

		return $auth;
	}

	function get() {
		logger('SocialAuth Signin controller GET', LOGGER_DEBUG);

		require __DIR__ . '/vendor/autoload.php';
		$provider = $_GET['provider'];

		try {
			if ( x($provider) ) {
				$auth = self::getAuth($provider);
			}
			else {
				logger('No provider specified', LOGGER_DEBUG);
			}

			// second pass: check if user is connected to a provider
			if (!x($provider)) {
				$storage = new \Hybridauth\Storage\Session();
				$provider = $storage->get('provider');

				logger('Provider = ' . print_r($provider, true), LOGGER_DEBUG);
				if (!x($provider)) {
					logger('Provider not detected', LOGGER_DEBUG);
					goaway(z_root());
				}

				$auth = self::getAuth($provider);
				$adapter = $auth->authenticate($provider);
				logger('authentication done', LOGGER_DEBUG);

				if ($adapter->isConnected()) {
					logger('Socialauth - Connected to '. $provider .' OK', LOGGER_DEBUG);

					// clear session provider parameter
					$storage->set('provider', null);

					socialauth_signin($provider, $auth);
				} else {
					logger('Socialauth - Authentication failed with provider '. $provider, LOGGER_DEBUG);
					goaway(z_root());
				}

			}

			// first pass: provider is ... provided
			if ($auth->isConnectedWith($provider)) {
				logger('Socialauth - connected to ' . $provider. '!', LOGGER_DEBUG);

				socialauth_signin($provider, $auth);
			} else {
				logger('Socialauth - not connected to ' . $provider . ' yet', LOGGER_DEBUG);

				// remember provider for callback
				$storage = new \Hybridauth\Storage\Session();
				$storage->set('provider', $provider);

				// authentication should trigger callback from the provider to this page
				$adapter = $auth->authenticate($provider);
			}

		}
		catch ( \Hybridauth\Exception\HttpClientFailureException $e ) {
			logger('Network error : ' . print_r( $auth->getHttpClient()->getResponseClientError(), true) , LOGGER_NORMAL, LOG_ERR);
			info ( t('Network error') . EOL );
		}
		catch ( \Hybridauth\Exception\HttpRequestFailedException $e ) {
			logger('Raw API response: ' . print_r( $auth->getHttpClient()->getResponseBody(), true), LOGGER_NORMAL, LOG_ERR);
			info ( t('API error') . EOL );
		}
		catch ( \Exception $e ) {
			logger('Unknown issue: ' . print_r( $e->getMessage(), true), LOGGER_NORMAL, LOG_ERR);
			info ( t('Unknown issue') . EOL );
		}
	}
}

function socialauth_signin($provider, $auth)
{
	try {
		$adapter = $auth->getAdapter($provider);
		if (!x($adapter) || !($adapter->isConnected()))
		{
			logger('Authorization callback called but not connected to provider', LOGGER_NORMAL, LOG_ERR);
			goaway(z_root());
		}

		if (\SocialAuthConfig::isCustomProvider($provider)) {

			// get the user endpoint to provide to adapter to be able to send user profile api request
			$config = $auth->getProviderConfig($provider);
			$endpoints = $config['endpoints'];

			if ( !$endpoints->exists('user_endpoint') ) {
				logger('Missing user endpoint for custom provider ' . $provider, LOGGER_NORMAL, LOG_ERR);
			}

			$user_endpoint = $endpoints->get('user_endpoint');
			$email_field = $endpoints->get('userprofile_email_field');

			// for a custom provider, the adapter should be of type Customauth, so it must have custom setUserXXX methods
			$adapter->setUserEndPoint($user_endpoint);
			$adapter->setUserProfileEmailField($email_field);
		}


		$userprofile = $adapter->getUserProfile();
		$email = $userprofile->email;
		if ( !x($email) ) {
			logger('Cannot retrieve email address', LOGGER_NORMAL, LOG_ERR);
			info( t('Unable to retrieve email address from remote identity provider') . EOL);
			goaway(z_root());
		}

		// all ok, continue as logged in user
		logger('Trying to log in account ' . $email, LOGGER_DEBUG);

		$r = q("select * from account where account_email = '%s' LIMIT 1", $email);
		if ( count($r) ) {
			$record = $r[0];

			$channel_id = $record['account_default_channel'];
			$_SESSION['uid'] = $channel_id;

			require_once('include/security.php');
			authenticate_success($record, null, true, false, true, true);

		} else {
			logger('Email address '. $email .' not found in database', LOGGER_DEBUG);
			info( t('Unable to login using email address ') . $email . EOL);
		}

		$adapter->disconnect();
		goaway(z_root());
	}
	catch ( \Hybridauth\Exception\HttpClientFailureException $e ) {
		logger('Network error : ' . print_r( $adapter->getHttpClient()->getResponseClientError(), true) , LOGGER_NORMAL, LOG_ERR);
		info ( t('Network error') . EOL );
	}
	catch ( \Hybridauth\Exception\HttpRequestFailedException $e ) {
		logger('Raw API response: ' . print_r( $adapter->getHttpClient()->getResponseBody(), true), LOGGER_NORMAL, LOG_ERR);
		info ( t('API error') . EOL );
	}
	catch ( \Exception $e ) {
		logger('Unknown issue: ' . print_r( $e->getMessage(), true), LOGGER_NORMAL, LOG_ERR);
		info ( t('Unknown issue') . EOL );
	}


}

// Social Auth App configuration
class SocialAuth extends Controller {

	function get() {
		if(! local_channel())
			return;

		if(! is_site_admin())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'socialauth')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Social authentication');
			return Apps::app_render($papp, 'module');
		}

		$content .= '<div class="section-content-info-wrapper">';
		$content .= t('Social Authentication using your social media account');
		$content .= '</div>';

		$content .= '<div class="section-content-info-wrapper">';
		$content .= t('This app enables one or more social provider sign-in buttons on the login page.');
                $content .= '</div>';

		$yes_no = array( t('No') , t('Yes') );

		require_once __DIR__ . '/include/config.php';

		$provider_select_array = Array( "None" => "None" );
		$allproviders = \SocialAuthConfig::getBuiltinProviders();
		$allconfiguredproviders = \SocialAuthConfig::getConfiguredProviders();
		$provider_select_array = array_merge(
			$provider_select_array,
			array_diff( $allproviders, $allconfiguredproviders )        // remove already added providers from drop-down list
		);

		$content .= replace_macros(get_markup_template('field_select.tpl'), array(
			"field" => Array(
				"Provider",
				t('Add an identity provider'),
				"None",
				"Select a built-in identity provider to add or select None to keep your current list of providers",
				$provider_select_array
			)
		));

		foreach ( $allconfiguredproviders as $name ) {

			$providerfullconfig = \SocialAuthConfig::getProviderConfig($name);
			$provider = $providerfullconfig["providers"][$name];

			$enabled = $provider['enabled'] ? 1 : 0;
			$key = $provider['keys']['key'];
			$secret = $provider['keys']['secret'];

			$displayName = $name;
			$str = "provider ";
			if ( \SocialAuthConfig::isCustomProvider($name) )
			{
				$displayName = \SocialAuthConfig::getCustomProviderName($name);
				$str = "custom provider ";
			}

			// radio button enabled/disabled
			$content .= replace_macros(get_markup_template('field_checkbox.tpl'),
				[
					'$field' => [\SocialAuthConfig::getKey($name, 'enabled'), t('Enable ' . $str) . $displayName, $enabled, '', $yes_no]
				]
			);

			// let the user configure key & secret
			$content .= replace_macros(get_markup_template('field_input.tpl'),
				[
					'$field' => [\SocialAuthConfig::getKey($name, 'key'), t('Key'), $key, t('Word')]
				]
			);
			$content .= replace_macros(get_markup_template('field_password.tpl'),
				[
					'$field' => [\SocialAuthConfig::getKey($name, 'secret'), t('Secret'), $secret, t('Word')]
				]
			);

			// endpoints for custom providers
			if ( \SocialAuthConfig::isCustomProvider($name) ) {

				$custom_endpoints = $provider["endpoints"];

				if ( x($custom_endpoints) ) {

					logger("Custom endpoints: " . print_r($custom_endpoints, true), LOGGER_DEBUG);

					foreach($custom_endpoints->toArray() as $prop => $value) {
						$content .= replace_macros(get_markup_template('field_input.tpl'),
							[
								'$field' => [ \SocialAuthConfig::getKey($name, $prop), $prop, $value, t('Word')]
							]
						);
					}
				} else {
					logger("Missing custom endpoints", LOGGER_NORMAL, LOG_ERR);
				}

				$custom_scope = $provider["scope"];
				$content .= replace_macros(get_markup_template('field_input.tpl'),
					[
						'$field' => [ \SocialAuthConfig::getKey($name, "scope"), "Scope", $custom_scope, t('Word')]
					]
				);
			}

		}

		$content .= replace_macros( get_markup_template('field_input.tpl'),
			[
				'$field' => ["CustomProvider", t('Add a custom provider'), "", t('Word')]
			]
		);

                $configured_providers = array();
                foreach ($allconfiguredproviders as $provider) {
                        // assigning provider name as key here because of field_select checkbox dependency
                        // if we don't, then default selection does not work correctly as "anystring" == 0 in php&smarty
                        $configured_providers[$provider] = $provider;
                }

		$remove_provider_select_array = Array( "None" => "None" );
		$remove_provider_select_array = array_merge(
			$remove_provider_select_array,
			$configured_providers
		);

		$content .= replace_macros(get_markup_template('field_select.tpl'), array(
			"field" => Array(
				"RemoveProvider",
				t('Remove an identity provider'),
				"None",
				"Select a provider to remove",
				$remove_provider_select_array
			)
		));

		$o = replace_macros(get_markup_template('settings_addon.tpl'), array(
			'$action_url' => 'socialauth',
			'$form_security_token' => get_form_security_token("socialauth"),
			'$title' => t('Social authentication'),
			'$content'  => $content,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}

	function post() {

		if(! local_channel())
			return;

		if(! is_site_admin())
			return;

		if(! Apps::addon_app_installed(local_channel(),'socialauth'))
			return;

		check_form_security_token_redirectOnErr('/socialauth', 'socialauth');

		require_once __DIR__ . '/include/config.php';

		$removeprovider = $_POST["RemoveProvider"];
		if ($removeprovider === "None") {
			// no action
		} else {
			\SocialAuthConfig::removeProvider($removeprovider);
			logger("Removed provider " . print_r($removeprovider, true), LOGGER_DEBUG);
		}

		$newprovider = $_POST["Provider"];
		if ($newprovider === "None") {
			// no action
		} else {
			// pre-configured provider
			$builtinProviders = \SocialAuthConfig::getBuiltinProviders();
			$newproviderval = $builtinProviders[ $newprovider ];

			if (! x($newproviderval) ) {
				logger("Unsupported provider <" . print_r($newprovider, true) . ">", LOGGER_ERROR);
				info( t('Error while saving provider settings') . EOL );
				return;
			}

			\SocialAuthConfig::addProvider($newproviderval);
			logger("Added new provider ". print_r($newproviderval, true), LOGGER_DEBUG);
		}

		// handle custom provider
		$argnewcustomprovider = $_POST["CustomProvider"];
		if ( x($argnewcustomprovider) ) {
			// PHP will replace dots and spaces by an underscore for POST arguments
			// as we will used the value of the CustomProvider parameter later as POST argument, we make the conversion already
			$newcustomprovider = str_replace(array(".", " "), "_", $argnewcustomprovider);
			if ($newcustomprovider !== $argnewcustomprovider) {
				logger('Converted new custom provider name ' . print_r($argnewcustomprovider, true) . ' to ' . print_r($newcustomprovider, true), LOGGER_INFO);
			}

			if ( \SocialAuthConfig::addCustomProvider( $newcustomprovider ) ) {
				logger("Added new custom provider ". print_r( $newcustomprovider, true), LOGGER_DEBUG);
			}
			else {
				logger("Adding new custom provider " . print_r ($newcustomprovider, true) . " failed - already exists", LOGGER_ERROR);
				info( t('Custom provider already exists') . EOL );
			}
		}

		// if 'enabled' is set to 'No', then the 'enabled' parameter is not present in POST array. So we disable by default
		foreach (\SocialAuthConfig::getConfiguredProviders() as $name)
		{
			$key = \SocialAuthConfig::getKey($name, 'enabled');
			$enabled = x($_POST, $key) ? 1 : 0;
			\SocialAuthConfig::save($key, $enabled);
		}

		// saving will silently fail if it's not an existing parameter
		foreach ($_POST as $param => $value) {
			\SocialAuthConfig::save($param, $value);
		}

		info( t('Social authentication settings saved.') . EOL);
	}
}

