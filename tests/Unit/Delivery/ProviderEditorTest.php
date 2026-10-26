<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class ProviderEditorTest extends AlarmManagerTestCase {
	private function reject(callable $call, string $code): void {
		try {
			$call();
			$this->fail('Expected ' . $code);
		} catch (InvalidArgumentException $error) {
			$this->assertSame($code, $error->getMessage());
		}
	}

	public function testProviderFieldsGenerateStandardTransportsAndSurviveRoundTrips(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$sid = 'AC' . str_repeat('a', 32);

		foreach (alarmmanager_provider_definitions() as $provider => $definition) {
			$preset   = alarmmanager_integration_templates()[$provider];
			$input    = ['provider_account_sid' => $sid, 'provider_instance_url' => 'https://customer.service-now.com/'];
			$settings = alarmmanager_provider_transport($provider, $preset['transport'], $input, true);
			$settings = alarmmanager_transport_configuration($preset['type'], $settings);
			$this->assertSame($provider, $settings['provider']);
			$this->assertTrue($settings['credential']);
			$this->assertSame($settings, alarmmanager_transport_configuration($preset['type'], json_decode(json_encode($settings), true)));
			$this->assertSame($provider, alarmmanager_admin_transport_fields($preset['type'], $settings, ['endpoint_fields' => 'yes'])['provider']);

			if (str_starts_with($provider, 'twilio')) {
				$this->assertSame('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json', $settings['url']);
				$this->assertSame($sid, alarmmanager_provider_value($provider, 'account_sid', $settings));
				$this->assertSame('', alarmmanager_provider_value($provider, 'account_sid', []));
			}

			if (str_starts_with($provider, 'servicenow')) {
				$this->assertStringStartsWith('https://customer.service-now.com/api/now/table/', $settings['url']);
				$this->assertSame('https://customer.service-now.com', alarmmanager_provider_value($provider, 'instance_url', $settings));
			}

			if ($provider === 'pagerduty') {
				$this->assertNotContains('routing_key', array_column($settings['mapping']['default'], 'to'));
				$event = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true);
				$this->assertSame('[REDACTED]', alarmmanager_notification_payload($settings, $event)->routing_key);
			}
		}
		$this->assertSame('', alarmmanager_provider_value('', 'instance_url', []));
		$this->assertStringContainsString('REPLACE-WITH-ACCOUNT-SID', alarmmanager_provider_transport('twilio', [], [], false)['url']);
		$this->assertStringContainsString('REPLACE-INSTANCE', alarmmanager_provider_transport('servicenow', [], [], false)['url']);
		$this->reject(fn () => alarmmanager_provider_transport('bad', [], [], false), 'invalid_provider');

		foreach (['', 'ACinvalid', [], 'AC' . str_repeat('a', 31) . '/'] as $bad) {
			$this->reject(fn () => alarmmanager_provider_transport('twilio', [], ['provider_account_sid' => $bad], true), 'invalid_twilio_account');
		}
		$this->reject(fn () => alarmmanager_provider_transport('servicenow', [], ['provider_instance_url' => []], true), 'invalid_provider_instance');
		$this->reject(fn () => alarmmanager_provider_transport('servicenow', [], ['provider_instance_url' => 'https://example.test/api/now'], true), 'invalid_provider_instance');

		foreach (['missing', null, []] as $bad) {
			$this->reject(fn () => alarmmanager_transport_configuration('restapi', ['url' => 'https://example.test', 'provider' => $bad]), 'invalid_provider');
		}
	}

	public function testProviderCredentialsUseExistingEncryptedStorage(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$target          = ['url' => 'https://example.test', 'method' => 'POST'];
		[$url, $payload] = alarmmanager_provider_request(['provider' => 'telegram'], $target, (object) ['text' => 'Alarm'], ['provider_token' => '123:fixture']);
		$this->assertSame('https://api.telegram.org/bot123:fixture/sendMessage', $url['url']);
		$this->assertSame('Alarm', $payload->text);
		[$url, $payload] = alarmmanager_provider_request(['provider' => 'pagerduty'], $target, (object) [], ['provider_token' => 'fixture']);
		$this->assertSame($target, $url);
		$this->assertSame('fixture', $payload->routing_key);
		$key     = random_bytes(32);
		$records = [
			'twilio'          => ['basic' => ['username' => 'SK' . str_repeat('a', 32), 'password' => 'api-secret']],
			'twilio_whatsapp' => ['basic' => ['username' => 'AC' . str_repeat('a', 32), 'password' => 'auth-token']],
			'slack'           => ['headers' => ['Authorization' => 'Bearer xoxb-fixture']],
			'teams'           => ['teams_webhook_url' => 'https://prod.logic.azure.com/workflows/fixture?sig=fixture'],
			'telegram'        => ['provider_token' => '12345:fixture-token'],
			'pagerduty'       => ['provider_token' => str_repeat('a', 32)],
			'servicenow'      => ['basic' => ['username' => 'service', 'password' => 'secret']]
		];

		foreach ($records as $provider => $record) {
			$validated = alarmmanager_credential_validate($record);
			alarmmanager_provider_credentials($provider, $validated);
			$this->assertSame($validated, alarmmanager_decrypt_credential(alarmmanager_encrypt_credential($record, 7, $key), 7, $key));
			$this->reject(fn () => alarmmanager_provider_credentials($provider, null), 'provider_credentials_required');
		}
		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'provider_token'], true));
		$this->assertSame(['headers' => [], 'provider_token' => 'fixture-token'], alarmmanager_admin_credential(['auth_type' => 'provider_token', 'provider_token' => 'fixture-token']));
		$this->reject(fn () => alarmmanager_provider_credentials('twilio', ['basic' => ['username' => 'wrong', 'password' => 'secret']]), 'invalid_twilio_credentials');

		foreach (['', [], "a\nb", 'has space'] as $bad) {
			$this->reject(fn () => alarmmanager_credential_validate(['provider_token' => $bad]), 'invalid_credentials');
		}
		$this->reject(fn () => alarmmanager_credential_validate(['provider_token' => 'key', 'basic' => ['username' => 'user', 'password' => 'secret']]), 'invalid_credentials');
	}
	public function testProviderSaveBuildsUrlAndKeepsIdentityWithoutExposingCredentials(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$GLOBALS['config']['poller_id']              = 1;
		$_SESSION['sess_user_id']                    = 1;
		$_SERVER['REQUEST_METHOD']                   = 'POST';
		$GLOBALS['alarmmanager_test_allowed_realms'] = [1, 101];
		$GLOBALS['alarmmanager_test_config_options'] = ['auth_method' => 1];
		$GLOBALS['alarmmanager_test_csrf_valid']     = true;
		$stored                                      = ['version' => 1, 'endpoints' => [['id' => 7, 'name' => 'Custom', 'type' => 'restapi', 'enabled' => false, 'transport' => ['url' => 'https://example.test']]], 'rules' => []];
		$key                                         = random_bytes(32);
		$cipher                                      = alarmmanager_encrypt_credential(['basic' => ['username' => 'AC' . str_repeat('a', 32), 'password' => 'saved-secret']], 7, $key);
		$writes                                      = [];
		$GLOBALS['alarmmanager_test_cell_handler']   = static fn ($sql) => str_contains($sql, 'settings_user') ? base64_encode($key) : (str_contains($sql, '@@in_transaction') ? 0 : 2);
		$GLOBALS['alarmmanager_test_query_handler']  = static function ($sql) use (&$stored, $cipher) {
			if (str_contains($sql, 'SELECT ciphertext')) {
				return [['ciphertext' => $cipher]];
			}

			if (str_contains($sql, 'SELECT id FROM plugin_alarmmanager_endpoints')) {
				return [['id' => 7]];
			}

			if (str_contains($sql, 'plugin_realms')) {
				return [['id' => 1]];
			}

			if (str_contains($sql, 'configuration_history')) {
				return [['definition' => json_encode($stored)]];
			}

			if (str_contains($sql, 'plugin_alarmmanager_schema')) {
				return [['config_revision' => 1]];
			}

			return [];
		};
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$writes, &$stored) {
			$writes[] = [$sql, $params];

			if (str_contains($sql, 'INSERT INTO plugin_alarmmanager_configuration_history')) {
				foreach ($params as $value) {
					if (is_string($value) && str_starts_with($value, '{')) {
						$stored = json_decode($value, true);
					}
				}
			}

			return true;
		};
		$input = ['action' => 'endpoint_save', 'revision' => 1, 'id' => 7, 'name' => 'SMS', 'type' => 'restapi', 'provider' => 'twilio', 'endpoint_fields' => 'yes', 'auth_type' => 'basic', 'twilio_auth_mode' => 'account', 'provider_account_sid' => 'AC' . str_repeat('a', 32), 'basic_password' => 'fixture-auth-token', 'transport_json' => '{}', 'mapping_json' => json_encode(alarmmanager_integration_templates()['twilio']['transport']['mapping'])];

		try {
			$this->reject(fn () => alarmmanager_admin_action($input), 'confirmation_required');
			$this->reject(fn () => alarmmanager_admin_action(array_replace($input, ['provider_reset' => 'yes', 'auth_type' => 'keep'])), 'provider_credentials_required');
			$this->reject(fn () => alarmmanager_admin_action(array_replace($input, ['provider' => 'bad'])), 'invalid_provider');
			$input['provider_reset'] = 'yes';
			$this->assertSame('Configuration saved.', alarmmanager_admin_action($input));
			$this->assertSame('twilio', $stored['endpoints'][0]['transport']['provider']);
			$this->assertStringContainsString($input['provider_account_sid'], $stored['endpoints'][0]['transport']['url']);
			$this->assertStringNotContainsString('fixture-auth-token', json_encode($stored));
			$input['enabled'] = 'yes';
			$this->assertSame('Configuration saved.', alarmmanager_admin_action($input));
			$input['auth_type'] = 'keep';
			$this->assertSame('Configuration saved.', alarmmanager_admin_action($input));
			$this->assertTrue((bool) array_filter($writes, static fn ($write) => str_contains($write[0], 'DELETE FROM plugin_alarmmanager_credentials')));
		} finally {
			unset($GLOBALS['alarmmanager_test_csrf_valid']);
		}
	}
	public function testProviderEditorRendersSavedProviderAndNativeCreationPicker(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'fixture';
			}
		}

		if (!function_exists('get_filter_request_var')) {
			function get_filter_request_var($name, $filter, $options) {
				return $options['options']($_REQUEST[$name] ?? '') ?: ($options['default'] ?? '');
			}
		}
		$before = $_REQUEST;

		try {
			foreach (['twilio', 'pagerduty'] as $provider) {
				$_REQUEST  = ['integration_template' => $provider];
				$transport = alarmmanager_integration_templates()[$provider]['transport'] + ['provider' => $provider];
				ob_start();

				try {
					alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [['id' => 7, 'name' => 'Saved', 'type' => 'restapi', 'transport' => $transport]], 'rules' => []]], 'endpoints');
					$html = ob_get_contents();
				} finally {
					ob_end_clean();
				}
				$this->assertStringContainsString('value="' . $provider . '" selected', $html);
				$this->assertStringContainsString('name="provider_account_sid"', $html);
			}

			foreach (['invalid_provider', 'invalid_twilio_account', 'invalid_twilio_credentials', 'invalid_provider_instance', 'provider_credentials_required'] as $code) {
				$this->assertStringNotContainsString('Action failed:', alarmmanager_admin_error_message(new InvalidArgumentException($code)));
			}
		} finally {
			$_REQUEST = $before;
		}
	}
}
