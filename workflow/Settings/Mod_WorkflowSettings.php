<?php

namespace Zotlabs\Module\Settings;

use \App;
use \Zotlabs\Lib\Apps;
use \Zotlabs\Web\Controller;
use \Zotlabs\Lib\PConfig;
use \Zotlabs\Extend\Hook;

require_once('Zotlabs/Lib/Apps.php');
require_once('addon/workflow/workflow.php');
require_once('addon/workflow/Settings/WorkflowSettingsUtil.php');
require_once('include/attach.php');
require_once('include/network.php');
require_once('include/channel.php');
require_once('include/text.php');


class Workflow extends Controller {

	public $displaycontent = null;

	function init() {
	}

	function post() {
		Hook::insert('dm42workflow_settings_post','WorkflowSettingsUtil::StatusesPost',1,1000);

		if (!local_channel()) {
			App::$error = 405;
			return;
		}

		if (! Apps::addon_app_installed(!local_channel(),'workflow')) {
			App::$error = 405;
			return;
		}

		call_hooks('dm42workflow_settings_post',$hookinfo);

		goaway(z_root().'/settings/workflow');

	}


	function get() {
		Hook::insert('dm42workflow_settings','WorkflowSettingsUtil::StatusesForm',1,1000);

		$content = "<H1>ERROR: Page not found</H1>";

		$uid = local_channel();

		if (!$uid) {
			App::$error = 405;
			return $content;
		}

		if (! Apps::addon_app_installed($uid,'workflow')) {
			App::$error = 404;
			return $content;
		}
		$content = '';

		/*
		* @hook dm42workflow_settings
		*
		*/
		$hookinfo = [];
		call_hooks('dm42workflow_settings',$hookinfo);

		usort($hookinfo,function($a,$b) {
			if (intval(@$a['priority']) == intval(@$b['priority'])) {
				return 0;
			}

			return (intval(@$a['priority']) < intval(@$b['priority'])) ? 1 : -1;
		});

		$count = 0;

		$form_security_token = get_form_security_token('settings');

		foreach ($hookinfo as $group) {
			$count++;
			if (!isset($group['formcontents'])) { continue; }
			$count++;
			$templatevars = [
				'$title' => isset($group['title']) ? $group['title'] : 'Settings Group '.$count,
				'$groupid' => $count,
				'$form_security_token' => $form_security_token,
				'$formname' => $group['formname'],
				'$content' => $group['formcontents'],
				'$submit' => t('Submit')
			];

			$content .= replace_macros(get_markup_template('settings_group.tpl','addon/workflow'),$templatevars);
		}

		$templatevars = [
			'$title' => t('Workflow Settings'),
			'$content' => $content,

		];
		$o = replace_macros(get_markup_template('settings.tpl','addon/workflow'),$templatevars);
		return $o;
	}
}
