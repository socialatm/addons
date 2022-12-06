<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Pageheader extends Controller {

	function post() {

		if(! is_site_admin())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'pageheader'))
			return;

		check_form_security_token_redirectOnErr('/pageheader', 'pageheader');

		set_config('pageheader','text',trim(strip_tags($_POST['pageheader-words'])));
		info( t('pageheader Settings saved.') . EOL);
	}

	function get() {

		if(! is_site_admin())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'pageheader')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Page Header');
			return Apps::app_render($papp, 'module');
		}

		$words = get_config('pageheader','text');
		if(! $words)
			$words = '';

		$sc .= '<label id="pageheader-label" for="pageheader-words">' . t('Message to display on every page on this server') . ' </label>';
		$sc .= '<textarea class="form-control form-group" id="pageheader-words" type="text" name="pageheader-words">' . $words . '</textarea>';

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'pageheader',
			'$form_security_token' => get_form_security_token('pageheader'),
			'$title' => t('Page Header'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;


	}
}
