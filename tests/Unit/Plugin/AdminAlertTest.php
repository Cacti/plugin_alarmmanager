<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class AdminAlertTest extends AlarmManagerTestCase {
	public function testAdminAlertContractAndFallback(): void {
		$handler = var_export(dirname(__DIR__, 3) . '/includes/admin_alert.php', true);
		$script = '<?php require ' . $handler . ';' . <<<'CODE'
		$config = ['poller_id' => 3];
		$alert = ['contract_version' => 1, 'status' => 'unhandled', 'event_id' => 'admin-test-1',
			'occurred_at' => '2026-10-25T12:00:00Z', 'collector_id' => 3,
			'subject' => 'Cacti System Warning', 'message' => 'Poller output not empty'];
		$calls = 0;
		function check($condition) { if (!$condition) { throw new RuntimeException('Contract assertion failed'); } }
		function api_plugin_hook_function($hook, $envelope) {
			global $calls, $reply, $alert, $captured;
			check($hook === 'alarmmanager_submit');
			$calls++;
			$captured = $envelope['event'];
			check(alarmmanager_admin_alert($alert) === $alert); // Recursive alerts must fall back.
			if ($reply === 'throw') { throw new RuntimeException('storage unavailable'); }
			return $reply === 'invalid' ? null : ['status' => $reply];
		}
		foreach (['accepted', 'already_accepted', 'unhandled', 'failed', 'throw', 'invalid'] as $reply) {
			$result = alarmmanager_admin_alert($alert);
			check($result['status'] === (in_array($reply, ['accepted', 'already_accepted']) ? $reply : 'unhandled'));
			check($captured['source'] === 'cacti' && $captured['collector_id'] === 3);
			check($captured['transition'] === 'occurrence' && $captured['device']['id'] === null);
			check($captured['summary'] === $alert['subject'] && $captured['details'] === $alert['message']);
			check($captured['event_id'] === $alert['event_id'] && $captured['episode_id'] === $alert['event_id']);
		}
		check($calls === 6);
		foreach (['collector_id' => 1, 'subject' => str_repeat('x', 513), 'message' => [],
			'occurred_at' => 'invalid', 'event_id' => '', 'contract_version' => 2, 'status' => 'accepted'] as $key => $value) {
			$bad = array_replace($alert, [$key => $value]);
			check(alarmmanager_admin_alert($bad) === $bad);
		}
		check(alarmmanager_admin_alert(null) === null && $calls === 6);
		echo 'ok';
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
		$this->assertSame('ok', $output);
	}
}
