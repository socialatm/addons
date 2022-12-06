<?php

/**
 * Name: Social auth 
 * Description: Login to Hubzilla using a social account (Google, Facebook, Twitter etc or even a custom OAuth2 Provider) 
 * Version: 0.2
 * Author: Pascal Deklerck <http://hub.eenoog.org/profile/pascal>
 * Maintainer: Pascal Deklerck <pascal.deklerck@gmail.com> 
 */


use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function socialauth_load() {
	logger('Socialauth load', LOGGER_DEBUG);

	Hook::register('login_hook', 'addon/socialauth/socialauth.php', 'socialauth_login');

	Route::register('addon/socialauth/Mod_SocialAuth.php', 'socialauthsignin');
	Route::register('addon/socialauth/Mod_SocialAuth.php', 'socialauth');
}


function socialauth_unload() {
	logger('Socialauth unload', LOGGER_DEBUG);

	Hook::unregister('login_hook', 'addon/socialauth/socialauth.php', 'socialauth_login');

	Route::unregister('addon/socialauth/Mod_SocialAuth.php', 'socialauthsignin');
	Route::unregister('addon/socialauth/Mod_SocialAuth.php', 'socialauth');
}

function socialauth_login(&$o) {
	require_once __DIR__ . '/include/config.php';

	$providers = SocialAuthConfig::getConfiguredProviders();

	$adapter_output = "";

	logger('Providers: ' . print_r($providers, true), LOGGER_DEBUG);

	foreach($providers as $name) {
		$providerfullconfig = SocialAuthConfig::getProviderConfig($name);
		$provider = $providerfullconfig["providers"][$name];

		$providername = $name;
		if (SocialAuthConfig::isCustomProvider($name))
			$providername = SocialAuthConfig::getCustomProviderName($name);

		logger('Adding provider ' . $providername , LOGGER_DEBUG);

		if ( $provider['enabled'] ) {
			$buttonFileName = __DIR__ . '/buttons/button_'. $providername .'.html';
			if ( file_exists ( $buttonFileName ) )
			{
				$redirect_url = SocialAuthConfig::getCallback() . '?provider=' . $name;
				$adapter_output .= str_replace('socialauth_redirect', 'redirect_socialauth(\''. $redirect_url .'\');', file_get_contents($buttonFileName) );

			} else {
				logger('Button file missing - skipping provider '.$name, LOGGER_NORMAL, LOG_WARNING);
				$adapter_output .= '
<ul>
	<li>
		<a href="'. SocialAuthConfig::getCallback() . '?provider='. $name .'"/>Sign in with <strong>'. $providername .'</strong>
	</li>
</ul>
';
			}
		}
	}

	if ( !x($adapter_output) ) {
		logger('No providers found', LOGGER_DEBUG);
		return $o;
	}

	$o .= '
<head>
<script>
function redirect_socialauth(url) {
	location.href = url;
}
</script>
<style>
.socialauthlabel {
	padding-top: 2em;
	font-weight: bold;
	display: block;
	margin-bottom: .5rem;
	max-width: 400px;
	margin: auto;
}
</style>
</head>
<body>
<div class="socialauthlabel">
<span>Sign in with:</span>
</div>
</body>
'. $adapter_output;
 
	return $o;
}
