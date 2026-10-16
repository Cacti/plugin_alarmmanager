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
require_once(__DIR__ . '/routing.php');
require_once(__DIR__ . '/credentials.php');
require_once(__DIR__ . '/supervisor.php');
require_once(__DIR__ . '/queue.php');
require_once(__DIR__ . '/../integrations/producer_bridge.php');

/** Cacti's full replication supplies an already connected remote database. */
function alarmmanager_replicate_out(mixed $data): mixed {
	if (!is_array($data) || ($data['class'] ?? '') !== 'all' || alarmmanager_local_poller() !== 1) {
		return $data;
	}
	$poller     = filter_var($data['remote_poller_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

	// Device replication also emits this hook for main-poller devices.
	if ($poller === 1) {
		return $data;
	}
	$connection = $data['rcnn_id'] ?? null;

	if (!$poller || !$connection instanceof PDO) {
		throw new RuntimeException('configuration_replication_target_invalid');
	}

	try {
		$installed = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_alarmmanager_schema'", [], '', false, $connection);

		if ($installed === false) {
			throw new RuntimeException('configuration_replication_unavailable');
		}

		if ((int) $installed === 0) {
			return $data;
		}
		alarmmanager_replicate_configuration($connection, $poller);
	} catch (Throwable $error) {
		alarmmanager_log('configuration replication failed for collector ' . $poller . '; previous configuration retained');

		// Do not let Cacti mark a failed synchronization complete, or expose SQL/credentials.
		throw new RuntimeException('configuration_replication_failed');
	}

	return $data;
}

/**
 * A coherent source snapshot includes authentication even when no routing profiles exist.
 * @return array<string, mixed>
 */
function alarmmanager_replication_configuration(): array {
	return alarmmanager_transaction(static function (): array {
		$key         = alarmmanager_secret_key(true);
		$row         = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');
		$history     = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_configuration_history WHERE revision = ?', [$row['config_revision']]);
		$settings    = alarmmanager_configuration($history ? json_decode($history['definition'], true, 24, JSON_THROW_ON_ERROR) : ['version' => 1]);
		$credentials = [];

		foreach (alarmmanager_db_rows('SELECT endpoint_id, ciphertext FROM plugin_alarmmanager_credentials ORDER BY endpoint_id LIMIT 1001') as $credential) {
			$id = (int) $credential['endpoint_id'];

			if (isset($settings['endpoints'][$id]) && in_array($settings['endpoints'][$id]['type'], ['restapi', 'webhook'], true)) {
				$credentials[$id] = alarmmanager_decrypt_credential($credential['ciphertext'], $id, $key);
			}
		}
		$producers = alarmmanager_db_rows('SELECT source, scope, resource_id, definition FROM plugin_alarmmanager_producer_config ORDER BY source, scope, resource_id LIMIT 257');

		if (count($producers) > 256) {
			throw new RuntimeException('provision_producer_limit');
		}

		foreach ($producers as &$producer) {
			$producer['definition'] = json_encode(AlarmManagerProducerBridge::validate(json_decode($producer['definition'], true, 8, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
		}
		unset($producer);

		return ['settings' => $settings, 'credentials' => $credentials, 'producers' => $producers, 'daemon' => alarmmanager_supervisor_options(), 'paused' => alarmmanager_notifications_paused()];
	});
}

/** Detect remote edits as well as source changes without generating redundant configuration history. */
function alarmmanager_replication_state(PDO $connection): string {
	return hash('sha256', json_encode([
		alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1', [], $connection),
		alarmmanager_db_rows("SELECT name, value FROM settings_user WHERE user_id = 1 AND name = 'alarmmanager_daemon_configuration'", [], $connection),
		alarmmanager_db_rows("SELECT value FROM settings WHERE name = 'alarmmanager_notifications_paused'", [], $connection),
		alarmmanager_db_rows('SELECT endpoint_id, ciphertext FROM plugin_alarmmanager_credentials ORDER BY endpoint_id', [], $connection),
		alarmmanager_db_rows('SELECT source, scope, resource_id, definition FROM plugin_alarmmanager_producer_config ORDER BY source, scope, resource_id', [], $connection)
	], JSON_THROW_ON_ERROR));
}

/** Replace settings atomically, preserving each collector's key, queues, leases and history. */
function alarmmanager_replicate_configuration(PDO $connection, int $poller): void {
	alarmmanager_secret_guard();

	if (alarmmanager_local_poller() !== 1 || $poller < 2) {
		throw new RuntimeException('configuration_replication_target_invalid');
	}
	$bundle = alarmmanager_replication_configuration();
	$source = alarmmanager_db_row('SELECT instance_uuid FROM plugin_alarmmanager_node WHERE id = 1');
	alarmmanager_transaction(static function () use ($connection, $poller, $bundle, $source): void {
		$owner = alarmmanager_db_row('SELECT instance_uuid FROM plugin_alarmmanager_node WHERE id = 1 FOR UPDATE', [], $connection);

		if (!$owner || !$source || $owner['instance_uuid'] === $source['instance_uuid']) {
			throw new RuntimeException('configuration_replication_target_invalid');
		}
		// Lock in the same key -> schema order as credential rotation and provisioning.
		$key = alarmmanager_decode_key(db_fetch_cell_prepared('SELECT value FROM settings_user WHERE user_id = ? AND name = ? FOR UPDATE', [1, 'alarmmanager_key'], '', false, $connection));
		alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE', [], $connection);
		$digest   = hash_hmac('sha256', 'alarmmanager:configuration:v1:' . $poller . ':' . json_encode($bundle, JSON_THROW_ON_ERROR), $key);
		$marker   = alarmmanager_db_row('SELECT value FROM settings_user WHERE user_id = ? AND name = ?', [1, 'alarmmanager_replicated_configuration'], $connection);
		$previous = $marker ? json_decode($marker['value'], true, 8) : [];

		if (($previous['digest'] ?? '') === $digest && ($previous['state'] ?? '') === alarmmanager_replication_state($connection)) {
			return;
		}
		alarmmanager_write_configuration($bundle['settings'], null, $connection);
		alarmmanager_db_write('DELETE FROM plugin_alarmmanager_producer_config', [], $connection);

		foreach ($bundle['producers'] as $producer) {
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source, scope, resource_id, definition) VALUES (?, ?, ?, ?)', [$producer['source'], $producer['scope'], $producer['resource_id'], $producer['definition']], $connection);
		}
		alarmmanager_db_write('DELETE FROM plugin_alarmmanager_credentials', [], $connection);

		foreach ($bundle['credentials'] as $id => $record) {
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_credentials (endpoint_id, ciphertext, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6))', [$id, alarmmanager_encrypt_credential($record, $id, $key)], $connection);
		}
		alarmmanager_db_write('REPLACE INTO settings_user (user_id, name, value) VALUES (?, ?, ?)', [1, 'alarmmanager_daemon_configuration', json_encode($bundle['daemon'], JSON_THROW_ON_ERROR)], $connection);
		alarmmanager_db_write("REPLACE INTO settings (name, value) VALUES ('alarmmanager_notifications_paused', ?)", [$bundle['paused'] ? '1' : '0'], $connection);
		$marker = json_encode(['digest' => $digest, 'state' => alarmmanager_replication_state($connection)], JSON_THROW_ON_ERROR);
		alarmmanager_db_write('REPLACE INTO settings_user (user_id, name, value) VALUES (?, ?, ?)', [1, 'alarmmanager_replicated_configuration', $marker], $connection);
	}, $connection);
}
