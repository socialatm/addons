<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

require_once(dirname(__FILE__).'/channelreputation.php');

class Channelreputation extends Controller {

	function post() {
		$html = \ChannelReputation_Utils::mod_post($_POST);
		echo $html;
		killme();
	}

	function get() {

		goaway(z_root().'/settings/channelreputation');

	}

}
