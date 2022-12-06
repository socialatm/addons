<?php

/**
 * Name: Diaspora Statistics
 * Description: Generates some statistics for the-federation.info (formerly http://pods.jasonrobinson.me/)
 * Version: 2.0
 * Author: Mike Macgirvin
 * Author: Michael Vogel <https://pirati.ca/profile/heluecht>
 * Maintainer: none
 */

function statistics_load() {
	register_hook('cron_weekly', 'addon/statistics/statistics.php', 'statistics_cron_weekly');
	register_hook('well_known', 'addon/statistics/statistics.php', 'statistics_well_known');
	register_hook('module_loaded', 'addon/statistics/statistics.php', 'statistics_load_module');

	//register at the-federation.info
	$url = "https://the-federation.info/register/" . App::get_hostname();
	$ret = z_fetch_url($url);

}


function statistics_unload() {
	unregister_hook('cron_weekly', 'addon/statistics/statistics.php', 'statistics_cron_weekly');
	unregister_hook('well_known', 'addon/statistics/statistics.php', 'statistics_well_known');
	unregister_hook('module_loaded', 'addon/statistics/statistics.php', 'statistics_load_module');
}


function statistics_well_known(&$a,&$b) {
	if(argc() > 1 && argv(1) === 'nodeinfo') {
		$arr = [ 'links' => [
			[
				'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
				'href' => z_root() . '/nodeinfo/2.0'
			],
			[
				'rel' => 'http://nodeinfo.diaspora.software/ns/schema/1.0',
				'href' => z_root() . '/nodeinfo/1.0'
			],

		]];

		header('Content-type: application/json');
		echo json_encode($arr);
		killme();
	}
}


function statistics_load_module(&$a, &$b) {
	if($b['module'] === 'nodeinfo') {
		require_once('addon/statistics/nodeinfo.php');
		$b['installed'] = true;
	}
}


/**
 * Commenting out the old statistics.json interface
 * use nodeinfo instead
 *

function statistics_module() {}

function statistics_init() {

	if(! get_config('system','channels_total_stat')) {
		require_once('include/statistics_fns.php');
		update_channels_total_stat();
		update_channels_active_halfyear_stat();
		update_channels_active_monthly_stat();
		update_local_posts_stat();
		update_local_comments_stat();
	}

	// ignore $_REQUEST['module_format'] ('json')

	$hidden = get_config('diaspora','hide_in_statistics');
	

	$statistics = array(
		"name" => get_config('system','sitename'),
		"network" => Zotlabs\Lib\System::get_platform_name(),
		"version" => (($hidden) ? '0.0' : Zotlabs\Lib\System::get_project_version()),
		"registrations_open" => (($hidden) ? false : (bool) (get_config('system','register_policy') != 0)),
		"total_users" => (($hidden) ? "1" : (string) get_config('system','channels_total_stat')),
		"active_users_halfyear" => (($hidden) ? "1" : (string) get_config('system','channels_active_halfyear_stat')),
		"active_users_monthly" => (($hidden) ? "1" : (string) get_config('system','channels_active_monthly_stat')),
		"local_posts" => (($hidden) ? "1" : (string) get_config('system','local_posts_stat')),
		"local_comments" => (($hidden) ? "1" : (string) get_config('system','local_comments_stat')),
		"twitter" => (($hidden) ? false : (bool) get_config('statistics','twitter')),
		"wordpress" => (($hidden) ? false : (bool) get_config('statistics','wordpress'))
	);

	header("Content-Type: application/json");
	echo json_encode($statistics);
	logger("statistics_init: printed " . print_r($statistics, true));
	killme();
}

 *
 * End commenting out old statistics.json interface
 */

function statistics_cron_weekly($a,$b) {

	logger('statistics_cron: cron_start');

	$wordpress = false;
	$r = q("select * from addon where hidden = 0 and aname = 'wppost'");
		if($r)
		$wordpress = true;

	set_config('statistics','wordpress', intval($wordpress));

	$twitter = false;
	$r = q("select * from addon where hidden = 0 and aname = 'twitter'");
	if($r)
		$twitter = true;

	set_config('statistics','twitter', intval($twitter));

	// Now trying to register
	$url = "https://the-federation.info/register/" . App::get_hostname();

	$ret = z_fetch_url($url);
	logger('statistics_cron: registering answer: '. print_r($ret,true), LOGGER_DEBUG);
	logger('statistics_cron: cron_end');

}

