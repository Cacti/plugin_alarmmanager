<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class ActivationReplicationTest extends AlarmManagerTestCase {
	public function testActivationReplicatesFinalStateWithoutCoreChanges(): void {
		$bootstrap = var_export(dirname(__DIR__, 2) . '/bootstrap-unit.php', true);
		$setup     = var_export(dirname(__DIR__, 3) . '/setup.php', true);

		foreach (['enabled', 'remote', 'invalid', 'disabled', 'failure'] as $scenario) {
			$script = '<?php require ' . $bootstrap . '; require ' . $setup . '; $scenario = ' . var_export($scenario, true) . ';' . <<<'CODE'
			alarmmanager_test_reset_state();
			$config['poller_id'] = $scenario === 'remote' ? 3 : 1;
			$GLOBALS['alarmmanager_test_status'] = 4;
			$GLOBALS['activation_snapshots'] = [];
			$GLOBALS['alarmmanager_test_log'] = [];
			function get_username($user = null) { return 'unit-test'; }
			function api_plugin_check_config($plugin) { return plugin_alarmmanager_check_config(); }
			function api_plugin_enable_hooks($plugin) { api_plugin_replicate_config(); }
			function api_plugin_replicate_config() {
				if ($GLOBALS['scenario'] === 'failure' && $GLOBALS['alarmmanager_test_status'] === 1) {
					throw new RuntimeException('fixture failure');
				}
				$GLOBALS['activation_snapshots'][] = array_fill_keys([3, 4], $GLOBALS['alarmmanager_test_status']);
			}
			// Exercise Cacti's real enable ordering against the plugin's real readiness callback.
			$source = file_get_contents($config['base_path'] . '/lib/plugins.php');
			preg_match('/function api_plugin_enable\(.*?^}\R/ms', $source, $matches);
			eval($matches[0]);
			if (!plugin_alarmmanager_install()) { throw new RuntimeException('fixture install failed'); }
			if ($scenario === 'invalid') { $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] = 'invalid'; }
			plugin_alarmmanager_check_config();
			plugin_alarmmanager_check_config();
			api_plugin_enable('alarmmanager');
			if ($scenario === 'disabled') { $GLOBALS['alarmmanager_test_status'] = 4; }
			register_shutdown_function(static function (): void {
				echo json_encode(['snapshots' => $GLOBALS['activation_snapshots'], 'logs' => $GLOBALS['alarmmanager_test_log']]);
			});
			CODE;
			$process = proc_open([PHP_BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$this->assertIsResource($process);
			fwrite($pipes[0], $script);
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error  = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$this->assertSame(0, proc_close($process), $scenario . ': ' . $error);
			$this->assertSame('', $error);
			$result   = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
			$expected = $scenario === 'invalid' ? [] : [[3 => 4, 4 => 4]];

			if ($scenario === 'enabled') {
				$expected[] = [3 => 1, 4 => 1];
			}
			$this->assertSame($expected, $result['snapshots'], $scenario);
			$logs = array_values(array_filter($result['logs'], static fn ($message) => !str_starts_with($message, 'NOTE: Cacti Plugin alarmmanager has been enabled by ')));
			$this->assertSame($scenario === 'failure' ? ['plugin activation replication failed; retry Cacti synchronization from main'] : [], $logs, $scenario);
		}
	}
}
