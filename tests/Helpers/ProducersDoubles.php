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
 | Guarded doubles for the pinned producer-branch tests. The global db_*,  |
 | read_config_option and logging names already belong to CactiStubs.php,  |
 | so every substitute here lives in a dedicated namespace: producer code  |
 | that is eval()ed inside that namespace binds the namespaced double      |
 | first, while everything else still falls back to the guarded Cacti      |
 | doubles. Every definition is wrapped in function_exists so this file is |
 | idempotent, and all recorded state lives in $GLOBALS['alarmmanager_*']. |
 +-------------------------------------------------------------------------+
*/

namespace AlarmManagerProducers {
	if (!function_exists('AlarmManagerProducers\read_config_option')) {
		function read_config_option($name, $force = false) {
			return $GLOBALS['alarmmanager_test_producer_config_options'][$name] ?? '';
		}
	}

	if (!function_exists('AlarmManagerProducers\db_fetch_row_prepared')) {
		function db_fetch_row_prepared($sql, $params = [], $log = true) {
			return $GLOBALS['alarmmanager_test_host'];
		}
	}

	if (!function_exists('AlarmManagerProducers\db_execute_prepared')) {
		function db_execute_prepared($sql, $params = [], $log = true) {
			if (str_contains($sql, 'UPDATE host SET')) {
				$columns = ['status', 'status_event_count', 'status_fail_date', 'status_rec_date', 'status_last_error', 'min_time', 'max_time', 'cur_time', 'avg_time', 'total_polls', 'failed_polls', 'availability'];

				foreach ($columns as $i => $field) {
					$GLOBALS['alarmmanager_test_host'][$field] = in_array($field, ['status_fail_date', 'status_rec_date'], true) ? gmdate('Y-m-d H:i:s', (int) $params[$i]) : $params[$i];
				}
				$GLOBALS['alarmmanager_test_writes']++;
			}

			return true;
		}
	}

	if (!function_exists('AlarmManagerProducers\cacti_log')) {
		function cacti_log(...$args) {
		}
	}

	if (!function_exists('AlarmManagerProducers\cacti_sizeof')) {
		function cacti_sizeof($data) {
			return count($data);
		}
	}

	if (!function_exists('AlarmManagerProducers\html_escape')) {
		function html_escape($value) {
			return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('AlarmManagerProducers\__')) {
		function __($text, ...$args) {
			if ($args) {
				array_pop($args);
			}

			return $args ? vsprintf($text, $args) : $text;
		}
	}

	if (!function_exists('AlarmManagerProducers\__esc')) {
		function __esc($text, ...$args) {
			return __($text, ...$args);
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_debug')) {
		function syslog_debug($message) {
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_db_fetch_assoc_prepared')) {
		function syslog_db_fetch_assoc_prepared($sql, $params) {
			return $GLOBALS['alarmmanager_test_syslog_records'];
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_db_fetch_cell_prepared')) {
		function syslog_db_fetch_cell_prepared($sql, $params) {
			return $GLOBALS['alarmmanager_test_syslog_repeat'] ? 1 : 0;
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_alert_suppression_check')) {
		function syslog_alert_suppression_check($alert, $hostname, $at) {
			return ['allowed' => $GLOBALS['alarmmanager_test_syslog_allow']];
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_alert_suppression_record')) {
		function syslog_alert_suppression_record($alert, $state) {
			$GLOBALS['alarmmanager_test_syslog_calls'][] = 'suppression_record';
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_log_alert')) {
		function syslog_log_alert(...$args) {
			$GLOBALS['alarmmanager_test_syslog_calls'][] = ['log', $args];

			return 12;
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_sendemail')) {
		function syslog_sendemail(...$args) {
			$GLOBALS['alarmmanager_test_syslog_calls'][] = ['email', $args];
		}
	}

	if (!function_exists('AlarmManagerProducers\alert_setup_environment')) {
		function alert_setup_environment(...$args) {
			$GLOBALS['alarmmanager_test_syslog_calls'][] = ['environment', $args];
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_execute_ticket_command')) {
		function syslog_execute_ticket_command(...$args) {
			$GLOBALS['alarmmanager_test_syslog_calls'][] = ['ticket', $args];
		}
	}

	if (!function_exists('AlarmManagerProducers\syslog_execute_alert_command')) {
		function syslog_execute_alert_command(...$args) {
			$GLOBALS['alarmmanager_test_syslog_calls'][] = ['script', $args];
		}
	}

	if (!function_exists('AlarmManagerProducers\api_plugin_remote_capabilities')) {
		function api_plugin_remote_capabilities($plugin) {
			return ['remote_collect' => 1, 'remote_poller' => 1];
		}
	}

	if (!function_exists('AlarmManagerProducers\api_plugin_hook_is_remote_collect')) {
		function api_plugin_hook_is_remote_collect(...$args) {
			return false;
		}
	}

	if (!function_exists('AlarmManagerProducers\api_plugin_status_run')) {
		function api_plugin_status_run(...$args) {
			return true;
		}
	}
}

namespace AlarmManagerReplication {
	if (!function_exists('AlarmManagerReplication\db_fetch_assoc_prepared')) {
		function db_fetch_assoc_prepared($sql, $params = [], $log = true) {
			$GLOBALS['alarmmanager_test_queries'][] = $sql;

			if (str_contains($sql, 'SELECT poller_id FROM')) {
				return $GLOBALS['alarmmanager_test_peers'];
			}

			if (str_contains($sql, 'SELECT device_id')) {
				return [['device_id' => 42, 'poller_id' => 2, 'valid_from' => gmdate('Y-m-d H:i:s', time() - 60), 'valid_until' => gmdate('Y-m-d H:i:s', time() + 300)]];
			}

			return [];
		}
	}

	if (!function_exists('AlarmManagerReplication\db_execute_prepared')) {
		function db_execute_prepared($sql, $params = [], $log = true, $connection = false) {
			$GLOBALS['alarmmanager_test_queries'][] = $sql;

			return true;
		}
	}

	if (!function_exists('AlarmManagerReplication\db_fetch_assoc')) {
		function db_fetch_assoc($sql, $log = true, $connection = false) {
			return ($GLOBALS['alarmmanager_test_schema_mismatch'] ?? false) ? [] : [['Field' => 'id'], ['Field' => 'status']];
		}
	}

	if (!function_exists('AlarmManagerReplication\db_table_exists')) {
		function db_table_exists(...$args) {
			return true;
		}
	}

	if (!function_exists('AlarmManagerReplication\cacti_sizeof')) {
		function cacti_sizeof($data) {
			return count($data);
		}
	}

	if (!function_exists('AlarmManagerReplication\cacti_log')) {
		function cacti_log(...$args) {
		}
	}

	if (!function_exists('AlarmManagerReplication\replicate_out_table')) {
		function replicate_out_table($connection, $data, $table, $poller, $truncate = true, $exclude = false) {
			$GLOBALS['alarmmanager_test_replications'][] = [$table, $poller, $truncate, $exclude];
		}
	}
}

namespace {
	if (!function_exists('alarmmanager_device_bridge_poll_allowed')) {
		function alarmmanager_device_bridge_poll_allowed($id): bool {
			return true;
		}
	}

	if (!function_exists('alarmmanager_device_bridge_core')) {
		function alarmmanager_device_bridge_core($safe, $previous, $write): bool {
			$GLOBALS['alarmmanager_test_publications'][] = [$safe, $previous];

			return $write();
		}
	}

	if (!class_exists('AlarmManagerProducerBridge', false)) {
		final class AlarmManagerProducerBridge {
			public static $decision = ['owned' => false, 'channels' => []];
			public static $calls    = [];
			public static function publish($source, $resource, $template, $identity, $input): array {
				self::$calls[] = compact('source', 'resource', 'template', 'identity', 'input');

				return self::$decision;
			}
			public static function owns($decision, $channel): bool {
				return ($decision['owned'] ?? false) && in_array($channel, $decision['channels'] ?? [], true);
			}
		}
	}

	if (!function_exists('alarmmanager_test_producer_namespace_prefix')) {
		/**
		 * Return the alias prologue for one producer double namespace.
		 *
		 * Namespaced producer code compiled by eval() binds unqualified class
		 * names to its own namespace with no global fallback, so the global
		 * bridge double and the few core classes the pinned code references
		 * are aliased into the namespace before the source segment.
		 *
		 * @param string $namespace Double namespace for the segment.
		 */
		function alarmmanager_test_producer_namespace_prefix(string $namespace): string {
			$prefix = 'namespace ' . $namespace . ';';

			foreach (['AlarmManagerProducerBridge', 'Throwable', 'Exception', 'RuntimeException', 'DateTimeImmutable', 'DateTimeZone', 'DateTime'] as $class) {
				if (!class_exists($namespace . '\\' . $class, false) && !interface_exists($namespace . '\\' . $class, false) && !enum_exists($namespace . '\\' . $class, false)) {
					$prefix .= 'if (!class_exists(' . var_export($namespace . '\\' . $class, true) . ', false) && !interface_exists(' . var_export($namespace . '\\' . $class, true) . ', false) && !enum_exists(' . var_export($namespace . '\\' . $class, true) . ', false)) { class_alias(' . var_export('\\' . $class, true) . ', ' . var_export($namespace . '\\' . $class, true) . '); }';
				}
			}

			return $prefix;
		}
	}

	if (!function_exists('alarmmanager_test_producer_namespaced_code')) {
		/**
		 * Return the source of one trusted file wrapped in a producer double namespace.
		 *
		 * @param string $file      Absolute path of the trusted source file.
		 * @param string $namespace Double namespace that shadows the Cacti boundaries.
		 */
		function alarmmanager_test_producer_namespaced_code(string $file, string $namespace = 'AlarmManagerProducers'): string {
			$code = (string) file_get_contents($file);
			$tag  = strpos($code, '<?php');

			if ($tag !== false) {
				$code = substr($code, $tag + 5);
			}

			if (preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $code, $match, PREG_OFFSET_CAPTURE) === 1) {
				$declare = $match[0][0];

				return substr($code, $match[0][1], strlen($declare)) . alarmmanager_test_producer_namespace_prefix($namespace) . substr($code, $match[0][1] + strlen($declare));
			}

			return alarmmanager_test_producer_namespace_prefix($namespace) . $code;
		}
	}

	if (!function_exists('alarmmanager_test_producer_eval')) {
		/**
		 * Evaluate one trusted code segment inside a producer double namespace.
		 *
		 * @param string $code      Trusted extracted source segment without an opening tag.
		 * @param string $namespace Double namespace for the segment.
		 */
		function alarmmanager_test_producer_eval(string $code, string $namespace = 'AlarmManagerProducers'): void {
			eval(alarmmanager_test_producer_namespace_prefix($namespace) . $code);
		}
	}
}
