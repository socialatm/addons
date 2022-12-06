<?php

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnableToBuildUuidException;
use Zotlabs\Extend\Route;
use Zotlabs\Extend\Hook;

/**
 * Name: queueworker
 * Description: Next generation queue worker for backgrounded tasks (BETA)
 * Version: 0.8.1
 * Author: Matthew Dent <dentm42@dm42.net>
 * MinVersion: 4.2.1
 */
class QueueWorkerUtils {

	public static $queueworker_version = '0.8.0';
	public static $hubzilla_minver = '4.2.1';
	public static $queueworker_dbver = 1;
	public static $queueworker = null;
	public static $maxworkers = 0;
	public static $workermaxage = 0;
	public static $workersleep = 100;
	public static $default_priorities = [
		'Notifier'          => 10,
		'Deliver'           => 10,
		'Cache_query'       => 10,
		'Content_importer'  => 1,
		'File_importer'     => 1,
		'Channel_purge'     => 1,
		'Directory'         => 1
	];

	public static function check_min_version($platform, $minver) {
		switch ($platform) {
			case 'hubzilla':
				$curver = STD_VERSION;
				break;
			case 'queueworker':
				$curver = QueueWorkerUtils::$queueworker_version;
				break;
			default:
				return false;
		}

		$checkver = explode('.', $minver);
		$ver      = explode('.', $curver);

		$major = intval($checkver[0]) <= intval($ver[0]);
		$minor = intval($checkver[1]) <= intval($ver[1]);
		$patch = intval($checkver[2]) <= intval($ver[2]);

		if ($major && $minor && $patch) {
			return true;
		}
		else {
			return false;
		}
	}

	public static function dbCleanup() {
		$success = UPDATE_SUCCESS;

		$sqlstmts[DBTYPE_MYSQL]    = [
			1 => ["DROP TABLE IF EXISTS workerq;"],
		];
		$sqlstmts[DBTYPE_POSTGRES] = [
			1 => ["DROP TABLE IF EXISTS workerq;"],
		];
		$dbsql                     = $sqlstmts[ACTIVE_DBTYPE];
		foreach ($dbsql as $updatever => $sql) {
			foreach ($sql as $query) {
				$r = q($query);
				if (!$r) {
					logger('Error running dbCleanup. sql query failed: ' . $query, LOGGER_NORMAL);
					$success = UPDATE_FAILED;
				}
			}
		}
		if ($success == UPDATE_SUCCESS) {
			logger('dbCleanup successful.', LOGGER_NORMAL);
			self::delsysconfig("dbver");
		}
		else {
			logger('Error in dbCleanup.', LOGGER_NORMAL);
		}
		return $success;
	}

	public static function dbUpgrade() {
		$dbverconfig = self::getsysconfig("dbver");

		$dbver = $dbverconfig ? $dbverconfig : 0;

		$dbsql[DBTYPE_MYSQL] = [
			1 => [
				"CREATE TABLE IF NOT EXISTS workerq (
					workerq_id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
					workerq_priority smallint,
					workerq_reservationid varchar(25) DEFAULT NULL,
					workerq_processtimeout datetime NOT NULL DEFAULT '0001-01-01 00:00:00',
					workerq_data text,
					KEY `workerq_priority` (`workerq_priority`),
					KEY `workerq_reservationid` (`workerq_reservationid`),
					KEY `workerq_processtimeout` (`workerq_processtimeout`)
				) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4;"
			],
			2 => [
				"ALTER TABLE workerq
					ADD COLUMN workerq_uuid char(36) NOT NULL DEFAULT '',
					ADD INDEX (workerq_uuid);"
			]
		];

		$dbsql[DBTYPE_POSTGRES] = [
			1 => [
				"CREATE TABLE IF NOT EXISTS workerq (
					workerq_id bigserial NOT NULL,
					workerq_priority smallint,
					workerq_reservationid varchar(25) DEFAULT NULL,
					workerq_processtimeout timestamp NOT NULL DEFAULT '0001-01-01 00:00:00',
					workerq_data text,
					PRIMARY KEY (workerq_id)
				);",
				"CREATE INDEX idx_workerq_priority ON workerq (workerq_priority);",
				"CREATE INDEX idx_workerq_reservationid ON workerq (workerq_reservationid);",
				"CREATE INDEX idx_workerq_processtimeout ON workerq (workerq_processtimeout);",
			],
			2 => [
				"ALTER TABLE workerq ADD workerq_uuid UUID NOT NULL;",
				"CREATE INDEX idx_workerq_uuid ON workerq (workerq_uuid);"
			]
		];

		foreach ($dbsql[ACTIVE_DBTYPE] as $ver => $sql) {
			if ($ver <= $dbver) {
				continue;
			}
			foreach ($sql as $query) {
				$r = q($query);
				if (!$r) {
					logger('dbUpgrade/Install Error (query): ' . $query, LOGGER_NORMAL);
					return UPDATE_FAILED;
				}
			}
			self::setsysconfig("dbver", $ver);
		}
		return UPDATE_SUCCESS;
	}

	private static function maybeunjson($value) {
		if (is_array($value)) {
			return $value;
		}

		if ($value != null) {
			$decoded = json_decode($value, true);
		}
		else {
			return null;
		}

		if (json_last_error() == JSON_ERROR_NONE) {
			return $decoded;
		}
		else {
			return $value;
		}
	}

	private static function maybejson($value, $options = 0) {
		if ($value != null) {
			if (!is_array($value)) {
				$decoded = json_decode($value, true);
			}
		}
		else {
			return null;
		}

		if (is_array($value) || json_last_error() != JSON_ERROR_NONE) {
			$encoded = json_encode($value, $options);
			return $encoded;
		}
		else {
			return $value;
		}
	}

	public static function checkver() {
		if (QueueWorkerUtils::getsysconfig("appver") == self::$queueworker_version) {
			return true;
		}

		QueueWorkerUtils::setsysconfig("status", "version-mismatch");
		return false;
	}

	public static function getsysconfig($param) {
		$val = get_config("queueworker", $param);
		$val = QueueWorkerUtils::maybeunjson($val);
		return $val;
	}

	public static function setsysconfig($param, $val) {
		$val = QueueWorkerUtils::maybejson($val);
		return set_config("queueworker", $param, $val);
	}

	public static function delsysconfig($param) {
		return del_config("queueworker", $param);
	}

	private static function qbegin($tablename) {
		switch (ACTIVE_DBTYPE) {
			case DBTYPE_MYSQL:
				q('BEGIN');
				q('LOCK TABLE ' . $tablename . ' WRITE');
				break;

			case DBTYPE_POSTGRES:
				q('BEGIN');
				//q('LOCK TABLE '.$tablename.' IN ACCESS EXCLUSIVE MODE');
				break;
		}
		return;
	}

	private static function qcommit() {
		switch (ACTIVE_DBTYPE) {
			case DBTYPE_MYSQL:
				q("UNLOCK TABLES");
				q("COMMIT");
				break;

			case DBTYPE_POSTGRES:
				q("COMMIT");
				break;
		}
		return;
	}

	private static function qrollback() {
		switch (ACTIVE_DBTYPE) {
			case DBTYPE_MYSQL:
				q("ROLLBACK");
				q("UNLOCK TABLES");
				break;

			case DBTYPE_POSTGRES:
				q("ROLLBACK");
				break;
		}
		return;
	}

	public static function MasterSummon(&$arr) {
		$argv = $arr['argv'];
		$argc = count($argv);
		if ($argv[0] != 'Queueworker') {

			$priority = 0; // @TODO allow reprioritization
			if(isset(self::$default_priorities[$argv[0]])) {
				$priority = self::$default_priorities[$argv[0]];
			}

			$workinfo      = ['argc' => $argc, 'argv' => $argv];
			$workinfo_json = self::maybejson($workinfo);
			$uuid          = self::get_uuid($workinfo_json);

			$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
				dbesc($uuid)
			);
			if ($r) {
				logger("Summon: Ignoring duplicate workerq task", LOGGER_DEBUG);
				logger(print_r($workinfo,true));
				$arr = ['argv' => []];
				return;
			}

			self::qbegin('workerq');
			$r = q("INSERT INTO workerq (workerq_priority, workerq_data, workerq_uuid) VALUES (%d, '%s', '%s')",
				intval($priority),
				$workinfo_json,
				dbesc($uuid)
			);
			if (!$r) {
				self::qrollback();
				logger("INSERT FAILED", LOGGER_DEBUG);
				return;
			}
			self::qcommit();
			logger('INSERTED: ' . self::maybejson($workinfo), LOGGER_DEBUG);
		}
		$argv    = [];
		$arr     = ['argv' => $argv];
		$workers = self::GetWorkerCount();
		if ($workers < self::$maxworkers) {
			logger("Less than max active workers ($workers) max = " . self::$maxworkers . ".", LOGGER_DEBUG);
			$phpbin = get_config('system', 'phpbin', 'php');
			proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker']);
		}
	}

	public static function MasterRelease(&$arr) {
		$argv = $arr['argv'];
		$argc = count($argv);
		if ($argv[0] != 'Queueworker') {

			$priority = 0; // @TODO allow reprioritization
			if(isset(self::$default_priorities[$argv[0]])) {
				$priority = self::$default_priorities[$argv[0]];
			}

			$workinfo      = ['argc' => $argc, 'argv' => $argv];
			$workinfo_json = self::maybejson($workinfo);
			$uuid          = self::get_uuid($workinfo_json);

			$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
				dbesc($uuid)
			);
			if ($r) {
				logger("Release: Duplicate task - do not insert.", LOGGER_DEBUG);
				logger(print_r($workinfo,true));

				$arr = ['argv' => []];
				return;
			}

			self::qbegin('workerq');
			$r = q("INSERT INTO workerq (workerq_priority, workerq_data, workerq_uuid) VALUES (%d, '%s', '%s')",
				intval($priority),
				$workinfo_json,
				dbesc($uuid)
			);
			if (!$r) {
				self::qrollback();
				logger("Insert failed: " . json_encode($workinfo), LOGGER_DEBUG);
				return;
			}
			self::qcommit();
			logger('INSERTED: ' . self::maybejson($workinfo), LOGGER_DEBUG);
		}
		$argv = [];
		$arr  = ['argv' => $argv];
		self::Process();
	}

	public static function GetWorkerCount() {
		if (self::$maxworkers == 0) {
			self::$maxworkers = get_config('queueworker', 'max_queueworkers', 4);
			self::$maxworkers = self::$maxworkers > 3 ? self::$maxworkers : 4;
		}
		if (self::$workermaxage == 0) {
			self::$workermaxage = get_config('queueworker', 'max_queueworker_age');
			self::$workermaxage = self::$workermaxage > 120 ? self::$workermaxage : 300;
		}

		q("update workerq set workerq_reservationid = null where workerq_reservationid is not null and workerq_processtimeout < %s",
			db_utcnow()
		);

		usleep(self::$workersleep);
		$workers = dbq("select count(distinct workerq_reservationid) as total from workerq where workerq_reservationid is not null");
		logger("WORKERCOUNT: " . $workers[0]['total'], LOGGER_DEBUG);
		return intval($workers[0]['total']);
	}

	public static function GetWorkerID() {
		if (self::$queueworker) {
			return self::$queueworker;
		}
		$wid = uniqid('', true);
		usleep(mt_rand(500000, 3000000)); //Sleep .5 - 3 seconds before creating a new worker.
		$workers = self::GetWorkerCount();
		if ($workers >= self::$maxworkers) {
			logger("Too many active workers ($workers) max = " . self::$maxworkers, LOGGER_DEBUG);
			return false;
		}
		self::$queueworker = $wid;
		return $wid;
	}

	private static function getworkid() {
		self::GetWorkerCount();

		self::qbegin('workerq');

		if (ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$work = dbq("SELECT workerq_id FROM workerq WHERE workerq_reservationid IS NULL ORDER BY workerq_priority DESC, workerq_id ASC LIMIT 1 FOR UPDATE SKIP LOCKED;");
		}
		else {
			$work = dbq("SELECT workerq_id FROM workerq WHERE workerq_reservationid IS NULL ORDER BY workerq_priority DESC, workerq_id ASC LIMIT 1;");
		}

		if (!$work) {
			self::qrollback();
			return false;
		}
		$id = $work[0]['workerq_id'];

		$work = q("UPDATE workerq SET workerq_reservationid = '%s', workerq_processtimeout = %s + INTERVAL %s WHERE workerq_id = %d",
			self::$queueworker,
			db_utcnow(),
			db_quoteinterval(self::$workermaxage . " SECOND"),
			intval($id)
		);

		if (!$work) {
			self::qrollback();
			logger("Could not update workerq.", LOGGER_DEBUG);
			return false;
		}
		logger("GOTWORK: " . json_encode($work), LOGGER_DEBUG);
		self::qcommit();
		return $id;
	}

	public static function Process() {
		self::$workersleep = get_config('queueworker', 'queue_worker_sleep');
		self::$workersleep = intval(self::$workersleep) > 100 ? intval(self::$workersleep) : 100;

		if (!self::GetWorkerID()) {
			logger('Unable to get worker ID. Exiting.', LOGGER_DEBUG);
			killme();
		}

		$jobs   = 0;
		$workid = self::getworkid();
		while ($workid) {
			usleep(self::$workersleep);
			// @FIXME:  Currently $workersleep is a fixed value.  It may be a good idea
			// to implement a "backoff" instead - based on load average or some
			// other metric.

			self::qbegin('workerq');

			if (ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
				$workitem = q("SELECT * FROM workerq WHERE workerq_id = %d FOR UPDATE SKIP LOCKED",
					$workid
				);
			}
			else {
				$workitem = q("SELECT * FROM workerq WHERE workerq_id = %d",
					$workid
				);
			}

			self::qcommit();

			if (isset($workitem[0])) {
				// At least SOME work to do.... in case there's more, let's ramp up workers.
				$workers = self::GetWorkerCount();
				if ($workers < self::$maxworkers) {
					logger("Less than max active workers ($workers) max = " . self::$maxworkers . ".", LOGGER_DEBUG);
					$phpbin = get_config('system', 'phpbin', 'php');
					proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker']);
				}

				$jobs++;
				logger("Workinfo: " . $workitem[0]['workerq_data'], LOGGER_DEBUG);

				$workinfo = self::maybeunjson($workitem[0]['workerq_data']);
				$argv     = $workinfo['argv'];
				logger('Master: process: ' . json_encode($argv), LOGGER_DEBUG);

				$cls  = '\\Zotlabs\\Daemon\\' . $argv[0];
				$argv = flatten_array_recursive($argv);
				$argc = count($argv);
				$cls::run($argc, $argv);

				// @FIXME: Right now we assume that if we get a return, everything is OK.
				// At some point we may want to test whether the run returns true/false
				// and requeue the work to be tried again if needed.  But we probably want
				// to implement some sort of "retry interval" first.

				self::qbegin('workerq');
				q("delete from workerq where workerq_id = %d", $workid);
				self::qcommit();
			}
			else {
				logger("NO WORKITEM!", LOGGER_DEBUG);
			}
			$workid = self::getworkid();
		}
		logger('Master: Worker Thread: queue items processed:' . $jobs, LOGGER_DEBUG);
	}

	public static function ClearQueue() {
		$work = q("select * from workerq");
		while ($work) {
			foreach ($work as $workitem) {
				$workinfo = self::maybeunjson($workitem['v']);
				$argc     = $workinfo['argc'];
				$argv     = $workinfo['argv'];
				logger('Master: process: ' . print_r($argv, true), LOGGER_ALL, LOG_DEBUG);
				if (!isset($argv[0])) {
					q("delete from workerq where workerq_id = %d",
						$work[0]['workerq_id']
					);
					continue;
				}
				$cls = '\\Zotlabs\\Daemon\\' . $argv[0];
				$cls::run($argc, $argv);
				q("delete from workerq where workerq_id = %d",
					$work[0]['workerq_id']
				);
				usleep(300000);
				//Give the server .3 seconds to catch its breath between tasks.
				//This will hopefully keep it from crashing to it's knees entirely
				//if the last task ended up initiating other parallel processes
				//(eg. polling remotes)
			}
			//Make sure nothing new came in
			$work = q("select * from workerq");
		}
		return;
	}

	/**
	 * @brief Generate a name-based v5 UUID with custom namespace
	 *
	 * @param string $data
	 * @return string $uuid
	 */
	private static function get_uuid($data) {
		$namespace = '3a112e42-f147-4ccf-a78b-f6841339ea2a';
		try {
			$uuid = Uuid::uuid5($namespace, $data)->toString();
		} catch (UnableToBuildUuidException $e) {
			logger('UUID generation failed');
			return '';
		}
		return $uuid;
	}

	public static function uninstall() {
		logger('Uninstall start.');
		//Prevent new work form being added.
		Hook::unregister('daemon_master_release', __FILE__, 'QueueWorkerUtils::MasterRelease');
		QueueWorkerUtils::ClearQueue();
		QueueWorkerUtils::dbCleanup();

		QueueWorkerUtils::delsysconfig("appver");
		QueueWorkerUtils::setsysconfig("status", "uninstalled");
		notice('QueueWorker Uninstalled.' . EOL);
		logger('Uninstalled.');
		return;
	}

	public static function install() {
		logger('Install start.');
		if (QueueWorkerUtils::dbUpgrade() == UPDATE_FAILED) {
			notice('QueueWorker Install error - Abort installation.' . EOL);
			logger('Install error - Abort installation.');
			QueueWorkerUtils::setsysconfig("status", "install error");
			return;
		}
		notice('QueueWorker Installed successfully.' . EOL);
		logger('QueueWorker Installed successfully.', LOGGER_NORMAL);
		QueueWorkerUtils::setsysconfig("appver", self::$queueworker_version);
		QueueWorkerUtils::setsysconfig("status", "ready");
	}
}

function queueworker_install() {
	QueueWorkerUtils::install();
}

function queueworker_uninstall() {
	QueueWorkerUtils::uninstall();
}

function queueworker_load() {
	// HOOK REGISTRATION
	Hook::register('daemon_master_release', __FILE__, 'QueueWorkerUtils::MasterRelease', 1, 0);
	Hook::register('daemon_master_summon', __FILE__, 'QueueWorkerUtils::MasterSummon', 1, 0);
	Route::register(dirname(__FILE__) . '/Mod_Queueworker.php', 'queueworker');
	QueueWorkerUtils::dbupgrade();
}

function queueworker_unload() {
	Hook::unregister_by_file(__FILE__);
	Route::unregister_by_file(dirname(__FILE__) . '/Mod_Queueworker.php');
}
