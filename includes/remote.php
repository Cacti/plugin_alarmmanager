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
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/device.php');
require_once(__DIR__ . '/routing.php');
require_once(__DIR__ . '/credentials.php');
require_once(__DIR__ . '/../integrations/producer_bridge.php');
const ALARMMANAGER_SYNC_LIMIT = 1048576;
function alarmmanager_node_uuid(?PDO $connection = null): string {
	$id = db_fetch_cell_prepared('SELECT instance_uuid FROM plugin_alarmmanager_node WHERE id = 1', [], '', false, $connection);

	if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
		throw new RuntimeException('node_identity_unavailable');
	}

	return $id;
}
function alarmmanager_remote_private_read(string $path, int $limit): string {
	$stat = @lstat($path);

	if (!$stat || is_link($path) || !is_file($path) || ($stat['mode'] & 0077) || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
		throw new RuntimeException('private_file_required');
	}
	$data = @file_get_contents($path, false, null, 0, $limit + 1);

	if ($data === false || strlen($data) > $limit) {
		throw new RuntimeException('private_input_invalid');
	}

	return $data;
}
function alarmmanager_remote_private_write(string $path, string $data): void {
	if (!str_starts_with($path, '/') || !realpath(dirname($path)) || str_starts_with(realpath(dirname($path)) . '/', realpath(__DIR__ . '/..') . '/')) {
		throw new RuntimeException('private_output_outside_plugin_required');
	}
	$previous = umask(0077);
	$stream   = @fopen($path, 'xb');
	umask($previous);

	if (!$stream) {
		throw new RuntimeException('private_output_invalid');
	}

	try {
		if (fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !fsync($stream)) {
			throw new RuntimeException('private_output_invalid');
		}
	} finally {
		fclose($stream);
	}
}
function alarmmanager_sync_normalize(array $data): array {
	if (isset($data['external_reference'])) {
		require_once(__DIR__ . '/integrations.php');
		$data['external_reference'] = json_encode(alarmmanager_external_reference_validate(json_decode($data['external_reference'], true, 8, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
	}

	foreach (['id', 'event_row_id', 'rule_id', 'endpoint_id', 'config_revision', 'collector_id', 'processing_poller_id', 'attempts', 'delivery_id', 'attempt_number', 'duration_ms'] as $field) {
		if (!array_key_exists($field, $data)) {
			continue;
		}
		$value = filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

		if ($value === false) {
			throw new InvalidArgumentException('sync_numeric_field_invalid');
		} $data[$field] = $value;
	}

	return $data;
}
function alarmmanager_sync_entities(): array {
	return [
		'event'      => ['events', ['id', 'event_id', 'source', 'source_instance', 'mode', 'payload', 'collector_id', 'processing_poller_id', 'received_at']],
		'processing' => ['jobs', ['id', 'event_row_id', 'status', 'attempts', 'last_error', 'created_at', 'updated_at']],
		'decision'   => ['decisions', ['id', 'event_row_id', 'rule_id', 'endpoint_id', 'reason', 'config_revision', 'created_at']],
		'delivery'   => ['deliveries', ['id', 'event_row_id', 'endpoint_id', 'status', 'attempts', 'last_error', 'idempotency_key', 'created_at', 'updated_at', 'external_reference', 'recipient']],
		'attempt'    => ['attempts', ['id', 'delivery_id', 'attempt_number', 'outcome', 'code', 'started_at', 'ended_at', 'duration_ms']]
	];
}
/** Persistent exact pending bytes make lost acknowledgments replayable without changing the batch. */
function alarmmanager_sync_export(int $limit = 100): ?string {
	if ($limit < 1 || $limit > 500 || alarmmanager_local_poller() < 2 || !alarmmanager_runtime_enabled()) {
		throw new RuntimeException('sync_sender_invalid');
	}

	if ((int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false) !== 0) {
		throw new RuntimeException('transaction_unavailable');
	}
	$isolation = db_fetch_cell_prepared('SELECT @@tx_isolation', [], '', false);
	$restore   = match ($isolation) {
		'READ-COMMITTED' => 'READ COMMITTED', 'REPEATABLE-READ' => 'REPEATABLE READ', 'SERIALIZABLE' => 'SERIALIZABLE', 'READ-UNCOMMITTED' => 'READ UNCOMMITTED', default => throw new RuntimeException('sync_isolation_unknown')
	};
	alarmmanager_db_write('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

	try {
		return alarmmanager_transaction(function () use ($limit): ?string {
			$sender = alarmmanager_db_row('SELECT * FROM plugin_alarmmanager_sync_sender WHERE id = 1 FOR UPDATE');

			if ($sender['pending_body'] !== null) {
				return $sender['pending_body'];
			}
			$connection = $GLOBALS['remote_db_cnn_id'] ?? null;

			if (!$connection instanceof PDO) {
				throw new RuntimeException('sync_main_connection_unavailable');
			}
			$receiver = alarmmanager_node_uuid($connection);
			$rows     = alarmmanager_db_rows('SELECT id, entity, entity_id FROM plugin_alarmmanager_sync_outbox WHERE id > ? ORDER BY id LIMIT ' . $limit . ' FOR UPDATE WAIT 2', [(int) $sender['acked']]);

			if (!$rows) {
				return null;
			}
			$packet   = ['version' => 1, 'poller_id' => alarmmanager_local_poller(), 'instance_uuid' => alarmmanager_node_uuid(), 'receiver_uuid' => $receiver, 'through' => (int) $sender['acked'], 'records' => []];
			$entities = alarmmanager_sync_entities();
			$seen     = [];

			foreach ($rows as $row) {
				$key = $row['entity'] . ':' . $row['entity_id'];

				if (isset($seen[$key])) {
					$packet['through'] = (int) $row['id'];

					continue;
				}
				[$table, $columns] = $entities[$row['entity']];
				$data              = alarmmanager_db_row('SELECT e.' . implode(', e.', $columns) . ', (SELECT MAX(o.id) FROM plugin_alarmmanager_sync_outbox AS o WHERE o.entity = ? AND o.entity_id = e.id) AS alarmmanager_sync_revision FROM plugin_alarmmanager_' . $table . ' AS e WHERE e.id = ?', [$row['entity'], $row['entity_id']]);

				if (!$data) {
					throw new RuntimeException('sync_source_history_missing');
				}
				$revision = (int) $data['alarmmanager_sync_revision'];
				unset($data['alarmmanager_sync_revision']);
				$data                   = alarmmanager_sync_normalize($data);
				$record                 = ['entity' => $row['entity'], 'id' => (int) $row['entity_id'], 'revision' => $revision, 'data' => $data];
				$candidate              = $packet;
				$candidate['records'][] = $record;
				$candidate['through']   = (int) $row['id'];

				if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) > ALARMMANAGER_SYNC_LIMIT) {
					if (!$packet['records']) {
						throw new RuntimeException('sync_record_limit');
					}

					break;
				}
				$packet     = $candidate;
				$seen[$key] = true;
			}
			$body   = json_encode($packet, JSON_THROW_ON_ERROR);
			$digest = hash('sha256', $body);
			alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_sender SET pending_end = ?, pending_hash = ?, pending_body = ? WHERE id = 1', [$packet['through'], $digest, $body]);

			return $body;
		});
	} finally {
		alarmmanager_db_write('SET SESSION TRANSACTION ISOLATION LEVEL ' . $restore);
	}
}
/** Mirror tables only. Import never calls ingestion, routing, replay or any delivery adapter. */
function alarmmanager_sync_receive(string $body, int $poller, ?PDO $connection = null): string {
	if (strlen($body) > ALARMMANAGER_SYNC_LIMIT || ($connection === null && alarmmanager_local_poller() !== 1) || !alarmmanager_runtime_enabled($connection)) {
		throw new RuntimeException('sync_receiver_invalid');
	}
	$packet = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

	if (!is_array($packet)) {
		throw new InvalidArgumentException('sync_packet_invalid');
	}
	alarmmanager_event_keys($packet, ['version', 'poller_id', 'instance_uuid', 'receiver_uuid', 'through', 'records', 'heartbeat']);

	if (($packet['version'] ?? null) !== 1 || ($packet['poller_id'] ?? null) !== $poller || $poller < 2 || ($packet['receiver_uuid'] ?? '') !== alarmmanager_node_uuid($connection) || !is_int($packet['through'] ?? null) || $packet['through'] < 0 || !is_array($packet['records'] ?? null) || !array_is_list($packet['records']) || count($packet['records']) > 500) {
		throw new InvalidArgumentException('sync_packet_invalid');
	}

	if (!is_string($packet['instance_uuid'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $packet['instance_uuid']) || !alarmmanager_db_row('SELECT id FROM poller WHERE id = ?', [$poller], $connection)) {
		throw new RuntimeException('sync_peer_invalid');
	}

	if (!$packet['records'] && ($packet['heartbeat'] ?? false) !== true) {
		throw new InvalidArgumentException('sync_packet_empty');
	}

	if ($packet['records'] && ($packet['heartbeat'] ?? false)) {
		throw new InvalidArgumentException('sync_packet_invalid');
	}
	$entities = alarmmanager_sync_entities();
	$seen     = [];

	foreach ($packet['records'] as &$record) {
		if (!is_array($record)) {
			throw new InvalidArgumentException('sync_record_invalid');
		}
		alarmmanager_event_keys($record, ['entity', 'id', 'revision', 'data']);

		if (!isset($entities[$record['entity'] ?? '']) || !is_int($record['id'] ?? null) || $record['id'] < 1 || !is_int($record['revision'] ?? null) || $record['revision'] < 1 || !is_array($record['data'] ?? null)) {
			throw new InvalidArgumentException('sync_record_invalid');
		}

		if ($record['entity'] === 'delivery') {
			$record['data']['external_reference'] ??= null;
			$record['data']['recipient'] ??= '';

			if ($record['data']['recipient'] !== '') {
				$record['data']['recipient'] = alarmmanager_twilio_recipients([$record['data']['recipient']])[0];
			}
		}
		$record['data'] = alarmmanager_sync_normalize($record['data']);
		$key            = $record['entity'] . ':' . $record['id'];

		if (isset($seen[$key])) {
			throw new InvalidArgumentException('sync_duplicate_record');
		} $seen[$key] = true;
		$columns      = $entities[$record['entity']][1];

		if (array_diff(array_keys($record['data']), $columns) || array_diff($columns, array_keys($record['data'])) || (int) $record['data']['id'] !== $record['id']) {
			throw new InvalidArgumentException('sync_record_invalid');
		}

		foreach ($record['data'] as $value) {
			if (!is_scalar($value) && $value !== null) {
				throw new InvalidArgumentException('sync_record_invalid');
			}
		}

		if ($record['entity'] === 'event') {
			$event = alarmmanager_validate_event(json_decode($record['data']['payload'], true, 32, JSON_THROW_ON_ERROR));

			if ($event['event_id'] !== $record['data']['event_id'] || $event['source'] !== $record['data']['source'] || $event['source_instance'] !== $record['data']['source_instance'] || $event['collector_id'] !== $poller || (int) $record['data']['collector_id'] !== $poller || (int) $record['data']['processing_poller_id'] !== $poller || !in_array($record['data']['mode'], ['observe', 'centralized'], true)) {
				throw new InvalidArgumentException('sync_event_owner_invalid');
			}
		}
	}
	unset($record);
	$digest = hash('sha256', $body);
	alarmmanager_transaction(function () use ($packet, $poller, $connection): void {
		alarmmanager_db_write("INSERT INTO plugin_alarmmanager_sync_peers (poller_id, instance_uuid, key_file, last_seen, enabled) VALUES (?, ?, '', UTC_TIMESTAMP(6), 1) ON DUPLICATE KEY UPDATE instance_uuid = VALUES(instance_uuid), last_seen = VALUES(last_seen), enabled = 1", [$poller, $packet['instance_uuid']], $connection);

		if ($packet['heartbeat'] ?? false) {
			return;
		}

		foreach ($packet['records'] as $record) {
			$definition = json_encode($record['data'], JSON_THROW_ON_ERROR);
			$event      = $record['entity'] === 'event' ? alarmmanager_validate_event(json_decode($record['data']['payload'], true, 32, JSON_THROW_ON_ERROR)) : null;

			if ($event) {
				$priorEvent = alarmmanager_db_row("SELECT definition FROM plugin_alarmmanager_remote_history WHERE poller_id = ? AND instance_uuid = ? AND entity = 'event' AND entity_id = ? FOR UPDATE", [$poller, $packet['instance_uuid'], $record['id']], $connection);

				if ($priorEvent && $priorEvent['definition'] !== $definition) {
					throw new RuntimeException('sync_immutable_event_conflict');
				}
			}
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_remote_history (poller_id, instance_uuid, entity, entity_id, revision, definition, device_id, source, sequence, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE definition = IF(VALUES(revision) > revision, VALUES(definition), definition), received_at = IF(VALUES(revision) > revision, VALUES(received_at), received_at), revision = GREATEST(revision, VALUES(revision))', [$poller, $packet['instance_uuid'], $record['entity'], $record['id'], $record['revision'], $definition, $event['device']['id'] ?? null, $event['source'] ?? null, $event['sequence'] ?? null], $connection);
		}
	}, $connection);

	return json_encode(['version' => 1, 'poller_id' => $poller, 'instance_uuid' => $packet['instance_uuid'], 'receiver_uuid' => alarmmanager_node_uuid($connection), 'through' => $packet['through'], 'digest' => $digest], JSON_THROW_ON_ERROR);
}
function alarmmanager_sync_acknowledge(string $ack): void {
	$data = json_decode($ack, true, 8, JSON_THROW_ON_ERROR);
	alarmmanager_transaction(function () use ($data): void {
		$sender  = alarmmanager_db_row('SELECT * FROM plugin_alarmmanager_sync_sender WHERE id = 1 FOR UPDATE');
		$pending = json_decode($sender['pending_body'] ?? 'null', true, 32, JSON_THROW_ON_ERROR);

		if (!is_array($pending) || ($data['version'] ?? null) !== 1 || ($data['poller_id'] ?? null) !== alarmmanager_local_poller() || ($data['instance_uuid'] ?? '') !== alarmmanager_node_uuid() || ($data['receiver_uuid'] ?? '') !== $pending['receiver_uuid'] || ($data['through'] ?? null) !== (int) $sender['pending_end'] || !is_string($data['digest'] ?? null) || !hash_equals($sender['pending_hash'], $data['digest'])) {
			throw new RuntimeException('sync_ack_invalid');
		}
		alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_sender SET acked = pending_end, pending_end = 0, pending_hash = ?, pending_body = NULL WHERE id = 1', ['']);
	});
}
/** Cacti owns the collector-to-main connection; acknowledge locally only after main commits. */
function alarmmanager_sync_send(int $limit = 100): bool {
	$connection = $GLOBALS['remote_db_cnn_id'] ?? null;

	if (alarmmanager_local_poller() < 2 || !$connection instanceof PDO) {
		throw new RuntimeException('sync_main_connection_unavailable');
	}

	if (alarmmanager_node_uuid($connection) === alarmmanager_node_uuid()) {
		throw new RuntimeException('sync_receiver_invalid');
	}
	$body      = alarmmanager_sync_export($limit);
	$heartbeat = $body === null;

	if ($heartbeat) {
		$body = json_encode(['version' => 1, 'poller_id' => alarmmanager_local_poller(), 'instance_uuid' => alarmmanager_node_uuid(), 'receiver_uuid' => alarmmanager_node_uuid($connection), 'through' => 0, 'records' => [], 'heartbeat' => true], JSON_THROW_ON_ERROR);
	}
	$ack = alarmmanager_sync_receive($body, alarmmanager_local_poller(), $connection);

	if (!$heartbeat) {
		alarmmanager_sync_acknowledge($ack);
	}

	return true;
}

/** Provision configuration and usable credentials together, preserving local key/queues/history. */
function alarmmanager_remote_provision(#[\SensitiveParameter] array $bundle): void {
	alarmmanager_secret_guard();
	alarmmanager_event_keys($bundle, ['version', 'poller_id', 'configuration', 'producers', 'grants', 'credentials']);

	if (!in_array($bundle['version'] ?? null, [1, 2], true) || ($bundle['poller_id'] ?? null) !== alarmmanager_local_poller()) {
		throw new InvalidArgumentException('provision_owner_invalid');
	}
	$settings = alarmmanager_configuration($bundle['configuration'] ?? []);

	foreach (['producers' => 256, 'grants' => 4096, 'credentials' => 1000] as $section => $max) {
		if (!is_array($bundle[$section] ?? []) || !array_is_list($bundle[$section]) || count($bundle[$section]) > $max) {
			throw new InvalidArgumentException('provision_bundle_invalid');
		}
	}
	$producers   = [];
	$grants      = [];
	$secrets     = [];

	foreach ($bundle['producers'] as $row) {
		alarmmanager_event_keys($row, ['source', 'scope', 'resource_id', 'definition']);

		if (!is_string($row['source'] ?? null) || !preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,15}\z/D', $row['source']) || !in_array($row['scope'] ?? '', ['default', 'template', 'resource'], true) || !is_int($row['resource_id'] ?? null) || $row['resource_id'] < 0 || ($row['scope'] === 'default' && $row['resource_id'] !== 0)) {
			throw new InvalidArgumentException('provision_producer_invalid');
		}
		$row['definition'] = AlarmManagerProducerBridge::validate($row['definition']);
		$producers[]       = $row;
	}

	foreach ($bundle['grants'] as $row) {
		$row = alarmmanager_device_grant_validate($row);

		if ($row['poller_id'] !== alarmmanager_local_poller()) {
			throw new InvalidArgumentException('provision_device_owner_invalid');
		} $grants[] = $row;
	}

	foreach ($bundle['credentials'] as $row) {
		alarmmanager_event_keys($row, ['endpoint_id', 'record']);
		$id = $row['endpoint_id'] ?? null;

		if (!is_int($id) || $id < 1 || isset($secrets[$id]) || !isset($settings['endpoints'][$id]) || !in_array($settings['endpoints'][$id]['type'], ['restapi', 'webhook'], true)) {
			throw new InvalidArgumentException('provision_credential_invalid');
		}
		$secrets[$id] = alarmmanager_credential_validate($row['record']);
	}
	alarmmanager_transaction(function () use ($settings, $producers, $grants, $secrets): void {
		$key = alarmmanager_secret_key(true);
		alarmmanager_write_configuration($settings);

		foreach ($producers as $row) {
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source, scope, resource_id, definition) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE revision = revision + 1, definition = VALUES(definition)', [$row['source'], $row['scope'], $row['resource_id'], json_encode($row['definition'], JSON_THROW_ON_ERROR)]);
		}

		foreach ($grants as $grant) {
			alarmmanager_install_device_grant($grant);
			alarmmanager_device_seed_local($grant);
		}

		foreach ($secrets as $id => $record) {
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_credentials (endpoint_id, ciphertext, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), updated_at = VALUES(updated_at)', [$id, alarmmanager_encrypt_credential($record, $id, $key)]);
		}
	});
	unset($GLOBALS['alarmmanager_route_cache']);
}
/** Internal grant install: cannot rewind an epoch or reset its sequence. */
function alarmmanager_install_device_grant(array $grant): void {
	alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');
	$old = alarmmanager_device_grant($grant['device_id'], true);

	if ((!$old || (int) $old['poller_id'] !== $grant['poller_id']) && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_device_owners WHERE poller_id = ?', [$grant['poller_id']], '', false) >= 4096) {
		throw new RuntimeException('device_grant_limit');
	}

	if ($old && $grant['epoch'] < (int) $old['epoch']) {
		throw new RuntimeException('device_epoch_stale');
	}

	if ($old && $grant['epoch'] === (int) $old['epoch'] && ((int) $old['poller_id'] !== $grant['poller_id'] || $old['deployment_uuid'] !== $grant['deployment_uuid'] || $old['owner'] !== $grant['owner'])) {
		throw new RuntimeException('device_epoch_conflict');
	}

	if ($old && $grant['epoch'] === (int) $old['epoch'] && ($grant['valid_until'] < $old['valid_until'] || $grant['valid_from'] !== $old['valid_from'])) {
		throw new RuntimeException('device_lease_cannot_shrink');
	}

	if ($old && $grant['epoch'] > (int) $old['epoch'] && alarmmanager_local_poller() === 1) {
		if ((int) $old['poller_id'] === 1) {
			$latest      = alarmmanager_db_row("SELECT payload FROM plugin_alarmmanager_events WHERE source = 'cacti_device' AND device_id = ? AND collector_id = 1 ORDER BY sequence DESC LIMIT 1", [$grant['device_id']]);
			$latestEvent = $latest ? alarmmanager_validate_event(json_decode($latest['payload'], true, 32, JSON_THROW_ON_ERROR)) : null;
		} else {
			$latest      = alarmmanager_db_row("SELECT definition FROM plugin_alarmmanager_remote_history WHERE poller_id = ? AND device_id = ? AND source = 'cacti_device' AND entity = 'event' ORDER BY sequence DESC LIMIT 1", [$old['poller_id'], $grant['device_id']]);
			$data        = $latest ? json_decode($latest['definition'], true, 32, JSON_THROW_ON_ERROR) : null;
			$latestEvent = $data ? alarmmanager_validate_event(json_decode($data['payload'], true, 32, JSON_THROW_ON_ERROR)) : null;
		}

		if ($latestEvent === null || $latestEvent !== $grant['seed_event'] || (int) ($latestEvent['context']['ownership_epoch'] ?? 0) !== (int) $old['epoch']) {
			throw new RuntimeException('device_handoff_history_unacknowledged');
		}
	}

	if ($old && $grant['epoch'] > (int) $old['epoch']) {
		$not_before = (new DateTimeImmutable($old['valid_until'], new DateTimeZone('UTC')))->modify('+735 seconds')->format('Y-m-d H:i:s.u');

		if ($grant['valid_from'] < $not_before) {
			throw new RuntimeException('device_reassignment_overlap');
		}
	}
	alarmmanager_db_write("INSERT INTO plugin_alarmmanager_device_owners (device_id, deployment_uuid, poller_id, epoch, owner, mode, channels, valid_from, valid_until, seed_event) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_sequence = IF(VALUES(epoch) > epoch, 0, last_sequence), last_episode = IF(VALUES(epoch) > epoch, '', last_episode), last_transition = IF(VALUES(epoch) > epoch, '', last_transition), seed_event = VALUES(seed_event), deployment_uuid = VALUES(deployment_uuid), poller_id = VALUES(poller_id), epoch = VALUES(epoch), owner = VALUES(owner), mode = VALUES(mode), channels = VALUES(channels), valid_from = VALUES(valid_from), valid_until = VALUES(valid_until)", [$grant['device_id'], $grant['deployment_uuid'], $grant['poller_id'], $grant['epoch'], $grant['owner'], $grant['mode'], json_encode($grant['channels']), $grant['valid_from'], $grant['valid_until'], $grant['seed_event'] !== null ? json_encode($grant['seed_event'], JSON_THROW_ON_ERROR) : null]);
}

/** Explicit main-poller export to a private file; selected integrations determine credential scope.
 * @param list<int> $endpoints
 */
function alarmmanager_remote_export_configuration(int $poller, array $endpoints): array {
	if (alarmmanager_local_poller() !== 1 || $poller < 2 || !$endpoints || count($endpoints) > 256) {
		throw new InvalidArgumentException('provision_export_invalid');
	}

	foreach ($endpoints as $id) {
		if (!is_int($id) || $id < 1) {
			throw new InvalidArgumentException('provision_export_invalid');
		}
	}

	return alarmmanager_transaction(function () use ($poller, $endpoints): array {
		$key     = alarmmanager_secret_key(true);
		$row     = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');
		$history = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_configuration_history WHERE revision = ?', [$row['config_revision']]);

		if (!$history) {
			throw new RuntimeException('provision_routing_missing');
		}
		$configuration              = json_decode($history['definition'], true, 24, JSON_THROW_ON_ERROR);
		$configuration['endpoints'] = array_values(array_filter($configuration['endpoints'], static fn ($row) => in_array((int) $row['id'], $endpoints, true)));

		if (count($configuration['endpoints']) !== count(array_unique($endpoints))) {
			throw new RuntimeException('provision_endpoint_missing');
		}

		foreach ($configuration['rules'] as &$rule) {
			$rule['endpoints'] = array_values(array_intersect($rule['endpoints'], $endpoints));
		}
		unset($rule);
		$configuration['rules']     = array_values(array_filter($configuration['rules'], static fn ($row) => (bool) $row['endpoints']));
		$producers                  = [];

		foreach (alarmmanager_db_rows('SELECT source, scope, resource_id, definition FROM plugin_alarmmanager_producer_config ORDER BY source, scope, resource_id LIMIT 257') as $row) {
			$definition = json_decode($row['definition'], true, 8, JSON_THROW_ON_ERROR);

			if (count($producers) >= 256) {
				throw new RuntimeException('provision_producer_limit');
			}

			$row['resource_id'] = (int) $row['resource_id'];
			$row['definition']  = $definition;
			$producers[]        = $row;
		}
		$grants = alarmmanager_db_rows('SELECT device_id, deployment_uuid, poller_id, epoch, owner, mode, channels, valid_from, valid_until, seed_event FROM plugin_alarmmanager_device_owners WHERE poller_id = ? ORDER BY device_id LIMIT 4097', [$poller]);

		if (count($grants) > 4096) {
			throw new RuntimeException('provision_grant_limit');
		}

		foreach ($grants as &$grant) {
			foreach (['device_id', 'poller_id', 'epoch'] as $field) {
				$grant[$field] = (int) $grant[$field];
			} $grant['channels'] = json_decode($grant['channels'], true);
			$grant['seed_event'] = $grant['seed_event'] !== null ? json_decode($grant['seed_event'], true) : null;

			foreach (['valid_from', 'valid_until'] as $field) {
				$grant[$field] = str_replace(' ', 'T', $grant[$field]) . 'Z';
			}
		} unset($grant);
		$credentials = [];

		foreach (alarmmanager_db_rows('SELECT endpoint_id, ciphertext FROM plugin_alarmmanager_credentials ORDER BY endpoint_id LIMIT 1001') as $row) {
			if (in_array((int) $row['endpoint_id'], $endpoints, true)) {
				$credentials[] = ['endpoint_id' => (int) $row['endpoint_id'], 'record' => alarmmanager_decrypt_credential($row['ciphertext'], (int) $row['endpoint_id'], $key)];
			}
		}

		return ['version' => 2, 'poller_id' => $poller, 'configuration' => $configuration, 'producers' => $producers, 'grants' => $grants, 'credentials' => $credentials];
	});
}

/** Seed condition state and source host dates without creating a processing or delivery job. */
function alarmmanager_device_seed_local(array $grant): void {
	$seed = $grant['seed_event'] ?? null;

	if ($seed === null) {
		return;
	}
	$stream = alarmmanager_stream_key($seed, $grant['mode']);
	$state  = alarmmanager_db_row('SELECT last_sequence FROM plugin_alarmmanager_states WHERE stream_key = ? FOR UPDATE', [$stream]);

	if ($state && (int) $state['last_sequence'] >= $seed['sequence']) {
		return;
	}
	$host = alarmmanager_db_row('SELECT id, poller_id FROM host WHERE id = ? FOR UPDATE', [$grant['device_id']]);

	if (!$host || (int) $host['poller_id'] !== alarmmanager_local_poller()) {
		throw new RuntimeException('device_handoff_host_invalid');
	}
	alarmmanager_db_write('UPDATE plugin_alarmmanager_device_owners SET last_episode = ?, last_transition = ? WHERE device_id = ?', [$seed['episode_id'], $seed['transition'], $grant['device_id']]);
	$fail = (int) strtotime($seed['transition'] === 'updated' ? (($seed['device']['status_fail_date'] ?? '') ?: $seed['occurred_at']) : $seed['occurred_at']);

	if ($seed['transition'] === 'recovered') { // A closed episode needs no synthesized active alarm.
		alarmmanager_db_write('UPDATE host SET status = 3, status_rec_date = FROM_UNIXTIME(?), status_event_count = 0 WHERE id = ?', [(int) strtotime($seed['occurred_at']), $grant['device_id']]);

		return;
	}
	alarmmanager_db_write('UPDATE host SET status = ?, status_fail_date = FROM_UNIXTIME(?), status_event_count = 0 WHERE id = ?', [$seed['transition'] === 'updated' ? 2 : 1, $fail, $grant['device_id']]);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_states (stream_key, episode_id, last_sequence, active, severity, summary, updated_at) VALUES (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE episode_id = VALUES(episode_id), last_sequence = VALUES(last_sequence), active = 1, severity = VALUES(severity), summary = VALUES(summary), updated_at = VALUES(updated_at)', [$stream, $seed['episode_id'], $seed['sequence'], $seed['severity'], $seed['summary']]);
}
