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
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/runtime.php');
require_once(__DIR__ . '/device.php');
require_once(__DIR__ . '/schema.php');

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
			|| (isset($envelope['mode']) && $envelope['mode'] !== 'centralized')
			|| !is_array($envelope['event'] ?? null)) {
			throw new InvalidArgumentException('Invalid event envelope.');
		}
		$event = alarmmanager_validate_event($envelope['event']);

		if (!alarmmanager_device_event_authorized($event)) {
			throw new RuntimeException('device_owner_invalid');
		}
		$payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (strlen($payload) > 32768) {
			throw new InvalidArgumentException('Event payload is too large.');
		}
		$hash      = hash('sha256', $payload);
		$poller_id = filter_var($config['poller_id'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

		if (!is_int($poller_id) || $poller_id < 1 || $poller_id > 2147483647) {
			throw new RuntimeException('Invalid processing owner.');
		}

		if ($event['collector_id'] !== $poller_id) {
			throw new InvalidArgumentException('Event belongs to another collector.');
		}
		// Never commit or roll back a producer-owned transaction.
		$transaction = db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false);

		if ($transaction === false || $transaction === null || (int) $transaction !== 0) {
			$envelope['status'] = 'failed';
			$envelope['error']  = 'transaction_unavailable';

			return $envelope;
		}

		// Repair runs only outside any producer-owned transaction: DDL would
		// implicitly commit one.
		if (!alarmmanager_schema_ensure()) {
			$envelope['status'] = 'failed';
			$envelope['error']  = 'storage_failure';

			return $envelope;
		}

		if (!db_execute_prepared('START TRANSACTION', [], false)) {
			throw new RuntimeException('Cannot start ingestion.');
		}
		$started   = true;
		$persisted = alarmmanager_persist_event($event, 'centralized', $poller_id, $envelope);

		if (!db_execute_prepared('COMMIT', [], false)) {
			throw new RuntimeException('Cannot confirm ingestion commit.');
		}
		$started                  = false;
		$envelope['status']       = $persisted['status'];
		$envelope['event_row_id'] = $persisted['event_row_id'];

		return $envelope;
	} catch (InvalidArgumentException | JsonException $error) {
		alarmmanager_log('event ingestion rejected: ' . $error->getMessage());
		$envelope['error'] = 'invalid_event';
	} catch (Throwable $error) {
		// Never expose SQL, payload contents, database diagnostics or credentials
		// in the envelope; the reason code alone goes to the Cacti log.
		alarmmanager_log('event ingestion failed: ' . $error->getMessage());
		$envelope['error'] ??= $error->getMessage() === 'event_identity_conflict' ? 'event_identity_conflict' : 'storage_failure';
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

/** Internal caller owns the transaction; the stored mode is retained for historical identity compatibility. */
function alarmmanager_persist_event(array $event, string $mode, int $poller_id, array $envelope = []): array {
	$payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	if (strlen($payload) > 32768) {
		throw new InvalidArgumentException('Event payload is too large.');
	}
	$hash  = hash('sha256', $payload);
	$token = bin2hex(random_bytes(16));
	$sql   = 'INSERT INTO plugin_alarmmanager_events
			(contract_version, event_id, source, source_instance, resource_id, alarm_key,
			episode_id, sequence, transition, severity, occurred_at, received_at,
			collector_id, processing_poller_id, mode, payload, payload_hash, ingestion_token, stream_key, device_id)
			VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE id = id';

	if (!db_execute_prepared($sql, [
		$event['event_id'], $event['source'], $event['source_instance'], $event['resource_id'],
		$event['alarm_key'], $event['episode_id'], $event['sequence'], $event['transition'],
		$event['severity'], str_replace(['T', 'Z'], [' ', ''], $event['occurred_at']),
		$event['collector_id'], $poller_id, $mode, $payload, $hash, $token, alarmmanager_stream_key($event, $mode), $event['device']['id']
	], false)) {
		throw new RuntimeException('Cannot persist event.');
	}
	// A locking current read sees the winner even under REPEATABLE READ.
	$row = db_fetch_row_prepared('SELECT id, payload_hash, ingestion_token FROM plugin_alarmmanager_events
			WHERE source = ? AND source_instance = ? AND event_id = ? AND mode = ? FOR UPDATE',
		[$event['source'], $event['source_instance'], $event['event_id'], $mode], false);

	if (!is_array($row) || !hash_equals($hash, (string) $row['payload_hash'])) {
		throw new RuntimeException('event_identity_conflict');
	}
	$id = (int) $row['id'];

	// A retry may find a job already completed; do not reopen or reschedule it.
	if (!db_execute_prepared('INSERT INTO plugin_alarmmanager_jobs (event_row_id, available_at, created_at, updated_at, priority)
			VALUES (?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?) ON DUPLICATE KEY UPDATE event_row_id = event_row_id', [$id, match ($event['severity']) {
		'critical' => 3, 'major' => 2, 'warning', 'minor' => 1, default => 0
	}], false)) {
		throw new RuntimeException('Cannot persist processing job.');
	}
	alarmmanager_accept_producer_receipt($envelope, $event, $id);

	return ['status' => hash_equals($token, (string) $row['ingestion_token']) ? 'accepted' : 'already_accepted', 'event_row_id' => $id];
}
/**
 * Called inside the ingestion transaction: acceptance and producer ownership
 * commit together.
 *
 * @param array<string, mixed> $envelope
 * @param array<string, mixed> $event
 */
function alarmmanager_accept_producer_receipt(array $envelope, array $event, int $id): void {
	if (!isset($envelope['producer_receipt'])) {
		return;
	}
	$receipt = $envelope['producer_receipt'];

	if (!is_string($receipt) || !preg_match('/\A[a-f0-9]{64}\z/D', $receipt)) {
		throw new InvalidArgumentException('invalid_producer_receipt');
	}
	$row = alarmmanager_db_row('SELECT status, stream_key, envelope FROM plugin_alarmmanager_producer_receipts WHERE receipt = ? FOR UPDATE', [$receipt]);

	if (!$row || !in_array($row['status'], ['pending', 'accepted', 'observed'], true)) {
		throw new RuntimeException('producer_receipt_unavailable');
	}
	$saved = json_decode($row['envelope'], true, 32, JSON_THROW_ON_ERROR);

	if (($saved['mode'] ?? 'centralized') !== 'centralized' || alarmmanager_validate_event($saved['event']) !== $event) {
		throw new InvalidArgumentException('producer_receipt_conflict');
	}
	alarmmanager_db_write('UPDATE plugin_alarmmanager_producer_receipts SET status = ?, event_row_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE receipt = ?', ['accepted', $id, $receipt]);
	// Duplicate receipts cannot rewind newer source ordering.
	alarmmanager_db_write('UPDATE plugin_alarmmanager_producer_streams SET active = ?, episode_id = ?, severity = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ? AND sequence = ?', [$event['transition'] === 'recovered' || $event['transition'] === 'occurrence' ? 0 : 1, $event['episode_id'], $event['severity'], $row['stream_key'], $event['sequence']]);
}
