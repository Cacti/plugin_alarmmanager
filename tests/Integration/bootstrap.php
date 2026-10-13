<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
// Real PDO-backed implementations of the Cacti helper signatures for this test process.
function alarmmanager_test_connect(): PDO {
	return new PDO(getenv('ALARMMANAGER_TEST_DSN'), getenv('ALARMMANAGER_TEST_USER') ?: 'root', getenv('ALARMMANAGER_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function db_execute_prepared($sql, $params = [], $log = true) {
	$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
	global $alarmmanager_test_db, $alarmmanager_fail_commit;
	if (!empty($GLOBALS['alarmmanager_test_failure_plan'][$sql])) {
		$code = array_shift($GLOBALS['alarmmanager_test_failure_plan'][$sql]);
		$GLOBALS['database_last_error'] = 'DB Failed!, Error ' . $code;
		return false;
	}
	if ($sql === 'COMMIT' && !empty($alarmmanager_fail_commit)) {
		return false;
	}
	try {
		return $alarmmanager_test_db->prepare($sql)->execute($params);
	} catch (PDOException $error) {
		$code = (string) ($error->errorInfo[1] ?? $error->getCode());
		$GLOBALS['database_last_error'] = 'DB Failed!, Error ' . $code;
		$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;
		return false;
	}
}
function db_fetch_cell_prepared($sql, $params = [], $column = '', $log = true) {
	$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
	global $alarmmanager_test_db;
	try {
		$query = $alarmmanager_test_db->prepare($sql);
		$query->execute($params);
		return $query->fetchColumn();
	} catch (PDOException $error) {
		$code = (string) ($error->errorInfo[1] ?? $error->getCode());
		$GLOBALS['database_last_error'] = 'DB Failed!, Error ' . $code;
		$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;
		return false;
	}
}
function db_fetch_row_prepared($sql, $params = [], $log = true) {
	$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
	global $alarmmanager_test_db;
	try {
		$query = $alarmmanager_test_db->prepare($sql);
		$query->execute($params);
		return $query->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $error) {
		$code = (string) ($error->errorInfo[1] ?? $error->getCode());
		$GLOBALS['database_last_error'] = 'DB Failed!, Error ' . $code;
		$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;
		return false;
	}
}
function api_plugin_register_hook($plugin, $hook, $callback, $file, $enable = false) {
	return true;
}
function api_plugin_register_realm($plugin, $file, $name, $admin) {
	return true;
}
function __($text, $domain = null) {
	return $text;
}

function db_fetch_assoc_prepared($sql, $params = [], $log = true) {
	$GLOBALS['alarmmanager_test_queries'] = ($GLOBALS['alarmmanager_test_queries'] ?? 0) + 1;
	global $alarmmanager_test_db;
	try {
		$query = $alarmmanager_test_db->prepare($sql);
		$query->execute($params);
		return $query->fetchAll(PDO::FETCH_ASSOC);
	} catch (PDOException $error) {
		$code = (string) ($error->errorInfo[1] ?? $error->getCode());
		$GLOBALS['database_last_error'] = 'DB Failed!, Error ' . $code;
		$GLOBALS['alarmmanager_test_database_errors'][$code] = ($GLOBALS['alarmmanager_test_database_errors'][$code] ?? 0) + 1;
		return false;
	}
}
