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
require_once(__DIR__ . '/runtime.php');

/** Read fresh on every claim so long-running workers observe maintenance changes. */
function alarmmanager_notifications_paused(): bool {
	$row = alarmmanager_db_row("SELECT value FROM settings WHERE name = 'alarmmanager_notifications_paused'");

	return ($row['value'] ?? '0') === '1';
}

/**
 * Purge alert history older than the configured retention, one bounded batch per call.
 * Events with active, queued, leased or failed work are kept, as are events backing a current condition.
 * @return int Number of events removed; 0 when retention is indefinite or nothing has aged out.
 */
function alarmmanager_history_purge(int $limit = 1000): int {
	$retention = alarmmanager_retention();

	if ($retention['unit'] === 'forever') {
		return 0;
	}
	$interval = (int) $retention['value'] . ' ' . match ($retention['unit']) {
		'days' => 'DAY', 'weeks' => 'WEEK', 'months' => 'MONTH'
	};
	$cutoff = "DATE_SUB(UTC_TIMESTAMP(6), INTERVAL $interval)";
	$limit  = max(1, min(10000, $limit));

	return alarmmanager_transaction(function () use ($cutoff, $limit): int {
		alarmmanager_db_write("DELETE FROM plugin_alarmmanager_states WHERE active = 0 AND updated_at < $cutoff LIMIT $limit");
		alarmmanager_db_write("DELETE FROM plugin_alarmmanager_episodes WHERE status <> 'active' AND ended_at IS NOT NULL AND ended_at < $cutoff LIMIT $limit");
		alarmmanager_db_write("DELETE FROM plugin_alarmmanager_remote_history WHERE received_at < $cutoff LIMIT $limit");
		$ids = array_map('intval', array_column(alarmmanager_db_rows("SELECT e.id FROM plugin_alarmmanager_events AS e
			WHERE e.received_at < $cutoff
				AND NOT EXISTS (SELECT 1 FROM plugin_alarmmanager_jobs AS j WHERE j.event_row_id = e.id AND j.status IN ('pending', 'leased', 'failed'))
				AND NOT EXISTS (SELECT 1 FROM plugin_alarmmanager_deliveries AS d WHERE d.event_row_id = e.id AND d.status IN ('pending', 'leased', 'failed'))
				AND NOT EXISTS (SELECT 1 FROM plugin_alarmmanager_states AS s WHERE s.last_event_id = e.id)
			ORDER BY e.id LIMIT $limit"), 'id'));

		if (!$ids) {
			return 0;
		}
		$in = implode(',', $ids);
		alarmmanager_db_write("DELETE a FROM plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id WHERE d.event_row_id IN ($in)");
		alarmmanager_db_write("DELETE f FROM plugin_alarmmanager_failed_jobs AS f INNER JOIN plugin_alarmmanager_jobs AS j ON f.queue = 'processing' AND f.job_id = j.id WHERE j.event_row_id IN ($in)");
		alarmmanager_db_write("DELETE f FROM plugin_alarmmanager_failed_jobs AS f INNER JOIN plugin_alarmmanager_deliveries AS d ON f.queue = 'delivery' AND f.job_id = d.id WHERE d.event_row_id IN ($in)");

		foreach (['deliveries', 'decisions', 'jobs', 'device_receipts', 'producer_receipts'] as $table) {
			alarmmanager_db_write("DELETE FROM plugin_alarmmanager_$table WHERE event_row_id IN ($in)");
		}
		alarmmanager_db_write("DELETE FROM plugin_alarmmanager_events WHERE id IN ($in)");

		return count($ids);
	});
}

function alarmmanager_queue_table(string $queue): string {
	return match ($queue) {
		'processing' => 'plugin_alarmmanager_jobs', 'delivery' => 'plugin_alarmmanager_deliveries', default => throw new InvalidArgumentException('invalid_queue')
	};
}
function alarmmanager_failed_job(string $queue, int $id, string $code): void {
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_failed_jobs (queue, job_id, code, failed_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE code = VALUES(code), failed_at = VALUES(failed_at)', [$queue, $id, $code]);
}
function alarmmanager_reclaim(string $queue, int $limit = 100): int {
	$table = alarmmanager_queue_table($queue);
	$limit = max(1, min(100, $limit));

	return alarmmanager_transaction(function () use ($queue, $table, $limit): int {
		$max  = $queue === 'delivery' ? 'COALESCE(ep.max_attempts, 5)' : '5';
		$join = $queue === 'delivery' ? 'LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = j.endpoint_id' : '';
		$rows = alarmmanager_db_rows("SELECT j.id, j.status, j.attempts, j.lease_token, $max AS max_attempts FROM $table AS j $join
			WHERE (j.status = 'leased' AND j.lease_until <= UTC_TIMESTAMP(6)) OR (j.status = 'pending' AND j.attempts >= $max) ORDER BY j.lease_until, j.id LIMIT $limit FOR UPDATE SKIP LOCKED");

		foreach ($rows as $row) {
			$terminal = (int) $row['attempts'] >= (int) $row['max_attempts'];
			$code     = $row['status'] === 'leased' ? 'lease_expired' : 'attempts_exhausted';
			alarmmanager_db_write("UPDATE $table SET status = ?, lease_token = NULL, lease_until = NULL, last_error = ?, available_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?",
				[$terminal ? 'failed' : 'pending', $code, $row['id']]);

			if ($queue === 'delivery' && $row['status'] === 'leased') {
				alarmmanager_db_write("UPDATE plugin_alarmmanager_attempts SET outcome = 'abandoned', code = 'lease_expired', ended_at = UTC_TIMESTAMP(6) WHERE delivery_id = ? AND lease_token = ? AND outcome = 'in_progress'", [$row['id'], $row['lease_token']]);
			}

			if ($terminal) {
				alarmmanager_failed_job($queue, (int) $row['id'], $code);
			}
		}

		return count($rows);
	});
}
function alarmmanager_claim_processing(int $lease_seconds = 180): ?array {
	if (!alarmmanager_runtime_enabled()) {
		return null;
	}
	$lease_seconds = max(1, min(600, $lease_seconds));

	return alarmmanager_transaction(function () use ($lease_seconds): ?array {
		$job = alarmmanager_db_row("SELECT j.id, j.event_row_id, j.attempts FROM plugin_alarmmanager_jobs AS j
			INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id
			WHERE j.status = 'pending' AND j.available_at <= UTC_TIMESTAMP(6) AND e.processing_poller_id = ?
			AND NOT EXISTS (SELECT 1 FROM plugin_alarmmanager_jobs AS prior
				INNER JOIN plugin_alarmmanager_events AS pe ON pe.id = prior.event_row_id
				WHERE pe.stream_key = e.stream_key AND prior.status IN ('pending', 'leased', 'failed')
				AND (pe.sequence < e.sequence OR (pe.sequence = e.sequence AND prior.id < j.id)))
			ORDER BY (j.priority + FLOOR(TIMESTAMPDIFF(SECOND, j.created_at, UTC_TIMESTAMP()) / 30)) DESC, j.id
			LIMIT 1 FOR UPDATE SKIP LOCKED", [alarmmanager_local_poller()]);

		if (!$job) {
			return null;
		}
		$token = bin2hex(random_bytes(16));
		alarmmanager_db_write("UPDATE plugin_alarmmanager_jobs SET status = 'leased', attempts = attempts + 1, lease_token = ?, lease_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'pending'", [$token, $lease_seconds, $job['id']]);

		if (!alarmmanager_db_row("SELECT id FROM plugin_alarmmanager_jobs WHERE id = ? AND status = 'leased' AND lease_token = ?", [$job['id'], $token])) {
			return null;
		}

		return ['id' => (int) $job['id'], 'event_row_id' => (int) $job['event_row_id'], 'lease_token' => $token, 'attempts' => (int) $job['attempts'] + 1];
	});
}
function alarmmanager_claim_delivery(array $supported_types, int $lease_seconds = 180): ?array {
	$types = array_values(array_intersect(['restapi', 'webhook', 'email', 'script', 'snmptrap', 'syslog', 'websocket'], $supported_types));

	if (!$types || !alarmmanager_runtime_enabled() || alarmmanager_notifications_paused()) {
		return null;
	}
	$lease_seconds = max(1, min(600, $lease_seconds));

	return alarmmanager_transaction(function () use ($types, $lease_seconds): ?array {
		$slots    = implode(',', array_fill(0, count($types), '?'));
		$endpoint = alarmmanager_db_row("SELECT ep.id, ep.name, ep.type, ep.max_concurrency, ep.timeout_seconds, ep.min_interval_ms, ep.max_attempts
			FROM plugin_alarmmanager_endpoints AS ep WHERE ep.enabled = 1 AND ep.type IN ($slots) AND ep.next_start_at <= UTC_TIMESTAMP(6)
			AND (SELECT COUNT(*) FROM plugin_alarmmanager_deliveries AS active WHERE active.endpoint_id = ep.id AND active.status = 'leased' AND active.lease_until > UTC_TIMESTAMP(6)) < ep.max_concurrency
			AND EXISTS (SELECT 1 FROM plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
				WHERE d.endpoint_id = ep.id AND d.status = 'pending' AND d.attempts < ep.max_attempts AND d.available_at <= UTC_TIMESTAMP(6) AND e.processing_poller_id = ?
				AND NOT EXISTS (SELECT 1 FROM plugin_alarmmanager_deliveries AS prior WHERE prior.endpoint_id = d.endpoint_id AND prior.stream_key = d.stream_key AND prior.recipient = d.recipient
					AND prior.status IN ('pending', 'leased') AND (prior.sequence < d.sequence OR (prior.sequence = d.sequence AND prior.id < d.id))))
			ORDER BY ep.last_claim_at, ep.id LIMIT 1 FOR UPDATE SKIP LOCKED", [...$types, alarmmanager_local_poller()]);

		if (!$endpoint) {
			return null;
		}
		$job = alarmmanager_db_row("SELECT d.id, d.event_row_id, d.attempts, d.idempotency_key, d.recipient FROM plugin_alarmmanager_deliveries AS d
			INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
			WHERE d.endpoint_id = ? AND d.status = 'pending' AND d.attempts < ? AND d.available_at <= UTC_TIMESTAMP(6) AND e.processing_poller_id = ?
			AND NOT EXISTS (SELECT 1 FROM plugin_alarmmanager_deliveries AS prior WHERE prior.endpoint_id = d.endpoint_id AND prior.stream_key = d.stream_key AND prior.recipient = d.recipient
				AND prior.status IN ('pending', 'leased') AND (prior.sequence < d.sequence OR (prior.sequence = d.sequence AND prior.id < d.id)))
			ORDER BY (d.priority + FLOOR(TIMESTAMPDIFF(SECOND, d.created_at, UTC_TIMESTAMP()) / 30)) DESC, d.id LIMIT 1 FOR UPDATE SKIP LOCKED", [$endpoint['id'], (int) $endpoint['max_attempts'], alarmmanager_local_poller()]);

		if (!$job) {
			return null;
		}
		$token = bin2hex(random_bytes(16));
		$lease = max($lease_seconds, (int) $endpoint['timeout_seconds'] + 15);
		alarmmanager_db_write("UPDATE plugin_alarmmanager_deliveries SET status = 'leased', attempts = attempts + 1, lease_token = ?, lease_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'pending'", [$token, $lease, $job['id']]);
		alarmmanager_db_write('UPDATE plugin_alarmmanager_endpoints SET last_claim_at = UTC_TIMESTAMP(6), next_start_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? MICROSECOND) WHERE id = ?', [(int) $endpoint['min_interval_ms'] * 1000, $endpoint['id']]);
		$next_attempt = alarmmanager_db_row('SELECT COALESCE(MAX(attempt_number), 0) + 1 AS number FROM plugin_alarmmanager_attempts WHERE delivery_id = ?', [$job['id']]);
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_attempts (delivery_id, attempt_number, lease_token, started_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [$job['id'], (int) $next_attempt['number'], $token]);

		return ['id' => (int) $job['id'], 'event_row_id' => (int) $job['event_row_id'], 'lease_token' => $token,
			'recipient' => $job['recipient'] ?? '', 'attempts'  => (int) $job['attempts'] + 1, 'idempotency_key' => $job['idempotency_key'], 'endpoint' => $endpoint];
	});
}
function alarmmanager_retry_delay(int $attempt): int {
	return min(3600, 2 ** min(10, max(1, $attempt))) + random_int(0, 3);
}
function alarmmanager_finish_delivery(array $claim, array $result): bool {
	require_once(__DIR__ . '/integrations.php');
	$outcome = $result['outcome'] ?? 'retry';
	$code    = $result['code'] ?? 'transport_error';

	if (!in_array($outcome, ['success', 'retry', 'failure'], true) || !is_string($code) || !preg_match('/\A[a-z0-9_]{1,32}\z/D', $code)) {
		$outcome = 'retry';
		$code    = 'invalid_transport_result';
	}
	$reference   = $outcome === 'success' && isset($result['external_reference']) ? json_encode(alarmmanager_external_reference_validate($result['external_reference']), JSON_THROW_ON_ERROR) : null;
	$retry_after = $result['retry_after'] ?? 0;
	$retry_after = is_int($retry_after) ? max(0, min(3600, $retry_after)) : 0;
	$duration    = $result['duration_ms'] ?? 0;
	$duration    = is_int($duration) ? max(0, $duration) : 0;

	return alarmmanager_transaction(function () use ($claim, $outcome, $code, $retry_after, $duration, $reference): bool {
		$job = alarmmanager_db_row("SELECT id, attempts FROM plugin_alarmmanager_deliveries WHERE id = ? AND status = 'leased' AND lease_token = ? AND lease_until > UTC_TIMESTAMP(6) FOR UPDATE", [$claim['id'], $claim['lease_token']]);

		if (!$job) {
			return false;
		}
		$terminal = $outcome === 'failure' || ($outcome !== 'success' && (int) $job['attempts'] >= (int) $claim['endpoint']['max_attempts']);
		$status   = $outcome === 'success' ? 'completed' : ($terminal ? 'failed' : 'pending');
		$delay    = $status  === 'pending' ? max($retry_after, alarmmanager_retry_delay((int) $job['attempts'])) : 0;
		alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET status = ?, last_error = ?, external_reference = ?, available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), lease_token = NULL, lease_until = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND lease_token = ?', [$status, $outcome === 'success' ? '' : $code, $reference, $delay, $job['id'], $claim['lease_token']]);
		alarmmanager_db_write('UPDATE plugin_alarmmanager_attempts SET outcome = ?, code = ?, ended_at = UTC_TIMESTAMP(6), duration_ms = ? WHERE delivery_id = ? AND lease_token = ?', [$outcome, $code, $duration, $job['id'], $claim['lease_token']]);

		if ($terminal) {
			alarmmanager_failed_job('delivery', (int) $job['id'], $code);
		}

		return true;
	});
}
function alarmmanager_fail_processing(array $claim, string $code = 'processing_error'): bool {
	if (!preg_match('/\A[a-z0-9_]{1,32}\z/D', $code)) {
		$code = 'processing_error';
	}

	return alarmmanager_transaction(function () use ($claim, $code): bool {
		$job = alarmmanager_db_row("SELECT id, attempts FROM plugin_alarmmanager_jobs WHERE id = ? AND status = 'leased' AND lease_token = ? AND lease_until > UTC_TIMESTAMP(6) FOR UPDATE", [$claim['id'], $claim['lease_token']]);

		if (!$job) {
			return false;
		}
		$terminal = (int) $job['attempts'] >= 5;
		alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET status = ?, last_error = ?, available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), lease_token = NULL, lease_until = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND lease_token = ?', [$terminal ? 'failed' : 'pending', $code, $terminal ? 0 : alarmmanager_retry_delay((int) $job['attempts']), $job['id'], $claim['lease_token']]);

		if ($terminal) {
			alarmmanager_failed_job('processing', (int) $job['id'], $code);
		}

		return true;
	});
}
function alarmmanager_heartbeat(string $worker, string $role, string $status, ?int $job_id = null): void {
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_workers (worker_id, role, poller_id, pid, status, current_job_id, heartbeat_at)
		VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status = VALUES(status), current_job_id = VALUES(current_job_id), heartbeat_at = VALUES(heartbeat_at)', [$worker, $role, alarmmanager_local_poller(), getmypid(), $status, $job_id]);
}
