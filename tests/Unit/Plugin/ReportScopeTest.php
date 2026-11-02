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

final class ReportScopeTest extends AlarmManagerTestCase {
	private const MARKER = '/*VIEWER_SCOPE*/';

	/** @return array{string, list<mixed>} The UNION query a report issues and its parameters. */
	private function unionQuery(string $kind, array $scope, array $input): array {
		$captured                                   = [];
		$GLOBALS['alarmmanager_test_query_handler'] = static function (string $sql, array $params) use (&$captured): array {
			$captured[] = [$sql, $params];

			return [];
		};

		try {
			alarmmanager_report_list($kind, $scope, alarmmanager_report_filters(['tab' => $kind] + $input));
		} finally {
			unset($GLOBALS['alarmmanager_test_query_handler']);
		}
		$union = array_values(array_filter($captured, static fn (array $call): bool => str_contains($call[0], 'UNION ALL')));
		$this->assertCount(1, $union, 'The default collector view mirrors remote history.');

		return $union[0];
	}

	private function viewer(): array {
		return ['admin' => false, 'device_sql' => 'e.device_id IS NOT NULL AND EXISTS (SELECT 1 FROM host AS h WHERE h.id = e.device_id AND h.id IN (5) ' . self::MARKER . ')'];
	}

	public function testMirroredCollectorRowsHonourTheViewerScope(): void {
		self::alarmmanager_test_load('includes/reports.php');

		foreach (['events', 'conditions'] as $kind) {
			[$sql, $params]   = $this->unionQuery($kind, $this->viewer(), ['severity' => 'critical', 'device_id' => 5, 'source' => 'thold']);
			[$local, $mirror] = explode(' UNION ALL ', $sql, 2);

			$this->assertSame(1, substr_count($local, self::MARKER), "Local $kind rows are scoped.");
			$this->assertSame(1, substr_count($mirror, self::MARKER), "Mirrored collector $kind rows are scoped by the same device policy.");

			foreach (['e.device_id = ?', 'e.severity = ?', 'e.source = ?'] as $predicate) {
				$this->assertStringContainsString($predicate, $mirror, "Mirrored $kind rows honour $predicate.");
			}
			$this->assertSame(substr_count($sql, '?'), count($params), "Every $kind placeholder is bound exactly once.");
		}
	}

	public function testAdministratorsStayUnrestrictedOnMirroredRows(): void {
		self::alarmmanager_test_load('includes/reports.php');
		[$sql] = $this->unionQuery('events', ['admin' => true, 'device_sql' => '1 = 1'], []);
		$this->assertStringNotContainsString(self::MARKER, $sql);
		$this->assertStringContainsString('(1 = 1)', explode(' UNION ALL ', $sql, 2)[1]);
	}

	public function testSearchConditionsReachMirroredRows(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$query = static fn (string $field, string $value): string => json_encode(['version' => 1, 'conditions' => [['field' => $field, 'operator' => '=', 'value' => $value]]]);

		[$sql, $params] = $this->unionQuery('events', $this->viewer(), ['query' => $query('decision_reason', 'cooldown')]);
		$mirror         = explode(' UNION ALL ', $sql, 2)[1];
		$this->assertStringContainsString("r.entity = 'decision' AND r.poller_id = e.collector_id", $mirror, 'A routing decision condition is matched against the mirrored decisions.');
		$this->assertSame(substr_count($sql, '?'), count($params));

		[$sql, $params] = $this->unionQuery('events', $this->viewer(), ['query' => json_encode(['version' => 1, 'conditions' => [['field' => 'summary', 'operator' => 'contains', 'value' => 'disk']]])]);
		$this->assertStringContainsString("JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.summary'))", explode(' UNION ALL ', $sql, 2)[1]);
		$this->assertSame(substr_count($sql, '?'), count($params));

		[$sql, $params] = $this->unionQuery('conditions', $this->viewer(), ['query' => $query('acknowledged', '1')]);
		$this->assertStringContainsString('0 = ?', explode(' UNION ALL ', $sql, 2)[1], 'Mirrored conditions are never acknowledged, so the field must not reference the local states table.');
		$this->assertSame(substr_count($sql, '?'), count($params));
	}
}
