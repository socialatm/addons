<?php

/**
 * Name: Content Importer
 * Description: import content and file storage to a cloned channel
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: Mike Macgirvin <mike@macgirvin.com>
 */

use Zotlabs\Extend\Route;

function content_import_install() {
	Route::register('addon/content_import/Mod_content_import.php','content_import');	
}

function content_import_uninstall() {
	Route::unregister('addon/content_import/Mod_content_import.php','content_import');	
}
