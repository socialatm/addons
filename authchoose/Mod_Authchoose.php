<?php

/**
 * Name: Auth Choose
 * Description: Allow magic authentication only to websites of your immediate connections.
 *
 */

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Authchoose extends Controller {

	function get() {

		if(! local_channel())
			return;


		if(! Apps::addon_app_installed(local_channel(), 'authchoose')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Authchoose');
			return Apps::app_render($papp, 'module');
		}

		$desc = t('Allow magic authentication only to websites of your immediate connections');
		$content .= $desc;

		$tpl = get_markup_template("settings_addon.tpl");

		$o .= replace_macros($tpl, array(
			'$title' => t('Authchoose'),
			'$content'  => $content
		));

		return $o;
	}

}
