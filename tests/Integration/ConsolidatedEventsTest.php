<?php
// SPDX-License-Identifier: GPL-2.0-or-later

use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ConsolidatedEventsTest extends AlarmManagerTestCase {
	public function testEventSummariesDetailsAndFiltersStayScoped(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable database creation must be enabled.');
		}
		self::alarmmanager_test_load('includes/schema.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/reports.php');
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$name                            = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$name`");
		$db->exec("USE `$name`");

		try {
			$this->assertTrue(alarmmanager_schema_install());
			$db->exec('CREATE TABLE poller (id INT PRIMARY KEY, name VARCHAR(128))');
			$event                 = alarmmanager_validate_event(json_decode(file_get_contents(dirname(__DIR__, 2) . '/examples/event.json'), true));
			$event['device']['id'] = 42;
			alarmmanager_transaction(static fn () => alarmmanager_persist_event($event, 'centralized', 1));
			$id      = (int) $db->query('SELECT id FROM plugin_alarmmanager_events')->fetchColumn();
			$filters = alarmmanager_report_filters(['until' => time() + 1]);
			$scope   = ['device_sql' => 'e.device_id = 42'];
			$this->assertSame('Pending processing', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['integration_status']);

			foreach ([1 => 'completed', 2 => 'failed'] as $endpoint => $status) {
				alarmmanager_db_write('INSERT INTO plugin_alarmmanager_deliveries (event_row_id, endpoint_id, stream_key, sequence, priority, status, available_at, created_at, updated_at, idempotency_key) VALUES (?, ?, ?, 1, 1, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)', [$id, $endpoint, str_repeat('a', 64), $status, hash('sha256', (string) $endpoint)]);
			}
			$rows = alarmmanager_report_list('events', $scope, $filters)['rows'];
			$this->assertCount(1, $rows, 'Multiple integrations must not duplicate event rows.');
			$this->assertSame('1 Delivered · 1 Failed', $rows[0]['integration_status']);
			$this->assertCount(1, alarmmanager_report_list('events', $scope, array_replace($filters, ['status' => 'failed']))['rows']);
			$this->assertSame([], alarmmanager_report_list('events', $scope, array_replace($filters, ['status' => 'pending']))['rows']);
			$this->assertSame([], alarmmanager_report_list('events', ['device_sql' => 'e.device_id = 43'], $filters)['rows']);
			$delivery = (int) $db->query('SELECT id FROM plugin_alarmmanager_deliveries LIMIT 1')->fetchColumn();
			alarmmanager_db_write("INSERT INTO plugin_alarmmanager_attempts (delivery_id, attempt_number, lease_token, outcome, code, started_at) VALUES (?, 1, ?, 'success', 'http_200', UTC_TIMESTAMP(6))", [$delivery, str_repeat('b', 32)]);
			$selected = array_replace($filters, ['event_id' => $id]);
			$detail   = alarmmanager_event_detail($scope, $selected);
			$this->assertCount(2, $detail['_workflow']['deliveries']);
			$this->assertCount(1, $detail['_workflow']['attempts']);
			$this->assertStringContainsString('Deleted endpoint #', $detail['_workflow']['deliveries'][0]['integration']);
			$this->assertNull(alarmmanager_event_detail(['device_sql' => 'e.device_id = 43'], $selected));
			$this->assertCount(1, alarmmanager_report_list('events', $scope, array_replace($filters, ['job' => 'attempt', 'status' => 'success']))['rows']);
			$db->exec('DELETE FROM plugin_alarmmanager_deliveries');
			$db->exec("UPDATE plugin_alarmmanager_jobs SET status = 'failed', last_error = 'fixture_failure'");
			$this->assertSame('Processing failed', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['integration_status']);
			$this->assertCount(1, alarmmanager_report_list('events', $scope, array_replace($filters, ['job' => 'processing', 'status' => 'failed']))['rows']);
			$db->exec("UPDATE plugin_alarmmanager_jobs SET status = 'completed'");
			$this->assertSame('No matching integration', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['integration_status']);
			alarmmanager_db_write("INSERT INTO plugin_alarmmanager_decisions (event_row_id, rule_id, endpoint_id, reason, created_at) VALUES (?, 0, 0, 'no_route', UTC_TIMESTAMP(6))", [$id]);
			$this->assertSame('No matching integration', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['integration_status']);
			alarmmanager_db_write("INSERT INTO plugin_alarmmanager_decisions (event_row_id, rule_id, endpoint_id, reason, created_at) VALUES (?, 1, 1, 'maintenance', UTC_TIMESTAMP(6))", [$id]);
			$this->assertSame('Suppressed', alarmmanager_report_list('events', $scope, $filters)['rows'][0]['integration_status']);
		} finally {
			$db->exec("DROP DATABASE `$name`");
		}
	}
}
