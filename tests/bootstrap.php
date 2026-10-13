<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Dedicated standalone process. These doubles never load into Cacti/Pest.
$GLOBALS['alarmmanager_test_store'] = [];
$GLOBALS['alarmmanager_test_hooks'] = [];
$GLOBALS['alarmmanager_test_write_failure'] = false;

function db_fetch_cell_prepared($sql, $params, $column = '', $log = true) {
	return $GLOBALS['alarmmanager_test_store'][$params[0] . ':' . $params[1]] ?? false;
}

function db_execute_prepared($sql, $params, $log = true) {
	if ($GLOBALS['alarmmanager_test_write_failure']) {
		return false;
	}
	if (!str_starts_with($sql, 'INSERT IGNORE')) {
		throw new RuntimeException('Unexpected test write.');
	}
	$GLOBALS['alarmmanager_test_store'][$params[0] . ':' . $params[1]] ??= $params[2];
	return true;
}

function api_plugin_register_hook($plugin, $hook, $callback, $file) {
	$GLOBALS['alarmmanager_test_hooks'][] = $hook;
	return true;
}

function api_plugin_register_realm($plugin, $file, $name, $admin) {
	return $plugin === 'alarmmanager' && $file === 'alarmmanager.php' && $admin === 1;
}

function __($text, $domain = null) {
	return $text;
}
