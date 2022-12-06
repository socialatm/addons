<?php

/**
 * Name: Cavatar
 * Description: create random cat-avatar for default profile photo
 * Version: 1.0
 *
 */


function cavatar_load() {
	\Zotlabs\Extend\Hook::register('create_channel_photo',  'addon/cavatar/cavatar.php', 'cavatar_channel_photo');
	\Zotlabs\Extend\Hook::register('default_profile_photo', 'addon/cavatar/cavatar.php', 'cavatar_default_profile_photo');
	$old = get_config('system','default_profile_photo',EMPTY_STR);
	if($old !== 'cavatar') {
		set_config('cavatar','original_profile_photo',$old);
	}
	set_config('system','default_profile_photo','cavatar');
}

function cavatar_unload() {
	\Zotlabs\Extend\Hook::unregister('create_channel_photo',  'addon/cavatar/cavatar.php', 'cavatar_channel_photo');
	\Zotlabs\Extend\Hook::unregister('default_profile_photo', 'addon/cavatar/cavatar.php', 'cavatar_default_profile_photo');
	set_config('system','default_profile_photo',get_config('cavatar','original_profile_photo',EMPTY_STR));
}


function cavatar_channel_photo(&$x) {

	$x['photo_url'] = z_root() . '/cavatar?f=&seed=' . notags($x['channel']['channel_hash']) ;

}

function cavatar_default_profile_photo(&$x) {
	if($x['scheme'] === 'cavatar') {
		//$x['url'] = 'cavatar?f=&seed=' . random_string(14) . '&size=' . $x['size'];
		$x['url'] = 'addon/cavatar/cache/cavatar-' . $x['size'] . '.png';
	}
}

function cavatar_module() {}


function cavatar_init(&$a) {
	require_once('addon/cavatar/cat-avatar-generator.php');
	build_cat($_REQUEST['seed'],intval($_REQUEST['size']));

}


