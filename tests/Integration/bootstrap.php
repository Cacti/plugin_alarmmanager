<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
 | Integration-suite bootstrap: real PDO-backed implementations of the     |
 | Cacti helper signatures for this test process. Run through              |
 | phpunit-integration.xml with ALARMMANAGER_TEST_DSN set; tests declare   |
 | @group db and skip when the DSN is absent. This bootstrap must never be |
 | loaded into the unit-suite process (the doubles win there through       |
 | tests/Helpers/CactiStubs.php) or a live Cacti installation.             |
 +-------------------------------------------------------------------------+
*/

if (PHP_SAPI !== 'cli' && !(PHP_SAPI === 'cli-server' && getenv('ALARMMANAGER_TEST_WEB') === '1')) {
	http_response_code(403);
	exit;
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 5);
}

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 3);
}

if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
	$GLOBALS['config'] = ['base_path' => getenv('ALARMMANAGER_CACTI_ROOT') ?: dirname(__DIR__, 4), 'url_path' => '/cacti/', 'cacti_server_os' => 'unix', 'poller_id' => 1];
}

$candidate_roots = [];

if (getenv('CACTI_PATH') !== false) {
	$candidate_roots[] = rtrim((string) getenv('CACTI_PATH'), '/');
}

if (getenv('ALARMMANAGER_CACTI_ROOT') !== false) {
	$candidate_roots[] = rtrim((string) getenv('ALARMMANAGER_CACTI_ROOT'), '/');
}

// tests/Integration -> tests -> plugins -> cacti root
$candidate_roots[] = dirname(__DIR__, 4);

$cacti_root = null;

foreach ($candidate_roots as $candidate_root) {
	if (is_file($candidate_root . '/include/vendor/autoload.php') && is_file($candidate_root . '/include/cacti_version')) {
		$cacti_root = $candidate_root;

		break;
	}
}

if ($cacti_root === null) {
	throw new RuntimeException('Integration bootstrap: set CACTI_PATH or ALARMMANAGER_CACTI_ROOT to a Cacti checkout with include/vendor.');
}

require_once $cacti_root . '/include/vendor/autoload.php';
require_once dirname(__DIR__) . '/TestCase.php';

if (!$GLOBALS['config']['base_path']) {
	$GLOBALS['config']['base_path'] = $cacti_root;
}

// Real PDO-backed implementations of the Cacti helper signatures for this test process.
if (!function_exists('alarmmanager_test_connect')) {
	function alarmmanager_test_connect(): PDO {
		return new PDO(getenv('ALARMMANAGER_TEST_DSN'), getenv('ALARMMANAGER_TEST_USER') ?: 'root', getenv('ALARMMANAGER_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true) {
		$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
		global $alarmmanager_test_db, $alarmmanager_fail_commit;

		if (!empty($GLOBALS['alarmmanager_test_failure_plan'][$sql])) {
			$code                           = array_shift($GLOBALS['alarmmanager_test_failure_plan'][$sql]);
			$GLOBALS['database_last_error'] = 'DB Failed!, Error ' . $code;

			return false;
		}

		if ($sql === 'COMMIT' && !empty($alarmmanager_fail_commit)) {
			return false;
		}

		try {
			return $alarmmanager_test_db->prepare($sql)->execute($params);
		} catch (PDOException $error) {
			$code                                                = (string) ($error->errorInfo[1] ?? $error->getCode());
			$GLOBALS['database_last_error']                      = 'DB Failed!, Error ' . $code;
			$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;

			return false;
		}
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $column = '', $log = true) {
		$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
		global $alarmmanager_test_db;

		try {
			$query = $alarmmanager_test_db->prepare($sql);
			$query->execute($params);

			return $query->fetchColumn();
		} catch (PDOException $error) {
			$code                                                = (string) ($error->errorInfo[1] ?? $error->getCode());
			$GLOBALS['database_last_error']                      = 'DB Failed!, Error ' . $code;
			$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;

			return false;
		}
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = [], $log = true) {
		$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
		global $alarmmanager_test_db;

		try {
			$query = $alarmmanager_test_db->prepare($sql);
			$query->execute($params);

			return $query->fetch(PDO::FETCH_ASSOC);
		} catch (PDOException $error) {
			$code                                                = (string) ($error->errorInfo[1] ?? $error->getCode());
			$GLOBALS['database_last_error']                      = 'DB Failed!, Error ' . $code;
			$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;

			return false;
		}
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true) {
		$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
		global $alarmmanager_test_db;

		try {
			$query = $alarmmanager_test_db->prepare($sql);
			$query->execute($params);

			return $query->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $error) {
			$code                                                = (string) ($error->errorInfo[1] ?? $error->getCode());
			$GLOBALS['database_last_error']                      = 'DB Failed!, Error ' . $code;
			$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;

			return false;
		}
	}
}

if (!function_exists('db_table_exists')) {
	function db_table_exists($table): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) === 1;
	}
}

if (!function_exists('api_plugin_register_hook')) {
	function api_plugin_register_hook($plugin, $hook, $callback, $file, $enable = false) {
		db_execute_prepared('CREATE TABLE IF NOT EXISTS plugin_hooks (name VARCHAR(64), hook VARCHAR(64), `function` VARCHAR(64), file VARCHAR(128), status INT, PRIMARY KEY(name, hook)) ENGINE=InnoDB');
		db_execute_prepared('INSERT INTO plugin_hooks (name, hook, `function`, file, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `function` = VALUES(`function`), file = VALUES(file)', [$plugin, $hook, $callback, $file, (int) $enable]);
	}
}

if (!function_exists('api_plugin_register_realm')) {
	function api_plugin_register_realm($plugin, $file, $name, $admin) {
		db_execute_prepared('CREATE TABLE IF NOT EXISTS plugin_realms (id INT AUTO_INCREMENT PRIMARY KEY, plugin VARCHAR(64), file VARCHAR(128), display VARCHAR(128), UNIQUE KEY plugin_file (plugin, file)) ENGINE=InnoDB');
		db_execute_prepared('INSERT INTO plugin_realms (plugin, file, display) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE display = VALUES(display)', [$plugin, $file, $name]);
	}
}

if (!function_exists('__')) {
	function __($text, $domain = null) {
		return $text;
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $force = false, $module = 'SYSTEM', $level = POLLER_VERBOSITY_MEDIUM) {
		$GLOBALS['alarmmanager_test_log'][] = $message;

		return false;
	}
}
