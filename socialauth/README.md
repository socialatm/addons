# Social authentication - Add-on for Hubzilla

Log in to Hubzilla with your social account 

## Installation
Note: this enables the feature for all users on the hub
Install the social auth app

## Configuration

### Adding a built-in provider
Select one of the providers from the drop-down box & submit
 
### Adding a custom provider
In the box "Add a custom provider", enter a descriptive name for the non built-in provider & submit

### Built-in provider configuration
Your provider docs should explain how to generate a key and a secret\
Enter the key and the secret & submit

### Custom provider configuration
Your provider docs should explain 
  - how to generate a key & secret
  - the authorize URL (initial call by the socialauth app)
  - the access token URL (second call by the socialauth app)
  - the user endpoint, i.e. how an external system can request a user profile from the provider e.g. "api/v1/user" (3rd call by the socialauth app)
  - scope. e.g. to configure Hubzilla as openid provider, use as scope "openid email"

Note: by default, the email address field in the user profile record is 'email'. If this field has another name, enter it in the parameter "userprofile_email_field"

### Enable provider
Toggle the Yes/No box next to 'Enable provider XXX' to 'Yes'

### Removing a provider
Select the (built-in or custom) provider from the drop-down list & submit\
Note: the values you entered for this provider remain in the database, so if you add the provider again, the last known values are restored

### Authorization callback URL
  - Create an OAuth2 application in the provider's interface 
  - Configure the provider's OAuth2 application to execute a callback to the Hubzilla socialauth app on "<your hub's base URL>/socialauthsignin"

## Usage
  * Prerequisites
    - You must be able to authenticate at the provider with an email address which exists in your hub

  * Expected behaviour
    - Log out of Hubzilla
    - On the login page, for each enabled provider, either
      * a sign-in button is shown
      * a link saying "Sign-in using <provider>" is shown (button contributions are welcome)
    - Click on the button/link
    - Your hub will send 
      * an authorization request
      * (upon callback from provider on your hub's Redirect URI) an access token request
      * a user profile request
    - Upon succesful profile request & match on an email address in Hubzilla database: login to Hubzilla as if you entered your Hubzilla username & password
    - In case sign-in fails, enable debug logging in Hubzilla, retry & check logs. If not clear, create an issue and attach these logs. 

## Limitations
  - Creating new accounts in Hubzilla is not supported through socialauth sign-in

## Notes
  * You shoud assume that your login attempts might logged at the Identity provider
  * It is assumed that, if the socialauth app is able to fetch the user's email address (because the user authenticated at the Identity provider), the identity is proven and login to Hubzilla is granted as if the user entered his or her Hubzilla credentials 
  * The socialauth app can select a scope for custom providers. The different scopes should be seperated by a space.
  * Often the full user profile (username, profile photo etc) is provided to the socialauth app, but only the email address is used to match against a Hubzilla account's email address
  * The author does not take responsibility for possible security issues introduced by this add-on. In other words, use at your own risk.

## Credits
The workhorse for this Hubzilla add-on is hybridauth (https://hybridauth.github.io/)
