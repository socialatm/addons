<?php

/**
 * Name: Hubzilla Cloud File copy
 * Description: import hubzilla file storage from another site
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: Mike Macgirvin <mike@macgirvin.com>
 */

use Zotlabs\Web\HTTPSig;

function hzfiles_install() {}
function hzfiles_uninstall() {}
function hzfiles_module() {}
function hzfiles_load() {}
function hzfiles_unload() {}

function hzfiles_post(&$a) {

	if(! local_channel())
		return;

	$channel = App::get_channel();

	$hz_server = $_REQUEST['hz_server'];

	// The API will convert these to UTC.

	$since = datetime_convert(date_default_timezone_get(),date_default_timezone_get(),$_REQUEST['since']);
	$until = datetime_convert(date_default_timezone_get(),date_default_timezone_get(),$_REQUEST['until']);

	
	$headers = [];
	$headers['X-API-Token'] = random_string();
	$headers['X-API-Request'] = $hz_server . '/api/z/1.0/files?f=&since=' . urlencode($since) . '&until=' . urlencode($until);
	$headers = HTTPSig::create_sig($headers,$channel['channel_prvkey'], 'acct:' . channel_reddress($channel),true,'sha512');
		
	$x = z_fetch_url($hz_server . '/api/z/1.0/files?f=&since=' . urlencode($since) . '&until=' . urlencode($until),false,$redirects,[ 'headers' => $headers ]);

	if(! $x['success']) {
		logger('no API response');
		return;
	}

	$j = json_decode($x['body'],true);


	if(! $j['success']) 
		return;

	$poll_interval = get_config('system','poll_interval',3);

	if(count($j['results'])) {
		$todo = count($j['results']);
		logger('total to process: ' . $todo); 

		foreach($j['results'] as $jj) {

//			logger('json data: ' . print_r($jj,true));

			proc_run('php','addon/hzfiles/hzfilehelper.php',$jj['hash'], $channel['channel_address'], urlencode($hz_server));
			sleep($poll_interval);

		}

		goaway(z_root() . '/cloud/' . $channel['channel_address']);
	}
}


function hzfiles_content(&$a) {

	if(! local_channel()) {
		notice( t('Permission denied') . EOL);
		return;
	}


	$o = replace_macros(get_markup_template('hzfiles.tpl','addon/hzfiles'),array( 
		'$header' => t('Hubzilla File Storage Import'),
		'$desc' => t('This will import all your cloud files from another server.'),
		'$fr_server' => array('hz_server', t('Hubzilla Server base URL'),'',''),
		'$since' => array('since', t('Since modified date yyyy-mm-dd'),'0001-01-01',''),
		'$until' => array('until', t('Until modified date yyyy-mm-dd'),datetime_convert('UTC',date_default_timezone_get(),'now','Y-m-d'),''),
		'$submit' => t('Submit'),
	));
	return $o;
}
