<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/runtime.php');
function alarmmanager_schema_delivery(): bool {
	try {
		alarmmanager_db_write('CREATE TABLE IF NOT EXISTS plugin_alarmmanager_credentials (endpoint_id INT UNSIGNED PRIMARY KEY, ciphertext MEDIUMTEXT NOT NULL, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB');
		alarmmanager_db_write('CREATE TABLE IF NOT EXISTS plugin_alarmmanager_transports (endpoint_id INT UNSIGNED PRIMARY KEY, definition MEDIUMTEXT NOT NULL) ENGINE=InnoDB');
		$count = db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plugin_alarmmanager_credentials', 'plugin_alarmmanager_transports') AND ENGINE = 'InnoDB'", [], '', false);
		if ((int) $count !== 2) { return false; }
		alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 3) WHERE id = 1 AND version <= 4');
		return true;
	} catch (Throwable $error) { return false; }
}
