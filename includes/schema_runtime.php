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
require_once(__DIR__ . '/runtime.php');

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
function alarmmanager_schema_runtime(): bool {
	try {
		$version = db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false);

		if ($version === false || (int) $version > 6) {
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
				id INT UNSIGNED PRIMARY KEY, name VARCHAR(128) NOT NULL, type VARCHAR(16) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
				max_concurrency INT NOT NULL DEFAULT 1, min_interval_ms INT NOT NULL DEFAULT 0, timeout_seconds INT NOT NULL DEFAULT 10,
				max_attempts INT NOT NULL DEFAULT 5, next_start_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00',
				last_claim_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00', KEY fair_endpoints (enabled, last_claim_at, id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_profiles (
				id INT UNSIGNED PRIMARY KEY, name VARCHAR(128) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, endpoints_json TEXT NOT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_rules (
				id INT UNSIGNED PRIMARY KEY, name VARCHAR(128) NOT NULL, priority INT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
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
