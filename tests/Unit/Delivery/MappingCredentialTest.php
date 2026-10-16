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

final class MappingCredentialTest extends AlarmManagerTestCase {
	public function testAdminMappingErrorsAreSpecificWithoutExposingSubmittedValues(): void {
		self::alarmmanager_test_load('includes/admin.php');

		foreach (['conflicting_mapping' => 'unique destination name', 'unknown_mapping_field' => 'unknown variable', 'invalid_mapping_template' => 'Combined text', 'mapping_required' => 'required message value'] as $code => $expected) {
			$this->assertStringContainsString($expected, alarmmanager_admin_error_message(new InvalidArgumentException($code)));
		}
		$this->assertStringContainsString('JSON', alarmmanager_admin_error_message(new JsonException('sensitive value')));
		$this->assertStringNotContainsString('sensitive value', alarmmanager_admin_error_message(new RuntimeException('sensitive value')));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sampleEvent(): array {
		return json_decode(
			file_get_contents(__DIR__ . '/../../../examples/event.json'),
			true,
			32,
			JSON_THROW_ON_ERROR
		);
	}

	private function assertRejects(callable $action, string $label): void {
		$failed = false;

		try {
			$action();
		} catch (Throwable $error) {
			$failed = true;
		}
		$this->assertTrue($failed, $label);
	}

	/**
	 * @return void
	 */
	public function testMapsPayloadsForApiAndRecovery(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		self::alarmmanager_test_load('includes/credentials.php');

		$event             = alarmmanager_validate_event($this->sampleEvent());
		$event['severity'] = 'critical';
		$mapping           = ['default' => [
		 ['to' => 'hostname', 'source' => 'device.id', 'type' => 'string', 'required' => true],
		 ['to' => 'alarm.level', 'source' => 'alarm.severity', 'values' => ['critical' => 5]],
		 ['to' => 'defaulted', 'source' => 'context.rule_type', 'default' => 'unset'],
		 ['to' => 'nested.enabled', 'constant' => 'false', 'type' => 'boolean'],
		 ['to' => 'nested.count', 'constant' => '42', 'type' => 'integer'],
		 ['to' => 'nested.ratio', 'constant' => '1.25', 'type' => 'number'],
		 ['to' => 'escaped', 'constant' => '"\\ <script>&', 'type' => 'html'],
		 ['to' => 'literal', 'constant' => '"\\ <script>&'],
		 ['to' => 'list', 'source' => 'device.tags']
		], 'recovered' => [['to' => 'status', 'constant' => 'resolved']]];
		$out = alarmmanager_map_payload($mapping, $event);
		$this->assertTrue($out->hostname === (string) $event['device']['id'], 'API hostname mapping');
		$mapping2 = ['default' => [['to' => 'host', 'source' => 'device.id']]];
		$this->assertTrue(alarmmanager_map_payload($mapping2, $event)->host === $event['device']['id'], 'API host mapping');
		$this->assertTrue($out->nested->enabled === false && $out->nested->count === 42 && $out->nested->ratio === 1.25, 'Explicit conversions');
		$this->assertTrue($out->defaulted === 'unset' && $out->alarm->level === 5, 'Defaults/severity values');
		$this->assertTrue(json_decode(json_encode($out))->literal === '"\\ <script>&' && !str_contains($out->escaped, '<script>'), 'Structural escaping');
		$this->assertTrue(is_array($out->list), 'List preserved');
		$event['transition'] = 'recovered';
		$this->assertTrue(alarmmanager_map_payload($mapping, $event)->status === 'resolved', 'Recovery payload');
		$this->assertTrue(json_encode(alarmmanager_map_payload([], $event)) === '{}', 'Empty object');
	}

	/**
	 * @return void
	 */
	public function testCombinesWhitelistedVariablesWithText(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		$event    = alarmmanager_validate_event($this->sampleEvent());
		$template = 'Device {{device.id}} with description {{ device.description }} went down at {{event.occurred_at}}';
		$mapping  = ['default' => [['to' => 'summary', 'template' => $template, 'required' => true]]];
		$this->assertSame('Device 42 with description Sample router went down at ' . $event['occurred_at'], alarmmanager_map_payload($mapping, $event)->summary);
		$event['device']['description'] = '<script>"\\ & {{device.id}}';
		$mapping['default'][0]['type']  = 'html';
		$this->assertSame(htmlspecialchars('Device 42 with description ' . $event['device']['description'] . ' went down at ' . $event['occurred_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), alarmmanager_map_payload($mapping, $event)->summary);
		$this->assertSame('{{device.id}}', alarmmanager_map_payload(['default' => [['to' => 'literal', 'constant' => '{{device.id}}']]], $event)->literal);
		$this->assertSame('Ticket CASE-42', alarmmanager_map_payload(['recovered' => [['to' => 'summary', 'template' => 'Ticket {{integration.id}}']]], ['transition' => 'recovered'], ['id' => 'CASE-42'])->summary);
		$missing = ['default' => [['to' => 'summary', 'template' => 'Metric {{context.metric}}']]];
		$this->assertNull(alarmmanager_map_payload($missing, $event)->summary);
		$missing['default'][0]['default'] = 'Metric unavailable';
		$this->assertSame('Metric unavailable', alarmmanager_map_payload($missing, $event)->summary);
		unset($missing['default'][0]['default']);
		$missing['default'][0]['required'] = true;
		$this->assertRejects(fn () => alarmmanager_map_payload($missing, $event), 'Required template variable is missing');
		$this->assertRejects(fn () => alarmmanager_map_payload(['default' => [['to' => 'a', 'template' => '{{device.tags}}']]], $event), 'Arrays cannot become text');

		foreach ([null, '{{device.password}}', '{{device.id}', '{{}}', str_repeat('x', 16385)] as $bad) {
			$this->assertRejects(fn () => alarmmanager_mapping_validate(['default' => [['to' => 'a', 'template' => $bad]]]), 'Invalid template rejected');
		}

		foreach (['source' => 'device.id', 'constant' => 'literal'] as $key => $value) {
			$this->assertRejects(fn () => alarmmanager_mapping_validate(['default' => [['to' => 'a', 'template' => '{{device.id}}', $key => $value]]]), 'Ambiguous template rejected');
		}
	}

	public function testRejectsInvalidMappingsAndConversions(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		self::alarmmanager_test_load('includes/credentials.php');

		foreach ([
			['to' => 'a', 'source' => 'device.id', 'constant' => 1],
			['to' => 'a', 'source' => 'device.password'],
			['to' => 'a', 'source' => null],
			['to' => 'a.0', 'source' => 'device.id'],
			['to' => 'a', 'constant' => 1, 'type' => 'eval']
		] as $bad) {
			$this->assertRejects(fn () => alarmmanager_mapping_validate(['default' => [$bad]]), 'Invalid mapping rejected');
		}
		$this->assertRejects(fn () => alarmmanager_mapping_validate(['default' => [['to' => 'a', 'constant' => 1], ['to' => 'a.b', 'constant' => 2]]]), 'Prefix conflict');
		$this->assertRejects(fn () => alarmmanager_mapping_validate(['default' => [['to' => 'a', 'constant' => 1], ['to' => 'a', 'constant' => 2]]]), 'Duplicate conflict');
		$this->assertRejects(fn () => alarmmanager_map_payload(['default' => [['to' => 'a', 'source' => 'context.rule_type', 'required' => true]]], alarmmanager_validate_event($this->sampleEvent())), 'Required missing');

		foreach (['integer' => '1.5', 'number' => 'INF', 'boolean' => 'truthy'] as $type => $value) {
			$this->assertRejects(fn () => alarmmanager_mapping_convert($value, $type), 'Invalid conversion');
		}
	}

	/**
	 * @return void
	 */
	public function testCredentialCiphertextProtection(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		self::alarmmanager_test_load('includes/credentials.php');

		$key       = random_bytes(32);
		$record    = ['headers' => ['Authorization' => 'Bearer fixture-token']];
		$encrypted = alarmmanager_encrypt_credential($record, 1, $key);
		$this->assertTrue(alarmmanager_decrypt_credential($encrypted, 1, $key) === $record, 'Authenticated roundtrip');
		$this->assertTrue(alarmmanager_encrypt_credential($record, 1, $key) !== $encrypted, 'Fresh nonce');
		$this->assertTrue(!str_contains($encrypted, 'fixture-token'), 'No plaintext stored');
		$this->assertRejects(fn () => alarmmanager_decrypt_credential($encrypted, 2, $key), 'Endpoint binding');
		$this->assertRejects(fn () => alarmmanager_decrypt_credential($encrypted, 1, random_bytes(32)), 'Wrong key');
		$this->assertRejects(fn () => alarmmanager_decrypt_credential($encrypted, 1, ''), 'Missing key');

		foreach (['am2:' . substr($encrypted, 4), $encrypted . ' ', 'am1:!!', 'am1:' . base64_encode(str_repeat('x', 27))] as $bad) {
			$this->assertRejects(fn () => alarmmanager_decrypt_credential($bad, 1, $key), 'Malformed ciphertext');
		}
		$binary = base64_decode(substr($encrypted, 4), true);

		foreach ([0, 12, 28] as $position) {
			$tampered            = $binary;
			$tampered[$position] = chr(ord($tampered[$position]) ^ 1);
			$this->assertRejects(fn () => alarmmanager_decrypt_credential('am1:' . base64_encode($tampered), 1, $key), 'Tampering detected');
		}
	}

	/**
	 * @return void
	 */
	public function testRejectsInvalidCredentialRecords(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		self::alarmmanager_test_load('includes/credentials.php');

		$key = random_bytes(32);

		foreach ([['headers' => ['Authorization' => "x\r\ny"]], ['headers' => ['Host' => 'evil']], ['headers' => ['X-Test' => 'a', 'x-test' => 'b']], ['basic' => ['username' => 'a:b', 'password' => 'c']]] as $bad) {
			$this->assertRejects(fn () => alarmmanager_encrypt_credential($bad, 1, $key), 'Invalid credentials');
		}
	}
	public function testAuthenticationFieldsBuildValidatedCredentials(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$this->assertNull(alarmmanager_admin_credential(['headers' => '{}', 'bearer_token' => '', 'basic_username' => '', 'basic_password' => '']));
		$this->assertSame(['headers' => ['Authorization' => 'Bearer fixture-token']], alarmmanager_admin_credential(['bearer_token' => 'fixture-token']));
		$this->assertSame(['headers' => ['X-API-Key' => 'fixture-key'], 'basic' => ['username' => 'fixture-user', 'password' => 'fixture-password']], alarmmanager_admin_credential(['headers' => '{"X-API-Key":"fixture-key"}', 'basic_username' => 'fixture-user', 'basic_password' => 'fixture-password']));

		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'none', 'bearer_token' => 'ignored']));
		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'keep', 'basic_password' => 'ignored']));
		$this->assertSame(['headers' => ['Authorization' => 'Bearer selected-token']], alarmmanager_admin_credential(['auth_type' => 'bearer', 'bearer_token' => 'selected-token', 'basic_username' => 'ignored']));
		$this->assertSame(['headers' => [], 'basic' => ['username' => 'user', 'password' => '']], alarmmanager_admin_credential(['auth_type' => 'basic', 'basic_username' => 'user']));
		$this->assertSame(['headers' => ['X-API-Key' => 'key']], alarmmanager_admin_credential(['auth_type' => 'headers', 'headers' => '{"X-API-Key":"key"}', 'bearer_token' => 'ignored']));

		foreach ([
			['bearer_token' => "bad\r\ntoken"],
			['bearer_token' => ['invalid']],
			['auth_type' => 'invalid'],
			['auth_type' => ['bearer']],
			['auth_type' => 'bearer'],
			['auth_type' => 'basic', 'basic_password' => 'password'],
			['auth_type' => 'headers', 'headers' => '{}'],
			['bearer_token' => 'token', 'basic_username' => 'user', 'basic_password' => 'password'],
			['bearer_token' => 'token', 'headers' => '{"authorization":"other"}']
		] as $input) {
			$this->assertRejects(fn () => alarmmanager_admin_credential($input), 'Conflicting or invalid authentication rejected');
		}

		// Creating an endpoint defers credentials: a preset draft picks the
		// provider's authentication type and attaches the secret record later.
		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'bearer'], true));
		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'basic', 'basic_username' => '', 'basic_password' => ''], true));
		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'headers', 'headers' => '{}'], true));
		$this->assertSame(['headers' => ['Authorization' => 'Bearer draft-token']], alarmmanager_admin_credential(['auth_type' => 'bearer', 'bearer_token' => 'draft-token'], true));
		$this->assertSame(['headers' => [], 'basic' => ['username' => 'user', 'password' => '']], alarmmanager_admin_credential(['auth_type' => 'basic', 'basic_username' => 'user'], true));

		// Replacement and every other auth type still reject an unfilled record.
		$this->assertRejects(fn () => alarmmanager_admin_credential(['auth_type' => 'bearer'], false), 'Conflicting or invalid authentication rejected');
		$this->assertRejects(fn () => alarmmanager_admin_credential(['auth_type' => 'basic', 'basic_password' => 'password'], true), 'Conflicting or invalid authentication rejected');
		$this->assertRejects(fn () => alarmmanager_admin_credential(['auth_type' => 'invalid'], true), 'Conflicting or invalid authentication rejected');
	}
	public function testHttpAlarmMethodsExcludeDelete(): void {
		self::alarmmanager_test_load('includes/routing.php');

		foreach (['POST', 'PUT', 'PATCH'] as $method) {
			$this->assertSame($method, alarmmanager_transport_configuration('restapi', ['url' => 'https://example.test/alarms', 'method' => $method])['method']);
		}
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('invalid_transport_method');
		alarmmanager_transport_configuration('webhook', ['url' => 'https://example.test/alarms', 'method' => 'DELETE']);
	}
}
