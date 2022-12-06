<?php

/**
 * Name: notifyadmin
 * Description: When enabled, this addon will notify the admin by email when a new account is registered.
 * Version: 1.0
 * Author: Andrew Manning <andrew@reticu.li>
 * MinVersion: 2.8
 *
 */

function notifyadmin_load() {
	register_hook('register_account', 'addon/notifyadmin/notifyadmin.php', 'notifyadmin_register_account');
}

function notifyadmin_unload() {
	unregister_hook('register_account', 'addon/notifyadmin/notifyadmin.php', 'notifyadmin_register_account');
}

/**
 * notifyadmin_register_account - Email the admin when a new account is registered
 * See 'register_account' hook function
 * 
 * @param array $a - App variable (unused)
 * @param array $arr - Hook-specific array
 */
function notifyadmin_register_account($a, $arr) {
	$recip = get_config('system', 'admin_email');
	$res = z_mail(
	  [
		  'toEmail' => $recip,
		  'fromName' => 'NotifyAdmin Plugin',
		  'fromEmail' => $recip,
		  'messageSubject' => t('New registration'),
		  'textVersion' => 'New account registration: ' . $arr['email'],
	  ]
	);

	if (!$res) {
		logger(sprintf(t('%s : Message delivery failed.'), $recip) . EOL);
	} else {
		logger(sprintf(t('Message sent to %s. New account registration: %s'), $recip, $arr['email']) . EOL);
	}
}
