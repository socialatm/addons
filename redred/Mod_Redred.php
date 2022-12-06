<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Redred extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'redred'))
			return;

		check_form_security_token_redirectOnErr('/redred', 'redred');

		$channel = App::get_channel();
		// Don't let somebody post to their self channel. Since we aren't passing message-id this would be very very bad.

		if(! trim($_POST['redred_channel'])) {
			notice( t('Channel is required.') . EOL);
			return;
		}

		if($channel['channel_address'] === trim($_POST['redred_channel'])) {
			notice( t('Invalid channel.') . EOL);
			return;
		}

		set_pconfig(local_channel(), 'redred', 'baseapi',         trim($_POST['redred_baseapi']));
		set_pconfig(local_channel(), 'redred', 'username',        trim($_POST['redred_username']));
		set_pconfig(local_channel(), 'redred', 'password',        obscurify(trim($_POST['redred_password'])));
		set_pconfig(local_channel(), 'redred', 'channel',         trim($_POST['redred_channel']));
		set_pconfig(local_channel(), 'redred', 'post_by_default', intval($_POST['redred_default']));
		info( t('Hubzilla Crosspost Connector Settings saved.') . EOL);

	}

	function get() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'redred')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Hubzilla Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		$api     = get_pconfig(local_channel(), 'redred', 'baseapi');
		$username    = get_pconfig(local_channel(), 'redred', 'username' );
		$password = unobscurify(get_pconfig(local_channel(), 'redred', 'password' ));
		$channel = get_pconfig(local_channel(), 'redred', 'channel' );
		$defenabled = get_pconfig(local_channel(),'redred','post_by_default');
		$defchecked = (($defenabled) ? 1 : false);

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('redred_default', t('Send public postings to Hubzilla channel by default'), $defchecked, '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('redred_baseapi', t('Hubzilla API Path'), $api, t('https://{sitename}/api'))
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('redred_username', t('Hubzilla login name'), $username, t('Email'))
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('redred_channel', t('Hubzilla channel name'), $channel, t('Nickname'))
		));

		$sc .= replace_macros(get_markup_template('field_password.tpl'), array(
			'$field'	=> array('redred_password', t('Hubzilla password'), $password, '')
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'redred',
			'$form_security_token' => get_form_security_token('redred'),
			'$title' => t('Hubzilla Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;

	}
}

