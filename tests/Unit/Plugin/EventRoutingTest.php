<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class EventRoutingTest extends AlarmManagerTestCase {
	public function testRoutingSummarisesLocalAndMirroredDecisions(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$queries                                    = [];
		$GLOBALS['alarmmanager_test_query_handler'] = function ($sql, $params) use (&$queries) {
			$queries[] = [$sql, $params];

			if (str_contains($sql, 'FROM plugin_alarmmanager_decisions')) {
				return [
					['event_row_id' => '42', 'endpoint_id' => '7', 'reason' => 'queued'], ['event_row_id' => '42', 'endpoint_id' => '8', 'reason' => 'queued'],
					['event_row_id' => '43', 'endpoint_id' => '0', 'reason' => 'no_route'],
					['event_row_id' => '44', 'endpoint_id' => '9', 'reason' => 'cooldown'], ['event_row_id' => '44', 'endpoint_id' => '7', 'reason' => 'future_reason']
				];
			}

			if (str_contains($sql, 'FROM plugin_alarmmanager_remote_history')) {
				$this->assertSame(['2026-10-26 20:00:00', 3, 'abc', 5], $params, 'Mirrored decisions are bounded by time and limited to the displayed collectors, instances and events.');

				return [['poller_id' => '3', 'instance_uuid' => 'abc', 'event_row_id' => '5', 'endpoint_id' => '7', 'reason' => 'source_suppressed']];
			}

			return [['id' => '7', 'name' => 'Slack'], ['id' => '8', 'name' => 'SMS']];
		};
		$rows = [['id' => 42], ['id' => 43], ['id' => 44], ['id' => 45], ['id' => null, 'collector_id' => '3', 'received_at' => '2026-10-26 20:00:00', 'remote_entity_id' => '5', 'remote_instance_uuid' => 'abc']];
		$this->assertSame([
			'Queued → 2 destinations',
			'No matching rule',
			'Cooldown → Deleted destination #9 · future_reason → Slack',
			'—',
			'Suppressed by source → Slack'
		], alarmmanager_report_routing($rows, [42, 43, 44, 45]));
		$this->assertCount(3, $queries);
		$this->assertSame(['—'], alarmmanager_report_routing([['id' => null]], []), 'Rows without decisions show a dash.');
	}

	public function testEventsFilterOnRoutingDecisions(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$fields = alarmmanager_report_query_fields('events');
		$this->assertSame('plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id', $fields['decision_reason']['exists']);
		$this->assertContains('cooldown', $fields['decision_reason']['choices']);
		$this->assertArrayNotHasKey('decision_reason', alarmmanager_report_query_fields('dashboard'));

		$compiled = AlarmManager\QueryBuilder::compile(json_encode(['version' => 1, 'conditions' => [['field' => 'decision_reason', 'operator' => '=', 'value' => 'no_route'], ['join' => 'AND', 'field' => 'destination_id', 'operator' => '=', 'value' => '7']]]), $fields);
		$this->assertStringContainsString('EXISTS (SELECT 1 FROM plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id AND (r.reason = ?))', $compiled['sql']);
		$this->assertStringContainsString('EXISTS (SELECT 1 FROM plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id AND (r.endpoint_id = ?))', $compiled['sql']);
		$this->assertSame(['no_route', '7'], $compiled['params']);

		$sql                                        = '';
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($query, $params) use (&$sql) {
			if ($sql === '') {
				$sql = $query;
				\PHPUnit\Framework\Assert::assertContains('cooldown', $params);
			}

			return [];
		};
		alarmmanager_report_list('events', ['device_sql' => '1 = 1'], alarmmanager_report_filters(['reason' => 'cooldown', 'collector_id' => 1]));
		$this->assertStringContainsString('EXISTS (SELECT 1 FROM plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id AND r.reason = ?)', $sql, 'Dashboard suppression links filter Events by reason');
	}

	public function testMirroredEventsApplyTheRoutingFilters(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$this->assertSame(['', []], alarmmanager_report_remote_routing(alarmmanager_report_filters([])), 'No routing filter leaves mirrored events untouched.');

		$filters       = alarmmanager_report_filters(['reason' => 'cooldown']);
		[$sql, $bound] = alarmmanager_report_remote_routing($filters);
		$this->assertStringContainsString("x.entity = 'decision'", $sql);
		$this->assertSame([$filters['from'], 'cooldown'], $bound);

		$filters       = alarmmanager_report_filters(['status' => 'failed', 'endpoint_id' => 7]);
		[$sql, $bound] = alarmmanager_report_remote_routing($filters);
		$this->assertStringContainsString("x.entity = 'delivery'", $sql, 'Deliveries are the default job.');
		$this->assertSame([$filters['from'], 'failed', 7], $bound);

		$filters       = alarmmanager_report_filters(['job' => 'attempt', 'status' => 'retry', 'endpoint_id' => 7]);
		[$sql, $bound] = alarmmanager_report_remote_routing($filters);
		$this->assertStringContainsString("x.entity = 'attempt'", $sql);
		$this->assertStringContainsString("d.entity = 'delivery'", $sql, 'Attempts reach their event through the mirrored delivery.');
		$this->assertSame([$filters['from'], 'retry', 7], $bound);

		$filters       = alarmmanager_report_filters(['job' => 'processing', 'status' => 'pending', 'endpoint_id' => 7]);
		[$sql, $bound] = alarmmanager_report_remote_routing($filters);
		$this->assertStringContainsString("x.entity = 'processing'", $sql);
		$this->assertSame([$filters['from'], 'pending'], $bound, 'Processing jobs have no destination.');

		$captured                                   = [];
		$GLOBALS['alarmmanager_test_query_handler'] = static function (string $query, array $params) use (&$captured): array {
			$captured[] = [$query, $params];

			return [];
		};
		$filters = alarmmanager_report_filters(['reason' => 'cooldown', 'status' => 'failed']);
		alarmmanager_report_list('events', ['device_sql' => '1 = 1'], $filters);
		unset($GLOBALS['alarmmanager_test_query_handler']);
		[$query, $params] = current(array_filter($captured, static fn (array $call): bool => str_contains($call[0], 'UNION ALL')));
		$this->assertStringContainsString("x.entity = 'decision'", $query, 'The mirrored arm carries the routing filters.');
		$this->assertSame(substr_count($query, '?'), count($params), 'Every placeholder is bound exactly once.');
		$this->assertSame([$filters['from'], 'cooldown', $filters['from'], 'failed'], array_slice($params, -4), 'Routing parameters follow the mirrored time window.');
	}

	public function testConfigurationVersionMustBeOne(): void {
		self::alarmmanager_test_load('includes/routing.php');

		foreach ([2, 0, '1', null] as $version) {
			try {
				alarmmanager_configuration(['version' => $version, 'endpoints' => [], 'rules' => []]);
				$this->fail('Unsupported configuration version accepted: ' . json_encode($version));
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_configuration', $error->getMessage());
			}
		}
		$this->assertSame(['version' => 1, 'endpoints' => [], 'rules' => []], alarmmanager_configuration(['version' => 1, 'endpoints' => [], 'rules' => []]));
	}

	public function testRetiredTablesAreDroppedAtSchemaFourteen(): void {
		self::alarmmanager_test_load('includes/schema.php');
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql) use (&$writes) {
			$writes[] = $sql;

			return true;
		};
		$this->assertTrue(alarmmanager_schema_retired());
		$this->assertSame(['DROP TABLE IF EXISTS plugin_alarmmanager_api_clients, plugin_alarmmanager_audit, plugin_alarmmanager_sync_batches', 'UPDATE plugin_alarmmanager_schema SET version = 14 WHERE id = 1'], $writes);
		$GLOBALS['alarmmanager_test_execute_handler'] = static fn () => false;
		$this->assertFalse(alarmmanager_schema_retired(), 'A failed drop leaves the schema version unchanged');
	}
}
