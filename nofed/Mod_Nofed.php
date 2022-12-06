<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Nofed extends Controller {

	function post () {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'nofed'))
			return;

		check_form_security_token_redirectOnErr('/nofed', 'nofed');

		set_pconfig(local_channel(), 'nofed', 'post_by_default', intval($_POST['nofed_default']));
		info( t('nofed Settings saved.') . EOL);

	}

	function get() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'nofed')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('No Federation');
			return Apps::app_render($papp, 'module');
		}

		$defenabled = get_pconfig(local_channel(),'nofed','post_by_default');
		$defchecked = (($defenabled) ? 1 : false);

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('nofed_default', t('Federate posts by default'), $defchecked, '', array(t('No'),t('Yes'))),
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'nofed',
			'$form_security_token' => get_form_security_token('nofed'),
			'$title' => t('No Federation'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}
}
