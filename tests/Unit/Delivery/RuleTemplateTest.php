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

	public function testMalformedOverridesAreRejected(): void {
		self::alarmmanager_test_load('includes/admin.php');

		foreach ([['template_enabled' => '2'], ['template_enabled' => ['destination' => '2']], ['template_enabled' => array_fill(0, 33, '2')], ['template_mapping' => 'invalid']] as $input) {
			try {
				alarmmanager_admin_rule_policy(['rule_fields' => 'yes'] + $input);
				$this->fail('Malformed override form must be rejected.');
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_mapping', $error->getMessage());
			}
		}

		foreach (['invalid', array_fill(1, 33, [])] as $overrides) {
			try {
				alarmmanager_validate_rule_definition(['template_overrides' => $overrides]);
				$this->fail('Malformed override policy must be rejected.');
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_mapping', $error->getMessage());
			}
		}
	}

	public function testRuleEditorSeparatesCustomTemplatesFromDestinationDefaults(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'unit-test';
			}
		}
		$default       = ['default' => [['to' => 'text', 'constant' => 'Destination default']]];
		$custom        = ['default' => [['to' => 'text', 'constant' => 'Rule override']]];
		$configuration = ['endpoints' => [
			['id' => 1, 'name' => 'Selected', 'enabled' => true, 'type' => 'webhook', 'transport' => ['mapping' => $default]],
			['id' => 2, 'name' => 'Unselected', 'enabled' => false, 'type' => 'webhook', 'transport' => ['mapping' => $default]]
		]];
		ob_start();

		try {
			alarmmanager_rule_editor(['id' => 0, 'name' => 'Admin', 'enabled' => true, 'priority' => 1, 'endpoints' => [1], 'policy' => ['template_overrides' => [1 => $custom]]], 1, $configuration, ['sources' => ['cacti' => 'cacti']]);
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertStringContainsString('name="template_enabled[]" value="1" checked', $html);
		$this->assertStringContainsString('<details data-rule-template="2" hidden>', $html);
		$this->assertStringContainsString('name="template_mapping[1]"', $html);
		$this->assertStringContainsString('Rule override', $html);
		$this->assertStringContainsString('Destination default', $html);
		$this->assertStringContainsString('Cacti_Admin_Notifications', $html);
		$this->assertSame($default, $configuration['endpoints'][0]['transport']['mapping']);
	}

	public function testDeliveryUsesFirstMatchingRuleTemplateAndTestsUseDefault(): void {
		self::alarmmanager_test_load('includes/delivery.php');
		$event                                        = json_decode(file_get_contents(dirname(__DIR__, 3) . '/examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
		$custom                                       = ['default' => [['to' => 'text', 'constant' => 'First matching rule']]];
		$policy                                       = alarmmanager_validate_rule_definition(['template_overrides' => [2 => $custom]]);
		$custom                                       = $policy['template_overrides'][2];
		$claim                                        = ['id' => 1, 'event_row_id' => 1, 'lease_token' => 'test-lease', 'idempotency_key' => 'test-key', 'endpoint' => ['id' => 2, 'type' => 'webhook', 'timeout_seconds' => 5, 'max_attempts' => 3]];
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn ($sql) => str_contains($sql, 'config_revision') ? 1 : 0;
		$completed                                    = [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$completed): bool {
			if (str_starts_with($sql, 'UPDATE plugin_alarmmanager_deliveries')) {
				$completed[] = $params[0];
			}

			return true;
		};
		$seen                                       = [];
		$GLOBALS['alarmmanager_transport_handlers'] = ['webhook' => static function ($event, $endpoint) use (&$seen): array {
			$seen[] = $endpoint['mapping_override'] ?? null;

			return ['outcome' => 'success', 'code' => 'test_success'];
		}];

		try {
			foreach ([false, true] as $explicit) {
				$GLOBALS['alarmmanager_route_cache'] = ['revision' => 1, 'rules' => [
					['endpoints' => [2], 'policy' => $policy],
					['endpoints' => [2], 'policy' => alarmmanager_validate_rule_definition([])]
				]];
				$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) use ($event, $explicit): array {
					if (str_contains($sql, 'SELECT payload')) {
						return [['payload' => json_encode($event)]];
					}

					if (str_contains($sql, 'explicit_test_send')) {
						return $explicit ? [['id' => 1]] : [];
					}

					return [['id' => 1, 'attempts' => 1]];
				};
				$this->assertTrue(alarmmanager_run_delivery($claim));
			}
			$this->assertSame([$custom, null], $seen, 'Explicit sends bypass rule overrides.');
			$this->assertSame(['completed', 'completed'], $completed);
		} finally {
			unset($GLOBALS['alarmmanager_route_cache'], $GLOBALS['alarmmanager_transport_handlers']);
		}
	}
}
