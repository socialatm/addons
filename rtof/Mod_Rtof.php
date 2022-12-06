<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Rtof extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'rtof'))
			return;

		check_form_security_token_redirectOnErr('/rtof', 'rtof');

		set_pconfig(local_channel(), 'rtof', 'baseapi',         trim($_POST['rtof_baseapi']));
		set_pconfig(local_channel(), 'rtof', 'username',        trim($_POST['rtof_username']));
		set_pconfig(local_channel(), 'rtof', 'password',        obscurify(trim($_POST['rtof_password'])));
		set_pconfig(local_channel(), 'rtof', 'post_by_default', intval($_POST['rtof_default']));
		info( t('Friendica Crosspost Connector Settings saved.') . EOL);

	}

	function get() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'rtof')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Friendica Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		$api     = get_pconfig(local_channel(), 'rtof', 'baseapi');
		$username    = get_pconfig(local_channel(), 'rtof', 'username' );
		$password = unobscurify(get_pconfig(local_channel(), 'rtof', 'password' ));
		$defenabled = get_pconfig(local_channel(),'rtof','post_by_default');
		$defchecked = (($defenabled) ? 1 : false);


		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('rtof_default', t('Send public postings to Friendica by default'), $defchecked, '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('rtof_baseapi', t('Friendica API Path'), $api, t('https://{sitename}/api'))
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('rtof_username', t('Friendica login name'), $username, t('Email'))
		));

		$sc .= replace_macros(get_markup_template('field_password.tpl'), array(
			'$field'	=> array('rtof_password', t('Friendica password'), $password, '')
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'rtof',
			'$form_security_token' => get_form_security_token('rtof'),
			'$title' => t('Friendica Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}

}

