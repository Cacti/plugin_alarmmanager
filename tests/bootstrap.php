<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
// Standalone test doubles; never load in a live Cacti/Pest process.
$GLOBALS['alarmmanager_test_store'] = [];
$GLOBALS['alarmmanager_test_hooks'] = [];
$GLOBALS['alarmmanager_test_write_failure'] = false;
$GLOBALS['alarmmanager_test_schema'] = false;
$GLOBALS['alarmmanager_test_credentials'] = 0;
$GLOBALS['alarmmanager_test_version'] = '0.1.0';

function db_fetch_cell_prepared($sql, $params = [], $column = '', $log = true) {
	if ($sql === 'SELECT VERSION()') { return '10.11.14-MariaDB'; }
	if (str_contains($sql, 'information_schema')) {
		if (str_contains($sql, "TABLE_NAME = 'plugin_alarmmanager_credentials'")) { return $GLOBALS['alarmmanager_test_credentials'] > 0 ? 1 : 0; }
		if (str_contains($sql, 'COLUMNS') || str_contains($sql, 'STATISTICS')) { return 1; }
		return str_contains($sql, "ENGINE <> 'InnoDB'") ? 0 : (str_contains($sql, "plugin_alarmmanager_transports") ? 2 : 3);
	}
	if (str_contains($sql, 'COUNT(*) FROM plugin_alarmmanager_credentials')) {
		return $GLOBALS['alarmmanager_test_credentials'];
	}
	if (str_contains($sql, 'plugin_alarmmanager_schema')) {
		return $GLOBALS['alarmmanager_test_schema'] ?: false;
	}
	if (str_contains($sql, 'SELECT version FROM plugin_config')) {
		return $GLOBALS['alarmmanager_test_version'];
	}
	if (str_contains($sql, 'SELECT status FROM plugin_config')) {
		return 1;
	}
	return $GLOBALS['alarmmanager_test_store'][$params[0] . ':' . $params[1]] ?? false;
}

function db_execute_prepared($sql, $params = [], $log = true) {
	if ($GLOBALS['alarmmanager_test_write_failure']) {
		return false;
	}
	if (str_starts_with($sql, 'CREATE TABLE')) {
		return true;
	}
	if (str_contains($sql, 'INSERT INTO plugin_alarmmanager_schema')) {
		$GLOBALS['alarmmanager_test_schema'] = 1;
		return true;
	}
	if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_jobs')) { return true; }
	if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_schema')) { $GLOBALS['alarmmanager_test_schema'] = str_contains($sql, 'version = 3') ? 3 : max(2, $GLOBALS['alarmmanager_test_schema']); return true; }
	if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_events')) { return true; }
	if (str_starts_with($sql, 'UPDATE plugin_config')) {
		$GLOBALS['alarmmanager_test_version'] = $params[0];
		return true;
	}
	if (!str_starts_with($sql, 'INSERT IGNORE INTO settings_user')) {
		throw new RuntimeException('Unexpected test write.');
	}
	$GLOBALS['alarmmanager_test_store'][$params[0] . ':' . $params[1]] ??= $params[2];
	return true;
}

function api_plugin_register_hook($plugin, $hook, $callback, $file, $enable = false) {
	$GLOBALS['alarmmanager_test_hooks'][] = $hook;
	return true;
}

function api_plugin_register_realm($plugin, $file, $name, $admin) {
	return $plugin === 'alarmmanager' && $file === 'alarmmanager.php' && $admin === 1;
}

function __($text, $domain = null) {
	return $text;
}
