<?php
/**
 * Name: TOTP
 * Description: TOTP two-factor authentication
 * Version: 0.1
 * Depends: Core
 * Recommends: None
 * Category: authentication
 * Author: Pete Yadlowsky <pm@yadlowsky.us>
 * Maintainer: Pete Yadlowsky <pm@yadlowsky.us>
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Lib\AConfig;

function totp_module(){};
function totp_load() {
	register_hook('construct_page', 'addon/totp/totp.php',
		'totp_construct_page');
	register_hook('logged_in', 'addon/totp/totp.php',
		'totp_logged_in');
	register_hook('app_installed_filter', 'addon/totp/totp.php',
		'totp_app_installed');
	Zotlabs\Extend\Hook::register('module_loaded',
		'addon/totp/totp.php','totp_module_loaded');
	Zotlabs\Extend\Route::register('addon/totp/Settings/Totp.php',
		'settings/totp');
	}
function totp_unload() {
	unregister_hook('construct_page', 'addon/totp/totp.php',
		'totp_construct_page');
	unregister_hook('logged_in', 'addon/totp/totp.php',
		'totp_logged_in');
	unregister_hook('app_installed_filter', 'addon/totp/totp.php',
		'totp_app_installed');
	Zotlabs\Extend\Hook::unregister_by_file('addon/totp/totp.php');
	Zotlabs\Extend\Route::unregister('addon/totp/Settings/Totp.php',
		'settings/totp');
	}
function get_secret($acct_id) {
	return AConfig::get($acct_id, 'totp', 'secret', null);
	}
function totp_installed() {
	$id = local_channel();
	if (!$id) return false;
	return Apps::addon_app_installed($id, 'totp');
	}
function totp_app_installed(&$app, &$filter) {
	if (!totp_installed()) return;
	if ($filter['app']['plugin'] != 'totp') return;
	if (count($filter['installed']) < 1) return;
	$account = App::get_account();
	if (is_null(get_secret($account['account_id']))) {
		$_SESSION['ALLOW_SETTINGS'] = true;
		goaway(z_root() . '/settings/totp');
		}
	}
function totp_module_loaded(&$x) {
	//if (!totp_installed()) return;
	if ($x['module'] == 'totp') {
		require_once('addon/totp/Mod_Totp.php');
		$x['controller'] = new \Zotlabs\Module\TOTPController();
		$x['installed'] = true;
		}
	}
/**
* @brief Do we need to 2FA-verify?
*
* Determine whether 2FA verification is needed and, if so,
* route user to verification form.
*
*/
function totp_logged_in(&$a, &$user) {
	if (!totp_installed()) return;
	if (isset($_SESSION['2FA_VERIFIED'])) return;
	$mod = App::$module;
	$settings_ok = (($mod == 'settings')
		&& isset($_SESSION['ALLOW_SETTINGS']));
	if (($mod == 'totp') # avoid infinite recursion
			|| ($mod == 'ping') # Don't redirect essential
			|| ($mod == 'view') # system modules.
			|| ($mod == 'acl')
			|| ($mod == 'photo')
			|| $settings_ok
			) return;
	$account = App::get_account();
	if (is_null(get_secret($account['account_id']))) {
		$_SESSION['ALLOW_SETTINGS'] = true;
		goaway(z_root() . '/settings/totp');
		}
	goaway(z_root() . '/totp');
	return;
	}
function totp_construct_page(&$a, &$b){
	if (!totp_installed()) return;

	// Whatever you put in settings, will show up on the left nav of your pages.
	$b['layout']['region_aside'] .= '<div>' .
		#htmlentities($some_setting) .
		#"<pre>" . htmlentities(print_r(App::get_account(), true)) . "</pre>" .
		'</div>';
	}
