<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/schema_runtime.php');
function alarmmanager_schema_ui(): bool {
	try {
		alarmmanager_schema_column('plugin_alarmmanager_events', 'device_id', 'INT UNSIGNED NULL');
		alarmmanager_db_write("UPDATE plugin_alarmmanager_events SET device_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.device.id')) AS UNSIGNED) WHERE device_id IS NULL AND JSON_TYPE(JSON_EXTRACT(payload, '$.device.id')) = 'INTEGER'");
		alarmmanager_schema_index('plugin_alarmmanager_events', 'device_received', 'device_id, received_at, id');
		alarmmanager_schema_index('plugin_alarmmanager_events', 'mode_received', 'mode, received_at, id');
		alarmmanager_schema_index('plugin_alarmmanager_deliveries', 'created_report', 'created_at, event_row_id, status');
		alarmmanager_schema_index('plugin_alarmmanager_attempts', 'delivery_report', 'delivery_id, started_at');
		alarmmanager_schema_index('plugin_alarmmanager_decisions', 'reason_report', 'reason, created_at, event_row_id');
		alarmmanager_db_write('CREATE TABLE IF NOT EXISTS plugin_alarmmanager_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, action VARCHAR(32) CHARACTER SET ascii NOT NULL, resource_id BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL, KEY audit_time (created_at, id)) ENGINE=InnoDB');
		$engine = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_alarmmanager_audit' AND ENGINE = 'InnoDB'", [], '', false);
		if ((int) $engine !== 1) { return false; }
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = 4 WHERE id = 1 AND version <= 4');
		return true;
	} catch (Throwable $error) { return false; }
}
