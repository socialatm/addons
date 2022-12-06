<?php


/**
 * Name: Rainbowtag
 * Description: Add some colour to tag clouds
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: Mike Macgirvin <mike@macgirvin.com>
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function rainbowtag_load() {
	Hook::register('construct_page', 'addon/rainbowtag/rainbowtag.php', 'rainbowtag_construct_page');
	Route::register('addon/rainbowtag/Mod_Rainbowtag.php','rainbowtag');
}

function rainbowtag_unload() {
	Hook::unregister('construct_page', 'addon/rainbowtag/rainbowtag.php', 'rainbowtag_construct_page');
	Route::unregister('addon/rainbowtag/Mod_Rainbowtag.php','rainbowtag');
}

function rainbowtag_construct_page(&$b) {

	if(! App::$profile_uid)
		return;

	if(! Apps::addon_app_installed(App::$profile_uid,'rainbowtag'))
		return;

	$c = get_pconfig(App::$profile_uid,'rainbowtag','colors');
	$color1 = ((is_array($c) && $c[0]) ? $c[0] : 'DarkGray');
	$color2 = ((is_array($c) && $c[1]) ? $c[1] : 'LawnGreen');
	$color3 = ((is_array($c) && $c[2]) ? $c[2] : 'DarkOrange');
	$color4 = ((is_array($c) && $c[3]) ? $c[3] : 'Red');
	$color5 = ((is_array($c) && $c[4]) ? $c[4] : 'Gold');
	$color6 = ((is_array($c) && $c[5]) ? $c[5] : 'Teal');
	$color7 = ((is_array($c) && $c[6]) ? $c[6] : 'DarkMagenta');
	$color8 = ((is_array($c) && $c[7]) ? $c[7] : 'DarkGoldenRod');
	$color9 = ((is_array($c) && $c[8]) ? $c[8] : 'DarkBlue');
	$color10 = ((is_array($c) && $c[9]) ? $c[9] : 'DeepPink');

		

	$o = '<style>';
	$o .= '.tag1  { color: ' . $color1  . ' !important; }' . "\r\n";
	$o .= '.tag2  { color: ' . $color2  . ' !important; }' . "\r\n";
	$o .= '.tag3  { color: ' . $color3  . ' !important; }' . "\r\n";
	$o .= '.tag4  { color: ' . $color4  . ' !important; }' . "\r\n";
	$o .= '.tag5  { color: ' . $color5  . ' !important; }' . "\r\n";
	$o .= '.tag6  { color: ' . $color6  . ' !important; }' . "\r\n";
	$o .= '.tag7  { color: ' . $color7  . ' !important; }' . "\r\n";
	$o .= '.tag8  { color: ' . $color8  . ' !important; }' . "\r\n";
	$o .= '.tag9  { color: ' . $color9  . ' !important; }' . "\r\n";
	$o .= '.tag10 { color: ' . $color10 . ' !important; }' . "\r\n";
	$o .= '</style>';

	App::$page['htmlhead'] .= $o;

}
