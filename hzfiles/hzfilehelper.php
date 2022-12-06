<?php

use Zotlabs\Web\HTTPSig;

require_once('include/cli_startup.php');
require_once('include/attach.php');
require_once('include/import.php');

cli_startup();

$attach_id = $argv[1];
$channel_address = $argv[2];
$hz_server = urldecode($argv[3]);

// define('FILESYNCTEST', 1);

	$channel = channelx_by_nick($channel_address);
	if(! $channel) {
		logger('redfilehelper: channel not found');
		killme();
	}

	$headers = [];
	$headers['X-API-Token'] = random_string();
	$headers['X-API-Request'] = $hz_server . '/api/z/1.0/file/export?f=&file_id=' . $attach_id;
	$headers = HTTPSig::create_sig($headers,$channel['channel_prvkey'], 'acct:' . channel_reddress($channel),true,'sha512');
		
	$x = z_fetch_url($hz_server . '/api/z/1.0/file/export?f=&file_id=' . $attach_id,false,$redirects,[ 'headers' => $headers ]);

	if(! $x['success']) {
		logger('no API response');
		return;
	}

	$j = json_decode($x['body'],true);

	if(defined('FILESYNCTEST')) {
		logger('data: ' . print_r($j,true));
	}
	else {
		$r = sync_files($channel,[$j]);
	}

	killme();

