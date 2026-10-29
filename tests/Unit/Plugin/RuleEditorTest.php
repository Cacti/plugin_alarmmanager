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
	public function testEndpointTestingIsInTheEditor(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'unit-test';
			}
		}
		ob_start();

		try {
			alarmmanager_endpoint_editor(['id' => 7, 'name' => 'Destination', 'type' => 'webhook', 'enabled' => true, 'transport' => alarmmanager_integration_templates()['teams']['transport']], 1);
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertStringContainsString('readonly', $html);
		$this->assertStringContainsString('Workflows', $html);
		$this->assertStringContainsString('name="test_destination" value="yes"', $html);
		$this->assertStringNotContainsString('section=test', $html);
		$this->assertStringContainsString('Test destination saves the current settings', $html);
		$this->assertLessThan(strpos($html, 'name="test_destination"'), strpos($html, '>Save endpoint</button>'), 'Implicit form submission must default to saving, not sending a test.');
		$this->assertArrayNotHasKey('test', alarmmanager_admin_sections());
	}

	public function testSharedDaemonSettingsValidation(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$settings = alarmmanager_admin_supervisor_settings(['processing_workers' => '3', 'delivery_workers' => '0']);
		$this->assertFalse($settings['enabled']);
		$this->assertSame(3, $settings['processing_workers']);
		$this->assertSame(0, $settings['delivery_workers']);
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_admin_supervisor_settings(['processing_workers' => '65', 'delivery_workers' => '4']);
	}

	public function testConfigurationCardsSaveIndependentlyWithFileImport(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'unit-test';
			}
		}
		ob_start();

		try {
			alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [], 'rules' => []]], 'configuration');
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$document = new DOMDocument();
		// libxml's HTML4 parser reports HTML5 elements such as <section>; collect those instead of raising warnings.
		$errors = libxml_use_internal_errors(true);
		$document->loadHTML($html);
		libxml_clear_errors();
		libxml_use_internal_errors($errors);
		$xpath = new DOMXPath($document);

		$this->assertSame(0, $xpath->query('//form[input[@value="settings_save"]]')->length, 'Settings moved to Cacti Settings.');

		foreach (['offline_summary', 'recovering_summary', 'online_summary', 'daemon_enabled', 'processing_workers', 'delivery_workers'] as $field) {
			$this->assertSame(0, $xpath->query('//input[@name="' . $field . '"]')->length, "$field belongs on the Cacti Settings tab.");
		}
		$this->assertStringNotContainsString('settings.php?tab=alarmmanager', $html, 'The configuration screen no longer points at Settings.');
		$this->assertSame(1, $xpath->query('//section[@class="alarmmanagerSettingsCard"]')->length, 'Only the backup and restore card remains.');
		$this->assertStringNotContainsString('section=daemon', $html);
		$this->assertStringNotContainsString('Administration sections', $html);
		$this->assertStringNotContainsString('class="tabs"', $html);
		$this->assertStringNotContainsString('name="configuration_json"', $html);
		$this->assertSame(1, $xpath->query('//form[@enctype="multipart/form-data"]//input[@type="file" and @name="configuration_file"]')->length);
		$this->assertStringContainsString('download=configuration', $html);
	}

	public function testConfigurationUploadRejectsMissingFailedAndLocalFiles(): void {
		self::alarmmanager_test_load('includes/admin.php');

		foreach ([[], ['error' => UPLOAD_ERR_INI_SIZE], ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__], ['error' => UPLOAD_ERR_OK, 'tmp_name' => []]] as $file) {
			try {
				alarmmanager_admin_configuration_upload($file);
				$this->fail('Invalid upload accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_configuration_upload', $error->getMessage());
			}
		}
	}

	public function testLogLinesNameTheUserNotTheId(): void {
		self::alarmmanager_test_load('includes/admin.php');

		if (!function_exists('get_username')) {
			function get_username($user_id) {
				return $user_id === 7 ? 'sean' : '';
			}
		}
		$before                = $_SESSION['sess_user_id'] ?? null;
		$_SESSION['sess_user_id'] = 7;

		try {
			$this->assertSame('sean', alarmmanager_web_username(), 'Logs must name the signed-in user.');
		} finally {
			$_SESSION['sess_user_id'] = $before;
		}
		$this->assertStringNotContainsString('alarmmanager_web_user()', self::alarmmanager_test_read_source('includes/admin.php'), 'No log line may print the numeric user ID.');
	}

	public function testImportIgnoresDeviceSummariesFromOlderBackups(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$backup = ['version' => 1, 'device_summaries' => ['offline' => 'Device is Offline', 'recovering' => 'Recovering', 'online' => 'Online'], 'endpoints' => [], 'rules' => []];

		$this->assertSame(['version' => 1, 'endpoints' => [], 'rules' => []], alarmmanager_configuration(alarmmanager_admin_configuration_import($backup)), 'An older backup must import without its retired device summaries.');
		$this->assertSame(['version' => 1], alarmmanager_admin_configuration_import(['version' => 1, 'event_log' => ['enabled' => true, 'target' => 'cacti_log']]), 'Event log settings from other builds are ignored.');
		$this->assertStringContainsString('does not recognize', alarmmanager_admin_error_message(new InvalidArgumentException('Unknown event fields.')), 'An unknown field gets an actionable message.');
		$this->assertSame(['version' => 1], alarmmanager_admin_configuration_import(['version' => 1]), 'A current backup passes through unchanged.');
	}

	public function testDeviceSummariesComeFromCactiSettings(): void {
		self::alarmmanager_test_load('includes/routing.php');
		$before = $GLOBALS['alarmmanager_test_config_options'];

		try {
			$GLOBALS['alarmmanager_test_config_options'] = [];
			$this->assertSame(['offline' => 'Device Offline', 'recovering' => 'Device Recovering', 'online' => 'Device Online'], alarmmanager_device_summaries());
			$GLOBALS['alarmmanager_test_config_options'] = ['alarmmanager_summary_offline' => ' Device is Offline ', 'alarmmanager_summary_recovering' => "line\nbreak", 'alarmmanager_summary_online' => str_repeat('x', 513)];
			$this->assertSame(['offline' => 'Device is Offline', 'recovering' => 'Device Recovering', 'online' => 'Device Online'], alarmmanager_device_summaries(), 'Invalid summaries fall back to defaults.');
		} finally {
			$GLOBALS['alarmmanager_test_config_options'] = $before;
		}
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_configuration(['version' => 1, 'device_summaries' => ['offline' => 'x']]);
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
		$this->assertSame('', alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'all_devices' => 'yes', 'device_query' => 'ignored conditions'])['device_query']);
		$this->assertSame([], $policy['match']);
		$this->assertNull($policy['schedule']);
		$this->assertNull($policy['maintenance_schedule']);
		$this->assertFalse($policy['suppress_acknowledged']);
		$this->assertSame(['severities' => []], alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'filter_severities' => 'yes'])['match'], 'An existing empty selector must keep matching nothing');
	}

	public function testAlarmQueryAndAllAlarmTypes(): void {
		self::alarmmanager_test_load('includes/admin_view.php');
		$query = json_encode(['version' => 1, 'conditions' => [['field' => 'severity', 'operator' => '=', 'value' => 'major'], ['join' => 'AND', 'negative' => true, 'field' => 'summary', 'operator' => 'contains', 'value' => 'maintenance']]], JSON_THROW_ON_ERROR);
		$this->assertSame($query, alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'alarm_query' => $query])['alarm_query']);
		$all = alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'all_alarm_types' => 'yes', 'alarm_query' => 'ignored', 'filter_sources' => 'yes', 'match_sources' => ['syslog']]);
		$this->assertSame('', $all['alarm_query']);
		$this->assertSame([], $all['match']);
		$converted = alarmmanager_rule_alarm_query(alarmmanager_validate_rule_definition(['match' => ['sources' => ['syslog', 'thold'], 'tags' => ['core', 'network']]]));
		$compiled  = AlarmManager\QueryBuilder::compile($converted, alarmmanager_rule_alarm_fields());
		$this->assertSame(['syslog', 'thold', 'core', 'network'], $compiled['params']);
		$this->assertStringContainsString(' OR ', $compiled['sql']);
		$this->assertStringContainsString('JSON_CONTAINS', $compiled['sql']);
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_validate_rule_definition(['alarm_query' => json_encode(['version' => 1, 'conditions' => [['field' => 'unknown', 'operator' => '=', 'value' => 'bad']]])]);
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
