<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Pumpio extends Controller {


	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'pumpio'))
			return;

		check_form_security_token_redirectOnErr('/pumpio', 'pumpio');

		// filtering the username if it is filled wrong
		$user = $_POST['pumpio_user'];
		if (strstr($user, "@")) {
			$pos = strpos($user, "@");
			if ($pos > 0)
				$user = substr($user, 0, $pos);
		}

		// Filtering the hostname if someone is entering it with "http"
		$host = $_POST['pumpio_host'];
		$host = trim($host);
		$host = str_replace(array("https://", "http://"), array("", ""), $host);

		set_pconfig(local_channel(),'pumpio','host',$host);
		set_pconfig(local_channel(),'pumpio','user',$user);
		set_pconfig(local_channel(),'pumpio','public',$_POST['pumpio_public']);
		set_pconfig(local_channel(),'pumpio','mirror',$_POST['pumpio_mirror']);
		set_pconfig(local_channel(),'pumpio','post_by_default',intval($_POST['pumpio_bydefault']));
		info( t('Pump.io Settings saved.') . EOL);

	}

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'pumpio')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Pump.io Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		$def_enabled = get_pconfig(local_channel(),'pumpio','post_by_default');
		$def_checked = (($def_enabled) ? 1 : false);

		$public_enabled = get_pconfig(local_channel(),'pumpio','public');
		$public_checked = (($public_enabled) ? 1 : false);

		$mirror_enabled = get_pconfig(local_channel(),'pumpio','mirror');
		$mirror_checked = (($mirror_enabled) ? 1 : false);

		$servername = get_pconfig(local_channel(), "pumpio", "host");
		$username = get_pconfig(local_channel(), "pumpio", "user");

		/* Add some HTML to the existing form */

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('pumpio_host', t('Pump.io servername'), $servername, t('Without "http://" or "https://"'))
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('pumpio_user', t('Pump.io username'), $username, t('Without the servername'))
		));


		if (($username != '') AND ($servername != '')) {

			$oauth_token = get_pconfig(local_channel(), "pumpio", "oauth_token");
			$oauth_token_secret = get_pconfig(local_channel(), "pumpio", "oauth_token_secret");

			if (($oauth_token == "") OR ($oauth_token_secret == "")) {
				$sc .= '<div class="section-content-danger-wrapper">';
				$sc .= '<strong>' . t("You are not authenticated to pumpio") . '</strong>';
				$sc .= '</div>';
				$sc .= '<a href="'.z_root().'/pumpio/connect" class="btn btn-primary btn-xs">'.t("(Re-)Authenticate your pump.io connection").'</a>';
			}

			$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				'$field'	=> array('pumpio_bydefault', t('Post to pump.io by default'), $def_checked, '', array(t('No'),t('Yes'))),
			));

			$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				'$field'	=> array('pumpio_public', t('Should posts be public'), $public_checked, '', array(t('No'),t('Yes'))),
			));

			$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				'$field'	=> array('pumpio_mirror', t('Mirror all public posts'), $mirror_checked, '', array(t('No'),t('Yes'))),
			));

		}

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'pumpio',
			'$form_security_token' => get_form_security_token("pumpio"),
			'$title' => t('Pump.io Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}

}
