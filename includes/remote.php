<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/runtime.php');
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/device.php');
require_once(__DIR__ . '/routing.php');
require_once(__DIR__ . '/credential_store.php');
require_once(__DIR__ . '/../integrations/producer_bridge.php');
const ALARMMANAGER_SYNC_LIMIT = 1048576;
function alarmmanager_node_uuid(): string {
	$id = db_fetch_cell_prepared('SELECT instance_uuid FROM plugin_alarmmanager_node WHERE id = 1', [], '', false);
	if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) { throw new RuntimeException('node_identity_unavailable'); }
	return $id;
}
function alarmmanager_remote_private_read(string $path, int $limit): string {
	$stat = @lstat($path);
	if (!$stat || is_link($path) || !is_file($path) || ($stat['mode'] & 0077) || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) { throw new RuntimeException('private_file_required'); }
	$data = @file_get_contents($path, false, null, 0, $limit + 1);
	if ($data === false || strlen($data) > $limit) { throw new RuntimeException('private_input_invalid'); }
	return $data;
}
function alarmmanager_remote_private_write(string $path, string $data): void {
	if (!str_starts_with($path, '/') || !realpath(dirname($path)) || str_starts_with(realpath(dirname($path)) . '/', realpath(__DIR__ . '/..') . '/')) { throw new RuntimeException('private_output_outside_plugin_required'); }
	$previous = umask(0077); $stream = @fopen($path, 'xb'); umask($previous);
	if (!$stream) { throw new RuntimeException('private_output_invalid'); }
	try { if (fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !fsync($stream)) { throw new RuntimeException('private_output_invalid'); } }
	finally { fclose($stream); }
}
function alarmmanager_sync_key(string $file): string { return alarmmanager_decode_key(trim(alarmmanager_remote_private_read($file, 128))); }
function alarmmanager_sync_signature(string $body, int $poller, int $timestamp, string $key, string $domain): string {
	return hash_hmac('sha256', 'alarmmanager-sync-v1:' . $domain . "\n" . $poller . "\n" . $timestamp . "\n" . hash('sha256', $body), $key);
}
function alarmmanager_sync_verify(string $body, int $poller, int $timestamp, string $signature, string $key, string $domain): void {
	if (strlen($body) > ALARMMANAGER_SYNC_LIMIT || abs(time() - $timestamp) > 300 || !preg_match('/\A[a-f0-9]{64}\z/D', $signature) || !hash_equals(alarmmanager_sync_signature($body, $poller, $timestamp, $key, $domain), $signature)) { throw new RuntimeException('sync_authentication_failed'); }
}
function alarmmanager_sync_normalize(array $data): array {
	foreach (['id', 'event_row_id', 'rule_id', 'endpoint_id', 'config_revision', 'collector_id', 'processing_poller_id', 'attempts', 'delivery_id', 'attempt_number', 'duration_ms'] as $field) {
		if (!array_key_exists($field, $data)) { continue; }
		$value = filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		if ($value === false) { throw new InvalidArgumentException('sync_numeric_field_invalid'); } $data[$field] = $value;
	}
	return $data;
}
function alarmmanager_sync_entities(): array {
	return [
		'event' => ['events', ['id', 'event_id', 'source', 'source_instance', 'mode', 'payload', 'collector_id', 'processing_poller_id', 'received_at']],
		'processing' => ['jobs', ['id', 'event_row_id', 'status', 'attempts', 'last_error', 'created_at', 'updated_at']],
		'decision' => ['decisions', ['id', 'event_row_id', 'rule_id', 'endpoint_id', 'reason', 'config_revision', 'created_at']],
		'delivery' => ['deliveries', ['id', 'event_row_id', 'endpoint_id', 'status', 'attempts', 'last_error', 'idempotency_key', 'created_at', 'updated_at']],
		'attempt' => ['attempts', ['id', 'delivery_id', 'attempt_number', 'outcome', 'code', 'started_at', 'ended_at', 'duration_ms']]
	];
}
/** Persistent exact pending bytes make lost acknowledgments replayable without changing the batch. */
function alarmmanager_sync_export(int $limit = 100): ?string {
	if ($limit < 1 || $limit > 500 || alarmmanager_local_poller() < 2 || !alarmmanager_runtime_enabled()) { throw new RuntimeException('sync_sender_invalid'); }
	if ((int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false) !== 0) { throw new RuntimeException('transaction_unavailable'); }
	$isolation = db_fetch_cell_prepared('SELECT @@tx_isolation', [], '', false);
	$restore = match ($isolation) { 'READ-COMMITTED' => 'READ COMMITTED', 'REPEATABLE-READ' => 'REPEATABLE READ', 'SERIALIZABLE' => 'SERIALIZABLE', 'READ-UNCOMMITTED' => 'READ UNCOMMITTED', default => throw new RuntimeException('sync_isolation_unknown') };
	alarmmanager_db_write('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
	try {
	return alarmmanager_transaction(function () use ($limit): ?string {
		$sender = alarmmanager_db_row('SELECT * FROM plugin_alarmmanager_sync_sender WHERE id = 1 FOR UPDATE');
		if ($sender['pending_body'] !== null) { return $sender['pending_body']; }
		$target = alarmmanager_db_row('SELECT instance_uuid FROM plugin_alarmmanager_sync_peers WHERE poller_id = 1 AND enabled = 1');
		if (!$target) { throw new RuntimeException('sync_target_missing'); }
		$rows = alarmmanager_db_rows('SELECT id, entity, entity_id FROM plugin_alarmmanager_sync_outbox WHERE id > ? ORDER BY id LIMIT ' . $limit . ' FOR UPDATE WAIT 2', [(int) $sender['acked']]);
		if (!$rows) { return null; }
		$packet = ['version' => 1, 'poller_id' => alarmmanager_local_poller(), 'instance_uuid' => alarmmanager_node_uuid(), 'receiver_uuid' => $target['instance_uuid'], 'through' => (int) $sender['acked'], 'records' => []];
		$entities = alarmmanager_sync_entities(); $seen = [];
		foreach ($rows as $row) {
			$key = $row['entity'] . ':' . $row['entity_id'];
			if (isset($seen[$key])) { $packet['through'] = (int) $row['id']; continue; }
			[$table, $columns] = $entities[$row['entity']];
			$data = alarmmanager_db_row('SELECT e.' . implode(', e.', $columns) . ', (SELECT MAX(o.id) FROM plugin_alarmmanager_sync_outbox AS o WHERE o.entity = ? AND o.entity_id = e.id) AS alarmmanager_sync_revision FROM plugin_alarmmanager_' . $table . ' AS e WHERE e.id = ?', [$row['entity'], $row['entity_id']]);
			if (!$data) { throw new RuntimeException('sync_source_history_missing'); }
			$revision = (int) $data['alarmmanager_sync_revision']; unset($data['alarmmanager_sync_revision']); $data = alarmmanager_sync_normalize($data);
			$record = ['entity' => $row['entity'], 'id' => (int) $row['entity_id'], 'revision' => $revision, 'data' => $data];
			$candidate = $packet; $candidate['records'][] = $record; $candidate['through'] = (int) $row['id'];
			if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) > ALARMMANAGER_SYNC_LIMIT) { if (!$packet['records']) { throw new RuntimeException('sync_record_limit'); } break; }
			$packet = $candidate; $seen[$key] = true;
		}
		$body = json_encode($packet, JSON_THROW_ON_ERROR); $digest = hash('sha256', $body);
		alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_sender SET pending_end = ?, pending_hash = ?, pending_body = ? WHERE id = 1', [$packet['through'], $digest, $body]);
		return $body;
	});
	} finally { alarmmanager_db_write('SET SESSION TRANSACTION ISOLATION LEVEL ' . $restore); }
}
/** Mirror tables only. Import never calls ingestion, routing, replay or any delivery adapter. */
function alarmmanager_sync_receive(string $body, int $poller): string {
	if (strlen($body) > ALARMMANAGER_SYNC_LIMIT || alarmmanager_local_poller() !== 1 || !alarmmanager_runtime_enabled()) { throw new RuntimeException('sync_receiver_invalid'); }
	$packet = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
	if (!is_array($packet)) { throw new InvalidArgumentException('sync_packet_invalid'); }
	alarmmanager_event_keys($packet, ['version', 'poller_id', 'instance_uuid', 'receiver_uuid', 'through', 'records', 'heartbeat']);
	if (($packet['version'] ?? null) !== 1 || ($packet['poller_id'] ?? null) !== $poller || $poller < 2 || ($packet['receiver_uuid'] ?? '') !== alarmmanager_node_uuid() || !is_int($packet['through'] ?? null) || $packet['through'] < 0 || !is_array($packet['records'] ?? null) || !array_is_list($packet['records']) || count($packet['records']) > 500) { throw new InvalidArgumentException('sync_packet_invalid'); }
	$peer = alarmmanager_db_row('SELECT instance_uuid FROM plugin_alarmmanager_sync_peers WHERE poller_id = ? AND enabled = 1', [$poller]);
	if (!$peer || $packet['instance_uuid'] !== $peer['instance_uuid']) { throw new RuntimeException('sync_peer_invalid'); }
	if (!$packet['records'] && ($packet['heartbeat'] ?? false) !== true) { throw new InvalidArgumentException('sync_packet_empty'); }
	if ($packet['records'] && ($packet['heartbeat'] ?? false)) { throw new InvalidArgumentException('sync_packet_invalid'); }
	$entities = alarmmanager_sync_entities(); $seen = [];
	foreach ($packet['records'] as &$record) {
		if (!is_array($record)) { throw new InvalidArgumentException('sync_record_invalid'); }
		alarmmanager_event_keys($record, ['entity', 'id', 'revision', 'data']);
		if (!isset($entities[$record['entity'] ?? '']) || !is_int($record['id'] ?? null) || $record['id'] < 1 || !is_int($record['revision'] ?? null) || $record['revision'] < 1 || !is_array($record['data'] ?? null)) { throw new InvalidArgumentException('sync_record_invalid'); }
		$record['data'] = alarmmanager_sync_normalize($record['data']);
		$key = $record['entity'] . ':' . $record['id']; if (isset($seen[$key])) { throw new InvalidArgumentException('sync_duplicate_record'); } $seen[$key] = true;
		$columns = $entities[$record['entity']][1];
		if (array_diff(array_keys($record['data']), $columns) || array_diff($columns, array_keys($record['data'])) || (int) $record['data']['id'] !== $record['id']) { throw new InvalidArgumentException('sync_record_invalid'); }
		foreach ($record['data'] as $value) { if (!is_scalar($value) && $value !== null) { throw new InvalidArgumentException('sync_record_invalid'); } }
		if ($record['entity'] === 'event') {
			$event = alarmmanager_validate_event(json_decode($record['data']['payload'], true, 32, JSON_THROW_ON_ERROR));
			if ($event['event_id'] !== $record['data']['event_id'] || $event['source'] !== $record['data']['source'] || $event['source_instance'] !== $record['data']['source_instance'] || $event['collector_id'] !== $poller || (int) $record['data']['collector_id'] !== $poller || (int) $record['data']['processing_poller_id'] !== $poller || !in_array($record['data']['mode'], ['observe', 'centralized'], true)) { throw new InvalidArgumentException('sync_event_owner_invalid'); }
		}
	}
	unset($record);
	$digest = hash('sha256', $body);
	alarmmanager_transaction(function () use ($packet, $digest, $poller): void {
		alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_peers SET last_seen = UTC_TIMESTAMP(6) WHERE poller_id = ?', [$poller]);
		if ($packet['heartbeat'] ?? false) { return; }
		$prior = alarmmanager_db_row('SELECT digest FROM plugin_alarmmanager_sync_batches WHERE poller_id = ? AND instance_uuid = ? AND through_id = ? FOR UPDATE', [$poller, $packet['instance_uuid'], $packet['through']]);
		if ($prior && !hash_equals($prior['digest'], $digest)) { throw new RuntimeException('sync_batch_conflict'); }
		foreach ($packet['records'] as $record) {
			$definition = json_encode($record['data'], JSON_THROW_ON_ERROR);
			$event = $record['entity'] === 'event' ? alarmmanager_validate_event(json_decode($record['data']['payload'], true, 32, JSON_THROW_ON_ERROR)) : null;
			if ($event) { $priorEvent = alarmmanager_db_row("SELECT definition FROM plugin_alarmmanager_remote_history WHERE poller_id = ? AND instance_uuid = ? AND entity = 'event' AND entity_id = ? FOR UPDATE", [$poller, $packet['instance_uuid'], $record['id']]); if ($priorEvent && $priorEvent['definition'] !== $definition) { throw new RuntimeException('sync_immutable_event_conflict'); } }
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_remote_history (poller_id, instance_uuid, entity, entity_id, revision, definition, device_id, source, sequence, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE definition = IF(VALUES(revision) > revision, VALUES(definition), definition), received_at = IF(VALUES(revision) > revision, VALUES(received_at), received_at), revision = GREATEST(revision, VALUES(revision))', [$poller, $packet['instance_uuid'], $record['entity'], $record['id'], $record['revision'], $definition, $event['device']['id'] ?? null, $event['source'] ?? null, $event['sequence'] ?? null]);
		}
		alarmmanager_db_write('INSERT IGNORE INTO plugin_alarmmanager_sync_batches (poller_id, instance_uuid, through_id, digest, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))', [$poller, $packet['instance_uuid'], $packet['through'], $digest]);
	});
	return json_encode(['version' => 1, 'poller_id' => $poller, 'instance_uuid' => $packet['instance_uuid'], 'receiver_uuid' => alarmmanager_node_uuid(), 'through' => $packet['through'], 'digest' => $digest], JSON_THROW_ON_ERROR);
}
function alarmmanager_sync_acknowledge(string $ack): void {
	$data = json_decode($ack, true, 8, JSON_THROW_ON_ERROR);
	alarmmanager_transaction(function () use ($data): void {
		$sender = alarmmanager_db_row('SELECT * FROM plugin_alarmmanager_sync_sender WHERE id = 1 FOR UPDATE');
		$target = alarmmanager_db_row('SELECT instance_uuid FROM plugin_alarmmanager_sync_peers WHERE poller_id = 1 AND enabled = 1');
		if (!$target || ($data['version'] ?? null) !== 1 || ($data['poller_id'] ?? null) !== alarmmanager_local_poller() || ($data['instance_uuid'] ?? '') !== alarmmanager_node_uuid() || ($data['receiver_uuid'] ?? '') !== $target['instance_uuid'] || ($data['through'] ?? null) !== (int) $sender['pending_end'] || !is_string($data['digest'] ?? null) || !hash_equals($sender['pending_hash'], $data['digest'])) { throw new RuntimeException('sync_ack_invalid'); }
		alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_sender SET acked = pending_end, pending_end = 0, pending_hash = ?, pending_body = NULL WHERE id = 1', ['']);
	});
}
/** Bounded TLS transport independent of main DB connectivity; errors preserve pending bytes. */
function alarmmanager_sync_send(int $limit = 100): bool {
	$body = alarmmanager_sync_export($limit); $heartbeat = $body === null;
	$target = alarmmanager_db_row('SELECT * FROM plugin_alarmmanager_sync_peers WHERE poller_id = 1 AND enabled = 1');
	if ($heartbeat) { $body = json_encode(['version' => 1, 'poller_id' => alarmmanager_local_poller(), 'instance_uuid' => alarmmanager_node_uuid(), 'receiver_uuid' => $target['instance_uuid'], 'through' => (int) db_fetch_cell_prepared('SELECT acked FROM plugin_alarmmanager_sync_sender WHERE id = 1', [], '', false), 'records' => [], 'heartbeat' => true], JSON_THROW_ON_ERROR); }
	require_once(__DIR__ . '/transport_config.php');
	$settings = alarmmanager_transport_configuration('restapi', ['url' => $target['endpoint_url'], 'credential' => true, 'ca_bundle' => $target['ca_bundle'], 'mapping' => []]);
	$key = alarmmanager_sync_key($target['key_file']); $timestamp = time(); $poller = alarmmanager_local_poller();
	$headers = "Content-Type: application/json\r\nX-AlarmManager-Poller: $poller\r\nX-AlarmManager-Time: $timestamp\r\nX-AlarmManager-Signature: " . alarmmanager_sync_signature($body, $poller, $timestamp, $key, 'history') . "\r\nConnection: close\r\n";
	$ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]; if ($settings['ca_bundle'] !== null) { $ssl['cafile'] = $settings['ca_bundle']; }
	$context = stream_context_create(['ssl' => $ssl, 'http' => ['method' => 'POST', 'header' => $headers, 'content' => $body, 'timeout' => 10, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'protocol_version' => 1.1]]);
	if (!function_exists('pcntl_alarm') || !function_exists('pcntl_async_signals')) { throw new RuntimeException('sync_deadline_unavailable'); }
	$previous_handler = pcntl_signal_get_handler(SIGALRM); $previous_async = pcntl_async_signals(true);
	pcntl_signal(SIGALRM, static function (): void { throw new RuntimeException('sync_timeout'); }); pcntl_alarm(11);
	try {
	$stream = @fopen($settings['url'], 'rb', false, $context); if (!$stream) { throw new RuntimeException('sync_network_failed'); }
	try { $metadata = stream_get_meta_data($stream); $reply = stream_get_contents($stream, 8193); } finally { fclose($stream); }
	if (!is_string($reply) || strlen($reply) > 8192) { throw new RuntimeException('sync_response_limit'); }
	$lines = $metadata['wrapper_data'] ?? []; $status = 0; $signature = ''; $time = 0; $header_bytes = 0;
	foreach ($lines as $line) {
		$header_bytes += strlen($line); if ($header_bytes > 16384) { throw new RuntimeException('sync_response_limit'); }
		if (preg_match('/\AHTTP\/\S+ ([0-9]{3})/', $line, $matches)) { $status = (int) $matches[1]; }
		if (stripos($line, 'X-AlarmManager-Signature:') === 0) { $signature = trim(substr($line, 25)); }
		if (stripos($line, 'X-AlarmManager-Time:') === 0) { $time = (int) trim(substr($line, 20)); }
	}
	if ($status !== 200) { throw new RuntimeException('sync_http_failed'); }
	alarmmanager_sync_verify($reply, $poller, $time, $signature, $key, 'ack');
	if ($heartbeat) { $ack = json_decode($reply, true, 8, JSON_THROW_ON_ERROR); if (($ack['digest'] ?? '') !== hash('sha256', $body) || ($ack['receiver_uuid'] ?? '') !== $target['instance_uuid'] || ($ack['instance_uuid'] ?? '') !== alarmmanager_node_uuid() || ($ack['poller_id'] ?? 0) !== $poller) { throw new RuntimeException('sync_ack_invalid'); } }
	else { alarmmanager_sync_acknowledge($reply); } return true;
	} finally { pcntl_alarm(0); pcntl_signal(SIGALRM, $previous_handler); pcntl_async_signals($previous_async); }
}

/** Provision configuration and usable credentials together, preserving local key/queues/history. */
function alarmmanager_remote_provision(#[\SensitiveParameter] array $bundle): void {
	alarmmanager_secret_guard(); alarmmanager_event_keys($bundle, ['version', 'poller_id', 'configuration', 'producers', 'grants', 'credentials']);
	if (($bundle['version'] ?? null) !== 1 || ($bundle['poller_id'] ?? null) !== alarmmanager_local_poller()) { throw new InvalidArgumentException('provision_owner_invalid'); }
	$settings = alarmmanager_configuration($bundle['configuration'] ?? []);
	foreach (['producers' => 256, 'grants' => 4096, 'credentials' => 1000] as $section => $max) { if (!is_array($bundle[$section] ?? []) || !array_is_list($bundle[$section]) || count($bundle[$section]) > $max) { throw new InvalidArgumentException('provision_bundle_invalid'); } }
	$producers = []; $grants = []; $secrets = [];
	foreach ($bundle['producers'] as $row) {
		alarmmanager_event_keys($row, ['source', 'scope', 'resource_id', 'definition']);
		if (!in_array($row['source'] ?? '', ['thold', 'syslog'], true) || !in_array($row['scope'] ?? '', ['default', 'template', 'resource'], true) || !is_int($row['resource_id'] ?? null) || $row['resource_id'] < 0 || ($row['scope'] === 'default' && $row['resource_id'] !== 0) || ($row['source'] === 'syslog' && $row['scope'] === 'template')) { throw new InvalidArgumentException('provision_producer_invalid'); }
		$row['definition'] = AlarmManagerProducerBridge::validate($row['definition']); $producers[] = $row;
	}
	foreach ($bundle['grants'] as $row) { $row = alarmmanager_device_grant_validate($row); if ($row['poller_id'] !== alarmmanager_local_poller()) { throw new InvalidArgumentException('provision_device_owner_invalid'); } $grants[] = $row; }
	foreach ($bundle['credentials'] as $row) {
		alarmmanager_event_keys($row, ['endpoint_id', 'record']); $id = $row['endpoint_id'] ?? null;
		if (!is_int($id) || $id < 1 || isset($secrets[$id]) || !isset($settings['endpoints'][$id]) || !in_array($settings['endpoints'][$id]['type'], ['restapi', 'webhook'], true)) { throw new InvalidArgumentException('provision_credential_invalid'); }
		$secrets[$id] = alarmmanager_credential_validate($row['record']);
	}
	alarmmanager_transaction(function () use ($settings, $producers, $grants, $secrets): void {
		$key = alarmmanager_secret_key(true); alarmmanager_write_configuration($settings);
		foreach ($producers as $row) { alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source, scope, resource_id, definition) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE revision = revision + 1, definition = VALUES(definition)', [$row['source'], $row['scope'], $row['resource_id'], json_encode($row['definition'], JSON_THROW_ON_ERROR)]); }
		foreach ($grants as $grant) { alarmmanager_install_device_grant($grant); alarmmanager_device_seed_local($grant); }
		foreach ($secrets as $id => $record) { alarmmanager_db_write('INSERT INTO plugin_alarmmanager_credentials (endpoint_id, ciphertext, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), updated_at = VALUES(updated_at)', [$id, alarmmanager_encrypt_credential($record, $id, $key)]); }
	});
	unset($GLOBALS['alarmmanager_route_cache']);
}
/** Internal grant install: cannot rewind an epoch or reset its sequence. */
function alarmmanager_install_device_grant(array $grant): void {
	alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');
	$old = alarmmanager_device_grant($grant['device_id'], true);
	if ((!$old || (int) $old['poller_id'] !== $grant['poller_id']) && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_device_owners WHERE poller_id = ?', [$grant['poller_id']], '', false) >= 4096) { throw new RuntimeException('device_grant_limit'); }
	if ($old && $grant['epoch'] < (int) $old['epoch']) { throw new RuntimeException('device_epoch_stale'); }
	if ($old && $grant['epoch'] === (int) $old['epoch'] && ((int) $old['poller_id'] !== $grant['poller_id'] || $old['deployment_uuid'] !== $grant['deployment_uuid'] || $old['owner'] !== $grant['owner'])) { throw new RuntimeException('device_epoch_conflict'); }
	if ($old && $grant['epoch'] === (int) $old['epoch'] && ($grant['valid_until'] < $old['valid_until'] || $grant['valid_from'] !== $old['valid_from'])) { throw new RuntimeException('device_lease_cannot_shrink'); }
	if ($old && $grant['epoch'] > (int) $old['epoch'] && alarmmanager_local_poller() === 1) {
		if ((int) $old['poller_id'] === 1) { $latest = alarmmanager_db_row("SELECT payload FROM plugin_alarmmanager_events WHERE source = 'cacti_device' AND device_id = ? AND collector_id = 1 ORDER BY sequence DESC LIMIT 1", [$grant['device_id']]); $latestEvent = $latest ? alarmmanager_validate_event(json_decode($latest['payload'], true, 32, JSON_THROW_ON_ERROR)) : null; }
		else { $latest = alarmmanager_db_row("SELECT definition FROM plugin_alarmmanager_remote_history WHERE poller_id = ? AND device_id = ? AND source = 'cacti_device' AND entity = 'event' ORDER BY sequence DESC LIMIT 1", [$old['poller_id'], $grant['device_id']]); $data = $latest ? json_decode($latest['definition'], true, 32, JSON_THROW_ON_ERROR) : null; $latestEvent = $data ? alarmmanager_validate_event(json_decode($data['payload'], true, 32, JSON_THROW_ON_ERROR)) : null; }
		if ($latestEvent === null || $latestEvent !== $grant['seed_event'] || (int) ($latestEvent['context']['ownership_epoch'] ?? 0) !== (int) $old['epoch']) { throw new RuntimeException('device_handoff_history_unacknowledged'); }
	}
	if ($old && $grant['epoch'] > (int) $old['epoch']) {
		$not_before = (new DateTimeImmutable($old['valid_until'], new DateTimeZone('UTC')))->modify('+735 seconds')->format('Y-m-d H:i:s.u');
		if ($grant['valid_from'] < $not_before) { throw new RuntimeException('device_reassignment_overlap'); }
	}
	alarmmanager_db_write("INSERT INTO plugin_alarmmanager_device_owners (device_id, deployment_uuid, poller_id, epoch, owner, mode, profile_id, channels, valid_from, valid_until, seed_event) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_sequence = IF(VALUES(epoch) > epoch, 0, last_sequence), last_episode = IF(VALUES(epoch) > epoch, '', last_episode), last_transition = IF(VALUES(epoch) > epoch, '', last_transition), seed_event = VALUES(seed_event), deployment_uuid = VALUES(deployment_uuid), poller_id = VALUES(poller_id), epoch = VALUES(epoch), owner = VALUES(owner), mode = VALUES(mode), profile_id = VALUES(profile_id), channels = VALUES(channels), valid_from = VALUES(valid_from), valid_until = VALUES(valid_until)", [$grant['device_id'], $grant['deployment_uuid'], $grant['poller_id'], $grant['epoch'], $grant['owner'], $grant['mode'], $grant['profile_id'], json_encode($grant['channels']), $grant['valid_from'], $grant['valid_until'], $grant['seed_event'] !== null ? json_encode($grant['seed_event'], JSON_THROW_ON_ERROR) : null]);
}

/** Explicit main-poller export to a private file; selected profiles determine credential scope. */
function alarmmanager_remote_export_configuration(int $poller, array $profiles): array {
	if (alarmmanager_local_poller() !== 1 || $poller < 2 || !$profiles || count($profiles) > 256) { throw new InvalidArgumentException('provision_export_invalid'); }
	foreach ($profiles as $id) { if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('provision_export_invalid'); } }
	return alarmmanager_transaction(function () use ($poller, $profiles): array {
		$key = alarmmanager_secret_key(true);
		$row = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');
		$history = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_configuration_history WHERE revision = ?', [$row['config_revision']]);
		if (!$history) { throw new RuntimeException('provision_routing_missing'); }
		$configuration = json_decode($history['definition'], true, 24, JSON_THROW_ON_ERROR);
		$configuration['profiles'] = array_values(array_filter($configuration['profiles'], static fn($row) => in_array((int) $row['id'], $profiles, true)));
		if (count($configuration['profiles']) !== count(array_unique($profiles))) { throw new RuntimeException('provision_profile_missing'); }
		$endpoints = []; foreach ($configuration['profiles'] as $profile) { $endpoints = [...$endpoints, ...$profile['endpoints']]; } $endpoints = array_unique($endpoints);
		$configuration['endpoints'] = array_values(array_filter($configuration['endpoints'], static fn($row) => in_array((int) $row['id'], $endpoints, true)));
		$configuration['rules'] = array_values(array_filter($configuration['rules'], static fn($row) => in_array((int) $row['profile_id'], $profiles, true)));
		$producers = [];
		foreach (alarmmanager_db_rows('SELECT source, scope, resource_id, definition FROM plugin_alarmmanager_producer_config ORDER BY source, scope, resource_id LIMIT 257') as $row) {
			$definition = json_decode($row['definition'], true, 8, JSON_THROW_ON_ERROR); if (count($producers) >= 256) { throw new RuntimeException('provision_producer_limit'); }
			if (!empty($definition['profile_id']) && !in_array($definition['profile_id'], $profiles, true)) { continue; }
			$row['resource_id'] = (int) $row['resource_id']; $row['definition'] = $definition; $producers[] = $row;
		}
		$grants = alarmmanager_db_rows('SELECT device_id, deployment_uuid, poller_id, epoch, owner, mode, profile_id, channels, valid_from, valid_until, seed_event FROM plugin_alarmmanager_device_owners WHERE poller_id = ? ORDER BY device_id LIMIT 4097', [$poller]);
		if (count($grants) > 4096) { throw new RuntimeException('provision_grant_limit'); }
		$grants = array_values(array_filter($grants, static fn($g) => in_array((int) $g['profile_id'], $profiles, true)));
		foreach ($grants as &$grant) { foreach (['device_id', 'poller_id', 'epoch', 'profile_id'] as $field) { $grant[$field] = (int) $grant[$field]; } $grant['channels'] = json_decode($grant['channels'], true); $grant['seed_event'] = $grant['seed_event'] !== null ? json_decode($grant['seed_event'], true) : null; foreach (['valid_from', 'valid_until'] as $field) { $grant[$field] = str_replace(' ', 'T', $grant[$field]) . 'Z'; } } unset($grant);
		$credentials = [];
		foreach (alarmmanager_db_rows('SELECT endpoint_id, ciphertext FROM plugin_alarmmanager_credentials ORDER BY endpoint_id LIMIT 1001') as $row) { if (in_array((int) $row['endpoint_id'], $endpoints, true)) { $credentials[] = ['endpoint_id' => (int) $row['endpoint_id'], 'record' => alarmmanager_decrypt_credential($row['ciphertext'], (int) $row['endpoint_id'], $key)]; } }
		return ['version' => 1, 'poller_id' => $poller, 'configuration' => $configuration, 'producers' => $producers, 'grants' => $grants, 'credentials' => $credentials];
	});
}

/** Seed condition state and source host dates without creating a processing or delivery job. */
function alarmmanager_device_seed_local(array $grant): void {
	$seed = $grant['seed_event'] ?? null; if ($seed === null) { return; }
	$stream = alarmmanager_stream_key($seed, $grant['mode']);
	$state = alarmmanager_db_row('SELECT last_sequence FROM plugin_alarmmanager_states WHERE stream_key = ? FOR UPDATE', [$stream]);
	if ($state && (int) $state['last_sequence'] >= $seed['sequence']) { return; }
	$host = alarmmanager_db_row('SELECT id, poller_id FROM host WHERE id = ? FOR UPDATE', [$grant['device_id']]);
	if (!$host || (int) $host['poller_id'] !== alarmmanager_local_poller()) { throw new RuntimeException('device_handoff_host_invalid'); }
	alarmmanager_db_write('UPDATE plugin_alarmmanager_device_owners SET last_episode = ?, last_transition = ? WHERE device_id = ?', [$seed['episode_id'], $seed['transition'], $grant['device_id']]);
	$fail = (int) strtotime($seed['transition'] === 'raised' ? $seed['occurred_at'] : '1970-01-01');
	if ($seed['transition'] === 'recovered') { // A closed episode needs no synthesized active alarm.
		alarmmanager_db_write('UPDATE host SET status = 3, status_rec_date = FROM_UNIXTIME(?), status_event_count = 0 WHERE id = ?', [(int) strtotime($seed['occurred_at']), $grant['device_id']]);
		return;
	}
	alarmmanager_db_write('UPDATE host SET status = 1, status_fail_date = FROM_UNIXTIME(?), status_event_count = 0 WHERE id = ?', [$fail, $grant['device_id']]);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_states (stream_key, episode_id, last_sequence, active, severity, summary, updated_at) VALUES (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE episode_id = VALUES(episode_id), last_sequence = VALUES(last_sequence), active = 1, severity = VALUES(severity), summary = VALUES(summary), updated_at = VALUES(updated_at)', [$stream, $seed['episode_id'], $seed['sequence'], $seed['severity'], $seed['summary']]);
}
