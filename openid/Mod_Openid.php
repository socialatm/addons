<?php
namespace Zotlabs\Module;


use URLify;

require_once('library/openid/openid.php');
require_once('include/auth.php');


class Openid extends \Zotlabs\Web\Controller {

	function get() {

		$noid = get_config('system','disable_openid');
		if($noid)
			goaway(z_root());

		logger('mod_openid ' . print_r($_REQUEST,true), LOGGER_DATA);

		if(x($_REQUEST,'openid_mode')) {

			$openid = new LightOpenID(z_root());

			if($openid->validate()) {

				logger('openid: validate');

				$authid = normalise_openid($_REQUEST['openid_identity']);

				if(! strlen($authid)) {
					logger( t('OpenID protocol error. No ID returned.') . EOL);
					goaway(z_root());
				}

				$x = match_openid($authid);
				if($x) {

					$r = q("select * from channel where channel_id = %d limit 1",
						intval($x)
					);
					if($r) {
						$y = q("select * from account where account_id = %d limit 1",
							intval($r[0]['channel_account_id'])
						);
						if($y) {
						    foreach($y as $record) {
						        if(($record['account_flags'] == ACCOUNT_OK) || ($record['account_flags'] == ACCOUNT_UNVERIFIED)) {
				            		logger('mod_openid: openid success for ' . $x[0]['channel_name']);
									$_SESSION['uid'] = $r[0]['channel_id'];
									$_SESSION['account_id'] = $r[0]['channel_account_id'];
									$_SESSION['authenticated'] = true;
									authenticate_success($record,$r[0],true,true,true,true);
									goaway(z_root());
								}
							}
						}
					}
				}

				// Successful OpenID login - but we can't match it to an existing account.
				// See if they've got an xchan

				$r = q("select * from xconfig left join xchan on xchan_hash = xconfig.xchan where cat = 'system' and k = 'openid' and v = '%s' limit 1",
					dbesc($authid)
				);

				if($r) {
					$_SESSION['authenticated'] = 1;
					$_SESSION['visitor_id'] = $r[0]['xchan_hash'];
					$_SESSION['my_url'] = $r[0]['xchan_url'];
					$_SESSION['my_address'] = $r[0]['xchan_addr'];
					$arr = array('xchan' => $r[0], 'session' => $_SESSION);
					call_hooks('magic_auth_openid_success',$arr);
					\App::set_observer($r[0]);
					require_once('include/security.php');
					\App::set_groups(init_groups_visitor($_SESSION['visitor_id']));
					info(sprintf( t('Welcome %s. Remote authentication successful.'),$r[0]['xchan_name']));
					logger('mod_openid: remote auth success from ' . $r[0]['xchan_addr']);
					if($_SESSION['return_url'])
						goaway($_SESSION['return_url']);
					goaway(z_root());
				}

				// no xchan...
				// create one.
				// We should probably probe the openid url and figure out if they have any kind of
				// social presence we might be able to scrape some identifying info from.

				$name = $authid;
				$url = trim($_REQUEST['openid_identity'],'/');
				if(strpos($url,'http') === false)
					$url = 'https://' . $url;
				$pphoto = z_root() . '/' . get_default_profile_photo();
				$parsed = @parse_url($url);
				if($parsed) {
					$host = $parsed['host'];
				}

				$attr = $openid->getAttributes();

				if(is_array($attr) && count($attr)) {
					foreach($attr as $k => $v) {
						if($k === 'namePerson/friendly')
							$nick = notags(trim($v));
						if($k === 'namePerson/first')
							$first = notags(trim($v));
						if($k === 'namePerson')
							$name = notags(trim($v));
						if($k === 'contact/email')
							$addr = notags(trim($v));
						if($k === 'media/image/aspect11')
							$photosq = trim($v);
						if($k === 'media/image/default')
							$photo_other = trim($v);
					}
				}
				if(! $nick) {
					if($first)
						$nick = $first;
					else
						$nick = $name;
				}

				$nick = strtolower(URLify::transliterate($nick));
				if($nick & $host)
					$addr = $nick . '@' . $host;
				$network = 'unknown';

				if($photosq)
					$pphoto = $photosq;
				elseif($photo_other)
					$pphoto = $photo_other;

				$mimetype = guess_image_type($pphoto);

		        $x = xchan_store_lowlevel(
					[
						'xchan_hash'           => $url,
						'xchan_photo_mimetype' => $mimetype,
						'xchan_photo_l'        => $pphoto,
						'xchan_addr'           => $addr,
						'xchan_url'            => $url,
						'xchan_name'           => $name,
						'xchan_network'        => $network,
						'xchan_photo_date'     => datetime_convert(),
						'xchan_name_date'      => datetime_convert(),
						'xchan_hidden'         => 1
					]
				);

				if($x) {
					$r = q("select * from xchan where xchan_hash = '%s' limit 1",
						dbesc($url)
					);
					if($r) {

						$photos = import_xchan_photo($pphoto,$url);
						if($photos) {
							$z = q("update xchan set xchan_photo_date = '%s', xchan_photo_l = '%s', xchan_photo_m = '%s', 
								xchan_photo_s = '%s', xchan_photo_mimetype = '%s' where xchan_hash = '%s'",
								dbesc($photos[5]),
								dbesc($photos[0]),
								dbesc($photos[1]),
								dbesc($photos[2]),
								dbesc($photos[3]),
								dbesc($url)
		            		);
						}

						set_xconfig($url,'system','openid',$authid);
						$_SESSION['authenticated'] = 1;
						$_SESSION['visitor_id'] = $r[0]['xchan_hash'];
						$_SESSION['my_url'] = $r[0]['xchan_url'];
						$_SESSION['my_address'] = $r[0]['xchan_addr'];
						$arr = array('xchan' => $r[0], 'session' => $_SESSION);
						call_hooks('magic_auth_openid_success',$arr);
						\App::set_observer($r[0]);
						info(sprintf( t('Welcome %s. Remote authentication successful.'),$r[0]['xchan_name']));
						logger('mod_openid: remote auth success from ' . $r[0]['xchan_addr']);
						if($_SESSION['return_url'])
							goaway($_SESSION['return_url']);
						goaway(z_root());
					}
				}

			}
		}
		notice( t('Login failed.') . EOL);
		goaway(z_root());
		// NOTREACHED
	}

}
