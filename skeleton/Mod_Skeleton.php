<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Skeleton extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'skeleton'))
			return;

		check_form_security_token_redirectOnErr('/skeleton', 'skeleton');

		set_pconfig(local_channel(), 'skeleton', 'some_setting', $_POST['some_setting']);

	}

	function get() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'skeleton')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Skeleton');
			return Apps::app_render($papp, 'module');
		}

		$some_setting = get_pconfig( $id, 'skeleton', 'some_setting');

		$sc = replace_macros(get_markup_template('field_input.tpl'), [
				'$field' => ['some_setting', t('Some setting'), $some_setting, t('A setting')]
		]);

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, [
			'$action_url' => 'skeleton',
			'$form_security_token' => get_form_security_token('skeleton'),
			'$title' => t('Skeleton Settings'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		]);

		return $o;
	}

}




