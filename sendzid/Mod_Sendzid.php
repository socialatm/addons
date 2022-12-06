<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Sendzid extends Controller {
	function get() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'sendzid')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Send ZID');
			return Apps::app_render($papp, 'module');
		}

		$desc = t('Send your identity to all websites');
		$content = '<div class="section-content-info-wrapper">' . $desc . '</div>';

		$tpl = get_markup_template("settings_addon.tpl");

		$o .= replace_macros($tpl, array(
			'$action_url' => '',
			'$form_security_token' => '',
			'$title' => t('Send ZID'),
			'$content'  => $content,
			'$baseurl'   => z_root(),
			'$submit'    => ''
		));

		return $o;
	}
}
