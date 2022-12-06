<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Xmpp extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'xmpp'))
			return;

		check_form_security_token_redirectOnErr('/xmpp', 'xmpp');

		set_pconfig(local_channel(),'xmpp','individual',intval($_POST['xmpp_individual']));
		set_pconfig(local_channel(),'xmpp','bosh_proxy',$_POST['xmpp_bosh_proxy']);

		info( t('XMPP settings updated.') . EOL);
	}

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'xmpp')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';

			$o = '<b>' . t('XMPP App') . ' (' . t('Not Installed') . '):</b><br>';
			$o .= t('Embedded XMPP (Jabber) client');
			return $o;
		}


		/* Get the current state of our config variable */

		$individual = intval(get_pconfig(local_channel(),'xmpp','individual'));
		$individual_checked = (($individual) ? ' checked="checked" ' : '');

		$bosh_proxy = get_pconfig(local_channel(),"xmpp","bosh_proxy");

		$sc = '';

		if(get_config("xmpp", "central_userbase")) {
			$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				'$field'    => array('xmpp_individual', t('Individual credentials'), $individual, '')
			));
		}

		if((! get_config("xmpp", "central_userbase")) || (get_pconfig(local_channel(),"xmpp","individual"))) {
			$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
				'$field'    => array('xmpp_bosh_proxy', t('Jabber BOSH server'), $bosh_proxy, '')
			));
		}

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'xmpp',
			'$form_security_token' => get_form_security_token("xmpp"),
			'$title' => t('XMPP Settings'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;

	}

}

