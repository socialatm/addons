<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Photocache extends Controller {

	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'photocache'))
			return;

		check_form_security_token_redirectOnErr('/photocache', 'photocache');

		$minres = intval($_POST['cache_minres']);
		if($minres > 1024)
			$minres = 1024;

		set_pconfig(local_channel(), 'photocache', 'cache_minres', $minres);

		info(t('Photo Cache settings saved.') . EOL);
	}


	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'photocache')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Photo Cache');
			return Apps::app_render($papp, 'module');
		}

		$desc = t('Saves a copy of images from external sites locally to increase your anonymity in the web.');
		$sc = '<div class="section-content-info-wrapper">' . $desc . '</div><br>';

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field' => array(
				'cache_minres',
				t('Minimal photo size for caching'),
				get_pconfig(local_channel(),'photocache','cache_minres', 0),
				t('In pixels. From 1 up to 1024, 0 will be replaced with system default.')
			),
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o .= replace_macros($tpl, array(
			'$action_url' => 'photocache',
			'$form_security_token' => get_form_security_token('photocache'),
			'$title' => t('Photo Cache'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}
}
