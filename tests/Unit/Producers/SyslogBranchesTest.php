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

final class SyslogBranchesTest extends AlarmManagerTestCase {
	private static bool $pinned_loaded = false;

	/**
	 * @var array<int, string> Temporary fixture files removed in tearDown().
	 */
	private array $fixture_files = [];

	/**
	 * @var array<string, mixed> Bootstrap globals restored in tearDown().
	 */
	private array $saved_globals = [];

	protected function tearDown(): void {
		foreach ($this->fixture_files as $file) {
			if (is_file($file)) {
				unlink($file);
			} elseif (is_dir($file)) {
				@rmdir($file);
			}
		}

		if ($this->saved_globals !== []) {
			foreach ($this->saved_globals as $name => $value) {
				$GLOBALS[$name] = $value;
			}
		}
	}

	private static function loadDoubles(): void {
		require_once __DIR__ . '/../../Helpers/ProducersDoubles.php';
	}

	private function bootstrapPinnedSyslog(): void {
		$patched = (string) (getenv('ALARMMANAGER_SYSLOG_PATCHED_ROOT') ?: '');

		if ($patched === '' || !is_file($patched . '/includes/functions.php') || !is_file($patched . '/includes/alarmmanager_adapter.php')) {
			$this->markTestSkipped('Pinned syslog checkout unavailable; branch test skipped.');
		}

		if (self::$pinned_loaded) {
			return;
		}
		self::loadDoubles();
		alarmmanager_test_producer_eval($this->extractFunction($patched . '/includes/functions.php', 'syslog_process_alert'));
		require_once($patched . '/includes/alarmmanager_adapter.php');
		self::$pinned_loaded = true;
	}

	private function extractFunction(string $path, string $name): string {
		$tokens  = token_get_all(file_get_contents($path));
		$collect = false;
		$found   = false;
		$brace   = 0;
		$code    = '';

		for ($i = 0; $i < count($tokens); $i++) {
			$t = $tokens[$i];

			if (!$collect && is_array($t) && $t[0] === T_FUNCTION) {
				$j = $i + 1;

				while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
					$j++;
				}

				if (is_array($tokens[$j]) && $tokens[$j][1] === $name) {
					$collect = true;
				}
			}

			if ($collect) {
				$code .= is_array($t) ? $t[1] : $t;

				if ($t === '{') {
					$brace++;
					$found = true;
				}

				if ($t === '}') {
					$brace--;

					if ($found && $brace === 0) {
						return $code;
					}
				}
			}
		}

		throw new RuntimeException('function_not_found');
	}

	private function stageFixture(): void {
		$fixture = sys_get_temp_dir() . '/alarmmanager_syslog_' . bin2hex(random_bytes(6));
		mkdir($fixture . '/lib', 0700, true);
		file_put_contents($fixture . '/lib/reports.php', '<?php');
		$this->fixture_files                                  = [$fixture . '/lib/reports.php', $fixture . '/lib', $fixture];
		$this->saved_globals                                  = ['config' => $GLOBALS['config']];
		$GLOBALS['config']                                    = ['base_path' => $fixture];
		$GLOBALS['severities']                                = [0 => 'Notice', 1 => 'Warning', 2 => 'Critical'];
		$GLOBALS['syslog_levels']                             = [3 => 'Error'];
		$GLOBALS['alarmmanager_test_syslog_allow']            = true;
		$GLOBALS['alarmmanager_test_syslog_repeat']           = false;
		$GLOBALS['alarmmanager_test_syslog_records']          = [['seq' => 9, 'host' => 'router.test', 'logtime' => '2026-10-13 12:00:00', 'priority_id' => 3, 'facility_id' => 1, 'message' => 'Fixture message']];
		$GLOBALS['alarmmanager_test_producer_config_options'] = ['settings_from_email' => 'fixture@example.test', 'settings_from_name' => 'Fixture', 'syslog_maxrecords' => 20, 'poller_interval' => 300, 'base_url' => 'http://example.test'];
		AlarmManagerProducerBridge::$calls                    = [];
		AlarmManagerProducerBridge::$decision                 = ['owned' => false, 'channels' => []];
	}

	/**
	 * @return array{0: int, 1: array<int, mixed>}
	 */
	private function runAlert(int $method, array $channels = [], bool $owned = false): array {
		$GLOBALS['alarmmanager_test_syslog_calls'] = [];
		AlarmManagerProducerBridge::$calls         = [];
		AlarmManagerProducerBridge::$decision      = ['owned' => $owned, 'channels' => $channels];
		$alert                                     = ['id' => 1, 'name' => 'Fixture alert', 'severity' => 2, 'method' => $method, 'email' => 'ops@example.test', 'notify' => 0, 'repeat_alert' => $GLOBALS['alarmmanager_test_syslog_repeat'] ? 1 : 0, 'body' => '', 'num' => 1, 'message' => 'Fixture', 'command' => 'configured-wrapper'];
		$count                                     = 0;

		try {
			$count = \AlarmManagerProducers\syslog_process_alert($alert, 'fixture', [], 1, 'router.test');
		} finally {
			$GLOBALS['alarmmanager_test_syslog_repeat'] = false;
		}

		return [$count, $GLOBALS['alarmmanager_test_syslog_calls']];
	}

	public function testLegacyAlertsRecordSourceChannels(): void {
		$this->bootstrapPinnedSyslog();
		$this->stageFixture();

		foreach ([0, 1] as $method) {
			[$count, $legacy] = $this->runAlert($method);

			$this->assertSame(1, $count, 'source alert count');
			$types = array_map(fn ($c) => is_array($c) ? $c[0] : $c, $legacy);

			foreach (['log', 'email', 'script', 'ticket', 'suppression_record'] as $type) {
				$this->assertTrue(in_array($type, $types, true), 'legacy ' . $type);
			}
		}
	}

	public function testCentralizedAndPartialHandoff(): void {
		$this->bootstrapPinnedSyslog();
		$this->stageFixture();

		foreach ([0, 1] as $method) {
			[$count, $central] = $this->runAlert($method, ['email', 'script', 'ticket'], true);
			$types             = array_map(fn ($c) => is_array($c) ? $c[0] : $c, $central);

			$this->assertTrue(!array_intersect(['email', 'script', 'ticket'], $types), 'central skips selected channels');
			$this->assertTrue(in_array('log', $types, true) && in_array('suppression_record', $types, true), 'source log suppression state retained');
			$this->assertSame('occurrence', AlarmManagerProducerBridge::$calls[0]['input']['transition'], 'syslog normalized occurrence');
			$this->assertSame('critical', AlarmManagerProducerBridge::$calls[0]['input']['severity'], 'pinned syslog numeric severity');

			[$count, $partial] = $this->runAlert($method, ['email'], true);
			$types             = array_map(fn ($c) => is_array($c) ? $c[0] : $c, $partial);

			$this->assertTrue(in_array('script', $types, true) && in_array('ticket', $types, true) && !in_array('email', $types, true), 'partial handoff');
		}
	}

	public function testSuppressionMetadataAndRepeatRetained(): void {
		$this->bootstrapPinnedSyslog();
		$this->stageFixture();

		foreach ([0, 1] as $method) {
			$GLOBALS['alarmmanager_test_syslog_allow'] = false;
			$this->runAlert($method);

			$this->assertTrue(AlarmManagerProducerBridge::$calls[0]['input']['suppressed'], 'source suppression metadata');
			$this->assertTrue($GLOBALS['alarmmanager_test_syslog_calls'] === [], 'suppressed no legacy side effects');

			$GLOBALS['alarmmanager_test_syslog_allow']  = true;
			$GLOBALS['alarmmanager_test_syslog_repeat'] = true;
			$this->runAlert($method);

			$this->assertSame('source_repeat', AlarmManagerProducerBridge::$calls[0]['input']['suppression_reason'], 'repeat suppression retained');
			$GLOBALS['alarmmanager_test_syslog_repeat'] = false;
		}
	}

	public function testOrderIndependentBatchIdentity(): void {
		$this->bootstrapPinnedSyslog();
		$this->stageFixture();

		$reordered           = $GLOBALS['alarmmanager_test_syslog_records'];
		$reordered[]         = $GLOBALS['alarmmanager_test_syslog_records'][0] + [];
		$reordered[1]['seq'] = 10;
		alarmmanager_syslog_begin(['id' => 1, 'name' => 'Test', 'severity' => 2, 'method' => 0], $reordered, 'router.test', 'body', true, false);
		$first = end(AlarmManagerProducerBridge::$calls)['identity'];
		alarmmanager_syslog_begin(['id' => 1, 'name' => 'Test', 'severity' => 2, 'method' => 0], array_reverse($reordered), 'router.test', 'body', true, false);

		$this->assertSame($first, end(AlarmManagerProducerBridge::$calls)['identity'], 'order-independent batch identity');
	}
}
