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
final class RuleEditorTest extends AlarmManagerTestCase {
	public function testDeviceSummaryConfiguration(): void {
		self::alarmmanager_test_load('includes/routing.php');
		$this->assertSame(['offline' => 'Device Offline', 'recovering' => 'Device Recovering', 'online' => 'Device Online'], alarmmanager_configuration(['version' => 1])['device_summaries']);
		$this->assertSame(['offline' => 'Device is Offline', 'recovering' => 'Device is Recovering', 'online' => 'Device is Online'], alarmmanager_configuration(['version' => 1, 'device_summaries' => ['offline' => ' Device is Offline ', 'recovering' => 'Device is Recovering', 'online' => 'Device is Online']])['device_summaries']);

		foreach (['', '  ', str_repeat('x', 513), "line\nbreak", ['invalid']] as $invalid) {
			try {
				alarmmanager_configuration(['version' => 1, 'device_summaries' => ['offline' => 'Device Offline', 'recovering' => $invalid, 'online' => 'Device Online']]);
				$this->fail('Invalid summary accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_device_summaries', $error->getMessage());
			}
		}
	}

	public function testGuidedFieldsProduceTheExistingRulePolicy(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$policy = alarmmanager_admin_rule_policy([
			'rule_fields'                  => 'yes', 'filter_sources' => 'yes', 'match_sources' => ['syslog', 'thold'],
			'filter_severities'            => 'yes', 'match_severities' => ['critical', 'major'],
			'filter_transitions'           => 'yes', 'match_transitions' => ['raised', 'recovered'],
			'filter_device_ids'            => 'yes', 'match_device_ids' => ['42'],
			'filter_collector_ids'         => 'yes', 'match_collector_ids' => ['2'],
			'filter_tags'                  => 'yes', 'match_tags' => '["network","production"]',
			'suppress_acknowledged'        => 'yes', 'cooldown_seconds' => '60', 'duplicate_seconds' => '120',
			'schedule_enabled'             => 'yes', 'schedule_days' => ['1', '5'], 'schedule_timezone' => 'America/Toronto', 'schedule_start' => '22:00', 'schedule_end' => '06:00',
			'maintenance_schedule_enabled' => 'yes', 'maintenance_schedule_days' => ['7'], 'maintenance_schedule_timezone' => 'UTC', 'maintenance_schedule_start' => '00:00', 'maintenance_schedule_end' => '00:00'
		]);
		$this->assertSame(['syslog', 'thold'], $policy['match']['sources']);
		$this->assertSame([42], $policy['match']['device_ids']);
		$this->assertSame([2], $policy['match']['collector_ids']);
		$this->assertSame(['network', 'production'], $policy['match']['tags']);
		$this->assertSame(60, $policy['cooldown_seconds']);
		$this->assertSame(120, $policy['duplicate_seconds']);
		$this->assertTrue($policy['suppress_acknowledged']);
		$this->assertSame([1, 5], $policy['schedule']['days']);
		$this->assertTrue(alarmmanager_schedule_matches($policy['schedule'], '2026-10-17T03:00:00Z'));
		$this->assertTrue(alarmmanager_schedule_matches($policy['maintenance_schedule'], '2026-10-18T15:00:00Z'));
		$this->assertSame($policy, alarmmanager_admin_rule_policy(['policy_json' => json_encode($policy)]));
	}

	public function testDisabledFiltersAndSchedulesDoNotRestrictRules(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$policy = alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'match_sources' => 'ignored', 'schedule_days' => ['invalid']]);
		$this->assertSame([], $policy['match']);
		$this->assertNull($policy['schedule']);
		$this->assertNull($policy['maintenance_schedule']);
		$this->assertFalse($policy['suppress_acknowledged']);
		$this->assertSame(['severities' => []], alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'filter_severities' => 'yes'])['match'], 'An existing empty selector must keep matching nothing');
	}

	public function testDeviceQueryIsValidatedAndPreserved(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$query = json_encode(['version' => 1, 'conditions' => [
			['field' => 'location', 'operator' => '=', 'value' => 'hello'],
			['join' => 'AND', 'field' => 'poller_id', 'operator' => '=', 'value' => '1'],
			['join' => 'OR', 'rows' => [['field' => 'description', 'operator' => 'contains', 'value' => 'test']]]
		]], JSON_THROW_ON_ERROR);
		$policy = alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'device_query' => $query]);
		$this->assertSame($query, $policy['device_query']);
		$compiled = AlarmManager\QueryBuilder::compile($query, alarmmanager_rule_device_fields());
		$this->assertSame(['hello', '1', '%test%'], $compiled['params']);
		$this->assertStringContainsString('h.poller_id = ?', $compiled['sql']);
		$this->assertStringContainsString('OR (', $compiled['sql']);

		foreach ([['device_query' => []], ['device_query' => '{'], ['device_query' => str_repeat('x', 8193)], ['device_query' => str_replace('location', 'snmp_password', $query)]] as $input) {
			try {
				alarmmanager_admin_rule_policy(['rule_fields' => 'yes'] + $input);
				$this->fail('Invalid device condition accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertNotSame('', $error->getMessage());
			}
		}
	}

	public function testRejectsInvalidGuidedPolicyFields(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$cases = [
			['filter_sources' => 'yes', 'match_sources' => 'syslog'],
			['filter_tags' => 'yes', 'match_tags' => str_repeat('x', 8193)],
			['filter_device_ids' => 'yes', 'match_device_ids' => ['42 OR 1=1']],
			['filter_severities' => 'yes', 'match_severities' => ['invalid']],
			['filter_transitions' => 'yes', 'match_transitions' => ['raised' => 'raised']],
			['filter_severities' => 'yes', 'match_severities' => array_fill(0, 33, 'major')],
			['schedule_enabled' => 'yes', 'schedule_days' => []],
			['schedule_enabled' => 'yes', 'schedule_days' => ['8']],
			['schedule_enabled' => 'yes', 'schedule_days' => ['1'], 'schedule_timezone' => 'Invalid/Zone', 'schedule_start' => '09:00', 'schedule_end' => '17:00'],
			['cooldown_seconds' => '-1'], ['duplicate_seconds' => '86401']
		];

		foreach ($cases as $case) {
			try {
				alarmmanager_admin_rule_policy(['rule_fields' => 'yes'] + $case);
				$this->fail('Invalid rule fields were accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertNotSame('', $error->getMessage());
			}
		}
	}
}
