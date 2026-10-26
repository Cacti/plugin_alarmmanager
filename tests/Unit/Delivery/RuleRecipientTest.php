<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class RuleRecipientTest extends AlarmManagerTestCase {
	private function reject(callable $call, string $code): void {
		try {
			$call();
			$this->fail('Expected ' . $code);
		} catch (InvalidArgumentException $error) {
			$this->assertSame($code, $error->getMessage());
		}
	}
	public function testRecipientListsValidateDeduplicateAndRoundTrip(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$numbers = ['+15551234567', '+15557654321'];
		$this->assertSame($numbers, alarmmanager_twilio_recipients([' +15551234567 ', 'whatsapp:+15557654321', '+15551234567']));
		$policy = alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'endpoints' => ['9'], 'recipient_numbers' => [9 => "+15551234567, +15557654321\n+15551234567", 10 => '+15550000000']]);
		$this->assertSame([9 => $numbers], $policy['recipient_overrides']);
		$this->assertArrayNotHasKey('recipient_overrides', alarmmanager_admin_rule_policy(['rule_fields' => 'yes', 'endpoints' => ['9'], 'recipient_numbers' => [9 => '']]));
		$this->assertArrayNotHasKey('recipient_overrides', alarmmanager_validate_rule_definition(['recipient_overrides' => [9 => []]]));
		$config = ['version' => 1, 'endpoints' => [['id' => 9, 'name' => 'SMS', 'type' => 'restapi', 'transport' => alarmmanager_integration_templates()['twilio']['transport'] + ['provider' => 'twilio']]], 'rules' => [['id' => 1, 'name' => 'Rule', 'endpoints' => [9], 'policy' => $policy]]];
		$stored = alarmmanager_configuration($config);
		$this->assertSame($stored, alarmmanager_configuration(json_decode(json_encode($config), true)));
		$config['endpoints'][0]['transport'] = alarmmanager_integration_templates()['slack']['transport'] + ['provider' => 'slack'];
		$this->reject(fn () => alarmmanager_configuration($config), 'recipient_destination_invalid');
		$config['rules'][0]['endpoints'] = [1];
		$this->reject(fn () => alarmmanager_configuration($config), 'recipient_destination_invalid');

		foreach (['bad', null, [1 => '+15551234567'], ['5551234567'], ['+01234'], [123], [str_repeat('1', 40)], array_fill(0, 51, '+15551234567')] as $bad) {
			$this->reject(fn () => alarmmanager_twilio_recipients($bad), 'invalid_twilio_recipients');
		}

		foreach (['bad', array_fill(1, 33, [])] as $bad) {
			$this->reject(fn () => alarmmanager_validate_rule_definition(['recipient_overrides' => $bad]), 'invalid_twilio_recipients');
		}

		foreach ([['recipient_numbers' => 'bad'], ['recipient_numbers' => array_fill(1, 33, '')], ['recipient_numbers' => [9 => []]], ['recipient_numbers' => [9 => str_repeat('1', 2049)]], ['endpoints' => '9'], ['endpoints' => [1 => 9]]] as $bad) {
			$this->reject(fn () => alarmmanager_admin_rule_policy(['rule_fields' => 'yes'] + $bad), 'invalid_twilio_recipients');
		}
	}
	public function testRecipientMigrationHandlesOldNewAndUnavailableIndexes(): void {
		self::alarmmanager_test_load('includes/schema.php');

		foreach ([[1, 0, true], [0, 1, true], [0, 0, true], [false, 0, false], [0, false, false]] as [$old, $new, $expected]) {
			$GLOBALS['alarmmanager_test_cell_handler'] = static fn ($sql) => str_contains($sql, 'STATISTICS') ? (str_contains($sql, "INDEX_NAME = 'event_endpoint'") ? $old : $new) : 1;
			$this->assertSame($expected, alarmmanager_schema_recipients());
		}
	}	public function testRoutingQueuesOneStableDeliveryPerRecipientAndDefaultIsUnchanged(): void {
		self::alarmmanager_test_load('includes/processor.php');
		$event                                        = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true);
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn ($sql) => str_contains($sql, 'UTC_TIMESTAMP') ? '2026-10-26 21:00:00.000000' : 1;
		$GLOBALS['alarmmanager_test_query_handler']   = static fn ($sql) => str_contains($sql, 'notification_state') ? [['episode_id' => '', 'fingerprint' => '', 'queued_at' => '1970-01-01 00:00:00']] : [['enabled' => 1]];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$writes) {
			if (str_contains($sql, 'INSERT INTO plugin_alarmmanager_deliveries')) {
				$writes[] = $params;
			}

			return true;
		};

		try {
			foreach ([['+15551234567', '+15557654321'], null] as $numbers) {
				$policy                              = alarmmanager_validate_rule_definition($numbers === null ? [] : ['recipient_overrides' => [9 => $numbers]]);
				$GLOBALS['alarmmanager_route_cache'] = ['revision' => 1, 'rules' => [['id' => 1, 'enabled' => 1, 'endpoints' => [9], 'stop_processing' => 1, 'policy' => $policy]], 'endpoints' => [9 => ['id' => 9, 'enabled' => 1]]];
				alarmmanager_route_event(['id' => 1, 'mode' => 'centralized', 'stream_key' => str_repeat('a', 64)], $event, ['acknowledged' => false], 'applied');
			}
			$this->assertSame(['+15551234567', '+15557654321', ''], array_column($writes, 6));
			$this->assertNotSame($writes[0][5], $writes[1][5]);
			$this->assertSame(hash('sha256', implode('|', [$event['source'], $event['source_instance'], $event['event_id'], 'centralized', '9'])), $writes[2][5]);
		} finally {
			unset($GLOBALS['alarmmanager_route_cache']);
		}
	}

	public function testDeliveryRejectsRemovedRecipientAndPassesAllowedRecipientToTransport(): void {
		self::alarmmanager_test_load('includes/delivery.php');
		$event                                        = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true);
		$seen                                         = [];
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn ($sql) => str_contains($sql, 'config_revision') ? 1 : 0;
		$GLOBALS['alarmmanager_test_query_handler']   = static fn ($sql) => str_contains($sql, 'SELECT payload') ? [['payload' => json_encode($event)]] : (str_contains($sql, 'explicit_test_send') ? [] : [['id' => 1, 'attempts' => 1]]);
		$GLOBALS['alarmmanager_test_execute_handler'] = static fn () => true;
		$GLOBALS['alarmmanager_transport_handlers']   = ['restapi' => static function ($event, $endpoint) use (&$seen) {
			$seen[] = $endpoint['recipient'];

			return ['outcome' => 'success', 'code' => 'fixture_ok'];
		}];
		$GLOBALS['alarmmanager_route_cache'] = ['revision' => 1, 'rules' => [['endpoints' => [9], 'policy' => alarmmanager_validate_rule_definition(['recipient_overrides' => [9 => ['+15551234567']]])]]];
		$claim                               = ['id' => 1, 'event_row_id' => 1, 'lease_token' => 'fixture', 'idempotency_key' => 'key', 'endpoint' => ['id' => 9, 'type' => 'restapi', 'timeout_seconds' => 5, 'max_attempts' => 3]];

		try {
			$this->assertTrue(alarmmanager_run_delivery($claim + ['recipient' => '+15551234567']));
			$this->assertTrue(alarmmanager_run_delivery($claim + ['recipient' => '+15557654321']));
			$this->assertSame(['+15551234567'], $seen);
		} finally {
			unset($GLOBALS['alarmmanager_transport_handlers'], $GLOBALS['alarmmanager_route_cache']);
		}
	}

	public function testRecipientOverrideUsesTwilioTransportOnlyWithoutExternalTraffic(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$key                                        = random_bytes(32);
		$cipher                                     = alarmmanager_encrypt_credential(['basic' => ['username' => 'fixture', 'password' => 'secret']], 9, $key);
		$GLOBALS['alarmmanager_test_cell_handler']  = static fn () => base64_encode($key);
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => str_contains($sql, 'plugin_alarmmanager_transports') ? [['definition' => json_encode($GLOBALS['recipient_fixture_transport'])]] : (str_contains($sql, 'SELECT ciphertext') ? [['ciphertext' => $cipher]] : []);
		$event                                      = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true));
		$endpoint                                   = ['id' => 9, 'type' => 'restapi', 'timeout_seconds' => 1, 'recipient' => '+15551234567'];

		try {
			foreach (['twilio', 'twilio_whatsapp'] as $provider) {
				$settings                               = alarmmanager_integration_templates()[$provider]['transport'] + ['provider' => $provider];
				$settings['url']                        = 'https://127.0.0.1:1/closed';
				$GLOBALS['recipient_fixture_transport'] = $settings;
				$this->assertSame('http_network_error', alarmmanager_http_transport($event, $endpoint, 'key')['code']);
			}
			$GLOBALS['recipient_fixture_transport'] = ['url' => 'https://127.0.0.1:1/closed'];
			$this->reject(fn () => alarmmanager_http_transport($event, $endpoint, 'key'), 'recipient_destination_invalid');
		} finally {
			unset($GLOBALS['recipient_fixture_transport']);
		}
	}	public function testRecipientHistorySyncAndQueueClaimsKeepRecipientIdentity(): void {
		self::alarmmanager_test_load('includes/remote.php');
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['config']['poller_id']               = 1;
		$stored                                       = [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$stored) {
			if (str_contains($sql, 'INSERT INTO plugin_alarmmanager_remote_history')) {
				$stored[] = $params;
			}

			return true;
		};
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => str_contains($sql, 'FROM poller') ? [['id' => 3]] : [];
		$data                                       = ['id' => 1, 'event_row_id' => 1, 'endpoint_id' => 9, 'status' => 'completed', 'attempts' => 1, 'last_error' => '', 'idempotency_key' => str_repeat('a', 64), 'created_at' => '2026-10-26 21:00:00', 'updated_at' => '2026-10-26 21:00:00', 'external_reference' => null];

		foreach (['+15551234567', ''] as $recipient) {
			$row = $data;

			if ($recipient !== '') {
				$row['recipient'] = $recipient;
			}
			$packet = ['version' => 1, 'poller_id' => 3, 'instance_uuid' => str_repeat('b', 32), 'receiver_uuid' => str_repeat('a', 32), 'through' => 1, 'records' => [['entity' => 'delivery', 'id' => 1, 'revision' => 1, 'data' => $row]]];
			$this->assertSame(3, json_decode(alarmmanager_sync_receive(json_encode($packet), 3), true)['poller_id']);
			$this->assertSame($recipient, json_decode($stored[array_key_last($stored)][5], true)['recipient']);
		}
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) {
			if (str_contains($sql, 'SELECT ep.id')) {
				return [['id' => 9, 'type' => 'restapi', 'max_attempts' => 5, 'timeout_seconds' => 5, 'min_interval_ms' => 0]];
			}

			if (str_contains($sql, 'SELECT d.id')) {
				return [['id' => 1, 'event_row_id' => 1, 'attempts' => 0, 'idempotency_key' => 'fixture', 'recipient' => '+15551234567']];
			}

			return [['number' => 1]];
		};
		$this->assertSame('+15551234567', alarmmanager_claim_delivery(['restapi'])['recipient']);
	}

	public function testRuleRecipientEditorAndHistoryQueriesExposeRecipients(): void {
		self::alarmmanager_test_load('includes/admin_view.php');
		self::alarmmanager_test_load('includes/reports.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'fixture';
			}
		}
		$endpoint                          = ['id' => 9, 'name' => 'SMS', 'enabled' => true] + alarmmanager_integration_templates()['twilio'];
		$endpoint['transport']['provider'] = 'twilio';
		ob_start();

		try {
			alarmmanager_rule_editor(['id' => 1, 'name' => 'Recipients', 'enabled' => true, 'priority' => 1, 'endpoints' => [9], 'policy' => ['recipient_overrides' => [9 => ['+15551234567']]]], 1, ['endpoints' => [$endpoint]], ['sources' => []]);
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertStringContainsString('name="recipient_numbers[9]"', $html);
		$this->assertStringContainsString('+15551234567', $html);
		$queries                                    = [];
		$event                                      = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true);
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) use (&$queries, $event) {
			$queries[] = $sql;

			return str_contains($sql, 'SELECT LEFT') ? [['payload' => json_encode($event)]] : [];
		};

		foreach (['deliveries', 'attempts'] as $kind) {
			alarmmanager_report_list($kind, ['device_sql' => '1=1'], alarmmanager_report_filters([]));
		}
		$this->assertNotNull(alarmmanager_event_detail(['device_sql' => '1=1'], alarmmanager_report_filters(['event_id' => 1])));
		$this->assertCount(4, array_filter($queries, static fn ($sql) => str_contains($sql, 'd.recipient')));

		foreach (['invalid_twilio_recipients', 'recipient_destination_invalid'] as $code) {
			$this->assertStringNotContainsString('Action failed:', alarmmanager_admin_error_message(new InvalidArgumentException($code)));
		}
	}
}
