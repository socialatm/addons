<?php
/**
 * Name: NSA bait
 * Description: Make yourself a political target
 * Version: 1.0
 * Author: Mike Macgirvin <http://macgirvin.com/profile/mike>
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;


function nsabait_load() {
	register_hook('post_local_start', 'addon/nsabait/nsabait.php', 'nsabait_post_hook');
	Route::register('addon/nsabait/Mod_Nsabait.php', 'nsabait');
	logger("loaded nsabait");
}


function nsabait_unload() {
	unregister_hook('post_local_start',    'addon/nsabait/nsabait.php', 'nsabait_post_hook');
	Route::unregister('addon/nsabait/Mod_Nsabait.php', 'nsabait');
	logger("removed nsabait");
}



function nsabait_post_hook($a, &$req) {
	/**
	 *
	 * An item was posted on the local system.
	 * We are going to look for specific items:
	 *      - A status post by a profile owner
	 *      - The profile owner must have allowed our plugin
	 *
	 */

	logger('nsabait invoked');

	if(! local_channel())   /* non-zero if this is a logged in user of this system */
		return;

	if(! Apps::addon_app_installed(local_channel(), 'nsabait'))
		return;

	if(local_channel() != $req['profile_uid'])    /* Does this person own the post? */
		return;

	if($req['parent'])   /* If the req has a parent, this is a comment or something else, not a status post. */
		return;

	if($req['namespace'] || $req['remote_id'] || $req['post_id'])
		return;

	$nsabait = file('addon/nsabait/words.txt');
	shuffle($nsabait);
	$used = array();

	$req['body'] .= "\n\n";

	for($x = 0; $x < 5; $x ++) {
		$y = mt_rand(0,count($nsabait));
		if((in_array(strtolower(trim($nsabait[$y])),$used)) || (! trim($nsabait[$y]))) {
			$x -= 1;
			continue;
		}
		$used[] = strtolower(trim($nsabait[$y]));

		$req['body'] .= ' #' . str_replace(' ','_',trim($nsabait[$y]));
	}

	return;
}
