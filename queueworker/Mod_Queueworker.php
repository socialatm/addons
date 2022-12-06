<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Web\Controller;

require_once(dirname(__FILE__) . '/queueworker.php');

class Queueworker extends Controller {

	function init() {
	}

	function post() {

		if ((!local_channel()) || (!is_site_admin())) {
			goaway(z_root() . '/queueworker');
		}

		check_form_security_token('form_security_token', 'queueworker');

		$maxqueueworkers = intval($_POST['queueworker_maxworkers']);
		$maxqueueworkers = ($maxqueueworkers > 3) ? $maxqueueworkers : 4;
		set_config('queueworker', 'max_queueworkers', $maxqueueworkers);

		$maxworkerage = intval($_POST['queueworker_max_age']);
		$maxworkerage = ($maxworkerage >= 120) ? $maxworkerage : 300;
		set_config('queueworker', 'queueworker_max_age', $maxworkerage);

		$queueworkersleep = intval($_POST['queue_worker_sleep']);
		$queueworkersleep = ($queueworkersleep > 100) ? $queueworkersleep : 100;
		set_config('queueworker', 'queue_worker_sleep', $queueworkersleep);

		goaway(z_root() . '/queueworker');
	}

	function get() {

		$content = "<H1>ERROR: Page not found</H1>";
		App::$error = 404;

		if (!local_channel()) {
			return $content;
		}

		if (!(is_site_admin())) {
			return $content;
		}

		load_config("queueworker");

		$content = "<H1>Queue Status</H1>\n";

		$r = q('select count(*) as qentries from workerq');

		if (!$r) {
			$content = "<H4>There was an error querying the database.</H4>";
			return $content;
		}

		$content .= "<H4>There are " . $r[0]['qentries'] . " queue items to be processed.</H4>";

		$r = dbq("select count(distinct workerq_reservationid) as qworkers from workerq where workerq_reservationid is not null");

		$content .= "<H4>Active workers: " . $r[0]['qworkers'] . "</H4>";

		$maxqueueworkers = get_config('queueworker', 'max_queueworkers', 4);
		$maxqueueworkers = ($maxqueueworkers > 3) ? $maxqueueworkers : 4;
		set_config('queueworker', 'max_queueworkers', $maxqueueworkers);

		$sc = '';

		$sc .= replace_macros(get_markup_template('field_input.tpl'), [
			'$field' => [
				'queueworker_maxworkers',
				t('Max queueworker threads'),
				$maxqueueworkers,
				t('Minimum 4, default 4')
			]
		]);

		$workermaxage = get_config('queueworker', 'queueworker_max_age');
		$workermaxage = ($workermaxage >= 120) ? $workermaxage : 300;
		set_config('queueworker', 'max_queueworker_age', $workermaxage);

		$sc .= replace_macros(get_markup_template('field_input.tpl'), [
			'$field' => [
				'queueworker_max_age',
				t('Assume workers dead after'),
				$workermaxage,
				t('Minimum 120, default 300 seconds')
			]
		]);

		$queueworkersleep = get_config('queueworker', 'queue_worker_sleep');
		$queueworkersleep = ($queueworkersleep > 100) ? $queueworkersleep : 100;
		set_config('queueworker', 'queue_worker_sleep', $queueworkersleep);

		$sc .= replace_macros(get_markup_template('field_input.tpl'), [
			'$field' => [
				'queue_worker_sleep',
				t('Pause before starting next task'),
				$queueworkersleep,
				t('Minimum 100, default 100 microseconds')
			]
		]);

		$tpl = get_markup_template('settings_addon.tpl');
		$content .= replace_macros($tpl, [
				'$action_url' => 'queueworker',
				'$form_security_token' => get_form_security_token('queueworker'),
				'$title' => t('Queueworker Settings'),
				'$content' => $sc,
				'$baseurl' => z_root(),
				'$submit' => t('Save')
			]
		);

		return $content;

	}
}
