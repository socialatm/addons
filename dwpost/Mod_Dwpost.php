<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Dwpost extends Controller {

	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'dwpost'))
			return;

		check_form_security_token_redirectOnErr('/dwpost', 'dwpost');

		set_pconfig(local_channel(),'dwpost','dw_username',trim($_POST['dw_username']));
		set_pconfig(local_channel(),'dwpost','dw_password',obscurify(trim($_POST['dw_password'])));
		set_pconfig(local_channel(),'dwpost','post_by_default',intval($_POST['dw_by_default']));
		set_pconfig(local_channel(),'dwpost','post_source_url',intval($_POST['dw_source_url']));
		set_pconfig(local_channel(),'dwpost','post_source_urltext',trim($_POST['dw_source_urltext']));
		info( t('Dreamwidth Crosspost Connector Settings saved.') . EOL);
	}


	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'dwpost')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Dreamwidth Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		/* Get the current state of our config variables */
		$dwpost_on = get_pconfig(local_channel(),'dwpost','post_by_default');
		$dw_username = get_pconfig(local_channel(), 'dwpost', 'dw_username');
		$dw_password = unobscurify(get_pconfig(local_channel(), 'dwpost', 'dw_password'));
		$dw_source_urltext = get_pconfig(local_channel(), 'dwpost', 'post_source_urltext');

		/* Add some HTML to the existing form */

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('dw_username', t('Dreamwidth username'), $dw_username, '')
		));

		$sc .= replace_macros(get_markup_template('field_password.tpl'), array(
			'$field'	=> array('dw_password', t('Dreamwidth password'), $dw_password, '')
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('dw_by_default', t('Post to Dreamwidth by default'), ($dwpost_on ? 1 : false), '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('dw_source_url', t('Add link to original post'), (get_pconfig(local_channel(),'dwpost','post_source_url') ? 1 : false), '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('dw_source_urltext', t('Link description (default:') . ' "' . t('Source') . '")', $dw_source_urltext, '')
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'dwpost',
			'$form_security_token' => get_form_security_token("dwpost"),
			'$title' => t('Dreamwidth Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}
}
