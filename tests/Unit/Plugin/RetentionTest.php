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

final class RetentionTest extends AlarmManagerTestCase {
	/** @param array<string, string> $options */
	private function withOptions(array $options, callable $test): void {
		$before = $GLOBALS['alarmmanager_test_config_options'];

		try {
			$GLOBALS['alarmmanager_test_config_options'] = $options;
			$test();
		} finally {
			$GLOBALS['alarmmanager_test_config_options'] = $before;
		}
	}

	public function testRetentionDefaultsToIndefinite(): void {
		self::alarmmanager_test_load('includes/routing.php');

		$this->withOptions([], function (): void {
			$this->assertSame(['unit' => 'forever', 'value' => 0], alarmmanager_retention(), 'Unset retention must keep history indefinitely.');
		});
		$this->withOptions(['alarmmanager_retention_unit' => 'forever', 'alarmmanager_retention_value' => '9'], function (): void {
			$this->assertSame(['unit' => 'forever', 'value' => 0], alarmmanager_retention(), 'Indefinite retention ignores the value.');
		});
	}

	public function testRetentionReadsEachUnitAndCapsTheValue(): void {
		self::alarmmanager_test_load('includes/routing.php');

		foreach (['days' => 3650, 'weeks' => 520, 'months' => 120] as $unit => $maximum) {
			$this->withOptions(['alarmmanager_retention_unit' => $unit, 'alarmmanager_retention_value' => '3650'], function () use ($unit, $maximum): void {
				$this->assertSame(['unit' => $unit, 'value' => $maximum], alarmmanager_retention(), "$unit must be capped at its maximum.");
			});
		}
		$this->withOptions(['alarmmanager_retention_unit' => 'weeks', 'alarmmanager_retention_value' => '6'], function (): void {
			$this->assertSame(['unit' => 'weeks', 'value' => 6], alarmmanager_retention());
		});
	}

	/** @dataProvider invalidRetention */
	public function testInvalidRetentionKeepsHistory(string $unit, string $value): void {
		self::alarmmanager_test_load('includes/routing.php');

		$this->withOptions(['alarmmanager_retention_unit' => $unit, 'alarmmanager_retention_value' => $value], function (): void {
			$this->assertSame(['unit' => 'forever', 'value' => 0], alarmmanager_retention(), 'An invalid choice must never purge.');
		});
	}

	/** @return array<string, array{string, string}> */
	public static function invalidRetention(): array {
		return ['unknown unit' => ['years', '1'], 'zero' => ['days', '0'], 'negative' => ['days', '-5'], 'not a number' => ['weeks', 'soon']];
	}

	public function testPurgeDoesNothingWhenRetainingIndefinitely(): void {
		self::alarmmanager_test_load('includes/queue.php');
		self::alarmmanager_test_load('includes/routing.php');
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function (string $sql) use (&$writes): bool {
			$writes[] = $sql;

			return true;
		};

		try {
			$this->withOptions([], function () use (&$writes): void {
				$this->assertSame(0, alarmmanager_history_purge(), 'Indefinite retention must not purge.');
				$this->assertSame([], $writes, 'Indefinite retention must not write.');
			});
		} finally {
			unset($GLOBALS['alarmmanager_test_execute_handler']);
		}
	}

	public function testPurgeRemovesAgedEventsAndDependents(): void {
		self::alarmmanager_test_load('includes/queue.php');
		self::alarmmanager_test_load('includes/routing.php');
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_query_handler']   = static fn (string $sql): array => str_contains($sql, 'SELECT e.id') ? [['id' => '7'], ['id' => '9']] : [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function (string $sql) use (&$writes): bool {
			$writes[] = $sql;

			return true;
		};

		try {
			$this->withOptions(['alarmmanager_retention_unit' => 'months', 'alarmmanager_retention_value' => '2'], function (): void {
				$this->assertSame(2, alarmmanager_history_purge(50), 'Two aged events must be reported as purged.');
			});
		} finally {
			unset($GLOBALS['alarmmanager_test_query_handler'], $GLOBALS['alarmmanager_test_execute_handler']);
		}
		$sql = implode("\n", $writes);
		$this->assertStringContainsString('INTERVAL 2 MONTH', $sql, 'Cutoff must use the configured interval.');
		$this->assertStringContainsString('DELETE FROM plugin_alarmmanager_events WHERE id IN (7,9)', $sql, 'Aged events must be deleted.');

		foreach (['attempts', 'deliveries', 'decisions', 'jobs', 'failed_jobs', 'device_receipts', 'producer_receipts', 'states', 'episodes', 'remote_history'] as $table) {
			$this->assertStringContainsString("plugin_alarmmanager_$table", $sql, "Purge must clean $table.");
		}
	}

	public function testPurgeReturnsZeroWhenNothingHasAgedOut(): void {
		self::alarmmanager_test_load('includes/queue.php');
		self::alarmmanager_test_load('includes/routing.php');

		$this->withOptions(['alarmmanager_retention_unit' => 'days', 'alarmmanager_retention_value' => '30'], function (): void {
			$this->assertSame(0, alarmmanager_history_purge(), 'No aged events means nothing purged.');
		});
	}

	public function testSettingsTabDefinesEveryAlarmManagerOption(): void {
		self::alarmmanager_test_load('setup.php');
		global $tabs, $settings;
		alarmmanager_config_settings();

		$this->assertSame('Alarm Manager', $tabs['alarmmanager']);
		$fields = array_keys($settings['alarmmanager']);

		foreach (['alarmmanager_summary_offline', 'alarmmanager_summary_recovering', 'alarmmanager_summary_online', 'alarmmanager_retention_unit', 'alarmmanager_retention_value', 'alarmmanager_daemon_enabled', 'alarmmanager_daemon_processing_workers', 'alarmmanager_daemon_delivery_workers'] as $field) {
			$this->assertContains($field, $fields, "$field must appear on the Settings tab.");
		}
		$this->assertSame(['forever', 'days', 'weeks', 'months'], array_keys($settings['alarmmanager']['alarmmanager_retention_unit']['array']), 'Retention offers indefinite, days, weeks and months.');
		$this->assertSame(64, max(array_keys($settings['alarmmanager']['alarmmanager_daemon_processing_workers']['array'])));
		$this->assertSame(0, min(array_keys($settings['alarmmanager']['alarmmanager_daemon_delivery_workers']['array'])));
	}

	public function testDaemonSyncWritesWorkerFileFromSettings(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$root   = sys_get_temp_dir() . '/alarmmanager_sync_' . bin2hex(random_bytes(5));
		$before = $GLOBALS['config'];
		mkdir($root . '/cache/alarmmanager', 0700, true);

		try {
			$GLOBALS['config']['base_path'] = $root;
			$this->withOptions([], function () use ($root): void {
				alarmmanager_daemon_sync();
				$this->assertFileDoesNotExist($root . '/cache/alarmmanager/workers.json', 'A disabled daemon with no file needs no file.');
			});
			$this->withOptions(['alarmmanager_daemon_enabled' => 'on', 'alarmmanager_daemon_processing_workers' => '5', 'alarmmanager_daemon_delivery_workers' => '0'], function (): void {
				alarmmanager_daemon_sync();
				$options = alarmmanager_supervisor_options();
				$this->assertTrue($options['enabled']);
				$this->assertSame([5, 0], [$options['processing_workers'], $options['delivery_workers']]);
				alarmmanager_daemon_sync();
				$this->assertSame($options, alarmmanager_supervisor_options(), 'An unchanged sync leaves the file as is.');
			});
			$this->withOptions(['alarmmanager_daemon_processing_workers' => '5', 'alarmmanager_daemon_delivery_workers' => '1'], function (): void {
				alarmmanager_daemon_sync();
				$this->assertFalse(alarmmanager_supervisor_options()['enabled'], 'Clearing the checkbox disables the daemon.');
			});
			file_put_contents($root . '/cache/alarmmanager/workers.json', '{broken');
			$this->withOptions(['alarmmanager_daemon_enabled' => 'on'], function (): void {
				alarmmanager_daemon_sync();
				$this->assertTrue(alarmmanager_supervisor_options()['enabled'], 'A corrupt worker file is rewritten from settings.');
			});
		} finally {
			$GLOBALS['config'] = $before;
			@unlink($root . '/cache/alarmmanager/workers.json');
			@rmdir($root . '/cache/alarmmanager');
			@rmdir($root . '/cache');
			@rmdir($root);
		}
	}

	public function testSettingsUpdateIgnoresOtherTabs(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$GLOBALS['alarmmanager_test_request'] = ['tab' => 'general'];
		$GLOBALS['alarmmanager_test_messages'] = [];

		try {
			$this->withOptions(['alarmmanager_daemon_enabled' => 'on'], function (): void {
				alarmmanager_settings_update();
				$this->assertSame('on', read_config_option('alarmmanager_daemon_enabled'), 'Other tabs must not touch daemon settings.');
			});
			$this->assertSame([], $GLOBALS['alarmmanager_test_messages']);
		} finally {
			unset($GLOBALS['alarmmanager_test_request']);
		}
	}

	public function testEnablingDaemonRequiresRunningService(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$root                                  = sys_get_temp_dir() . '/alarmmanager_enable_' . bin2hex(random_bytes(5));
		$before                                = $GLOBALS['config'];
		$GLOBALS['alarmmanager_test_request']  = ['tab' => 'alarmmanager'];
		$GLOBALS['alarmmanager_test_messages'] = [];
		mkdir($root . '/cache/alarmmanager', 0700, true);

		try {
			$GLOBALS['config']['base_path'] = $root;
			$this->withOptions(['alarmmanager_daemon_enabled' => 'on'], function (): void {
				alarmmanager_settings_update();
				$this->assertSame('', read_config_option('alarmmanager_daemon_enabled'), 'The checkbox is cleared when no service heartbeat exists.');
			});
			$this->assertSame(MESSAGE_LEVEL_ERROR, $GLOBALS['alarmmanager_test_messages'][0][2]);
			file_put_contents($root . '/cache/alarmmanager/status.json', json_encode(['updated_at' => time()]));
			$this->withOptions(['alarmmanager_daemon_enabled' => 'on'], function (): void {
				alarmmanager_settings_update();
				$this->assertSame('on', read_config_option('alarmmanager_daemon_enabled'), 'A running service allows enabling.');
				$this->assertTrue(alarmmanager_supervisor_options()['enabled']);
			});
		} finally {
			$GLOBALS['config'] = $before;
			unset($GLOBALS['alarmmanager_test_request']);
			@unlink($root . '/cache/alarmmanager/workers.json');
			@unlink($root . '/cache/alarmmanager/status.json');
			@rmdir($root . '/cache/alarmmanager');
			@rmdir($root . '/cache');
			@rmdir($root);
		}
	}

	public function testSettingsUpdateReportsUnwritableWorkerFile(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$root                                  = sys_get_temp_dir() . '/alarmmanager_readonly_' . bin2hex(random_bytes(5));
		$directory                             = $root . '/cache/alarmmanager';
		$before                                = $GLOBALS['config'];
		$GLOBALS['alarmmanager_test_request']  = ['tab' => 'alarmmanager'];
		$GLOBALS['alarmmanager_test_messages'] = [];
		mkdir($directory, 0700, true);
		file_put_contents($directory . '/status.json', json_encode(['updated_at' => time()]));
		chmod($directory, 0500);

		try {
			if (is_writable($directory)) {
				$this->markTestSkipped('Directory permissions are not enforced for this user.');
			}
			$GLOBALS['config']['base_path'] = $root;
			$this->withOptions(['alarmmanager_daemon_enabled' => 'on'], function (): void {
				alarmmanager_settings_update();
			});
			$this->assertSame(MESSAGE_LEVEL_ERROR, $GLOBALS['alarmmanager_test_messages'][0][2], 'A write failure must be reported to the administrator.');
		} finally {
			$GLOBALS['config'] = $before;
			unset($GLOBALS['alarmmanager_test_request']);
			chmod($directory, 0700);
			@unlink($directory . '/status.json');
			@rmdir($directory);
			@rmdir($root . '/cache');
			@rmdir($root);
		}
	}
}
