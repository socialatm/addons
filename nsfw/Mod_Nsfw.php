<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Nsfw extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(),'nsfw'))
			return;

		check_form_security_token_redirectOnErr('/nsfw', 'nsfw');

		set_pconfig(local_channel(),'nsfw','words',trim($_POST['nsfw-words']));

		info( t('NSFW Settings saved.') . EOL);
	}

	function get() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'nsfw')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('NSFW');
			return Apps::app_render($papp, 'module');
		}

		$words = get_pconfig(local_channel(),'nsfw','words');

		if(! $words)
			$words = 'nsfw,contentwarning,';

		$content .= '<div class="section-content-info-wrapper">';
		$content .= t('This app looks in posts for the words/text you specify below, and collapses any content containing those keywords so it is not displayed at inappropriate times, such as sexual innuendo that may be improper in a work setting. It is polite and recommended to tag any content containing nudity with #NSFW.  This filter can also match any other word/text you specify, and can thereby be used as a general purpose content filter.');
		$content .= '</div>';

		$content .= replace_macros(get_markup_template('field_input.tpl'),
			[
				'$field' => ['nsfw-words', t('Comma separated list of keywords to hide'), $words, t('Word, /regular-expression/, lang=xx, lang!=xx')]
			]
		);

		$tpl = get_markup_template("settings_addon.tpl");

		$o = replace_macros($tpl, array(
			'$action_url' => 'nsfw',
			'$form_security_token' => get_form_security_token("nsfw"),
			'$title' => t('NSFW'),
			'$content'  => $content,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;
	}

}
