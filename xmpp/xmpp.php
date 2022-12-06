<?php
/**
 * Name: XMPP (Jabber)
 * Description: Embedded XMPP (Jabber) client
 * Version: 0.1
 * Author: Michael Vogel <https://pirati.ca/profile/heluecht>
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function xmpp_load() {
	register_hook('page_end', 'addon/xmpp/xmpp.php', 'xmpp_script');
	register_hook('change_channel', 'addon/xmpp/xmpp.php', 'xmpp_login');

	Route::register('addon/xmpp/Mod_Xmpp.php','xmpp');
}

function xmpp_unload() {
	unregister_hook('page_end', 'addon/xmpp/xmpp.php', 'xmpp_script');
	unregister_hook('logged_in', 'addon/xmpp/xmpp.php', 'xmpp_login');
	unregister_hook('change_channel', 'addon/xmpp/xmpp.php', 'xmpp_login');

	Route::unregister('addon/xmpp/Mod_Xmpp.php','xmpp');
}

function xmpp_login($a,$b) {

	if(! local_channel())
		return;

	if (! $_SESSION['allow_api']) {
		$password = random_string(16);
		set_pconfig(local_channel(), "xmpp", "password", $password);
	}
}

function xmpp_plugin_admin(&$o){
	$t = get_markup_template("admin.tpl", "addon/xmpp/");

	$o = replace_macros($t, array(
		'$submit' => t('Save Settings'),
		'$bosh_proxy'       => array('bosh_proxy', t('Jabber BOSH host'),            get_config('xmpp', 'bosh_proxy'), ''),
		'$central_userbase' => array('central_userbase', t('Use central userbase'), get_config('xmpp', 'central_userbase'), t('If enabled, members will automatically login to an ejabberd server that has to be installed on this machine with synchronized credentials via the "auth_ejabberd.php" script.')),
	));
}

function xmpp_plugin_admin_post(){
	$bosh_proxy       = ((x($_POST,'bosh_proxy')) ?       trim($_POST['bosh_proxy']) : '');
	$central_userbase = ((x($_POST,'central_userbase')) ? intval($_POST['central_userbase']) : false);
	set_config('xmpp','bosh_proxy',$bosh_proxy);
	set_config('xmpp','central_userbase',$central_userbase);
	info( t('Settings updated.'). EOL );
}

function xmpp_script(&$a,&$s) {
	xmpp_converse($a,$s);
}

function xmpp_converse(&$a,&$s) {
	if (!local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(),'xmpp'))
		return;

	if ($_GET["mode"] == "minimal")
		return;

	App::$page['htmlhead'] .= '<link type="text/css" rel="stylesheet" media="screen" href="addon/xmpp/converse/css/converse.css" />'."\n";
	App::$page['htmlhead'] .= '<script src="addon/xmpp/converse/builds/converse.min.js"></script>'."\n";

	if (get_config("xmpp", "central_userbase") && !get_pconfig(local_channel(),"xmpp","individual")) {
		$bosh_proxy = get_config("xmpp", "bosh_proxy");

		$password = get_pconfig(local_channel(), "xmpp", "password");

		if ($password == "") {
			$password = substr(random_string(),0,16);
			set_pconfig(local_channel(), "xmpp", "password", $password);
		}
		$channel = App::get_channel();

		$jid = $channel["channel_address"]."@".App::get_hostname()."/converse-".substr(random_string(),0,5);;

		$auto_login = "auto_login: true,
			authentication: 'login',
			jid: '$jid',
			password: '$password',
			allow_logout: false,";
	} else {
		$bosh_proxy = get_pconfig(local_channel(), "xmpp", "bosh_proxy");

		$auto_login = "";
	}

	if ($bosh_proxy == "")
		return;

	if (in_array(argv(0), array("manage", "logout")))
		$additional_commands = "converse.user.logout();\n";
	else
		$additional_commands = "";

	$on_ready = "";

	$initialize = "converse.initialize({
					bosh_service_url: '$bosh_proxy',
					keepalive: true,
					message_carbons: false,
					forward_messages: false,
					play_sounds: true,
					sounds_path: 'addon/xmpp/converse/sounds/',
					roster_groups: false,
					show_controlbox_by_default: false,
					show_toolbar: true,
					allow_contact_removal: false,
					allow_registration: false,
					hide_offline_users: true,
					allow_chat_pending_contacts: false,
					allow_dragresize: true,
					auto_away: 0,
					auto_xa: 0,
					csi_waiting_time: 300,
					auto_reconnect: true,
					$auto_login
					xhr_user_search: false
				});\n";

	App::$page['htmlhead'] .= "<script>
					require(['converse'], function (converse) {
						$initialize
						converse.listen.on('ready', function (event) {
							$on_ready
						});
						$additional_commands
					});
				</script>";
}

