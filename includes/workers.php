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
*/
require_once(__DIR__ . '/processor.php');
require_once(__DIR__ . '/delivery.php');

function alarmmanager_worker_step(string $role, string $worker_id, int $lease_seconds = 180): bool {
	$claim = $role === 'processing' ? alarmmanager_claim_processing($lease_seconds)
		: alarmmanager_claim_delivery(array_keys(alarmmanager_delivery_handlers()), $lease_seconds);

	if (!$claim) {
		return false;
	}
	alarmmanager_heartbeat($worker_id, $role, 'busy', $claim['id']);

	try {
		if ($role === 'processing') {
			$previous_handler = pcntl_signal_get_handler(SIGALRM);
			$previous_async   = pcntl_async_signals(true);
			pcntl_signal(SIGALRM, function (): void { throw new RuntimeException('processing_timeout'); });
			pcntl_alarm(max(1, $lease_seconds - 5));

			try {
				alarmmanager_process_job($claim);
			} finally {
				pcntl_alarm(0);
				pcntl_signal(SIGALRM, $previous_handler);
				pcntl_async_signals($previous_async);
			}
		} else {
			alarmmanager_run_delivery($claim);
		}
	} catch (Throwable $error) {
		alarmmanager_log('worker ' . $worker_id . ' claim ' . $role . ' failed: ' . $error->getMessage());

		if ($role === 'processing') {
			alarmmanager_fail_processing($claim, 'processing_error');
		} else {
			alarmmanager_finish_delivery($claim, ['outcome' => 'retry', 'code' => 'delivery_error']);
		}
	}
	alarmmanager_heartbeat($worker_id, $role, 'idle');

	return true;
}
function alarmmanager_worker_loop(string $role, array $options, callable $should_stop): int {
	if (!in_array($role, ['processing', 'delivery'], true)) {
		throw new InvalidArgumentException('invalid_worker_role');
	}
	$worker_id      = bin2hex(random_bytes(16));
	$started        = microtime(true);
	$last_reclaim   = 0.0;
	$last_heartbeat = 0.0;
	$count          = 0;

	while (!$should_stop() && $count < $options['max_jobs'] && microtime(true) - $started < $options['max_seconds']) {
		$enabled = alarmmanager_runtime_enabled();

		if (microtime(true) - $last_heartbeat >= 1) {
			alarmmanager_heartbeat($worker_id, $role, $enabled ? 'idle' : 'paused');
			$last_heartbeat = microtime(true);
		}

		if ($enabled) {
			if (microtime(true) - $last_reclaim >= 1) {
				alarmmanager_reclaim($role);
				$last_reclaim = microtime(true);
			}

			if (alarmmanager_worker_step($role, $worker_id, $options['lease_seconds'])) {
				$count++;

				continue;
			}
		}

		if ($options['once']) {
			break;
		}
		usleep($options['idle_ms'] * 1000);
	}
	alarmmanager_heartbeat($worker_id, $role, 'stopped');

	return $count;
}
function alarmmanager_metrics(): array {
	$result = ['poller_id' => alarmmanager_local_poller(), 'queues' => [], 'workers' => [], 'throughput_window_seconds' => 300];

	foreach (['processing', 'delivery'] as $queue) {
		$table                    = alarmmanager_queue_table($queue);
		$result['queues'][$queue] = alarmmanager_db_rows("SELECT status, COUNT(*) AS jobs, COALESCE(MAX(TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP())), 0) AS oldest_age_seconds,
			COALESCE(SUM(GREATEST(CAST(attempts AS SIGNED) - 1, 0)), 0) AS retries FROM $table GROUP BY status");
		$result['completed_last_300_seconds'][$queue] = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM $table WHERE status = 'completed' AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 300 SECOND)", [], '', false);
	}
	$result['processing_blocked_by_failed_predecessor'] = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_jobs AS j INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id
		WHERE j.status = 'pending' AND EXISTS (SELECT 1 FROM plugin_alarmmanager_jobs AS prior INNER JOIN plugin_alarmmanager_events AS pe ON pe.id = prior.event_row_id
			WHERE pe.stream_key = e.stream_key AND prior.status = 'failed' AND (pe.sequence < e.sequence OR (pe.sequence = e.sequence AND prior.id < j.id)))", [], '', false);
	$result['workers'] = alarmmanager_db_rows('SELECT worker_id, role, poller_id, pid, status, current_job_id, TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP()) AS heartbeat_age_seconds
		FROM plugin_alarmmanager_workers WHERE poller_id = ? ORDER BY heartbeat_at DESC LIMIT 128', [alarmmanager_local_poller()]);
	$result['blocked_destination_types'] = alarmmanager_db_rows("SELECT ep.type, COUNT(*) AS pending_jobs FROM plugin_alarmmanager_deliveries AS d
		INNER JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id WHERE d.status = 'pending' GROUP BY ep.type");
	$supported                           = array_keys(alarmmanager_delivery_handlers());
	$result['blocked_destination_types'] = array_values(array_filter($result['blocked_destination_types'], static fn ($row) => !in_array($row['type'], $supported, true)));

	return $result;
}
