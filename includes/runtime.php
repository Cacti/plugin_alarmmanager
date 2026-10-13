<?php
// SPDX-License-Identifier: GPL-2.0-or-later

require_once(__DIR__ . '/../lib/AlarmManager/Exceptions.php');
function alarmmanager_storage_error(): RuntimeException {
	global $database_last_error;
	$message = (string) ($database_last_error ?? '');
	return preg_match('/(?:Error |errno[:= ]+)(?:1213|1205)\b/i', $message)
		? new \AlarmManager\TransientStorageException('storage_contention') : new \AlarmManager\StorageException('storage_failure');
}
function alarmmanager_db_write(string $sql, array $params = []): void {
	if (!db_execute_prepared($sql, $params, false)) {
		throw alarmmanager_storage_error();
	}
}
function alarmmanager_db_row(string $sql, array $params = []): array {
	$result = alarmmanager_db_rows($sql, $params);
	return $result[0] ?? [];
}
function alarmmanager_db_rows(string $sql, array $params = []): array {
	$result = db_fetch_assoc_prepared($sql, $params, false);
	if ($result === false) {
		throw alarmmanager_storage_error();
	}
	return $result;
}
function alarmmanager_transaction(callable $action) {
	$active = db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false);
	if ($active === false || (int) $active !== 0) {
		throw new RuntimeException('transaction_unavailable');
	}
	for ($try = 0; $try < 3; $try++) {
		alarmmanager_db_write('START TRANSACTION');
		try {
			$result = $action();
			alarmmanager_db_write('COMMIT');
			return $result;
		} catch (Throwable $error) {
			db_execute_prepared('ROLLBACK', [], false);
			if (!$error instanceof \AlarmManager\TransientStorageException || $try === 2) { throw $error; }
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
