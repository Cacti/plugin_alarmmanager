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

final class ContractTest extends AlarmManagerTestCase {
	public function testProducerConfigurationHasNoModeAndUsesGlobalRoutingByDefault(): void {
		self::alarmmanager_test_load('integrations/producer_bridge.php');
		$this->assertSame(['profile_id' => 0, 'channels' => [], 'complete_history' => false], AlarmManagerProducerBridge::validate([]));

		foreach (['observe', 'centralized', 'legacy'] as $oldMode) {
			$this->assertSame(AlarmManagerProducerBridge::validate([]), AlarmManagerProducerBridge::validate(['mode' => $oldMode]), 'Saved configurations lose their old operating mode.');
		}
	}

	public function testDeviceContextIsBoundedAndAvailableToTemplates(): void {
		self::alarmmanager_test_load('includes/mapping.php');
		self::alarmmanager_test_load('integrations/producer_bridge.php');
		$fields = AlarmManagerProducerBridge::deviceDetails(['location' => 'Rack 7', 'external_id' => 'ASSET-42', 'snmp_sysUpTimeInstance' => 9006100, 'status_fail_date' => 1791979200, 'status_rec_date' => 0, 'status_last_error' => 'SNMP timeout'], 2);
		$this->assertSame('1d 1h 1m 1s', $fields['uptime']);
		$this->assertSame('9006100', $fields['snmp_sysUpTimeInstance']);
		$this->assertSame('', $fields['status_rec_date']);
		$this->assertSame('Rack 7', $fields['cacti_location']);
		$event = $this->sampleEvent();
		$event['device'] += $fields;
		$event['device']['collector_name'] = 'Remote collector';
		$event = alarmmanager_validate_event($event);
		$payload = alarmmanager_map_payload(['default' => [['to' => 'text', 'template' => '{{device.cacti_location}} | {{device.collector_name}} | {{device.uptime}} | {{device.external_id}} | {{device.status_last_error}} | {{device.status_fail_date}} | {{device.status_rec_date}}']]], $event);
		$this->assertSame('Rack 7 | Remote collector | 1d 1h 1m 1s | ASSET-42 | SNMP timeout | ' . alarmmanager_event_time($fields['status_fail_date']) . ' | ', $payload->text);
		$this->assertSame('', AlarmManagerProducerBridge::deviceDetails([], 1)['uptime']);
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

	/**
	 * @return void
	 */
	public function testNormalizesTimestampsAndCanonicalizesEvents(): void {
		self::alarmmanager_test_load('includes/events.php');

		$sample     = $this->sampleEvent();
		$normalized = alarmmanager_validate_event($sample);
		$this->assertTrue($normalized['occurred_at'] === '2026-10-13T03:00:00.000000Z', 'Contract assertion failed.');
		$reordered                   = $sample;
		$reordered['device']['tags'] = ['lab', 'network', 'lab'];
		$this->assertTrue(alarmmanager_validate_event($reordered) === $normalized, 'Contract assertion failed.');
		$this->assertTrue(alarmmanager_event_time('2026-10-12T23:00:00-04:00') === $normalized['occurred_at'], 'Contract assertion failed.');
	}

	/**
	 * @return void
	 */
	public function testAcceptsEveryContractTransition(): void {
		self::alarmmanager_test_load('includes/events.php');

		$sample = $this->sampleEvent();

		foreach (['raised', 'updated', 'reminder', 'recovered', 'occurrence'] as $transition) {
			$event               = $sample;
			$event['transition'] = $transition;
			$this->assertTrue(alarmmanager_validate_event($event)['transition'] === $transition, 'Contract assertion failed.');
		}
	}

	/**
	 * @return void
	 */
	public function testRejectsContractViolations(): void {
		self::alarmmanager_test_load('includes/events.php');

		$sample  = $this->sampleEvent();
		$changes = [
			['sequence', 0], ['sequence', '1'], ['collector_id', 0], ['collector_id', 2147483648],
			['source', ''], ['event_id', str_repeat('x', 129)], ['episode_id', ''],
			['summary', str_repeat('x', 513)], ['details', str_repeat('x', 16385)],
			['severity', 'unknown'], ['transition', 'down'], ['occurred_at', '2026-02-30T03:00:00Z'],
			['occurred_at', '0999-10-13T03:00:00Z'], ['occurred_at', '2026-10-13 03:00:00'], ['occurred_at', '2026-10-13T03:00:00+14:01'],
			['device', ['hostname' => 'lab', 'snmp_community' => 'secret']],
			['device', ['id' => '42']], ['device', ['tags'                      => array_fill(0, 33, 'tag')]],
			['context', ['routing_profile_id' => '1']], ['context', ['password' => 'secret']], ['context', ['source_suppressed' => 'yes']], ['raw_host', []]
		];

		foreach ($changes as [$field, $value]) {
			$event         = $sample;
			$event[$field] = $value;
			$failed        = false;

			try {
				alarmmanager_validate_event($event);
			} catch (InvalidArgumentException $error) {
				$failed = true;
			}
			$this->assertTrue($failed, 'Contract assertion failed.');
		}
	}
}
