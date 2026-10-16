<?php
// SPDX-License-Identifier: GPL-2.0-or-later
function alarmmanager_test_device_metadata_schema(PDO $db): void {
	$db->exec('CREATE TABLE IF NOT EXISTS poller (id INT PRIMARY KEY, name VARCHAR(30), disabled VARCHAR(2) DEFAULT \'\', requires_sync VARCHAR(3) DEFAULT \'\')');
	$db->exec("INSERT IGNORE INTO poller (id, name) VALUES (1, 'Main collector'), (2, 'Remote collector')");
	$db->exec('CREATE TABLE IF NOT EXISTS host (id INT PRIMARY KEY)');
	$existing = $db->query('SHOW COLUMNS FROM host')->fetchAll(PDO::FETCH_COLUMN);
	foreach (['location' => 'VARCHAR(40)', 'external_id' => 'VARCHAR(40)', 'snmp_sysLocation' => 'VARCHAR(512)', 'snmp_sysContact' => 'VARCHAR(512)', 'snmp_sysName' => 'VARCHAR(255)', 'snmp_sysUpTimeInstance' => 'BIGINT UNSIGNED', 'status_last_error' => 'VARCHAR(2048)', 'status_fail_date' => 'DATETIME', 'status_rec_date' => 'DATETIME'] as $column => $type) {
		if (!in_array($column, $existing, true)) {
			$db->exec("ALTER TABLE host ADD COLUMN `$column` $type NULL");
		}
	}
}
