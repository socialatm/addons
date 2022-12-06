<?php

namespace Hybridauth\Provider;

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Data;
use Hybridauth\User;
use Hybridauth\Exception\UnexpectedApiResponseException;

class Customauth extends OAuth2 {

	// custom Provider's API user endpoint - see provider API docs
	protected $social_auth_user_endpoint = '';

	// custom Provider's user profile 'email' field containing the user's email address
	protected $social_auth_email_field = '';

	public function setUserEndPoint( $userEndPoint ) {
		$this->social_auth_user_endpoint = $userEndPoint;
	}

	public function setUserProfileEmailField( $emailField ) {
		$this->social_auth_email_field = $emailField;
	}

	public function getUserProfile() {

		$response = $this->apiRequest($this->social_auth_user_endpoint);
		$data = new Data\Collection($response);

		// default email field = 'email'
		$customEmailField = $this->social_auth_email_field;
		if ( !x( $customEmailField ) ) {
			$customEmailField = 'email';
		}

		# Email is a mandatory field to log in
		if (! $data->exists( $customEmailField ) ) {
		    throw new UnexpectedApiResponseException('Email address missing from Provider API response.');
		}

		$userProfile = new User\Profile();
		$userProfile->email = $data->get( $customEmailField );

		return $userProfile;

	}

}
