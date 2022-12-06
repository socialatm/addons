<?php

// wrapper for configuration mgmt
class SocialAuthConfig {

	// prevents instantiation
	private function __construct() {}

	static private $customPrefix = 'custom_';
	static private $supportedCustomEndpoints = ['api_base_url','authorize_url','access_token_url','user_endpoint','userprofile_email_field'];

	static private function createPrefix($provider) {
		return $provider.'_';
	}

	static public function getKey($provider, $key) {
		return self::createPrefix($provider).$key;
	}

	static public function isCustomProvider($provider) {
		return strpos($provider, self::$customPrefix) === 0;
	}

	static public function getCustomProviderName($provider) {
		return substr($provider, strlen(self::$customPrefix));
	}

	static public function getCustomProviderEndpoints() {
		return self::$supportedCustomEndpoints;
	}

	static public function getCallback() {
		return z_root() . '/socialauthsignin';
	}

	static public function getBuiltinProviders() {

		$hybridauth_providerdir = join(DIRECTORY_SEPARATOR, array(__DIR__,'..','vendor','hybridauth','hybridauth','src','Provider'));
		$providers = scandir($hybridauth_providerdir);

		if ($providers === false) {
			logger('Error retrieving list of Hybridauth providers', LOGGER_NORMAL, LOG_ERR);
			return array();
		}

		$providers = array_diff( $providers, array('.','..') );
		$builtin_providers = array();
		foreach ($providers as $provider) {

			// strip .php postfix from filename
			$providername = basename($provider, '.php');

			// assigning provider name as key here because of field_select checkbox dependency
			// if we don't, then default selection does not work correctly as "anystring" == 0 in php&smarty
			$builtin_providers[$providername] = $providername;
		}

		return $builtin_providers;
	}

	static public function getConfiguredProviders() {
		$providerString = get_config('socialauth', 'providers');
		if ( !x($providerString) ) {
			return Array();
		}

		$providerList = explode(",", get_config('socialauth', 'providers') );

		return $providerList;
	}

	static public function addProvider($provider) {
		$providerList = self::getConfiguredProviders();
		$index = array_search( $provider, $providerList );

		if ($index === false) {	
			$providerList = get_config('socialauth', 'providers');

			if ( x($providerList) ) {
				$providerList .= "," . $provider;
			} else {
				$providerList = $provider;
			}

			set_config('socialauth', 'providers', $providerList);

			logger('Added new provider ' . $provider, LOGGER_DEBUG);

			return true;
		}

		logger('Provider '. $provider . ' already exists', LOGGER_ERROR);
		return false;
		
	}

	static public function addCustomProvider($provider) {
		return self::addProvider(self::$customPrefix . $provider);
	}

	static public function removeProvider($provider) {
		$providerList = self::getConfiguredProviders();

		$index = array_search( $provider, $providerList );

		if ($index === false) {	
			logger('Provider '. $provider .' not found in list of configured providers', LOGGER_DEBUG); 
			return false;
		} else {
			unset($providerList[$index]);
			$newproviderList = implode(",", $providerList );
			set_config('socialauth', 'providers', $newproviderList);
		}

		return true;
	}

	static public function removeCustomProvider($provider) {
		return self::removeProvider($customPrefix . $provider);
	}

	// Get Hybridauth config tailored for given provider
	static public function getProviderConfig( $provider )
	{
		logger('Getting provider config for '. print_r($provider, true), LOGGER_DEBUG);

		$enabled = get_config('socialauth', self::getKey($provider, 'enabled')) == 1 ? true : false;
		$key = get_config('socialauth', self::getKey($provider, 'key'));
		$secret = get_config('socialauth', self::getKey($provider, 'secret'));

		$r = [
			$provider => [
				'enabled' => $enabled,
				'keys' => [
					'key' => $key,
					'secret' => $secret,
				],
			],
		];

		if (self::isCustomProvider($provider)) {
			$endpoints = array();
			foreach (self::getCustomProviderEndpoints() as $endpoint) {
				$endpoints[$endpoint] = get_config('socialauth', self::getKey($provider,$endpoint));
			}

			require __DIR__ . '/../vendor/autoload.php';
			$endpoint_collection = new \Hybridauth\Data\Collection($endpoints);

			$r[$provider]['endpoints'] = $endpoint_collection;
			$r[$provider]['adapter'] = '\Hybridauth\Provider\Customauth';
			$r[$provider]['scope'] = get_config('socialauth', self::getKey($provider, "scope"));
		}
 
		$hybridauthconfig = [
			'callback' => self::getCallback(),
			'providers' => $r,
		];

		return $hybridauthconfig;
	}

	// try to save a configuration field related to socialauth app
	// If it's not a social auth app configuration field, then it is silently ignored
	static public function save($field, $value) {

		foreach(self::getConfiguredProviders() as $provider) {
			$keys = ['enabled', 'key', 'secret'];

			if (self::isCustomProvider($provider)) {
				foreach (self::getCustomProviderEndpoints() as $endpoint) {
					$keys[] = $endpoint;
				}
				$keys[] = "scope";
			}

			foreach($keys as $key) {
				if ( $field === self::getKey($provider, $key) ) {
					logger('Setting config for key '. $field, LOGGER_DEBUG);
					set_config('socialauth', $field, $value);
				}
			}

		}
	}
 
}
