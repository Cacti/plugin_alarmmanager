<?php
// SPDX-License-Identifier: GPL-2.0-or-later
final class AutomaticDeviceTest extends AlarmManagerTestCase {
	public function testUnconfiguredDevicesAutomaticallyUseGlobalEndpoints(): void {
		self::requireAlarmManagerTestDb();
		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable database creation must be enabled.');
		}
		require_once dirname(__DIR__, 2) . '/setup.php';
		require_once dirname(__DIR__, 2) . '/includes/device.php';
		require_once dirname(__DIR__, 2) . '/includes/processor.php';
		require_once dirname(__DIR__, 2) . '/includes/queue.php';
		require_once dirname(__DIR__, 2) . '/includes/delivery.php';
		$database = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$GLOBALS['config'] = ['poller_id' => 1];
		$db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		try {
			$db->exec("USE `$database`");
			$db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id,name))');
			$db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT)');
			$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager','0.10.0',1)");
			$db->exec("CREATE TABLE host (id INT PRIMARY KEY,poller_id INT,status INT,deleted VARCHAR(2) DEFAULT '',status_fail_date DATETIME NULL,status_rec_date DATETIME NULL)");
			$db->exec('INSERT INTO host (id,poller_id,status) VALUES (42,1,3)');
			$this->assertTrue(plugin_alarmmanager_install());
			$routes = ['version' => 1, 'endpoints' => [
				['id' => 1, 'name' => 'First endpoint', 'type' => 'restapi', 'transport' => ['url' => 'https://first.example.test']],
				['id' => 2, 'name' => 'Second endpoint', 'type' => 'webhook', 'transport' => ['url' => 'https://second.example.test']],
				['id' => 3, 'name' => 'Disabled endpoint', 'type' => 'webhook', 'enabled' => false, 'transport' => ['url' => 'https://disabled.example.test']]
			], 'profiles' => [], 'rules' => []];
			alarmmanager_apply_configuration($routes);
			$fail = time() - 10;
			$host = ['id' => 42, 'status' => 1, 'hostname' => 'fixture.example', 'description' => 'Fixture', 'status_fail_date' => $fail, 'status_rec_date' => 0];
			$write = static fn (): bool => db_execute_prepared('UPDATE host SET status = ?, status_fail_date = FROM_UNIXTIME(?), status_rec_date = FROM_UNIXTIME(?) WHERE id = ?', [$host['status'], $host['status_fail_date'], $host['status_rec_date'], $host['id']]);
			$this->assertTrue(alarmmanager_device_core_commit($host, 3, $write), 'No enrollment is required to capture a confirmed down transition: ' . json_encode($GLOBALS['alarmmanager_test_log'] ?? []));
			$this->assertFalse(alarmmanager_device_core_commit($host, 3, $write), 'Concurrent duplicate transition is rejected');
			$event = json_decode((string) db_fetch_cell_prepared('SELECT payload FROM plugin_alarmmanager_events LIMIT 1'), true);
			$this->assertArrayNotHasKey('routing_profile_id', $event['context']);
			$this->assertTrue(alarmmanager_device_event_authorized($event), 'Automatic owner authorizes normal delivery');
			while ($claim = alarmmanager_claim_processing()) { alarmmanager_process_job($claim); }
			$this->assertSame([1, 2], array_map('intval', array_column(db_fetch_assoc_prepared('SELECT endpoint_id FROM plugin_alarmmanager_deliveries ORDER BY endpoint_id'), 'endpoint_id')), 'All enabled endpoints are queued without per-device rules');
			$host['status'] = 3; $host['status_rec_date'] = time();
			$upWrite = static fn (): bool => db_execute_prepared('UPDATE host SET status = ?, status_rec_date = FROM_UNIXTIME(?) WHERE id = ?', [$host['status'], $host['status_rec_date'], $host['id']]);
			$this->assertTrue(alarmmanager_device_core_commit($host, 1, $upWrite));
			while ($claim = alarmmanager_claim_processing()) { alarmmanager_process_job($claim); }
			$this->assertSame(4, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries'), 'Recovery uses the same global endpoints');
			$episodes = db_fetch_assoc_prepared('SELECT episode_id,sequence FROM plugin_alarmmanager_events ORDER BY id');
			$this->assertSame($episodes[0]['episode_id'], $episodes[1]['episode_id']);
			$this->assertGreaterThan((int) $episodes[0]['sequence'], (int) $episodes[1]['sequence']);
			// Existing explicit rules, including disabled rules, retain control of delivery.
			$routes['profiles'] = [['id' => 1, 'name' => 'Explicit', 'endpoints' => [1]]];
			$routes['rules'] = [['id' => 1, 'name' => 'Disabled rule', 'enabled' => false, 'profile_id' => 1, 'policy' => []]];
			alarmmanager_apply_configuration($routes);
			$this->assertFalse(alarmmanager_routing_snapshot()['default_route']);
			$host['status'] = 1; $host['status_fail_date'] = time();
			$nextWrite = static fn (): bool => db_execute_prepared('UPDATE host SET status = ?, status_fail_date = FROM_UNIXTIME(?) WHERE id = ?', [$host['status'], $host['status_fail_date'], $host['id']]);
			$this->assertTrue(alarmmanager_device_core_commit($host, 3, $nextWrite));
			while ($claim = alarmmanager_claim_processing()) { alarmmanager_process_job($claim); }
			$this->assertSame(4, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries'), 'Disabled custom rules do not turn on the global fallback');
			$routes['rules'] = []; $routes['profiles'] = [];
			foreach ($routes['endpoints'] as &$endpoint) { $endpoint['enabled'] = false; } unset($endpoint);
			alarmmanager_apply_configuration($routes);
			$db->exec('INSERT INTO host (id,poller_id,status) VALUES (43,1,3)');
			$host['id'] = 43;
			$noRouteWrite = static fn (): bool => db_execute_prepared('UPDATE host SET status = ?, status_fail_date = FROM_UNIXTIME(?) WHERE id = ?', [$host['status'], $host['status_fail_date'], $host['id']]);
			$this->assertTrue(alarmmanager_device_core_commit($host, 3, $noRouteWrite));
			while ($claim = alarmmanager_claim_processing()) { alarmmanager_process_job($claim); }
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE device_id=43'), 'Capture still works with no enabled endpoints');
			$this->assertSame('no_route', db_fetch_cell_prepared('SELECT reason FROM plugin_alarmmanager_decisions ORDER BY id DESC LIMIT 1'));

		} finally {
			$db->exec("DROP DATABASE `$database`");
			unset($GLOBALS['alarmmanager_route_cache']);
		}
	}
}
