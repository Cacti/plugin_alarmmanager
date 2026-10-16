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
*/

require_once(__DIR__ . '/exceptions.php');
function alarmmanager_storage_error(): RuntimeException {
	global $database_last_error;
	$message = (string) ($database_last_error ?? '');

	return preg_match('/(?:Error |errno[:= ]+)(?:1213|1205)\b/i', $message)
		? new \AlarmManager\TransientStorageException('storage_contention') : new \AlarmManager\StorageException('storage_failure');
}
function alarmmanager_db_write(string $sql, array $params = [], ?PDO $connection = null): void {
	if (!db_execute_prepared($sql, $params, false, $connection)) {
		throw alarmmanager_storage_error();
	}
}
function alarmmanager_db_row(string $sql, array $params = [], ?PDO $connection = null): array {
	$result = alarmmanager_db_rows($sql, $params, $connection);

	return $result[0] ?? [];
}
function alarmmanager_db_rows(string $sql, array $params = [], ?PDO $connection = null): array {
	$result = db_fetch_assoc_prepared($sql, $params, false, $connection);

	if ($result === false) {
		throw alarmmanager_storage_error();
	}

	return $result;
}
function alarmmanager_transaction(callable $action, ?PDO $connection = null) {
	$active = db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false, $connection);

	if ($active === false || (int) $active !== 0) {
		throw new RuntimeException('transaction_unavailable');
	}

	for ($try = 0; $try < 3; $try++) {
		alarmmanager_db_write('START TRANSACTION', [], $connection);

		try {
			$result = $action();
			alarmmanager_db_write('COMMIT', [], $connection);

			return $result;
		} catch (Throwable $error) {
			db_execute_prepared('ROLLBACK', [], false, $connection);

			if (!$error instanceof \AlarmManager\TransientStorageException || $try === 2) {
				throw $error;
			}
			usleep(random_int(10000, 30000));
		}
	}

	throw new RuntimeException('storage_failure');
}
function alarmmanager_stream_key(array $event, string $mode): string {
	return hash('sha256', implode('|', [$event['source'], $event['source_instance'], $event['alarm_key'], $mode]));
}
function alarmmanager_runtime_enabled(): bool {
	global $config;

	return empty($config['DEBUG_SQL_FLOW']) && (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false) === 1;
}
function alarmmanager_local_poller(): int {
	global $config;
	$value = filter_var($config['poller_id'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

	if ($value === false) {
		throw new RuntimeException('invalid_poller');
	}

	return $value;
}
/** One operational log line, tagged with the plugin directory name for grep and selective debugging. */
function alarmmanager_log(string $message): void {
	cacti_log($message, false, 'ALARMMANAGER');
}

/** Main-poller setting changes use Cacti's existing next-cycle synchronization queue. */
function alarmmanager_request_configuration_replication(): void {
	if (alarmmanager_local_poller() === 1) {
		alarmmanager_db_write("UPDATE poller SET requires_sync = 'on' WHERE id > 1 AND disabled = ''");
	}
}
