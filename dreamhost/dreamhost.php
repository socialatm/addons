<?php

/**
 * Name: Dreamhost
 * Description: Improved operation on Dreamhost shared hosting
 * Version: 1.0
 * Maintainer: none;
 */



function dreamhost_load() {
	Zotlabs\Extend\Hook::register('page_not_found', 'addon/dreamhost/dreamhost.php','dreamhost_page_not_found');
}

function dreamhost_unload() {
	Zotlabs\Extend\Hook::unregister('page_not_found', 'addon/dreamhost/dreamhost.php','dreamhost_page_not_found');
}

function dreamhost_page_not_found($x) {

	if((array_key_exists('QUERY_STRING',$_SERVER))
		&& ($_SERVER['QUERY_STRING'] === 'q=internal_error.html')) { 
			logger('Original URI =' . $_SERVER['REQUEST_URI'],LOGGER_DEBUG);
			goaway(z_root() . $_SERVER['REQUEST_URI']);
	}

}