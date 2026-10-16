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

/** Direct DDL avoids Cacti's destructive plugin_db_changes registry. */
function alarmmanager_schema_install(): bool {
	$database_version = db_fetch_cell_prepared('SELECT VERSION()', [], '', false);

	if (!is_string($database_version) || stripos($database_version, 'MariaDB') === false) {
		return false;
	}
	$database_version = str_starts_with($database_version, '5.5.5-') ? substr($database_version, 6) : $database_version;

	if (!preg_match('/\A(\d+\.\d+\.\d+)/', $database_version, $match) || version_compare($match[1], '10.11.0', '<')) {
		return false;
	}
	$statements = [
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_schema (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, version INT UNSIGNED NOT NULL) ENGINE=InnoDB',
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			contract_version TINYINT UNSIGNED NOT NULL,
			event_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			source_instance VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			resource_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			alarm_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			episode_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			sequence BIGINT UNSIGNED NOT NULL,
			transition VARCHAR(16) CHARACTER SET ascii NOT NULL,
			severity VARCHAR(16) CHARACTER SET ascii NOT NULL,
			occurred_at DATETIME(6) NOT NULL,
			received_at DATETIME(6) NOT NULL,
			collector_id INT UNSIGNED NOT NULL,
			processing_poller_id INT UNSIGNED NOT NULL,
			mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			payload MEDIUMTEXT NOT NULL,
			ingestion_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			UNIQUE KEY event_identity (source, source_instance, event_id, mode),
			KEY received (received_at, id),
			KEY alarm_stream (source, source_instance, alarm_key, episode_id, sequence),
			KEY collector_time (collector_id, received_at, id),
			KEY severity_time (severity, received_at, id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			event_row_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
			available_at DATETIME(6) NOT NULL,
			created_at DATETIME(6) NOT NULL,
			UNIQUE KEY event_job (event_row_id),
			KEY pending_jobs (status, available_at, id),
			CONSTRAINT plugin_alarmmanager_job_event FOREIGN KEY (event_row_id) REFERENCES plugin_alarmmanager_events (id)
		) ENGINE=InnoDB"
	];

	foreach ($statements as $sql) {
		if (!db_execute_prepared($sql, [], false)) {
			return false;
		}
	}
	$engines = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
		('plugin_alarmmanager_schema', 'plugin_alarmmanager_events', 'plugin_alarmmanager_jobs')
		AND ENGINE = 'InnoDB'", [], '', false);

	if ((int) $engines !== 3) {
		return false;
	}
	$base = (bool) db_execute_prepared('INSERT INTO plugin_alarmmanager_schema (id, version) VALUES (1, 1)
		ON DUPLICATE KEY UPDATE version = GREATEST(version, VALUES(version))', [], false);

	if (!$base) {
		return false;
	}

	if (!alarmmanager_schema_runtime()) {
		return false;
	}

	if (!alarmmanager_schema_delivery()) {
		return false;
	}

	if (!alarmmanager_schema_ui()) {
		return false;
	}

	if (!alarmmanager_schema_producers()) {
		return false;
	}

	return alarmmanager_schema_remote() && alarmmanager_schema_record_ids() && alarmmanager_schema_integrations() && alarmmanager_schema_device_status();
}

function alarmmanager_schema_ready(): bool {
	return (int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false) === 10;
}

function alarmmanager_schema_column(string $table, string $column, string $definition): void {
	$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column], '', false);

	if ($count === false) {
		throw new RuntimeException('schema_failure');
	}

	if ((int) $count === 0) {
		// Identifiers/definitions are developer-owned literals, never request values.
		alarmmanager_db_write("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
	}
}

function alarmmanager_schema_index(string $table, string $name, string $columns): void {
	$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $name], '', false);

	if ($count === false) {
		throw new RuntimeException('schema_failure');
	}

	if ((int) $count === 0) {
		alarmmanager_db_write("ALTER TABLE `$table` ADD INDEX `$name` ($columns)");
	}
}

/** v2: ordered stream keys, lease/fair scheduling and the report/core working tables. */
function alarmmanager_schema_runtime(): bool {
	try {
		$version = db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false);

		if ($version === false || (int) $version > 10) {
			return false;
		}
		alarmmanager_schema_column('plugin_alarmmanager_schema', 'config_revision', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
		alarmmanager_schema_column('plugin_alarmmanager_events', 'stream_key', "CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''");
		alarmmanager_db_write("UPDATE plugin_alarmmanager_events SET stream_key = SHA2(CONCAT(source, '|', source_instance, '|', alarm_key, '|', mode), 256) WHERE stream_key = ''");
		alarmmanager_schema_index('plugin_alarmmanager_events', 'stream_order', 'stream_key, sequence, id');

		foreach ([
			'lease_token' => 'CHAR(32) CHARACTER SET ascii NULL',
			'lease_until' => 'DATETIME(6) NULL',
			'attempts'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
			'priority'    => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
			'last_error'  => "VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT ''",
			'updated_at'  => "DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00'"
		] as $name => $definition) {
			alarmmanager_schema_column('plugin_alarmmanager_jobs', $name, $definition);
		}
		alarmmanager_schema_index('plugin_alarmmanager_jobs', 'lease_recovery', 'status, lease_until, id');
		alarmmanager_schema_index('plugin_alarmmanager_jobs', 'completed_time', 'status, updated_at');
		alarmmanager_db_write("UPDATE plugin_alarmmanager_jobs AS j INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id SET j.priority = CASE e.severity WHEN 'critical' THEN 3 WHEN 'major' THEN 2 WHEN 'warning' THEN 1 WHEN 'minor' THEN 1 ELSE 0 END");
		$ddl = [
			"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_states (
				stream_key CHAR(64) CHARACTER SET ascii PRIMARY KEY, episode_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
				last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 0, acknowledged TINYINT NOT NULL DEFAULT 0,
				severity VARCHAR(16) NOT NULL DEFAULT 'info', summary VARCHAR(512) NOT NULL DEFAULT '', last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				updated_at DATETIME(6) NOT NULL, KEY active_conditions (active, severity, updated_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_episodes (
				stream_key CHAR(64) CHARACTER SET ascii NOT NULL, episode_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				status VARCHAR(16) NOT NULL, raised_at DATETIME(6) NOT NULL, ended_at DATETIME(6) NULL,
				PRIMARY KEY (stream_key, episode_id)
			) ENGINE=InnoDB',
			"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_endpoints (
				id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL, type VARCHAR(16) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
				max_concurrency INT NOT NULL DEFAULT 1, min_interval_ms INT NOT NULL DEFAULT 0, timeout_seconds INT NOT NULL DEFAULT 10,
				max_attempts INT NOT NULL DEFAULT 5, next_start_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00',
				last_claim_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00', KEY fair_endpoints (enabled, last_claim_at, id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_profiles (
				id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, endpoints_json TEXT NOT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_rules (
				id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL, priority INT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
				profile_id INT UNSIGNED NOT NULL, stop_processing TINYINT NOT NULL DEFAULT 0, definition TEXT NOT NULL,
				KEY rule_order (enabled, priority, id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_decisions (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_row_id BIGINT UNSIGNED NOT NULL, rule_id INT UNSIGNED NOT NULL,
				endpoint_id INT UNSIGNED NOT NULL, reason VARCHAR(32) NOT NULL, config_revision BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL,
				UNIQUE KEY event_rule_endpoint (event_row_id, rule_id, endpoint_id), KEY event_decisions (event_row_id, reason)
			) ENGINE=InnoDB',
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_notification_state (
				stream_key CHAR(64) CHARACTER SET ascii NOT NULL, endpoint_id INT UNSIGNED NOT NULL, episode_id VARCHAR(128) NOT NULL,
				fingerprint CHAR(64) CHARACTER SET ascii NOT NULL, queued_at DATETIME(6) NOT NULL,
				PRIMARY KEY (stream_key, endpoint_id)
			) ENGINE=InnoDB',
			"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_deliveries (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_row_id BIGINT UNSIGNED NOT NULL, endpoint_id INT UNSIGNED NOT NULL,
				stream_key CHAR(64) CHARACTER SET ascii NOT NULL, sequence BIGINT UNSIGNED NOT NULL, priority TINYINT NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending', available_at DATETIME(6) NOT NULL, created_at DATETIME(6) NOT NULL,
				updated_at DATETIME(6) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, lease_token CHAR(32) CHARACTER SET ascii NULL,
				lease_until DATETIME(6) NULL, last_error VARCHAR(32) NOT NULL DEFAULT '', idempotency_key CHAR(64) CHARACTER SET ascii NOT NULL,
				UNIQUE KEY event_endpoint (event_row_id, endpoint_id), KEY endpoint_pending (endpoint_id, status, available_at, id),
				KEY stream_order (endpoint_id, stream_key, sequence, id), KEY lease_recovery (status, lease_until, id), KEY completed_time (status, updated_at)
			) ENGINE=InnoDB",
			"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_attempts (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, delivery_id BIGINT UNSIGNED NOT NULL, attempt_number INT UNSIGNED NOT NULL,
				lease_token CHAR(32) CHARACTER SET ascii NOT NULL, outcome VARCHAR(16) NOT NULL DEFAULT 'in_progress', code VARCHAR(32) NOT NULL DEFAULT '',
				started_at DATETIME(6) NOT NULL, ended_at DATETIME(6) NULL, duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
				UNIQUE KEY delivery_attempt (delivery_id, attempt_number), KEY recent_attempts (started_at, outcome)
			) ENGINE=InnoDB",
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_failed_jobs (
				queue VARCHAR(16) NOT NULL, job_id BIGINT UNSIGNED NOT NULL, code VARCHAR(32) NOT NULL, failed_at DATETIME(6) NOT NULL,
				PRIMARY KEY (queue, job_id)
			) ENGINE=InnoDB',
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_configuration_history (
				revision BIGINT UNSIGNED PRIMARY KEY, definition MEDIUMTEXT NOT NULL, created_at DATETIME(6) NOT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_workers (
				worker_id CHAR(32) CHARACTER SET ascii PRIMARY KEY, role VARCHAR(16) NOT NULL, poller_id INT UNSIGNED NOT NULL, pid INT NOT NULL,
				status VARCHAR(16) NOT NULL, current_job_id BIGINT UNSIGNED NULL, heartbeat_at DATETIME(6) NOT NULL,
				KEY worker_health (poller_id, heartbeat_at)
			) ENGINE=InnoDB'
		];

		foreach ($ddl as $sql) {
			alarmmanager_db_write($sql);
		}
		alarmmanager_schema_column('plugin_alarmmanager_decisions', 'config_revision', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
		$count = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 20) = 'plugin_alarmmanager_' AND ENGINE <> 'InnoDB'", [], '', false);

		if ($count === false || (int) $count !== 0) {
			return false;
		}
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 2) WHERE id = 1 AND version <= 4');

		return true;
	} catch (Throwable $error) {
		return false;
	}
}

/** v3: encrypted per-endpoint credentials and transport definitions. */
function alarmmanager_schema_delivery(): bool {
	try {
		alarmmanager_db_write('CREATE TABLE IF NOT EXISTS plugin_alarmmanager_credentials (endpoint_id INT UNSIGNED PRIMARY KEY, ciphertext MEDIUMTEXT NOT NULL, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB');
		alarmmanager_db_write('CREATE TABLE IF NOT EXISTS plugin_alarmmanager_transports (endpoint_id INT UNSIGNED PRIMARY KEY, definition MEDIUMTEXT NOT NULL) ENGINE=InnoDB');
		$count = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plugin_alarmmanager_credentials', 'plugin_alarmmanager_transports') AND ENGINE = 'InnoDB'", [], '', false);

		if ((int) $count !== 2) {
			return false;
		}
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 3) WHERE id = 1 AND version <= 4');

		return true;
	} catch (Throwable $error) {
		return false;
	}
}

/** v4: report indexes, device backfill from safe normalized payload JSON. */
function alarmmanager_schema_ui(): bool {
	try {
		alarmmanager_schema_column('plugin_alarmmanager_events', 'device_id', 'INT UNSIGNED NULL');
		alarmmanager_db_write("UPDATE plugin_alarmmanager_events SET device_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.device.id')) AS UNSIGNED) WHERE device_id IS NULL AND JSON_TYPE(JSON_EXTRACT(payload, '$.device.id')) = 'INTEGER'");
		alarmmanager_schema_index('plugin_alarmmanager_events', 'device_received', 'device_id, received_at, id');
		alarmmanager_schema_index('plugin_alarmmanager_events', 'mode_received', 'mode, received_at, id');
		alarmmanager_schema_index('plugin_alarmmanager_deliveries', 'created_report', 'created_at, event_row_id, status');
		alarmmanager_schema_index('plugin_alarmmanager_attempts', 'delivery_report', 'delivery_id, started_at');
		alarmmanager_schema_index('plugin_alarmmanager_decisions', 'reason_report', 'reason, created_at, event_row_id');
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = 4 WHERE id = 1 AND version <= 4');

		return true;
	} catch (Throwable $error) {
		return false;
	}
}

/** v5: producer configuration, stream counters and durable ownership receipts. */
function alarmmanager_schema_producers(): bool {
	$queries = [
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_config (
		 source VARCHAR(16) CHARACTER SET ascii NOT NULL, scope VARCHAR(16) CHARACTER SET ascii NOT NULL,
		 resource_id INT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, definition TEXT NOT NULL,
		 PRIMARY KEY (source, scope, resource_id)) ENGINE=InnoDB',
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_streams (
		 stream_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
		 episode_id VARCHAR(128) CHARACTER SET ascii NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 0,
		 severity VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '', updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_receipts (
		 receipt CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, source VARCHAR(16) CHARACTER SET ascii NOT NULL,
		 stream_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
		 envelope MEDIUMTEXT NOT NULL, channels TEXT NOT NULL, event_row_id BIGINT UNSIGNED NULL,
		 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
		 KEY receipt_status (status, updated_at), KEY source_stream (source, stream_key)) ENGINE=InnoDB"
	];

	foreach ($queries as $sql) {
		if (!db_execute_prepared($sql, [], false)) {
			return false;
		}
	}

	return (bool) db_execute_prepared('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 5) WHERE id = 1', [], false);
}

/** v6: node identity, device ownership, signed sync outbox/peers/history and outbox triggers. */
function alarmmanager_schema_remote(): bool {
	$version = (int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false);
	$queries = [
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_node (id TINYINT PRIMARY KEY, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL) ENGINE=InnoDB',
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_device_owners (device_id INT UNSIGNED PRIMARY KEY, deployment_uuid CHAR(32) CHARACTER SET ascii NOT NULL, poller_id INT UNSIGNED NOT NULL, epoch INT UNSIGNED NOT NULL, owner VARCHAR(8) NOT NULL, mode VARCHAR(16) NOT NULL, profile_id INT UNSIGNED NOT NULL, channels TEXT NOT NULL, valid_from DATETIME(6) NOT NULL, valid_until DATETIME(6) NOT NULL, last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0, last_episode VARCHAR(128) NOT NULL DEFAULT '', last_transition VARCHAR(16) NOT NULL DEFAULT '', seed_event MEDIUMTEXT NULL, KEY collector_mode (poller_id, mode, device_id)) ENGINE=InnoDB",
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_device_receipts (event_id CHAR(64) CHARACTER SET ascii PRIMARY KEY, device_id INT UNSIGNED NOT NULL, transition VARCHAR(16) NOT NULL, epoch INT UNSIGNED NOT NULL, sequence BIGINT UNSIGNED NOT NULL, fail_epoch BIGINT UNSIGNED NOT NULL, rec_epoch BIGINT UNSIGNED NOT NULL, event_row_id BIGINT UNSIGNED NOT NULL, mode VARCHAR(16) NOT NULL, channels TEXT NOT NULL, created_at DATETIME(6) NOT NULL, KEY device_transition (device_id, epoch, transition, fail_epoch, rec_epoch, sequence)) ENGINE=InnoDB',
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_outbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(16) CHARACTER SET ascii NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, seed TINYINT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY initial_seed (entity, entity_id, seed), KEY entity_version (entity, entity_id, id)) ENGINE=InnoDB',
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_sender (id TINYINT PRIMARY KEY, acked BIGINT UNSIGNED NOT NULL DEFAULT 0, pending_end BIGINT UNSIGNED NOT NULL DEFAULT 0, pending_hash CHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '', pending_body MEDIUMTEXT NULL) ENGINE=InnoDB",
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_peers (poller_id INT UNSIGNED PRIMARY KEY, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL, key_file VARCHAR(1024) NOT NULL, endpoint_url VARCHAR(2048) NULL, ca_bundle VARCHAR(1024) NULL, last_seen DATETIME(6) NULL, enabled TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB',
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_remote_history (poller_id INT UNSIGNED NOT NULL, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL, entity VARCHAR(16) CHARACTER SET ascii NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, revision BIGINT UNSIGNED NOT NULL, definition MEDIUMTEXT NOT NULL, device_id INT UNSIGNED NULL, source VARCHAR(32) NULL, sequence BIGINT UNSIGNED NULL, received_at DATETIME(6) NOT NULL, PRIMARY KEY (poller_id, instance_uuid, entity, entity_id), KEY remote_recent (received_at, poller_id), KEY device_history (poller_id, device_id, source, sequence)) ENGINE=InnoDB',
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_batches (poller_id INT UNSIGNED NOT NULL, instance_uuid CHAR(32) CHARACTER SET ascii NOT NULL, through_id BIGINT UNSIGNED NOT NULL, digest CHAR(64) CHARACTER SET ascii NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (poller_id, instance_uuid, through_id)) ENGINE=InnoDB'
	];

	foreach ($queries as $sql) {
		if (!db_execute_prepared($sql, [], false)) {
			return false;
		}
	}

	// Never silently change a previously provisioned node's identity.
	if ($version < 6) {
		if (!db_execute_prepared('INSERT IGNORE INTO plugin_alarmmanager_node (id, instance_uuid) VALUES (1, ?)', [bin2hex(random_bytes(16))], false)) {
			return false;
		}
	} elseif (!is_string(db_fetch_cell_prepared('SELECT instance_uuid FROM plugin_alarmmanager_node WHERE id = 1', [], '', false))) {
		return false;
	}

	if (!db_execute_prepared('INSERT IGNORE INTO plugin_alarmmanager_sync_sender (id) VALUES (1)', [], false)) {
		return false;
	}

	foreach (['event' => 'events', 'processing' => 'jobs', 'decision' => 'decisions', 'delivery' => 'deliveries', 'attempt' => 'attempts'] as $entity => $table) {
		foreach ($entity === 'event' || $entity === 'decision' ? ['INSERT'] : ['INSERT', 'UPDATE'] as $operation) {
			$sql = "CREATE TRIGGER IF NOT EXISTS plugin_alarmmanager_sync_{$entity}_" . strtolower($operation) . " AFTER $operation ON plugin_alarmmanager_$table FOR EACH ROW INSERT INTO plugin_alarmmanager_sync_outbox (entity, entity_id, created_at) VALUES ('$entity', NEW.id, UTC_TIMESTAMP(6))";

			if (!db_execute_prepared($sql, [], false)) {
				return false;
			}
		}
	}

	return (bool) db_execute_prepared('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 6) WHERE id = 1', [], false);
}

/** v7: database-assigned identities for administrator-created configuration records. */
function alarmmanager_schema_record_ids(): bool {
	try {
		foreach (['endpoints', 'profiles', 'rules'] as $section) {
			$table     = 'plugin_alarmmanager_' . $section;
			$automatic = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'", [$table], '', false);

			if ($automatic === false) {
				return false;
			}

			if ((int) $automatic === 0) {
				alarmmanager_db_write("ALTER TABLE `$table` MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
		}
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 7) WHERE id = 1');
	} catch (Throwable $error) {
		return false;
	}

	return true;
}

/** v9: validated delivery references share existing signed history synchronization. */
function alarmmanager_schema_integrations(): bool {
	try {
		alarmmanager_schema_column('plugin_alarmmanager_deliveries', 'external_reference', 'TEXT NULL');
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 9) WHERE id = 1');

		return true;
	} catch (Throwable $error) {
		return false;
	}
}

/** v10: poller-independent comparison with Cacti's confirmed device state. */
function alarmmanager_schema_device_status(): bool {
	try {
		alarmmanager_db_write("CREATE TABLE IF NOT EXISTS plugin_alarmmanager_device_status (
			device_id INT UNSIGNED PRIMARY KEY, poller_id INT UNSIGNED NOT NULL,
			epoch INT UNSIGNED NOT NULL, status TINYINT NOT NULL,
			fail_epoch BIGINT UNSIGNED NOT NULL, rec_epoch BIGINT UNSIGNED NOT NULL,
			updated_at DATETIME(6) NOT NULL, KEY collector (poller_id, device_id)
		) ENGINE=InnoDB");
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = 10 WHERE id = 1');
		return true;
	} catch (Throwable $error) {
		return false;
	}
}
