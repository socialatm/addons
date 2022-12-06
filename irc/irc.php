<?php
/**
* Name: IRC Chat Plugin
* Description: add an Internet Relay Chat chatroom
* Version: 1.0
* Author: tony baldwin <https://free-haven.org/profile/tony>
* Maintainer: none
*/

/* enable in admin->plugins
 * you will then have "irc chatroom" listed at yoursite/apps
 * and the app will run at yoursite/irc
 * documentation at http://tonybaldwin.me/hax/doku.php?id=friendica:irc
 * admin can set popular chans, auto connect chans in settings->plugin settings
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function irc_load() {
	register_hook('app_menu', 'addon/irc/irc.php', 'irc_app_menu');
	Route::register('addon/irc/Mod_Irc.php','irc');
}

function irc_unload() {
	unregister_hook('app_menu', 'addon/irc/irc.php', 'irc_app_menu');
	Route::unregister('addon/irc/Mod_Irc.php','irc');
}

function irc_plugin_admin(&$s) {
	/* setting popular channels, auto connect channels */
	$sitechats = get_config('irc','sitechats'); /* popular channels */
	$autochans = get_config('irc','autochans');  /* auto connect chans */

	$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
		'$field'	=> array('autochans', t('Channels to auto connect'), $autochans, t('Comma separated list'))
	));

	$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
		'$field'	=> array('sitechats', t('Popular Channels'), $sitechats, t('Comma separated list'))
	));

	$s .= replace_macros(get_markup_template('generic_addon_settings.tpl'), array(
		'$addon' 	=> array('irc', t('IRC Settings'), '', t('Submit')),
		'$content'	=> $sc
	));
}

function irc_plugin_admin_post() {
	set_config('irc','autochans',trim($_POST['autochans']));
	set_config('irc','sitechats',trim($_POST['sitechats']));
	/* stupid pop-up thing */
	info( t('IRC settings saved.') . EOL);
}

function irc_app_menu($a,&$b) {
	$b['app_menu'][] = '<div class="app-title"><a href="irc">' . t('IRC Chatroom') . '</a></div>';
}


