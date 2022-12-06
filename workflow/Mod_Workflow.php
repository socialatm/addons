<?php

namespace Zotlabs\Module;

use \App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

require_once('addon/workflow/workflow.php');

use \Workflow_Utils;
require_once('include/attach.php');
require_once('include/network.php');
require_once('include/channel.php');

class Workflow extends Controller {

	public $displaycontent = null;

	function init() {

		$which = null;
		if(argc() > 1)
			$which = argv(1);
		if(!$which && local_channel()) {
			$channel = App::get_channel();
			if ($channel && $channel['channel_address']) {
				$which = $channel['channel_address'];
			}
		}

		if (!$which) {
			goaway(z_root());
		}

		profile_load($which);
		if (x($_REQUEST,'jsondata')) {
			if (!App::$profile_uid) {
				App::$error = 404;
				json_return_and_die(['error'=>404,'errmsg'=>'Not Found']);
			}

			$ret = \Workflow_Utils::json_receiver($_REQUEST);
			json_return_and_die($ret);
		}
	}

	function post() {

		self::init();
		if (!App::$profile_uid) {
			App::$error = 405;
			return;
		}

		if (! Apps::addon_app_installed(!App::$profile_uid,'workflow')) {
			App::$error = 405;
			return;
		}

			logger(json_encode($_REQUEST));
		if (argc() >= 2){
			//return \Workflow_Utils::workflow_post($uid);
			return \Workflow_Utils::get();
		} else {
			logger(json_encode($_REQUEST));
		}

		App::$error = 405;
		return;
	}

	function get() {
		self::init();
		$content = "<H1>ERROR: Page not found</H1>";
		if (!App::$profile_uid) {
			App::$error = 404;
			return $content;
		}

		if (! Apps::addon_app_installed(App::$profile_uid,'workflow')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Workflow / Issue Tracking');
			return Apps::app_render($papp, 'module');
		}

		return Workflow_Utils::get();
	}
}
