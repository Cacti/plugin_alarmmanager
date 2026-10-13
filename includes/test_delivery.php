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
require_once(__DIR__ . '/transports.php');
require_once(__DIR__ . '/schema.php');

/** Administrator CLI only. Test requests become ordinary worker-owned deliveries. */
function alarmmanager_enqueue_test(array $sample, int $endpoint_id, ?int $audit_user = null): int {
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

	return alarmmanager_transaction(function () use ($endpoint_id, $event, $payload, $stream, $token, $poller, $audit_user): int {
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

		if ($audit_user !== null) {
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_audit (user_id, action, resource_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [$audit_user, 'test_send', $id]);
		}

		return $id;
	});
}
