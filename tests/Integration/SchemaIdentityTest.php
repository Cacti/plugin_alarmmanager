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

use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class SchemaIdentityTest extends AlarmManagerTestCase {
	public function testUpgradePreservesRecordIdentitiesAndAllocatesNewIds(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Dedicated database create opt-in required.');
		}
		self::alarmmanager_test_load('includes/schema.php');
		self::alarmmanager_test_load('includes/routing.php');
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$name                            = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$name`");
		$db->exec("USE `$name`");

		try {
			$this->assertTrue(alarmmanager_schema_install());
			$db->exec('CREATE TABLE poller (id INT PRIMARY KEY, requires_sync VARCHAR(3), disabled CHAR(2))');
			alarmmanager_apply_configuration(['version' => 1,
				'endpoints'                                => [['id' => 37, 'name' => 'Preserved endpoint', 'type' => 'restapi']],
				'rules'                                    => [['id' => 53, 'name' => 'Preserved rule', 'endpoints' => [37]]]
			]);

			foreach (['endpoints', 'rules'] as $section) {
				$db->exec("ALTER TABLE plugin_alarmmanager_$section MODIFY id INT UNSIGNED NOT NULL");
			}
			$db->exec('CREATE TABLE plugin_alarmmanager_profiles (id INT PRIMARY KEY)');
			$db->exec('ALTER TABLE plugin_alarmmanager_rules ADD COLUMN profile_id INT');
			$db->exec('ALTER TABLE plugin_alarmmanager_device_owners ADD COLUMN profile_id INT');
			$db->exec('UPDATE plugin_alarmmanager_schema SET version = 6 WHERE id = 1');
			$this->assertTrue(alarmmanager_schema_install(), 'Upgrade from schema v6');
			$this->assertTrue(alarmmanager_schema_install(), 'Repeated upgrade');
			$this->assertTrue(alarmmanager_schema_ready());
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_alarmmanager_profiles'"));
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plugin_alarmmanager_rules', 'plugin_alarmmanager_device_owners') AND COLUMN_NAME = 'profile_id'"));
			$this->assertSame('[37]', db_fetch_cell_prepared('SELECT endpoints_json FROM plugin_alarmmanager_rules WHERE id = 53'));

			foreach (['endpoints' => 37, 'rules' => 53] as $section => $id) {
				$this->assertSame($id, (int) db_fetch_cell_prepared("SELECT id FROM plugin_alarmmanager_$section"));
				$this->assertSame('auto_increment', db_fetch_cell_prepared("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id'", ['plugin_alarmmanager_' . $section]));
			}
			$db->exec("INSERT INTO plugin_alarmmanager_endpoints (name, type) VALUES ('Automatic endpoint', 'restapi')");
			$this->assertGreaterThan(37, (int) $db->lastInsertId());
			$db->exec("INSERT INTO plugin_alarmmanager_rules (name, priority, endpoints_json, definition) VALUES ('Automatic rule', 100, '[37]', '{}')");
			$this->assertGreaterThan(53, (int) $db->lastInsertId());
		} finally {
			$db->exec("DROP DATABASE `$name`");
		}
	}

	public function testUpgradeFromV10AddsZeroTouchSyncColumnsInPlace(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Dedicated database create opt-in required.');
		}
		self::alarmmanager_test_load('includes/schema.php');
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$name                            = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$name`");
		$db->exec("USE `$name`");

		try {
			$this->assertTrue(alarmmanager_schema_install());
			// A pre-upgrade v10 install: file-era pairs only, no zero-touch columns.
			$db->exec("INSERT INTO plugin_alarmmanager_sync_peers (poller_id, instance_uuid, key_file) VALUES (2, 'legacy-pair-row', 'legacy.key')");
			$db->exec('ALTER TABLE plugin_alarmmanager_sync_peers DROP COLUMN pair_secret, DROP COLUMN allow_http, DROP COLUMN ca_pem');
			$db->exec('UPDATE plugin_alarmmanager_schema SET version = 10 WHERE id = 1');
			$this->assertTrue(alarmmanager_schema_install(), 'Upgrade from schema v10');
			$this->assertTrue(alarmmanager_schema_ready());

			$type = static fn (string $column): string => strtolower((string) db_fetch_cell_prepared('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['plugin_alarmmanager_sync_peers', $column]));
			$this->assertSame('varchar(64)', $type('pair_secret'), 're-created pair_secret');
			$this->assertTrue(in_array($type('allow_http'), ['tinyint', 'tinyint(4)'], true), 're-created allow_http');
			$this->assertSame('mediumtext', $type('ca_pem'), 're-created ca_pem');
			$row = $db->query('SELECT * FROM plugin_alarmmanager_sync_peers WHERE poller_id = 2')->fetch(PDO::FETCH_ASSOC);
			$this->assertSame('legacy-pair-row', $row['instance_uuid'], 'Unprovisioned legacy pair preserved on upgrade');
			$this->assertSame('legacy.key', $row['key_file'], 'File-era key reference preserved on upgrade');
			$this->assertNull($row['pair_secret'], 'Legacy row starts unprovisioned; the GUI enables it.');
			$this->assertSame(0, (int) $row['allow_http']);
			$db->exec("INSERT INTO plugin_alarmmanager_sync_peers (poller_id, instance_uuid, key_file) VALUES (3, 'another-pair-row', 'other.key')");
			$this->assertTrue(alarmmanager_schema_install(), 'Repeat upgrade is idempotent');
			$this->assertTrue(alarmmanager_schema_ready());
		} finally {
			$db->exec("DROP DATABASE `$name`");
		}
	}
}
