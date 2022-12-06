<?php

/**
 * Name: Opensearch
 * Description: Opensearch provider interface
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: none
 */


function opensearch_load() {
	Zotlabs\Extend\Hook::register('build_pagehead','addon/opensearch/opensearch.php','opensearch_build_pagehead');
}

function opensearch_unload() {
	Zotlabs\Extend\Hook::unregister('build_pagehead','addon/opensearch/opensearch.php','opensearch_build_pagehead');
}

function opensearch_build_pagehead($x) {

	head_add_link([ 
		'rel' => 'search', 
		'href' => z_root() . '/opensearch', 
		'type' => 'application/opensearchdescription+xml',
		'title' => sprintf( t('Search %1$s (%2$s)','opensearch'), 
			Zotlabs\Lib\System::get_site_name(), 
			t('$Projectname','opensearch'))
	]);
}


function opensearch_module() {}

function opensearch_init(&$a) {

	$tpl = get_markup_template('opensearch.tpl');
		
	header("Content-type: application/opensearchdescription+xml");
		
	echo replace_macros(get_markup_template('opensearch.tpl','addon/opensearch'), array(
		'$project' => t('$Projectname'),
		'$search_project' => t('Search $Projectname'),
		'$baseurl'  => z_root(),
		'$nodename' => \App::get_hostname(),
	));
			
	killme();
}