<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Ljpost extends Controller {

	function post() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'ljpost'))
			return;

		check_form_security_token_redirectOnErr('/ljpost', 'ljpost');

		set_pconfig(local_channel(),'ljpost','lj_username',trim($_POST['lj_username']));
		set_pconfig(local_channel(),'ljpost','lj_password',obscurify(trim($_POST['lj_password'])));
		set_pconfig(local_channel(),'ljpost','post_by_default',intval($_POST['lj_by_default']));
		set_pconfig(local_channel(),'ljpost','post_wall2wall',intval($_POST['lj_wall2wall']));
		set_pconfig(local_channel(),'ljpost','post_source_url',intval($_POST['lj_source_url']));
	}


	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'ljpost')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Livejournal Crosspost Connector');
			return Apps::app_render($papp, 'module');
		}

		/* Get the current state of our config variables */
		$ljpost_on = get_pconfig(local_channel(),'ljpost','post_by_default');
		if(! $ljpost_on)
			set_pconfig(local_channel(),'ljpost','post_wall2wall',false);

		$lj_username = get_pconfig(local_channel(), 'ljpost', 'lj_username');
		$lj_password = unobscurify(get_pconfig(local_channel(), 'ljpost', 'lj_password'));


		/* Add some HTML to the existing form */

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('lj_username', t('Livejournal username'), $lj_username, '')
		));

		$sc .= replace_macros(get_markup_template('field_password.tpl'), array(
			'$field'	=> array('lj_password', t('Livejournal password'), $lj_password, '')
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('lj_by_default', t('Post to Livejournal by default'), ($ljpost_on ? 1 : false), '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('lj_wall2wall', t('Send wall-to-wall posts to Livejournal'), (get_pconfig(local_channel(),'ljpost','post_wall2wall') ? 1 : false), '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('lj_source_url', t('Add link to original post'), (get_pconfig(local_channel(),'ljpost','post_source_url') ? 1 : false), '', array(t('No'),t('Yes'))),
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'ljpost',
			'$form_security_token' => get_form_security_token("ljpost"),
			'$title' => t('Livejournal Crosspost Connector'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}
}
