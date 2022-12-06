<?php

namespace Zotlabs\Module\Settings;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Lib\AConfig;
use Zotlabs\Lib\System;

class Totp {
	function totp_installed() {
		$id = local_channel();
		if (!$id) return false;
		return Apps::addon_app_installed($id, 'totp');
		}
	function get_secret($acct_id) {
		return AConfig::get($acct_id, 'totp', 'secret', null);
		}
	function set_secret($acct_id, $secret) {
		AConfig::set($acct_id, 'totp', 'secret', $secret);
		}
	function send_qrcode($account) {
		# generate and deliver QR code png image
		require_once("addon/addon_common/phpqrcode/qrlib.php");
		require_once("addon/totp/class_totp.php");
		$totp = new \TOTP(ucfirst(System::get_platform_name()),
				$account['account_email'],
				$this->get_secret($account['account_id']), 30, 6);
		$tmpfile = tempnam(sys_get_temp_dir(), "qr");
		\QRcode::png($totp->uri(), $tmpfile);
		header("content-type: image/png");
		header("content-length: " . filesize($tmpfile));
		echo file_get_contents($tmpfile);
		unlink($tmpfile);
		}
	function post() {
		if (!$this->totp_installed())
			json_return_and_die(array("status" => false));
		$account = App::get_account();
		if (!$account) json_return_and_die(array("status" => false));
		$id = intval($account['account_id']);
		if (isset($_POST['set_secret'])) {
			$hash = hash("whirlpool",
				$account['account_salt'] . $_POST['password']);
			if ($hash != $account['account_password']) {
				json_return_and_die(array("auth" => false));
				}
			require_once("addon/totp/class_totp.php");
			$totp = new \TOTP(ucfirst(System::get_platform_name()),
						$account['account_email'], null, 30, 6);
			$this->set_secret($id, $totp->secret);
			json_return_and_die(
				array(
					"secret" => $totp->secret,
					"auth" => true
					)
				);
			}
		if (isset($_POST['totp_code'])) {
			require_once("addon/totp/class_totp.php");
			$ref = intval($_POST['totp_code']);
			$secret = $this->get_secret($id);
			$totp = new \TOTP(ucfirst(System::get_platform_name()),
							$account['account_email'],
							$secret, 30, 6);
			$match = ($totp->authcode($totp->timestamp()) == $ref);
			json_return_and_die(array("match" => ($match ? "1" : "0")));
			}
		}
	function get() {
		if (!$this->totp_installed()) return;
		$account = App::get_account();
		if (!$account) return;
		preg_match('/([^\/]+)$/', $_SERVER['REQUEST_URI'], $matches);
		$path = $matches[1];
		$path = preg_replace('/\?.+$/', '', $path);
		if ($path == "qrcode") {
			$this->send_qrcode($account);
			killme();
			}
		$acct_id = $account['account_id'];
		require_once("addon/totp/class_totp.php");
		$secret = $this->get_secret($acct_id);
		$totp = new \TOTP(ucfirst(System::get_platform_name()),
							$account['account_email'],
							$secret, 30, 6);
		$sc = replace_macros(get_markup_template('settings.tpl',
								'addon/totp'),
				[
				'$has_secret' => (is_null($secret) ? "false" : "true"),
				'$no_secret_text' =>
					t("You haven't set a TOTP secret yet.
Please click the button below to generate one and register this site
with your preferred authenticator app."),
				'$has_secret1_text' => t("Your TOTP secret is"),
				'$has_secret2_text' => t("Be sure to save it somewhere in case you lose or replace your mobile device.
Use your mobile device to scan the QR code below to register this site
with your preferred authenticator app."),
				'$secret' => $totp->secret,
				'$qrcode_url' => "/settings/totp/qrcode?s=",
				'$test_button' => t("Test"),
				'$gen_button' => t("Generate New Secret"),
				'$go_button' => t("Go"),
				'$enter_password' => t("Enter your password"),
				'$test_title' => t("enter TOTP code from your device"),
				'$test_pass' => t("Pass!"),
				'$test_fail' => t("Fail"),
				'$note_password' => t("Incorrect password, try again."),
				'$note_scan' => t("Record your new TOTP secret and rescan the QR code above."),
				'$salt' => microtime()
				]);
		return replace_macros(get_markup_template('settings_addon.tpl'),
				array(
					'$action_url' => 'settings/totp',
					'$form_security_token' =>
						get_form_security_token("totp"),
					'$title' => t('TOTP Settings'),
					'$content'  => $sc,
					'$baseurl'   => z_root(),
					'$submit'    => '',
					)
			);
		}
	}
