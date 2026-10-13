<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/runtime.php');

/** Functional hook: preserve the caller's envelope and explicitly signal ownership. */
function alarmmanager_submit($envelope) {
	global $config;
	if (!is_array($envelope)) {
		return $envelope;
	}
	$envelope['status'] = 'unhandled';
	unset($envelope['error'], $envelope['event_row_id']);
	$started = false;
	try {
		if (!empty($config['DEBUG_SQL_FLOW'])) {
			throw new RuntimeException('SQL flow tracing is incompatible with ingestion.');
		}
		$plugin = db_fetch_assoc_prepared('SELECT status FROM plugin_config WHERE directory = ?', ['alarmmanager'], false);
		if ($plugin === false) {
			throw new RuntimeException('Cannot read plugin availability.');
		}
		if (!$plugin || (int) $plugin[0]['status'] !== 1) {
			return $envelope;
		}
		if (($envelope['contract_version'] ?? null) !== 1
			|| !in_array($envelope['mode'] ?? null, ['observe', 'centralized'], true)
			|| !is_array($envelope['event'] ?? null)) {
			throw new InvalidArgumentException('Invalid event envelope.');
		}
		$event = alarmmanager_validate_event($envelope['event']);
		$payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (strlen($payload) > 32768) {
			throw new InvalidArgumentException('Event payload is too large.');
		}
		$hash = hash('sha256', $payload);
		$poller_id = filter_var($config['poller_id'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
		if (!is_int($poller_id) || $poller_id < 1 || $poller_id > 2147483647) {
			throw new RuntimeException('Invalid processing owner.');
		}
		// Never commit or roll back a producer-owned transaction.
		$transaction = db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false);
		if ($transaction === false || $transaction === null || (int) $transaction !== 0) {
			$envelope['status'] = 'failed';
			$envelope['error'] = 'transaction_unavailable';
			return $envelope;
		}
		if (!db_execute_prepared('START TRANSACTION', [], false)) {
			throw new RuntimeException('Cannot start ingestion.');
		}
		$started = true;
		$token = bin2hex(random_bytes(16));
		$sql = 'INSERT INTO plugin_alarmmanager_events
			(contract_version, event_id, source, source_instance, resource_id, alarm_key,
			episode_id, sequence, transition, severity, occurred_at, received_at,
			collector_id, processing_poller_id, mode, payload, payload_hash, ingestion_token, stream_key)
			VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE id = id';
		if (!db_execute_prepared($sql, [
			$event['event_id'], $event['source'], $event['source_instance'], $event['resource_id'],
			$event['alarm_key'], $event['episode_id'], $event['sequence'], $event['transition'],
			$event['severity'], str_replace(['T', 'Z'], [' ', ''], $event['occurred_at']),
			$event['collector_id'], $poller_id, $envelope['mode'], $payload, $hash, $token, alarmmanager_stream_key($event, $envelope['mode'])
		], false)) {
			throw new RuntimeException('Cannot persist event.');
		}
		// A locking current read sees the winner even under REPEATABLE READ.
		$row = db_fetch_row_prepared('SELECT id, payload_hash, ingestion_token FROM plugin_alarmmanager_events
			WHERE source = ? AND source_instance = ? AND event_id = ? AND mode = ? FOR UPDATE',
			[$event['source'], $event['source_instance'], $event['event_id'], $envelope['mode']], false);
		if (!is_array($row) || !hash_equals($hash, (string) $row['payload_hash'])) {
			$envelope['error'] = 'event_identity_conflict';
			throw new RuntimeException('Conflicting event identity.');
		}
		$id = (int) $row['id'];
		// A retry may find a job already completed; do not reopen or reschedule it.
		if (!db_execute_prepared('INSERT INTO plugin_alarmmanager_jobs (event_row_id, available_at, created_at, updated_at, priority)
			VALUES (?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?) ON DUPLICATE KEY UPDATE event_row_id = event_row_id', [$id, match ($event['severity']) { 'critical' => 3, 'major' => 2, 'warning', 'minor' => 1, default => 0 }], false)) {
			throw new RuntimeException('Cannot persist processing job.');
		}
		if (!db_execute_prepared('COMMIT', [], false)) {
			throw new RuntimeException('Cannot confirm ingestion commit.');
		}
		$started = false;
		$envelope['status'] = hash_equals($token, (string) $row['ingestion_token']) ? 'accepted' : 'already_accepted';
		$envelope['event_row_id'] = $id;
		return $envelope;
	} catch (InvalidArgumentException | JsonException $error) {
		$envelope['error'] = 'invalid_event';
	} catch (Throwable $error) {
		// Never expose SQL, payload contents, database diagnostics or credentials.
		$envelope['error'] ??= 'storage_failure';
	} finally {
		if ($started) {
			try {
				db_execute_prepared('ROLLBACK', [], false);
			} catch (Throwable $rollback_error) {
				// The failed result remains failed; the next call refuses active transactions.
			}
		}
	}
	$envelope['status'] = 'failed';
	return $envelope;
}
