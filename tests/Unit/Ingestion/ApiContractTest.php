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

final class ApiContractTest extends AlarmManagerTestCase {
	private function client(): array {
		require_once dirname(__DIR__, 3) . '/includes/api.php';

		return alarmmanager_api_client_validate(['name' => 'Generic monitor', 'source' => 'sample', 'source_instance' => 'lab-cacti', 'collector_id' => 1, 'mode' => 'centralized', 'profile_id' => 3, 'device_ids' => [42], 'all_devices' => false, 'unmapped_devices' => false]);
	}
	private function request(): array {
		return ['contract_version' => 1, 'mode' => 'centralized', 'event' => json_decode(file_get_contents(dirname(__DIR__, 3) . '/examples/event.json'), true, 32, JSON_THROW_ON_ERROR)];
	}
	public function testClientControlsIdentityDevicesModeAndRouting(): void {
		$client                                            = $this->client();
		$request                                           = $this->request();
		$request['event']['context']['routing_profile_id'] = 99;
		$request['event']['context']['ownership_epoch']    = 5;
		$result                                            = alarmmanager_api_envelope($request, $client);
		$this->assertSame(3, $result['event']['context']['routing_profile_id']);
		$this->assertArrayNotHasKey('ownership_epoch', $result['event']['context']);

		foreach ([['source', 'another'], ['source_instance', 'another-instance'], ['collector_id', 2], ['device', ['id' => 43]], ['device', ['hostname' => 'unmapped']]] as [$key, $value]) {
			$bad                = $request;
			$bad['event'][$key] = $value;

			try {
				alarmmanager_api_envelope($bad, $client);
				$this->fail('Cross-client event was accepted');
			} catch (RuntimeException $error) {
				$this->assertSame('api_scope_denied', $error->getMessage());
			}
		}
		$this->expectException(InvalidArgumentException::class);
		$request['mode'] = 'observe';
		alarmmanager_api_envelope($request, $client);
	}
	public function testNoImplicitDeviceGrantOrReservedDeviceSource(): void {
		$client = $this->client();

		foreach ([['device_ids', []], ['source', 'cacti_device'], ['profile_id', 0], ['device_ids', ['42']], ['all_devices', 'yes']] as [$key, $value]) {
			try {
				alarmmanager_api_client_validate(array_replace($client, [$key => $value]));
				$this->fail('Invalid API scope accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertNotEmpty($error->getMessage());
			}
		}
	}
	public function testNetworkClientRefusesInsecureOrUnboundedEndpoints(): void {
		require_once dirname(__DIR__, 3) . '/integrations/api_client.php';
		$valid = ['url' => 'https://monitor.example.test/cacti/plugins/alarmmanager/alarmmanager_api.php', 'token' => 'amapi_' . str_repeat('a', 64), 'mode' => 'observe'];
		$this->assertSame($valid + ['ca_bundle' => null], AlarmManagerApiClient::validate($valid));

		foreach (['http://monitor.example.test/api', 'https://user:password@monitor.example.test/api', 'https://monitor.example.test/api?token=secret', 'file:///tmp/api', 'https://monitor.example.test/api#fragment'] as $url) {
			try {
				AlarmManagerApiClient::validate(array_replace($valid, ['url' => $url]));
				$this->fail('Invalid producer URL accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_api_configuration', $error->getMessage());
			}
		}
	}
	public function testTwoProducerCopiesCanShareOnePhpProcess(): void {
		require_once dirname(__DIR__, 3) . '/integrations/api_client.php';
		$file = tempnam(sys_get_temp_dir(), 'alarmmanager-api-copy-');

		try {
			copy(dirname(__DIR__, 3) . '/integrations/api_client.php', $file);
			require $file;
			$this->assertTrue(class_exists('AlarmManagerApiClient', false));
		} finally {
			unlink($file);
		}
	}
}
