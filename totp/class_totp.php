<?php

class TOTP {
	private static $base32_map = array(
		'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H',
		'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P',
		'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X',
		'Y', 'Z', '2', '3', '4', '5', '6', '7'
		);
	private static $base32_pad = "=";

	public function __construct($issuer_name, $user,
			$secret, $period, $digits) {
		$this->issuer = $issuer_name;
		$this->user = $user;
		$this->period = (int)$period;
		if ($this->period < 1) $this->period = 30;
		$this->digits = (int)$digits;
		if ($this->digits >= 8) $this->digits = 8;
		else $this->digits = 6;
		if (is_null($secret)) $this->secret = $this->gen_secret();
		else $this->secret = $secret;
		}
	private function base32_encode($subject, $pad = true) {
		if (empty($subject)) return "";
		$chars = str_split($subject);
		$bin = "";
		for ($i = 0; $i < count($chars); $i++) {
			$bin .= str_pad(base_convert(ord($chars[$i]), 10, 2),
						8, '0', STR_PAD_LEFT);
			}
		$bin5 = str_split($bin, 5);
		$out = "";
		$i = 0;
		while ($i < count($bin5)) {
			$n = base_convert(str_pad($bin5[$i], 5, '0'), 2, 10);
			$out .= self::$base32_map[$n];
			$i++;
			}
		$resid = strlen($bin) % 40;
		if ($pad && ($resid != 0)) {
			if ($resid == 8) $out .= str_repeat(self::$base32_pad, 6);
			else if ($resid == 16) $out .= str_repeat(self::$base32_pad, 4);
			else if ($resid == 24) $out .= str_repeat(self::$base32_pad, 3);
			else if ($resid == 32) $out .= self::$base32_pad;
			}
		return $out;
		}
	private function gen_secret() {
		return bin2hex(random_bytes(16));
		}
	public function timestamp() {
		return floor(microtime(true) / $this->period);
		}
	public function uri() {
		return "otpauth://totp/" . rawurlencode($this->issuer) .
				":" . rawurlencode($this->user)
			. "?secret=" . $this->base32_encode($this->secret)
			. "&issuer=" . rawurlencode($this->issuer)
			. "&digits=" . $this->digits
			. "&period=" . $this->period;
		}
	private function oath_truncate($hash) {
		$offset = ord($hash[19]) & 0xf;
		return (
			((ord($hash[$offset+0]) & 0x7f) << 24 ) |
			((ord($hash[$offset+1]) & 0xff) << 16 ) |
			((ord($hash[$offset+2]) & 0xff) << 8 ) |
			(ord($hash[$offset+3]) & 0xff)) % pow(10, $this->digits);
		}
	private function oath_hotp($counter) {
		$bin = pack("N*", 0) . pack("N*", $counter);
		$hash = hash_hmac("sha1", $bin, $this->secret, true);
		return str_pad($this->oath_truncate($hash), $this->digits,
			"0", STR_PAD_LEFT);
		}
	public function authcode($timestamp) {
		return $this->oath_hotp($timestamp);
		}
	}
?>
