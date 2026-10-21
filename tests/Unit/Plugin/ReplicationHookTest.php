<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class ReplicationHookTest extends AlarmManagerTestCase {
	public function testFailuresRemainInsideTheHookAndRequeueAfterCactiFinishes(): void {
		$bootstrap = var_export(dirname(__DIR__, 2) . '/bootstrap-unit.php', true);
		$replication = var_export(dirname(__DIR__, 3) . '/includes/replication.php', true);

		foreach (['remote_read', 'invalid_connection', 'retry_write'] as $scenario) {
			$script = '<?php $scenario = ' . var_export($scenario, true) . '; require ' . $bootstrap . '; require ' . $replication . ';' . <<<'CODE'
			function db_fetch_cell_prepared($sql, $params = [], $column = '', $log = true, $connection = null) {
				throw new RuntimeException('PASSWORD_MUST_NOT_BE_LOGGED');
			}
			function db_execute_prepared($sql, $params = [], $log = true, $connection = null) {
				if ($GLOBALS['scenario'] === 'retry_write') { throw new RuntimeException('PASSWORD_MUST_NOT_BE_LOGGED'); }
				$GLOBALS['retry'] = [$sql, $params];
				return true;
			}
			$config['poller_id'] = 1;
			$data = ['class' => 'all', 'remote_poller_id' => 3, 'rcnn_id' => $scenario === 'invalid_connection' ? null : (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor()];
			$returned = alarmmanager_replicate_out($data) === $data;
			// Simulate poller_replicate.php clearing its flag after the hook returns.
			$GLOBALS['retry'] = null;
			register_shutdown_function(static function () use ($returned): void {
				echo json_encode(['returned' => $returned, 'retry' => $GLOBALS['retry'], 'logs' => $GLOBALS['alarmmanager_test_log']]);
			});
			CODE;
			$process = proc_open([PHP_BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$this->assertIsResource($process);
			fwrite($pipes[0], $script);
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$this->assertSame(0, proc_close($process), $error);
			$this->assertSame('', $error);
			$result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
			$this->assertTrue($result['returned']);
			$this->assertStringContainsString('configuration replication failed for collector 3', $result['logs'][0]);
			$this->assertStringNotContainsString('PASSWORD_MUST_NOT_BE_LOGGED', $output);
			if ($scenario === 'retry_write') {
				$this->assertNull($result['retry']);
				$this->assertStringContainsString('retry could not be queued', $result['logs'][1]);
			} else {
				$this->assertSame(["UPDATE poller SET requires_sync = 'on' WHERE id = ? AND disabled = ''", [3]], $result['retry']);
			}
		}
	}
}
