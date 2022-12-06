<?php
/**
 * Name: Cryptojs Decrypt
 * Description: Allow to decrypt legacy e2ee notes encrypted with the deprecated cryptojs library 
 * Version: 1.0
 * Author: Mario Vavti <mario@hub.somaton.com> 
 * Maintainer: Mario Vavti <mario@hub.somaton.com>
 * MinVersion: 4.7.10
 */

use Zotlabs\Extend\Hook;

function cryptojs_load() {
	Hook::register('page_end', 'addon/cryptojs/cryptojs.php', 'cryptojs_page_end');
}

function cryptojs_unload() {
	Hook::unregister('page_end', 'addon/cryptojs/cryptojs.php', 'cryptojs_page_end');
}

function cryptojs_page_end(&$str) {
	head_add_js('/addon/cryptojs/view/js/red_crypto.js');
	head_add_js('/addon/cryptojs/lib/cryptojs/components/core-min.js');
	head_add_js('/addon/cryptojs/lib/cryptojs/rollups/aes.js');
	head_add_js('/addon/cryptojs/lib/cryptojs/rollups/rabbit.js');
	head_add_js('/addon/cryptojs/lib/cryptojs/rollups/tripledes.js');
}
