<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Hideaside extends Controller {

	function post() {

	}

	function get() {
		if(! local_channel())
			return;

		//Do not display any associated widgets at this point
		App::$pdl = '';

		if(! Apps::addon_app_installed(local_channel(), 'hideaside')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Hide Aside');
			return Apps::app_render($papp, 'module');
		}
		$o = '<h3>' . t('Hide Aside App') . '</h3>';
		$o .= t('Fade out aside areas after a while when using endless scroll');
		return $o;
	}

}
