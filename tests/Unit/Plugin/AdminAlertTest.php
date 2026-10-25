<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class AdminAlertTest extends AlarmManagerTestCase {
	private function alert(): array {
		return ['contract_version' => 1, 'status' => 'unhandled', 'event_id' => 'admin-test-1',
			'occurred_at'             => '2026-10-25T12:00:00Z', 'collector_id' => 3,
			'subject'                 => 'Cacti System Warning', 'message' => 'Poller output not empty'];
	}

	public function testSubmissionOwnershipFallbackAndRecursion(): void {
		self::alarmmanager_test_load('includes/admin_alert.php');
		$config                         = $GLOBALS['config'];
		$GLOBALS['config']['poller_id'] = 3;
		$alert                          = $this->alert();
		$events                         = [];

		$GLOBALS['alarmmanager_test_query_handler'] = function ($sql, $params) {
			$this->assertSame('SELECT name FROM poller WHERE id = ?', $sql);
			$this->assertSame([3], $params);

			return [['name' => 'Main Poller']];
		};

		try {
			foreach (['accepted', 'already_accepted', 'unhandled', 'failed', 'throw', 'invalid'] as $reply) {
				$GLOBALS['alarmmanager_test_hook_handler'] = function ($hook, $envelope) use ($reply, $alert, &$events) {
					$this->assertSame('alarmmanager_submit', $hook);
					$this->assertSame(1, $envelope['contract_version']);
					$this->assertSame('unhandled', $envelope['status']);
					$this->assertSame($alert, alarmmanager_admin_alert($alert), 'Recursive notifications must fall back.');
					$events[] = $envelope['event'];

					if ($reply === 'throw') {
						throw new RuntimeException('storage unavailable');
					}

					return $reply === 'invalid' ? null : ['status' => $reply];
				};
				$expected = array_replace($alert, ['status' => in_array($reply, ['accepted', 'already_accepted'], true) ? $reply : 'unhandled']);
				$this->assertSame($expected, alarmmanager_admin_alert($alert));
			}
			$this->assertCount(6, $events, 'The recursion guard must reset after failures.');
			$this->assertCount(1, array_unique(array_map('serialize', $events)), 'Retries must preserve the event identity and payload.');
			$event = $events[0];
			$this->assertSame('cacti', $event['source']);
			$this->assertSame('poller:3', $event['source_instance']);
			$this->assertSame('poller:3', $event['resource_id']);
			$this->assertSame('admin_alert', $event['alarm_key']);
			$this->assertSame('occurrence', $event['transition']);
			$this->assertSame('warning', $event['severity']);
			$this->assertSame(3, $event['collector_id']);
			$this->assertNull($event['device']['id']);
			$this->assertSame(gethostname(), $event['device']['hostname']);
			$this->assertSame('Main Poller', $event['device']['description']);
			$this->assertSame('Main Poller', $event['device']['collector_name']);
			$this->assertSame('', $event['device']['location']);
			$this->assertSame('', $event['device']['contact']);
			$this->assertSame($alert['subject'], $event['summary']);
			$this->assertSame($alert['message'], $event['details']);
			$this->assertSame($alert['event_id'], $event['event_id']);
			$this->assertSame($alert['event_id'], $event['episode_id']);
		} finally {
			$GLOBALS['config'] = $config;
		}
	}

	public function testCollectorNameFallsBackWithoutInventingDeviceIdentity(): void {
		self::alarmmanager_test_load('includes/admin_alert.php');
		$config                                    = $GLOBALS['config'];
		$GLOBALS['config']['poller_id']            = 3;
		$event                                     = null;
		$GLOBALS['alarmmanager_test_hook_handler'] = static function ($hook, $envelope) use (&$event) {
			$event = $envelope['event'];

			return ['status' => 'accepted'];
		};

		try {
			$this->assertSame('accepted', alarmmanager_admin_alert($this->alert())['status']);
			$this->assertSame('Poller #3', $event['device']['description']);
			$this->assertSame('Poller #3', $event['device']['collector_name']);
			$this->assertNull($event['device']['id']);
		} finally {
			$GLOBALS['config'] = $config;
		}
	}

	public function testInvalidAndAlreadyHandledAlertsDoNotSubmit(): void {
		self::alarmmanager_test_load('includes/admin_alert.php');
		$config                                    = $GLOBALS['config'];
		$GLOBALS['config']['poller_id']            = 3;
		$GLOBALS['alarmmanager_test_hook_handler'] = function () {
			$this->fail('Invalid or already handled input must not reach ingestion.');
		};

		try {
			foreach (['collector_id' => 1, 'subject' => str_repeat('x', 513), 'message' => [],
				'occurred_at'           => 'invalid', 'event_id' => '', 'contract_version' => 2, 'status' => 'accepted'] as $key => $value) {
				$bad = array_replace($this->alert(), [$key => $value]);
				$this->assertSame($bad, alarmmanager_admin_alert($bad));
			}
			$this->assertNull(alarmmanager_admin_alert(null));
			$this->assertSame([], alarmmanager_admin_alert([]));
		} finally {
			$GLOBALS['config'] = $config;
		}
	}
}
