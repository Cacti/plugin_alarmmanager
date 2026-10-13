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

final class DeviceBranchesTest extends AlarmManagerTestCase {
	/**
	 * @var array<int, string> Temporary fixture files removed in tearDown() and finally.
	 */
	private array $fixture_files = [];

	protected function tearDown(): void {
		$this->removeFixtures();
	}

	private function removeFixtures(): void {
		foreach ($this->fixture_files as $file) {
			if (is_file($file)) {
				unlink($file);
			} elseif (is_dir($file)) {
				@rmdir($file);
			}
		}
	}

	private static function loadDoubles(): void {
		require_once __DIR__ . '/../../Helpers/ProducersDoubles.php';
	}

	private function requirePinnedCactiCheckout(): string {
		$root = (string) (getenv('ALARMMANAGER_CACTI_PATCHED_ROOT') ?: '');

		if ($root === '' || !is_file($root . '/include/global_constants.php') || !is_file($root . '/lib/functions.php') || !is_file($root . '/lib/plugins.php')) {
			$this->markTestSkipped('Pinned cacti checkout unavailable; branch test skipped.');
		}

		return $root;
	}

	private function requirePinnedCactiConstants(string $root): void {
		// The pinned checkout re-declares the poller verbosity constants the unit
		// bootstrap already defines; a transient handler absorbs those redefinition
		// warnings so the pinned file still loads verbatim.
		set_error_handler(static function (): bool {
			return true;
		}, E_WARNING);

		require_once($root . '/include/global_constants.php');
		restore_error_handler();
	}

	public function testHostTransitionPublications(): void {
		$root = $this->requirePinnedCactiCheckout();
		self::loadDoubles();
		$this->requirePinnedCactiConstants($root);
		require_once __DIR__ . '/../../fixtures/source_functions.php';
		$GLOBALS['alarmmanager_test_writes']                  = 0;
		$GLOBALS['alarmmanager_test_publications']            = [];
		$GLOBALS['alarmmanager_test_host']                    = ['id' => 42, 'status' => HOST_UP, 'status_event_count' => 0, 'status_fail_date' => '', 'status_rec_date' => '', 'snmp_community' => 'CREDENTIAL_CANARY', 'snmp_version' => 2, 'failed_polls' => 0, 'total_polls' => 1, 'availability' => 100, 'status_last_error' => '', 'min_time' => 0, 'max_time' => 0, 'cur_time' => 0, 'avg_time' => 0];
		$GLOBALS['alarmmanager_test_producer_config_options'] = ['ping_failure_count' => 2, 'ping_recovery_count' => 2];
		$fixture                                              = sys_get_temp_dir() . '/alarmmanager_core_' . bin2hex(random_bytes(6));
		mkdir($fixture . '/lib', 0700, true);
		file_put_contents($fixture . '/lib/alarmmanager_device_bridge.php', '<?php');
		$this->fixture_files = [$fixture . '/lib/alarmmanager_device_bridge.php', $fixture . '/lib', $fixture];
		$ping                = (object) ['ping_response' => 'Fixture ping', 'snmp_response' => 'Fixture SNMP', 'ping_time' => 1, 'snmp_time' => 1, 'ping_status' => true, 'snmp_status' => true];

		if (!function_exists('AlarmManagerProducers\update_host_status')) {
			$code = alarmmanager_test_source_function($root . '/lib/functions.php', 'update_host_status');
			$code = str_replace('__DIR__', var_export($fixture . '/lib', true), $code);
			alarmmanager_test_producer_eval($code);
		}

		try {
			\AlarmManagerProducers\update_host_status(HOST_DOWN, 42, $ping, AVAIL_PING, false);

			$this->assertTrue($GLOBALS['alarmmanager_test_host']['status'] === HOST_UP && $GLOBALS['alarmmanager_test_publications'] === [], 'transient failure creates no alarm');
			\AlarmManagerProducers\update_host_status(HOST_DOWN, 42, $ping, AVAIL_PING, false);

			$this->assertTrue($GLOBALS['alarmmanager_test_host']['status'] === HOST_DOWN && count($GLOBALS['alarmmanager_test_publications']) === 1, 'configured failure count raises once');
			$this->assertSame(HOST_DOWN, $GLOBALS['alarmmanager_test_publications'][0][0]['status'], 'normalized down boundary');
			$this->assertFalse(str_contains(json_encode($GLOBALS['alarmmanager_test_publications']), 'CREDENTIAL_CANARY'), 'host credentials excluded');
			\AlarmManagerProducers\update_host_status(HOST_DOWN, 42, $ping, AVAIL_PING, false);

			$this->assertSame(1, count($GLOBALS['alarmmanager_test_publications']), 'continued failure no per-sample publication');
			\AlarmManagerProducers\update_host_status(HOST_UP, 42, $ping, AVAIL_PING, false);

			$this->assertTrue($GLOBALS['alarmmanager_test_host']['status'] === HOST_RECOVERING && count($GLOBALS['alarmmanager_test_publications']) === 1, 'transient up not recovery');
			\AlarmManagerProducers\update_host_status(HOST_DOWN, 42, $ping, AVAIL_PING, false);

			$this->assertTrue($GLOBALS['alarmmanager_test_host']['status'] === HOST_DOWN && count($GLOBALS['alarmmanager_test_publications']) === 1, 'failed recovery no new outage');
			\AlarmManagerProducers\update_host_status(HOST_UP, 42, $ping, AVAIL_PING, false);
			\AlarmManagerProducers\update_host_status(HOST_UP, 42, $ping, AVAIL_PING, false);

			$this->assertTrue($GLOBALS['alarmmanager_test_host']['status'] === HOST_UP && count($GLOBALS['alarmmanager_test_publications']) === 2, 'configured recovery count publishes once');
			$this->assertSame(HOST_UP, $GLOBALS['alarmmanager_test_publications'][1][0]['status'], 'recovery boundary');
			\AlarmManagerProducers\update_host_status(HOST_UP, 42, $ping, AVAIL_PING, false);

			$this->assertTrue(count($GLOBALS['alarmmanager_test_publications']) === 2 && $GLOBALS['alarmmanager_test_writes'] === 8, 'normal polling still persists native counters');
		} finally {
			$this->removeFixtures();
		}
	}

	public function testCustomFunctionalHookDispatch(): void {
		$root = $this->requirePinnedCactiCheckout();
		self::loadDoubles();
		$this->requirePinnedCactiConstants($root);
		require_once __DIR__ . '/../../fixtures/source_functions.php';
		$saved_config      = $GLOBALS['config'];
		$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'offline'];

		// Exercise the actual pinned custom functional-hook dispatch on a disconnected collector.
		if (!function_exists('AlarmManagerProducers\api_plugin_run_plugin_hook_function')) {
			alarmmanager_test_producer_eval(alarmmanager_test_source_function($root . '/lib/plugins.php', 'api_plugin_run_plugin_hook_function'));
		}

		try {
			foreach (['offline', 'recovery', 'online'] as $connection) {
				$GLOBALS['config']['connection'] = $connection;
				$called                          = false;
				$returned                        = \AlarmManagerProducers\api_plugin_run_plugin_hook_function('alarmmanager_submit', 'alarmmanager', static function ($envelope) use (&$called) {
					$called = true;

					return $envelope;
				}, ['status' => 'unhandled']);

				$this->assertTrue($called && $returned['status'] === 'unhandled', 'custom functional dispatch ' . $connection);
			}
		} finally {
			$GLOBALS['config'] = $saved_config;
		}
	}
}
