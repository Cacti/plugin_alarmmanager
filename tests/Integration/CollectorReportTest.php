<?php
// SPDX-License-Identifier: GPL-2.0-or-later

use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class CollectorReportTest extends AlarmManagerTestCase {
	public function testReportsShowOriginatingCollectorAndKeepDeletedPollerHistory(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable database creation must be enabled.');
		}
		self::alarmmanager_test_load('includes/schema.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/reports.php');
		$db = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$name = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$name`");
		$db->exec("USE `$name`");

		try {
			$this->assertTrue(alarmmanager_schema_install());
			$db->exec('CREATE TABLE poller (id INT PRIMARY KEY, name VARCHAR(128))');
			$db->exec("INSERT INTO poller VALUES (1, 'Main collector'), (3, 'Remote collector')");
			$event = json_decode(file_get_contents(dirname(__DIR__, 2) . '/examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
			$event['collector_id'] = 3;
			$event['device']['id'] = 42;
			$event = alarmmanager_validate_event($event);
			alarmmanager_transaction(static fn () => alarmmanager_persist_event($event, 'centralized', 1));
			$filters = alarmmanager_report_filters(['until' => time() + 1]);
			$scope = ['device_sql' => 'e.device_id = 42'];

			foreach (['events', 'processing'] as $kind) {
				$row = alarmmanager_report_list($kind, $scope, $filters)['rows'][0];
				$this->assertSame(3, (int) $row['collector_id']);
				$this->assertSame('Remote collector', $row['collector_name'], 'Use the event origin, not the processing poller.');
				$this->assertSame([], alarmmanager_report_list($kind, ['device_sql' => 'e.device_id = 43'], $filters)['rows']);
			}

			foreach (['conditions', 'deliveries', 'attempts', 'decisions'] as $kind) {
				$this->assertSame([], alarmmanager_report_list($kind, $scope, $filters)['rows']);
			}
			$db->exec("UPDATE poller SET name = '' WHERE id = 3");
			$this->assertSame('Poller #3', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['collector_name']);
			$db->exec("UPDATE poller SET name = 'Renamed collector' WHERE id = 3");
			$this->assertSame('Renamed collector', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['collector_name']);
			$db->exec('DELETE FROM poller WHERE id = 3');
			$this->assertSame('Poller #3', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['collector_name'], 'Deleted pollers must not hide alarm history.');
		} finally {
			$db->exec("DROP DATABASE `$name`");
		}
	}
}
