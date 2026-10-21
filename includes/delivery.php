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
require_once(__DIR__ . '/queue.php');
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/transports.php');
require_once(__DIR__ . '/schema.php');

/** Trusted code registry only; configuration cannot name/evaluate PHP callbacks. */
function alarmmanager_delivery_handlers(): array {
	$handlers = ($GLOBALS['alarmmanager_transport_handlers'] ?? []) + ['restapi' => 'alarmmanager_http_transport', 'webhook' => 'alarmmanager_http_transport', 'email' => 'alarmmanager_email_transport', 'script' => 'alarmmanager_script_transport'];
	$result   = [];

	foreach (['restapi', 'webhook', 'email', 'script', 'websocket'] as $type) {
		if (isset($handlers[$type]) && is_callable($handlers[$type])) {
			$result[$type] = $handlers[$type];
		}
	}

	return $result;
}
function alarmmanager_run_delivery(array $claim): bool {
	$handlers = alarmmanager_delivery_handlers();
	$handler  = $handlers[$claim['endpoint']['type']] ?? null;

	if (!$handler) {
		return alarmmanager_finish_delivery($claim, ['outcome' => 'retry', 'code' => 'adapter_unavailable']);
	}

	// A delayed/stale worker must not begin a new external effect with an expired lease.
	if (!alarmmanager_db_row("SELECT id FROM plugin_alarmmanager_deliveries WHERE id = ? AND status = 'leased' AND lease_token = ? AND lease_until > DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)", [$claim['id'], $claim['lease_token'], (int) $claim['endpoint']['timeout_seconds']])) {
		return false;
	}
	$started          = hrtime(true);
	$previous_handler = null;
	$previous_async   = null;
	$armed            = false;

	try {
		$row = alarmmanager_db_row('SELECT payload FROM plugin_alarmmanager_events WHERE id = ?', [$claim['event_row_id']]);

		if (!$row || !function_exists('pcntl_alarm')) {
			throw new RuntimeException('transport_unavailable');
		}
		$event = alarmmanager_validate_event(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR));
		require_once(__DIR__ . '/device.php');

		if (!alarmmanager_device_event_authorized($event, (int) $claim['endpoint']['timeout_seconds'] + 5)) {
			return alarmmanager_finish_delivery($claim, ['outcome' => 'failure', 'code' => 'device_owner_fenced']);
		}
		// An explicitly confirmed integration test is independent of alarm routing rules.
		$allowed = (bool) alarmmanager_db_row("SELECT id FROM plugin_alarmmanager_decisions WHERE event_row_id = ? AND endpoint_id = ? AND reason = 'explicit_test_send' LIMIT 1", [$claim['event_row_id'], $claim['endpoint']['id']]);

		if (!$allowed) {
			foreach (alarmmanager_routing_snapshot()['rules'] as $rule) {
				if (in_array((int) $claim['endpoint']['id'], $rule['endpoints'], true) && alarmmanager_rule_matches($rule['policy'], $event)) {
					$allowed = true;

					break;
				}
			}
		}

		if (!$allowed) {
			return alarmmanager_finish_delivery($claim, ['outcome' => 'failure', 'code' => 'no_matching_rule']);
		}
		$previous_handler = pcntl_signal_get_handler(SIGALRM);
		$previous_async   = pcntl_async_signals(true);
		pcntl_signal(SIGALRM, function (): void { throw new RuntimeException('transport_timeout'); });
		pcntl_alarm((int) $claim['endpoint']['timeout_seconds']);
		$armed  = true;
		$result = $handler($event, $claim['endpoint'], $claim['idempotency_key']);

		if (!is_array($result)) {
			$result = ['outcome' => 'retry', 'code' => 'invalid_transport_result'];
		}
	} catch (InvalidArgumentException | JsonException $error) {
		alarmmanager_log('delivery transport configuration invalid for job ' . $claim['id'] . ': ' . $error->getMessage());
		$result = ['outcome' => 'failure', 'code' => 'transport_config_invalid'];
	} catch (Throwable $error) {
		$result = ['outcome' => 'retry', 'code' => $error instanceof RuntimeException && $error->getMessage() === 'transport_timeout' ? 'transport_timeout' : 'transport_exception'];

		if (str_starts_with($error->getMessage(), 'credential') || str_contains($error->getMessage(), 'credential key')) {
			$result = ['outcome' => 'failure', 'code' => 'credential_unavailable'];
		}
		alarmmanager_log('delivery job ' . $claim['id'] . ' transport attempt failed: ' . $result['code']);
	} finally {
		if ($armed) {
			pcntl_alarm(0);
			pcntl_signal(SIGALRM, $previous_handler);
			pcntl_async_signals($previous_async);
		}
	}
	$result['duration_ms'] = (int) ((hrtime(true) - $started) / 1000000);

	return alarmmanager_finish_delivery($claim, $result);
}

/** Administrator CLI only. Test requests become ordinary worker-owned deliveries. */
function alarmmanager_enqueue_test(array $sample, int $endpoint_id): int {
	if (!alarmmanager_runtime_enabled() || !alarmmanager_schema_ready()) {
		throw new RuntimeException('test_send_unavailable');
	}
	$token                     = bin2hex(random_bytes(16));
	$poller                    = alarmmanager_local_poller();
	$sample['event_id']        = 'test:' . $token;
	$sample['source']          = 'alarmmanager-test';
	$sample['source_instance'] = 'poller:' . $poller;
	$sample['alarm_key']       = 'test:' . $token;
	$sample['episode_id']      = 'test:' . $token;
	$sample['sequence']        = 1;
	$sample['collector_id']    = $poller;
	$event                     = alarmmanager_validate_event($sample);
	$payload                   = json_encode($event, JSON_THROW_ON_ERROR);
	$stream                    = alarmmanager_stream_key($event, 'centralized');

	return alarmmanager_transaction(function () use ($endpoint_id, $event, $payload, $stream, $token, $poller): int {
		$endpoint = alarmmanager_db_row('SELECT id, type FROM plugin_alarmmanager_endpoints WHERE id = ? AND enabled = 1 FOR UPDATE', [$endpoint_id]);

		if (!$endpoint) {
			throw new InvalidArgumentException('test_endpoint_unavailable');
		}
		$settings = alarmmanager_endpoint_transport($endpoint);
		alarmmanager_map_payload($settings['mapping'], $event);
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_events (contract_version, event_id, source, source_instance, resource_id, alarm_key, episode_id, sequence, transition, severity, occurred_at, received_at, collector_id, processing_poller_id, mode, payload, payload_hash, ingestion_token, stream_key, device_id) VALUES (1, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?, ?)',
			[$event['event_id'], $event['source'], $event['source_instance'], $event['resource_id'], $event['alarm_key'], $event['episode_id'], $event['transition'], $event['severity'], str_replace(['T', 'Z'], [' ', ''], $event['occurred_at']), $poller, $poller, 'centralized', $payload, hash('sha256', $payload), $token, $stream, $event['device']['id']]);
		$row = alarmmanager_db_row('SELECT id FROM plugin_alarmmanager_events WHERE source = ? AND source_instance = ? AND event_id = ? AND mode = ? FOR UPDATE', [$event['source'], $event['source_instance'], $event['event_id'], 'centralized']);
		$id  = (int) $row['id'];
		alarmmanager_db_write("INSERT INTO plugin_alarmmanager_jobs (event_row_id, status, available_at, created_at, updated_at) VALUES (?, 'completed', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$id]);
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_deliveries (event_row_id, endpoint_id, stream_key, sequence, priority, available_at, created_at, updated_at, idempotency_key) VALUES (?, ?, ?, 1, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)', [$id, $endpoint_id, $stream, hash('sha256', 'test|' . $token . '|' . $endpoint_id)]);
		alarmmanager_db_write("INSERT INTO plugin_alarmmanager_decisions (event_row_id, rule_id, endpoint_id, reason, config_revision, created_at) SELECT ?, 0, ?, 'explicit_test_send', config_revision, UTC_TIMESTAMP(6) FROM plugin_alarmmanager_schema WHERE id = 1", [$id, $endpoint_id]);

		return $id;
	});
}
