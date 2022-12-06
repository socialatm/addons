<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Planets extends Controller {

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'planets')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Random Planet');
			return Apps::app_render($papp, 'module');
		}
		else
		    $o = '<h3>' . t('Random Planet App') . '</h3>';

		$o .= t('Set a random planet from the Star Wars Empire as your location when posting');
		return $o;

	}

}
