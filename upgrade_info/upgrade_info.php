<?php
/**
 * Name: Upgrade Info
 * Description: Show upgrade info at the top of left-aside until dismissed
 * Version: 1.0
 * Depends: Core
 * Author: Mario Vavti <mario@mariovavti.com>
 */

use Zotlabs\Extend\Hook;

function upgrade_info_load(){
	Hook::register('construct_page', 'addon/upgrade_info/upgrade_info.php', 'upgrade_info_construct_page');
}

function upgrade_info_unload(){
	Hook::unregister('construct_page', 'addon/upgrade_info/upgrade_info.php', 'upgrade_info_construct_page');
}

function upgrade_info_construct_page(&$b){

	$upgrade_version = get_config('upgrade_info', 'version');

	if(version_compare(STD_VERSION, $upgrade_version) == 1) {
		set_config('upgrade_info', 'datetime', datetime_convert());
		set_config('upgrade_info', 'version', STD_VERSION);
	}

	if(! local_channel())
		return;

	$upgrade_datetime = get_config('upgrade_info', 'datetime');

	$account = App::get_account();
	if($account['account_created'] > $upgrade_datetime)
		return;

	$version = get_pconfig(local_channel(), 'upgrade_info', 'version');

	if(version_compare(STD_VERSION, $version) < 1)
		return; 

	$parts = explode('.', STD_VERSION);
	$dev = true;
	if(is_numeric($parts[1]) && $parts[1] % 2 == 0)
		$dev = false;

	$content[] = t('Your channel has been upgraded to $Projectname version');
	$content[] = STD_VERSION;
	$content[] = t('Please have a look at the');
	if($dev)
		$content[] = '<a target="_blank" href="https://framagit.org/hubzilla/core/commits/dev">' . t('git history') . '</a>';
	else
		$content[] = '<a target="_blank" href="https://framagit.org/hubzilla/core/tags/' . STD_VERSION . '">' . t('change log') . '</a>';
	$content[] = t('for further info.');

	$tpl = get_markup_template('upgrade_info.tpl', 'addon/upgrade_info');

	$o = replace_macros($tpl, [
		'$title' => t('Upgrade Info'),
		'$content' => $content,
		'$std_version' => STD_VERSION,
		'$form_security_token' => get_form_security_token('pconfig'),
		'$dismiss' => t('Do not show this again')
	]);

	$b['layout']['region_aside'] = $o . $b['layout']['region_aside'];

}



