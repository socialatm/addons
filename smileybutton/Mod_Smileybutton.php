<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Smileybutton extends Controller {

	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'smileybutton'))
			return;

		check_form_security_token_redirectOnErr('/smileybutton', 'smileybutton');

		set_pconfig(local_channel(),'smileybutton','nobutton',intval($_POST['smileybutton']));
		set_pconfig(local_channel(),'smileybutton','deactivated',intval($_POST['deactivated']));

	}

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'smileybutton')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Smileybutton');
			return Apps::app_render($papp, 'module');
		}

		$nobutton = get_pconfig(local_channel(),'smileybutton','nobutton');
		$checked['nobutton'] = (($nobutton) ? 1 : false);

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('smileybutton', t('Hide the button and show the smilies directly.'), $checked['nobutton'], '', array(t('No'),t('Yes'))),
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, [
			'$action_url' => 'smileybutton',
			'$form_security_token' => get_form_security_token('smileybutton'),
			'$title' => t('Smileybutton Settings'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		]);

		return $o;

	}

}

