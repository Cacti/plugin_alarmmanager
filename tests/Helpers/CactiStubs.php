<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
 | Guarded test doubles. Every definition is wrapped in function_exists so |
 | this file never redeclares a real Cacti core function; when the plugin   |
 | runs inside a Cacti tree the real helpers win. The doubles record into   |
 | $GLOBALS['alarmmanager_test_*'] state and are reset per test through     |
 | AlarmManagerTestCase::resetAlarmManagerTestState().                      |
 +-------------------------------------------------------------------------+
*/

if (!function_exists('alarmmanager_test_reset_state')) {
	$GLOBALS['alarmmanager_test_store']         = [];
	$GLOBALS['alarmmanager_test_hooks']         = [];
	$GLOBALS['alarmmanager_test_realms']        = [];
	$GLOBALS['alarmmanager_test_write_failure'] = false;
	$GLOBALS['alarmmanager_test_schema']        = false;
	$GLOBALS['alarmmanager_test_credentials']   = 0;
	$GLOBALS['alarmmanager_test_version']       = '0.1.0';

	function alarmmanager_test_reset_state(): void {
		$GLOBALS['alarmmanager_test_store']         = [];
		$GLOBALS['alarmmanager_test_hooks']         = [];
		$GLOBALS['alarmmanager_test_realms']        = [];
		$GLOBALS['alarmmanager_test_write_failure'] = false;
		$GLOBALS['alarmmanager_test_schema']        = false;
		$GLOBALS['alarmmanager_test_credentials']   = 0;
		$GLOBALS['alarmmanager_test_version']       = '0.1.0';
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $column = '', $log = true) {
		if (str_contains($sql, 'SELECT instance_uuid FROM plugin_alarmmanager_node')) {
			return str_repeat('a', 32);
		}

		if (str_contains($sql, 'COUNT(*) FROM plugin_hooks')) {
			return count(array_unique($GLOBALS['alarmmanager_test_hooks']));
		}

		if (str_contains($sql, 'COUNT(*) FROM plugin_realms')) {
			return count(array_unique($GLOBALS['alarmmanager_test_realms']));
		}

		if ($sql === 'SELECT VERSION()') {
			return '10.11.14-MariaDB';
		}

		if (str_contains($sql, 'information_schema')) {
			if (str_contains($sql, "TABLE_NAME = 'plugin_alarmmanager_audit'")) {
				return 1;
			}

			if (str_contains($sql, "TABLE_NAME = 'plugin_alarmmanager_credentials'")) {
				return $GLOBALS['alarmmanager_test_credentials'] > 0 ? 1 : 0;
			}

			if (str_contains($sql, 'COLUMNS') || str_contains($sql, 'STATISTICS')) {
				return 1;
			}

			return str_contains($sql, "ENGINE <> 'InnoDB'") ? 0 : (str_contains($sql, 'plugin_alarmmanager_transports') ? 2 : 3);
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
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true) {
		if ($GLOBALS['alarmmanager_test_write_failure']) {
			return false;
		}

		if (str_starts_with($sql, 'CREATE TABLE') || str_starts_with($sql, 'CREATE TRIGGER')) {
			return true;
		}

		if (str_contains($sql, 'INSERT INTO plugin_alarmmanager_schema')) {
			$GLOBALS['alarmmanager_test_schema'] = 1;

			return true;
		}

		if (str_starts_with($sql, 'INSERT IGNORE INTO plugin_alarmmanager_node') || str_starts_with($sql, 'INSERT IGNORE INTO plugin_alarmmanager_sync_sender')) {
			return true;
		}

		if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_jobs')) {
			return true;
		}

		if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_schema')) {
			$GLOBALS['alarmmanager_test_schema'] = str_contains($sql, 'version = 6') ? 6 : (str_contains($sql, 'GREATEST(version, 5)') ? max(5, $GLOBALS['alarmmanager_test_schema']) : (str_contains($sql, 'version = 4') ? 4 : (str_contains($sql, 'GREATEST(version, 3)') ? 3 : max(2, $GLOBALS['alarmmanager_test_schema']))));

			return true;
		}

		if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_events')) {
			return true;
		}

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
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true) {
		return [];
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = [], $log = true) {
		return false;
	}
}

if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql, $log = true) {
		return [];
	}
}

if (!function_exists('db_table_exists')) {
	function db_table_exists($table, $log = true) {
		return false;
	}
}

if (!function_exists('api_plugin_register_hook')) {
	function api_plugin_register_hook($plugin, $hook, $callback, $file, $enable = false) {
		if (!empty($GLOBALS['alarmmanager_test_registration_failure'])) {
			return false;
		}
		$GLOBALS['alarmmanager_test_hooks'][] = $hook;

		return null;
	}
}

if (!function_exists('api_plugin_register_realm')) {
	function api_plugin_register_realm($plugin, $file, $name, $admin) {
		if ($plugin !== 'alarmmanager' || !in_array($file, ['alarmmanager.php', 'alarmmanager_admin.php'], true) || $admin !== 1) {
			return false;
		}
		$GLOBALS['alarmmanager_test_realms'][] = $file;

		return null;
	}
}

if (!function_exists('api_plugin_is_enabled')) {
	function api_plugin_is_enabled($plugin) {
		return !empty($GLOBALS['alarmmanager_test_plugin_enabled']);
	}
}

if (!function_exists('read_config_option')) {
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['alarmmanager_test_config_options'][$config_name] ?? false;
	}
}

if (!function_exists('is_realm_allowed')) {
	function is_realm_allowed($realm) {
		return !empty($GLOBALS['alarmmanager_test_allowed_realms']) && in_array($realm, $GLOBALS['alarmmanager_test_allowed_realms'], true);
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $force = false, $module = 'SYSTEM', $level = POLLER_VERBOSITY_MEDIUM) {
		$GLOBALS['alarmmanager_test_log'][] = $message;

		return false;
	}
}

if (!function_exists('get_current_page')) {
	function get_current_page($returnapi = false) {
		return $GLOBALS['alarmmanager_test_current_page'] ?? '';
	}
}

if (!function_exists('__')) {
	function __($text, $domain = null) {
		return $text;
	}
}
