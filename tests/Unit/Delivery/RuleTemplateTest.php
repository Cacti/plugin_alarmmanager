<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class RuleTemplateTest extends AlarmManagerTestCase {
	public function testRuleFormKeepsOverridesOnTheRuleAndDropsUncheckedOnes(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$mapping = ['default' => [['to' => 'text', 'source' => 'alarm.summary']]];
		$form    = ['rule_fields' => 'yes', 'template_enabled' => ['2'], 'template_mapping' => [1 => 'invalid but unchecked', 2 => json_encode($mapping)]];
		$policy  = alarmmanager_admin_rule_policy($form);
		$this->assertSame(alarmmanager_mapping_validate($mapping), $policy['template_overrides'][2]);
		$this->assertArrayNotHasKey(1, $policy['template_overrides']);
		$this->assertSame($policy, alarmmanager_validate_rule_definition(json_decode(json_encode($policy), true)));
		unset($form['template_enabled']);
		$this->assertArrayNotHasKey('template_overrides', alarmmanager_admin_rule_policy($form));
	}

	public function testDestinationDefaultsAreUnchangedByRuleOverride(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$settings                                   = ['url' => 'https://receiver.example.test', 'mapping' => ['default' => [['to' => 'text', 'constant' => 'Default location and contact']]]];
		$GLOBALS['alarmmanager_test_query_handler'] = static fn () => [['definition' => json_encode($settings)]];
		$endpoint                                   = ['id' => 2, 'type' => 'webhook'];
		$custom                                     = ['default' => [['to' => 'text', 'constant' => 'Admin notification without device fields']]];
		$this->assertSame('Admin notification without device fields', alarmmanager_map_payload(alarmmanager_endpoint_transport($endpoint + ['mapping_override' => $custom])['mapping'], ['transition' => 'raised'])->text);
		$this->assertSame('Default location and contact', alarmmanager_map_payload(alarmmanager_endpoint_transport($endpoint)['mapping'], ['transition' => 'raised'])->text);
	}

	public function testOverrideMustReferToASelectedDestination(): void {
		self::alarmmanager_test_load('includes/routing.php');
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_configuration(['version' => 1, 'endpoints' => [['id' => 1, 'name' => 'Shared', 'type' => 'email', 'transport' => ['to' => 'ops@example.test']]], 'rules' => [['id' => 1, 'name' => 'Admin', 'endpoints' => [1], 'policy' => ['template_overrides' => [2 => ['default' => []]]]]]]);
	}
}
