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

final class DeviceReplicationTest extends AlarmManagerTestCase {
	private static function loadDoubles(): void {
		require_once __DIR__ . '/../../Helpers/ProducersDoubles.php';
	}

	/**
	 * Load the plugin device bridge with its own double namespace so its db
	 * and replication boundaries bind the producer doubles instead of the
	 * strict plugin CactiStubs, whose global names are already reserved.
	 */
	private static function loadDeviceBridge(): void {
		self::loadDoubles();

		if (!function_exists('AlarmManagerReplication\alarmmanager_device_bridge_replicate_hosts')) {
			eval(alarmmanager_test_producer_namespaced_code(dirname(__DIR__, 3) . '/integrations/device_bridge.php', 'AlarmManagerReplication'));
		}
	}

	public function testReplicationAndOwnershipCaching(): void {
		self::loadDeviceBridge();
		$saved_config                              = $GLOBALS['config'];
		$GLOBALS['config']                         = ['poller_id' => 2];
		$GLOBALS['alarmmanager_test_queries']      = [];
		$GLOBALS['alarmmanager_test_replications'] = [];
		$GLOBALS['alarmmanager_test_peers']        = [['poller_id' => 2]];
		$data                                      = [['id' => 42], ['id' => 43]];

		try {
			$this->assertTrue(\AlarmManagerReplication\alarmmanager_device_bridge_replicate_hosts('fixture', $data, 2), 'enrolled collector handled');
			$this->assertSame(false, $GLOBALS['alarmmanager_test_replications'][0][2], 'no host truncation');

			foreach (['status', 'status_event_count', 'status_fail_date', 'status_rec_date', 'failed_polls', 'availability'] as $field) {
				$this->assertTrue(in_array($field, $GLOBALS['alarmmanager_test_replications'][0][3], true), 'local field preserved ' . $field);
			}

			$this->assertTrue((bool) array_filter($GLOBALS['alarmmanager_test_queries'], fn ($q) => str_contains($q, "SET h.deleted = 'on'")), 'removed assignments retired without wiping survivors');
			$GLOBALS['alarmmanager_test_peers'] = [];
			$this->assertFalse(\AlarmManagerReplication\alarmmanager_device_bridge_replicate_hosts('fixture', $data, 3), 'unenrolled legacy replication unchanged');
			$before = count($GLOBALS['alarmmanager_test_queries']);
			$this->assertTrue(\AlarmManagerReplication\alarmmanager_device_bridge_poll_allowed(42), 'owned lease allows normal polling');
			$first = count($GLOBALS['alarmmanager_test_queries']);
			$this->assertTrue(\AlarmManagerReplication\alarmmanager_device_bridge_poll_allowed(42) && count($GLOBALS['alarmmanager_test_queries']) === $first, 'no per-sample config/schema query');
			$this->assertSame($before + 1, $first, 'one bounded ownership cache query');
			$GLOBALS['config']['poller_id'] = 3;
			$this->assertFalse(\AlarmManagerReplication\alarmmanager_device_bridge_poll_allowed(42), 'wrong collector polling fenced');
		} finally {
			$GLOBALS['config'] = $saved_config;
		}
	}
}
