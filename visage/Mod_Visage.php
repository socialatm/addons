<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Visage extends Controller {

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'visage')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Visitors');
			return Apps::app_render($papp, 'module');
		}

		$o = '<h3>' . t('Recent Channel/Profile Viewers') . '</h3>';

		// let's play fair.

		require_once('include/channel.php');

		if(! is_public_profile())
			return $o;

		$x = get_pconfig(local_channel(),'visage','visitors');
		if((! $x) || (! is_array($x))) {
			$o .= t('No entries.');
			return $o;
		}

		$chans = '';
		for($n = 0; $n < count($x); $n ++) {
			if($chans)
				$chans .= ',';
			$chans .= "'" . dbesc($x[$n][0]) . "'";
		}
		if($chans) {
			$r = q("select * from xchan where xchan_hash in ( $chans )");
		}
		if($r) {
			$tpl = get_markup_template('common_friends.tpl');

			for($g = count($x) - 1; $g >= 0; $g --) {
				foreach($r as $rr) {
					if($x[$g][0] == $rr['xchan_hash'])
						break;
				}

				$o .= replace_macros($tpl,array(
					'$url'   => (($rr['xchan_flags'] & XCHAN_FLAGS_HIDDEN) ? z_root() : chanlink_url($rr['xchan_url'])),
					'$name'  => $rr['xchan_name'],
					'$photo' => $rr['xchan_photo_m'],
					'$tags'  => (($rr['xchan_flags'] & XCHAN_FLAGS_HIDDEN) ? z_root() : chanlink_url($rr['xchan_url'])),
					'$note'  => relative_date($x[$g][1])
				));
			}

			$o .= cleardiv();
		}

		return $o;

	}

}
