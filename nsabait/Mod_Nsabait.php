<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Nsabait extends Controller {

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'nsabait')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('NSA Bait');
			return Apps::app_render($papp, 'module');
		}
		else
		    $o = '<h3>' . t('NSA Bait App') . '</h3>';

        $o .= t('Make yourself a political target.');
		return $o;


	}
}
