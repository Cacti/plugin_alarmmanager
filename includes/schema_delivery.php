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
