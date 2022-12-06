<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Ijpost extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'ijpost'))
			return;

		check_form_security_token_redirectOnErr('/ijpost', 'ijpost');

		set_pconfig(local_channel(),'ijpost','post_by_default',intval($_POST['ij_bydefault']));
		set_pconfig(local_channel(),'ijpost','ij_username',trim($_POST['ij_username']));
		set_pconfig(local_channel(),'ijpost','ij_password',obscurify(trim($_POST['ij_password'])));
	        info( t('Insane Journal Crosspost Connector Settings saved.') . EOL);
	}

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'ijpost')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';

			$o = '<b>' . t('Insane Journal Crosspost Connector App') . ' (' . t('Not Installed') . '):</b><br>';
			$o .= t('Relay public postings to Insane Journal');
			return $o;
		}

		/* Get the current state of our config variables */

		$def_enabled = get_pconfig(local_channel(),'ijpost','post_by_default');

		$def_checked = (($def_enabled) ? 1 : false);

		$ij_username = get_pconfig(local_channel(), 'ijpost', 'ij_username');
		$ij_password = unobscurify(get_pconfig(local_channel(), 'ijpost', 'ij_password'));


		/* Add some HTML to the existing form */

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('ij_username', t('InsaneJournal username'), $ij_username, '')
		));

		$sc .= replace_macros(get_markup_template('field_password.tpl'), array(
			'$field'	=> array('ij_password', t('InsaneJournal password'), $ij_password, '')
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('ij_bydefault', t('Post to InsaneJournal by default'), $def_checked, '', array(t('No'),t('Yes'))),
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'ijpost',
			'$form_security_token' => get_form_security_token('ijpost'),
			'$title' => t('Insane Journal Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;

	}

}
