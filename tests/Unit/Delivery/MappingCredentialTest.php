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
		self::alarmmanager_test_load('includes/credential_store.php');

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
	public function testRejectsInvalidMappingsAndConversions(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		self::alarmmanager_test_load('includes/credential_store.php');

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
		self::alarmmanager_test_load('includes/credential_store.php');

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
		self::alarmmanager_test_load('includes/credential_store.php');

		$key = random_bytes(32);

		foreach ([['headers' => ['Authorization' => "x\r\ny"]], ['headers' => ['Host' => 'evil']], ['headers' => ['X-Test' => 'a', 'x-test' => 'b']], ['basic' => ['username' => 'a:b', 'password' => 'c']]] as $bad) {
			$this->assertRejects(fn () => alarmmanager_encrypt_credential($bad, 1, $key), 'Invalid credentials');
		}
	}
}
