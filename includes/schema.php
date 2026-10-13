<?php
// SPDX-License-Identifier: GPL-2.0-or-later

/** Direct DDL avoids Cacti's destructive plugin_db_changes registry. */
function alarmmanager_schema_install(): bool {
	$database_version = db_fetch_cell_prepared('SELECT VERSION()', [], '', false);
	if (!is_string($database_version) || stripos($database_version, 'MariaDB') === false) { return false; }
	$database_version = str_starts_with($database_version, '5.5.5-') ? substr($database_version, 6) : $database_version;
	if (!preg_match('/\A(\d+\.\d+\.\d+)/', $database_version, $match) || version_compare($match[1], '10.11.0', '<')) { return false; }
	$statements = [
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_schema (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, version INT UNSIGNED NOT NULL) ENGINE=InnoDB',
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_events (
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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
	if (!$base) { return false; }
	require_once(__DIR__ . '/schema_runtime.php');
	return alarmmanager_schema_runtime();
}

function alarmmanager_schema_ready(): bool {
	return (int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false) === 2;
}
