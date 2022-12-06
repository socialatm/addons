<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Libertree extends Controller {

	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'libertree'))
			return;

		check_form_security_token_redirectOnErr('/libertree', 'libertree');

		set_pconfig(local_channel(),'libertree','post_by_default',intval($_POST['libertree_bydefault']));
		set_pconfig(local_channel(),'libertree','libertree_api_token',trim($_POST['libertree_api_token']));
		set_pconfig(local_channel(),'libertree','libertree_url',trim($_POST['libertree_url']));

		info( t('Libertree Crosspost Connector Settings saved.') . EOL);

	}

	function get() {

		if(! Apps::addon_app_installed(local_channel(), 'libertree')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Libertree Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		$def_enabled = get_pconfig(local_channel(),'libertree','post_by_default');

		$def_checked = (($def_enabled) ? 1 : false);

		$ltree_api_token = get_pconfig(local_channel(), 'libertree', 'libertree_api_token');
		$ltree_url = get_pconfig(local_channel(), 'libertree', 'libertree_url');


		/* Add some HTML to the existing form */

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('libertree_api_token', t('Libertree API token'), $ltree_api_token, '')
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('libertree_url', t('Libertree site URL'), $ltree_url, '')
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('libertree_bydefault', t('Post to Libertree by default'), $def_checked, '', array(t('No'),t('Yes'))),
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'libertree',
			'$form_security_token' => get_form_security_token("libertree"),
			'$title' => t('Libertree Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}
}

