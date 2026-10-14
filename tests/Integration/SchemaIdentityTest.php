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
			alarmmanager_apply_configuration(['version' => 1,
				'endpoints'                                => [['id' => 37, 'name' => 'Preserved endpoint', 'type' => 'restapi']],
				'profiles'                                 => [['id' => 41, 'name' => 'Preserved profile', 'endpoints' => [37]]],
				'rules'                                    => [['id' => 53, 'name' => 'Preserved rule', 'profile_id' => 41]]
			]);

			foreach (['endpoints', 'profiles', 'rules'] as $section) {
				$db->exec("ALTER TABLE plugin_alarmmanager_$section MODIFY id INT UNSIGNED NOT NULL");
			}
			$db->exec('UPDATE plugin_alarmmanager_schema SET version = 6 WHERE id = 1');
			$this->assertTrue(alarmmanager_schema_install(), 'Upgrade from schema v6');
			$this->assertTrue(alarmmanager_schema_install(), 'Repeated upgrade');
			$this->assertTrue(alarmmanager_schema_ready());

			foreach (['endpoints' => 37, 'profiles' => 41, 'rules' => 53] as $section => $id) {
				$this->assertSame($id, (int) db_fetch_cell_prepared("SELECT id FROM plugin_alarmmanager_$section"));
				$this->assertSame('auto_increment', db_fetch_cell_prepared("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id'", ['plugin_alarmmanager_' . $section]));
			}
			$this->assertSame('[37]', db_fetch_cell_prepared('SELECT endpoints_json FROM plugin_alarmmanager_profiles WHERE id = 41'));
			$this->assertSame(41, (int) db_fetch_cell_prepared('SELECT profile_id FROM plugin_alarmmanager_rules WHERE id = 53'));
			$db->exec("INSERT INTO plugin_alarmmanager_endpoints (name, type) VALUES ('Automatic endpoint', 'restapi')");
			$this->assertGreaterThan(37, (int) $db->lastInsertId());
			$db->exec("INSERT INTO plugin_alarmmanager_profiles (name, endpoints_json) VALUES ('Automatic profile', '[37]')");
			$this->assertGreaterThan(41, (int) $db->lastInsertId());
			$db->exec("INSERT INTO plugin_alarmmanager_rules (name, priority, profile_id, definition) VALUES ('Automatic rule', 100, 41, '{}')");
			$this->assertGreaterThan(53, (int) $db->lastInsertId());
		} finally {
			$db->exec("DROP DATABASE `$name`");
		}
	}
}
