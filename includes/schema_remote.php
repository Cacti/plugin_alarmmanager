<?php
// SPDX-License-Identifier: GPL-2.0-or-later
function alarmmanager_schema_remote(): bool {
	$version = (int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false);
	$queries = [
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_node (id TINYINT PRIMARY KEY, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_device_owners (device_id INT UNSIGNED PRIMARY KEY, deployment_uuid CHAR(32) CHARACTER SET ascii NOT NULL, poller_id INT UNSIGNED NOT NULL, epoch INT UNSIGNED NOT NULL, owner VARCHAR(8) NOT NULL, mode VARCHAR(16) NOT NULL, profile_id INT UNSIGNED NOT NULL, channels TEXT NOT NULL, valid_from DATETIME(6) NOT NULL, valid_until DATETIME(6) NOT NULL, last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0, last_episode VARCHAR(128) NOT NULL DEFAULT '', last_transition VARCHAR(16) NOT NULL DEFAULT '', seed_event MEDIUMTEXT NULL, KEY collector_mode (poller_id, mode, device_id)) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_device_receipts (event_id CHAR(64) CHARACTER SET ascii PRIMARY KEY, device_id INT UNSIGNED NOT NULL, transition VARCHAR(16) NOT NULL, epoch INT UNSIGNED NOT NULL, sequence BIGINT UNSIGNED NOT NULL, fail_epoch BIGINT UNSIGNED NOT NULL, rec_epoch BIGINT UNSIGNED NOT NULL, event_row_id BIGINT UNSIGNED NOT NULL, mode VARCHAR(16) NOT NULL, channels TEXT NOT NULL, created_at DATETIME(6) NOT NULL, KEY device_transition (device_id, epoch, transition, fail_epoch, rec_epoch, sequence)) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_outbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(16) CHARACTER SET ascii NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, seed TINYINT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY initial_seed (entity, entity_id, seed), KEY entity_version (entity, entity_id, id)) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_sender (id TINYINT PRIMARY KEY, acked BIGINT UNSIGNED NOT NULL DEFAULT 0, pending_end BIGINT UNSIGNED NOT NULL DEFAULT 0, pending_hash CHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '', pending_body MEDIUMTEXT NULL) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_peers (poller_id INT UNSIGNED PRIMARY KEY, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL, key_file VARCHAR(1024) NOT NULL, endpoint_url VARCHAR(2048) NULL, ca_bundle VARCHAR(1024) NULL, last_seen DATETIME(6) NULL, enabled TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_remote_history (poller_id INT UNSIGNED NOT NULL, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL, entity VARCHAR(16) CHARACTER SET ascii NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, revision BIGINT UNSIGNED NOT NULL, definition MEDIUMTEXT NOT NULL, device_id INT UNSIGNED NULL, source VARCHAR(32) NULL, sequence BIGINT UNSIGNED NULL, received_at DATETIME(6) NOT NULL, PRIMARY KEY (poller_id, instance_uuid, entity, entity_id), KEY remote_recent (received_at, poller_id), KEY device_history (poller_id, device_id, source, sequence)) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_batches (poller_id INT UNSIGNED NOT NULL, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL, through_id BIGINT UNSIGNED NOT NULL, digest CHAR(64) CHARACTER SET ascii NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (poller_id, instance_uuid, through_id)) ENGINE=InnoDB"
	];
	foreach ($queries as $sql) { if (!db_execute_prepared($sql, [], false)) { return false; } }
	// Never silently change a previously provisioned node's identity.
	if ($version < 6) {
		if (!db_execute_prepared('INSERT IGNORE INTO plugin_alarmmanager_node (id, instance_uuid) VALUES (1, ?)', [bin2hex(random_bytes(16))], false)) { return false; }
	} elseif (!is_string(db_fetch_cell_prepared('SELECT instance_uuid FROM plugin_alarmmanager_node WHERE id = 1', [], '', false))) { return false; }
	if (!db_execute_prepared('INSERT IGNORE INTO plugin_alarmmanager_sync_sender (id) VALUES (1)', [], false)) { return false; }
	foreach (['event' => 'events', 'processing' => 'jobs', 'decision' => 'decisions', 'delivery' => 'deliveries', 'attempt' => 'attempts'] as $entity => $table) {
		foreach ($entity === 'event' || $entity === 'decision' ? ['INSERT'] : ['INSERT', 'UPDATE'] as $operation) {
			$sql = "CREATE TRIGGER IF NOT EXISTS plugin_alarmmanager_sync_{$entity}_" . strtolower($operation) . " AFTER $operation ON plugin_alarmmanager_$table FOR EACH ROW INSERT INTO plugin_alarmmanager_sync_outbox (entity, entity_id, created_at) VALUES ('$entity', NEW.id, UTC_TIMESTAMP(6))";
			if (!db_execute_prepared($sql, [], false)) { return false; }
		}
	}
	return (bool) db_execute_prepared('UPDATE plugin_alarmmanager_schema SET version = 6 WHERE id = 1', [], false);
}
