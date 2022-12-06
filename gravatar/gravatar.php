<?php
/**
 * Name: Gravatar Support
 * Description: If there is no avatar image for a new user or contact this plugin will look for one at Gravatar.
 * Version: 1.1.1
 * MinVersion: 1.14
 * Author: Klaus Weidenbach <http://friendica.dszdw.net/profile/klaus>
 * Portig to Hubzilla: Sergey Lukin <mailto:sergey.lukin@cybergnosis.su>
 */

/**
 * Installs the plugin hook
 */
function gravatar_load() {
	register_hook('get_profile_photo', 'addon/gravatar/gravatar.php', 'gravatar_get_profile_photo');
    logger("registered gravatar in get_profile_photo hook");
}

/**
 * Removes the plugin hook
 */
function gravatar_unload() {
    unregister_hook('get_profile_photo', 'addon/gravatar/gravatar.php', 'gravatar_get_profile_photo');
	logger("unregistered gravatar in get_profile_photo hook");
}

/**
 * Looks up the avatar at gravatar.com and returns the URL.
 *
 * @param $a array
 * @param &$b array
 */
function gravatar_get_profile_photo($a, &$b) {
    $r = q("SELECT *
            FROM photo
            WHERE imgscale = %d
            AND uid = %d
            AND photo_usage = %d
            LIMIT 1",
            intval($b['imgscale']),
            intval($b['channel_id']),
            intval(PHOTO_PROFILE)
    );
    if (count($r)) {
		$data = dbunescbin($r[0]['content']);
		$mimetype = $r[0]['mimetype'];
		if(intval($r[0]['os_storage'])) {
			$data = file_get_contents($data);
		}
		if ($data) {
			$b['data'] = $data;
			$b['mimetype'] = $mimetype;
			return;
		}
    }
    $r = q("SELECT channel_account_id
            FROM channel
            WHERE channel_id = %d
            LIMIT 1",
            intval($b['channel_id'])
    );
    if (!count($r)) {
        return;
    }
    $r = q("SELECT account_email
            FROM account
            WHERE account_id = %d
            LIMIT 1",
            intval($r[0]['channel_account_id'])
    );
    $email = '';
    if(count($r)) {
        $email = $r[0]['account_email'];
    }

    $resolutions = [300, 300, 300, 300, 300, 80, 48];
    $imgscale = $b['imgscale'];
    if ($imgscale > 6) {
        $imgscale = 6;
    }

	$default_avatar = get_config('gravatar', 'default_img');
	$rating = get_config('gravatar', 'rating');

	// setting default value if nothing configured
	if (!$default_avatar) {
		$default_avatar = 'hub_default'; // default image will be a hub default profile photo
    }
	if (!$rating) {
		$rating = 'g'; // suitable for display on all websites with any audience type
    }
    $hash = md5(trim(strtolower($email)));
	$url = 'https://www.gravatar.com/avatar/' . $hash . '.jpg?s=' . $resolutions[$imgscale] .'&r=' . $rating;
	if ($default_avatar != "gravatar" && $default_avatar != "hub_default") {
		$url .= '&d=' . $default_avatar;
    } elseif ($default_avatar == "hub_default") {
		$url .= '&d=' . App::$config['system']['baseurl'] . "/" . get_default_profile_photo($resolutions[$imgscale]);
	}
	$i = z_fetch_url($url);
    if ($i['success']) {
        $b['mimetype'] = 'image/jpeg';
        $b['data'] = $i['body'];
    }
}

/**
 * Display admin settings for this addon
 */
function gravatar_plugin_admin (&$o) {
	$t = get_markup_template( "admin.tpl", "addon/gravatar/" );
	$default_avatar = get_config('gravatar', 'default_img');
	$rating = get_config('gravatar', 'rating');

	// set default values for first configuration
    if (!$default_avatar) {
		$default_avatar = 'hub_default'; // default profile photo for your hub
    }
	if (!$rating) {
		$rating = 'g'; // suitable for display on all websites with any audience type
    }
	// Available options for the select boxes
	$default_avatars = array(
		'mm' => t('generic profile image'),
		'identicon' => t('random geometric pattern'),
		'monsterid' => t('monster face'),
		'wavatar' => t('computer generated face'),
		'retro' => t('retro arcade style face'),
		'hub_default' => t('Hub default profile photo'),
	);
	$ratings = array(
		'g' => 'g',
		'pg' => 'pg',
		'r' => 'r',
		'x' => 'x'
	);

	// Check if Libravatar is enabled and show warning
	$r = q("SELECT * FROM addon WHERE aname = '%s' and installed = 1",
		dbesc('libravatar')
	);

	if (count($r)) {
		$o = '<h5>' .t('Information') .'</h5><p>' .t('Libravatar addon is installed, too. Please disable Libravatar addon or this Gravatar addon.<br>The Libravatar addon will fall back to Gravatar if nothing was found at Libravatar.') .'</p><br><br>';
	}

	// output Gravatar settings
	$o .= '<input type="hidden" name="form_security_token" value="' .get_form_security_token("gravatarsave") .'">';

	$o .= replace_macros( $t, array(
		'$submit' => t('Save Settings'),
		'$default_avatar' => array('avatar', t('Default avatar image'), $default_avatar, t('Select default avatar image if none was found at Gravatar. See README'), $default_avatars),
		'$rating' => array('rating', t('Rating of images'), $rating, t('Select the appropriate avatar rating for your site. See README'), $ratings)
	));
}

/**
 * Save admin settings
 */
function gravatar_plugin_admin_post () {
	check_form_security_token('gravatarsave');
	$default_avatar = ((x($_POST, 'avatar')) ? notags(trim($_POST['avatar'])) : 'identicon');
	$rating = ((x($_POST, 'rating')) ? notags(trim($_POST['rating'])) : 'g');
	set_config('gravatar', 'default_img', $default_avatar);
	set_config('gravatar', 'rating', $rating);
	info( t('Gravatar settings updated.') .EOL);
}
?>
