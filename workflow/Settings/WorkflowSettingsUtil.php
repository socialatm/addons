<?php

use \Zotlabs\Lib\Apps;
use \Zotlabs\Web\Controller;
use \Zotlabs\Lib\PConfig;
use \Zotlabs\Extend\Hook;

require_once('Zotlabs/Lib/Apps.php');
require_once('addon/workflow/workflow.php');
require_once('include/attach.php');
require_once('include/network.php');
require_once('include/channel.php');
require_once('include/text.php');


Class WorkflowSettingsUtil {

	public static function StatusesPost() {

		if($_REQUEST['formname'] != 'statuses') {
			return;
		}

		check_form_security_token_redirectOnErr('/settings/workflow', 'settings', $formname = 'form_security_token');

		$statuses = $_REQUEST['status'];

		if (is_array($statuses) && count($statuses)) {
			$newstatuses = [];
			foreach ($statuses as $k=>$v) {
				if (isset($newstatuses[$v])) continue;
				$newstatuses[$v] = [
					'status'=>$v,
					'priority'=>$_REQUEST['priority'][$k]
				];
			}
			$statuses = $newstatuses;
		}

		usort($statuses,function($a,$b) {
			if (intval(@$a['priority']) == intval(@$b['priority'])) {
				return 0;
			}

			return (intval(@$a['priority']) > intval(@$b['priority'])) ? 1 : -1;
		});
		$newstatuses=[];
		foreach ($statuses as $v) {
			$status = $v['status'];
			$priority = $v['priority'];
			if (!$status || $priority < 0) { continue; }
			$newstatuses[]=[
				'status'=>$status,
				'priority'=>$priority
			];
		}
		if (!count($newstatuses)) {
		$newstatuses=[
				[
					'status'=>'Closed',
					'priority'=>0
				],
				[
					'status'=>'Open',
					'priority'=>1
				],

			];
		}

		PConfig::Set(local_channel(),'workflow','statusconfig',json_encode($newstatuses));
		goaway(z_root().'/settings/workflow?tab=statuses');
	}

	public static function StatusesForm(&$hookinfo) {
                if(!local_channel()) { return; }
		$statuses=PConfig::Get(local_channel(),'workflow','statusconfig');
		$statuses=json_decode($statuses,true);

		$entries = $hookinfo;

		$content = '';

		$statuscount = count($statuses);

		$content .= "<input type=hidden name='statuscount' value='".$statuscount."'>";
		$count = 0;
		$content .= "<div id='status-list'>";
		foreach ($statuses as $status) {
			$templatevars = [ '$status'=> [
				"status[".$count."]",
				$status['status']
				],
				'$priority'=> [
				"priority[".$count."]",
				$status['priority'],
				]
			];
			$content .= replace_macros(get_markup_template('settings_status_input.tpl','addon/workflow'),$templatevars);
			$count++;
		}

	$content .= '</div>';
	$content .= '<button class="btn btn-block btn-primary btn-sm" type="button" id="button-addstatus" href="#"><i class="generic-icons-nav fa fa-fw fa-plus-circle"></i></a>';

	$blanklistitem='';
	$templatevars = [ 'status' => [
		"status[]",
		""
	], 
	'priority' => [
		"priority[]",
		""
	]];
	$blanklistitem .= replace_macros(get_markup_template('settings_status_input.tpl','addon/workflow'),$templatevars);
        $blanklistitem = str_replace(array("\n", "\r"), '', $blanklistitem);
        $blanklistitem = addslashes($blanklistitem);
	$content .= '
	<script>
	$(document).ready(function(){
  		$("#button-addstatus").click(function(){
    			$("#status-list").append("'.$blanklistitem.'");
  		});
	});
	</script>
	';

	$entries[] = [
		'formname'=> 'statuses',
		'formcontents' => $content,
		'title' => 'Status Levels'
	];
	$hookinfo = $entries;
	}

}
