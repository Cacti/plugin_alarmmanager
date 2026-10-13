<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/

final class LifecycleTest extends AlarmManagerTestCase {
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
	 * @return array<string, mixed>
	 */
	private function routingConfiguration(): array {
		return json_decode(
			file_get_contents(__DIR__ . '/../../../examples/routing.json'),
			true,
			32,
			JSON_THROW_ON_ERROR
		);
	}

	/**
	 * @return array<int, array<int, mixed>>
	 */
	private function invalidSchedules(): array {
		return [
			['timezone' => 'Bad/Zone', 'days' => [1], 'start' => '00:00', 'end' => '01:00'],
			['timezone' => 'UTC', 'days' => [8], 'start' => '00:00', 'end' => '01:00'],
			['timezone' => 'UTC', 'days' => [1], 'start' => '24:00', 'end' => '01:00']
		];
	}

	/**
	 * The original transition chain mutates one shared state machine; keep it whole.
	 *
	 * @return void
	 */
	public function testEpisodeTransitionChain(): void {
		self::alarmmanager_test_load('includes/lifecycle.php');
		self::alarmmanager_test_load('includes/routing.php');

		$state             = ['episode_id' => '', 'last_sequence' => 0, 'active' => 0, 'acknowledged' => 0, 'severity' => 'info', 'summary' => ''];
		$event             = alarmmanager_validate_event($this->sampleEvent());
		[$raised, $reason] = alarmmanager_transition($state, $event);
		$this->assertTrue($reason === 'applied' && $raised['active'] === 1, 'Lifecycle assertion failed.');
		[$again, $reason] = alarmmanager_transition($raised, $event);
		$this->assertTrue($reason === 'stale' && $again === $raised, 'Lifecycle assertion failed.');
		$event['sequence']    = 2;
		$event['transition']  = 'recovered';
		[$recovered, $reason] = alarmmanager_transition($raised, $event);
		$this->assertTrue($reason === 'applied' && $recovered['active'] === 0, 'Lifecycle assertion failed.');
		$event['sequence']   = 3;
		$event['transition'] = 'raised';
		[$closed, $reason]   = alarmmanager_transition($recovered, $event);
		$this->assertTrue($reason === 'closed_episode' && $closed === $recovered, 'Lifecycle assertion failed.');
		$event['episode_id']       = 'episode-2';
		$recovered['acknowledged'] = 1;
		[$new, $reason]            = alarmmanager_transition($recovered, $event);
		$this->assertTrue($new['active'] === 1 && $new['acknowledged'] === 0, 'Lifecycle assertion failed.');
		$event['sequence']    = 4;
		$event['transition']  = 'recovered';
		$event['episode_id']  = 'old-episode';
		[$unchanged, $reason] = alarmmanager_transition($new, $event);
		$this->assertTrue($reason === 'ignored_episode' && $unchanged === $new, 'Lifecycle assertion failed.');
		$event['transition']   = 'occurrence';
		[$occurrence, $reason] = alarmmanager_transition($state, $event);
		$this->assertTrue($reason === 'occurrence' && $occurrence['active'] === 0 && $occurrence['episode_id'] === '', 'Lifecycle assertion failed.');
		[$with_condition, $reason] = alarmmanager_transition($new, $event);
		$this->assertTrue($with_condition['active'] === 1 && $with_condition['episode_id'] === 'episode-2', 'Lifecycle assertion failed.');
	}

	/**
	 * @return void
	 */
	public function testSchedulesValidateAndMatch(): void {
		self::alarmmanager_test_load('includes/lifecycle.php');
		self::alarmmanager_test_load('includes/routing.php');

		$schedule = alarmmanager_validate_schedule(['timezone' => 'America/Toronto', 'days' => [1], 'start' => '22:00', 'end' => '02:00']);
		$this->assertTrue(alarmmanager_schedule_matches($schedule, '2026-10-13T03:00:00Z'), 'Lifecycle assertion failed.');
		$this->assertTrue(alarmmanager_schedule_matches($schedule, '2026-10-13T05:59:00Z'), 'Lifecycle assertion failed.');
		$this->assertTrue(!alarmmanager_schedule_matches($schedule, '2026-10-13T06:00:00Z'), 'Lifecycle assertion failed.');
		$this->assertTrue(!alarmmanager_schedule_matches($schedule, '2026-10-14T03:00:00Z'), 'Lifecycle assertion failed.');
		$dst = alarmmanager_validate_schedule(['timezone' => 'America/Toronto', 'days' => [7], 'start' => '01:00', 'end' => '02:00']);
		$this->assertTrue(alarmmanager_schedule_matches($dst, '2026-11-01T05:30:00Z'), 'Lifecycle assertion failed.');
		$this->assertTrue(alarmmanager_schedule_matches($dst, '2026-11-01T06:30:00Z'), 'Lifecycle assertion failed.');

		foreach ($this->invalidSchedules() as $invalid) {
			$failed = false;

			try {
				alarmmanager_validate_schedule($invalid);
			} catch (InvalidArgumentException $error) {
				$failed = true;
			}
			$this->assertTrue($failed, 'Lifecycle assertion failed.');
		}
	}

	/**
	 * @return void
	 */
	public function testRulesMatchByPolicyFields(): void {
		self::alarmmanager_test_load('includes/lifecycle.php');
		self::alarmmanager_test_load('includes/routing.php');

		$event  = alarmmanager_validate_event($this->sampleEvent());
		$policy = alarmmanager_validate_rule_definition(['match' => ['sources' => ['sample'], 'severities' => ['major'], 'transitions' => ['raised'], 'device_ids' => [42], 'collector_ids' => [1], 'tags' => ['network']], 'schedule' => ['timezone' => 'UTC', 'days' => [2], 'start' => '01:00', 'end' => '04:00']]);
		$this->assertTrue(alarmmanager_rule_matches($policy, $event), 'Lifecycle assertion failed.');
		$event['device']['id'] = 99;
		$this->assertTrue(!alarmmanager_rule_matches($policy, $event), 'Lifecycle assertion failed.');
		$event['device']['id']   = 42;
		$event['device']['tags'] = [];
		$this->assertTrue(!alarmmanager_rule_matches($policy, $event), 'Lifecycle assertion failed.');
	}

	/**
	 * @return void
	 */
	public function testRoutingConfigurationValidation(): void {
		self::alarmmanager_test_load('includes/lifecycle.php');
		self::alarmmanager_test_load('includes/routing.php');

		$config    = $this->routingConfiguration();
		$validated = alarmmanager_configuration($config);
		$this->assertTrue($validated['profiles'][1]['endpoints'] === [1, 2], 'Lifecycle assertion failed.');

		foreach (['missing_endpoint', 'duplicate_id', 'invalid_type', 'invalid_bool', 'ambiguous_fields'] as $kind) {
			$bad = $config;

			if ($kind === 'missing_endpoint') {
				$bad['profiles'][0]['endpoints'][] = 99;
			} elseif ($kind === 'duplicate_id') {
				$bad['endpoints'][] = $bad['endpoints'][0];
			} elseif ($kind === 'invalid_type') {
				$bad['endpoints'][0]['type'] = 'eval';
			} elseif ($kind === 'invalid_bool') {
				$bad['endpoints'][0]['enabled'] = 'yes';
			} else {
				$bad['rules'][0]['policy']['php'] = 'callback';
			}
			$failed = false;

			try {
				alarmmanager_configuration($bad);
			} catch (InvalidArgumentException $error) {
				$failed = true;
			}
			$this->assertTrue($failed, 'Lifecycle assertion failed.');
		}
	}
}
