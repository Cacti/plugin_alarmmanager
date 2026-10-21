<?php
// SPDX-License-Identifier: GPL-2.0-or-later
final class DeviceStatusTest extends AlarmManagerTestCase {
	public function testPollerComparisonCapturesSpineChangesAndDeduplicatesPhpEvents(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable database creation must be enabled.');
		}
		require_once dirname(__DIR__, 2) . '/setup.php';
		require_once dirname(__DIR__, 2) . '/includes/polling.php';
		require_once dirname(__DIR__, 2) . '/includes/processor.php';
		require_once dirname(__DIR__, 2) . '/includes/queue.php';
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$GLOBALS['config']               = ['poller_id' => 1];
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");

		try {
			$db->exec("USE `$database`");
			$db->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
			$db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id,name))');
			$db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT)');
			$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager','0.10.1',1)");
			$db->exec("CREATE TABLE host (id INT PRIMARY KEY,poller_id INT,status INT,deleted VARCHAR(2) DEFAULT '',disabled VARCHAR(2) DEFAULT '',availability_method INT DEFAULT 1,hostname VARCHAR(255) DEFAULT 'fixture.test',description VARCHAR(255) DEFAULT 'Fixture',status_fail_date DATETIME NULL,status_rec_date DATETIME NULL) ENGINE=InnoDB");
			require_once __DIR__ . '/../fixtures/device_metadata_schema.php';
			alarmmanager_test_device_metadata_schema($db);
			self::assertTrue(plugin_alarmmanager_install());
			self::assertTrue(alarmmanager_schema_install(), 'Upgrade/install is repeatable');
			self::assertTrue(alarmmanager_schema_ready());
			self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='host'")->fetchColumn(), 'No host triggers required');
			alarmmanager_apply_configuration(['version' => 1, 'endpoints' => [['id' => 1, 'name' => 'Fixture', 'type' => 'webhook', 'transport' => ['url' => 'https://example.test']]], 'rules' => []]);
			$db->exec('INSERT INTO host (id,poller_id,status) VALUES (42,1,3), (43,2,1), (44,1,3), (45,1,3), (46,1,3)');
			$db->exec("UPDATE host SET disabled='on' WHERE id=44");
			$db->exec("UPDATE host SET deleted='on' WHERE id=45");
			$db->exec('UPDATE host SET availability_method=0 WHERE id=46');
			self::assertSame(0, alarmmanager_device_status_check(), 'Initial up is a baseline, not recovery');
			self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_device_status')->fetchColumn(), 'Other collectors, disabled/deleted/unmonitored devices excluded');
			$fail = time() - 100;
			$db->exec("UPDATE host SET status=1,status_fail_date=FROM_UNIXTIME($fail) WHERE id=42");
			$GLOBALS['alarmmanager_fail_commit'] = true;
			self::assertSame(0, alarmmanager_device_status_check(), 'Failure is contained for poller');
			unset($GLOBALS['alarmmanager_fail_commit']);
			self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_events')->fetchColumn());
			self::assertSame(3, (int) $db->query('SELECT status FROM plugin_alarmmanager_device_status WHERE device_id=42')->fetchColumn(), 'Failed event cannot advance comparison state');
			self::assertSame(1, alarmmanager_device_status_check(), 'Retry captures direct Spine-style SQL down');
			self::assertSame(0, alarmmanager_device_status_check(), 'Repeated poll does not duplicate');
			$db->exec('UPDATE host SET status=2,status_rec_date=UTC_TIMESTAMP() WHERE id=42');
			self::assertSame(0, alarmmanager_device_status_check(), 'Recovering is not confirmed up');
			self::assertSame(1, (int) $db->query('SELECT status FROM plugin_alarmmanager_device_status WHERE device_id=42')->fetchColumn());
			$db->exec('UPDATE host SET status=3 WHERE id=42');
			self::assertSame(1, alarmmanager_device_status_check(), 'Confirmed recovery captured');
			self::assertSame(0, alarmmanager_device_status_check());
			$events = $db->query('SELECT transition,episode_id,sequence FROM plugin_alarmmanager_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
			self::assertSame(['raised', 'recovered'], array_column($events, 'transition'));
			self::assertSame($events[0]['episode_id'], $events[1]['episode_id']);
			self::assertGreaterThan((int) $events[0]['sequence'], (int) $events[1]['sequence']);

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries')->fetchColumn(), 'Both transitions route');
			// Existing PHP integration remains synchronous and comparison reuses its receipt.
			$fail += 20;
			$host  = ['id' => 42, 'hostname' => 'fixture.test', 'description' => 'Fixture', 'status' => 1, 'status_fail_date' => $fail, 'status_rec_date' => time()];
			$write = static fn (): bool => db_execute_prepared('UPDATE host SET status=1,status_fail_date=FROM_UNIXTIME(?) WHERE id=42', [$fail]);
			self::assertTrue(alarmmanager_device_core_commit($host, 3, $write));
			self::assertSame(0, alarmmanager_device_status_check(), 'No duplicate PHP raise');
			self::assertSame(3, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_events')->fetchColumn());
			$db->exec('UPDATE host SET status=3,status_rec_date=UTC_TIMESTAMP() WHERE id=42');
			// Device comparison also runs before daemon mode returns from poller hook.
			$root = sys_get_temp_dir() . '/alarmmanager_status_' . bin2hex(random_bytes(5));
			mkdir($root . '/cache/alarmmanager', 0700, true);
			file_put_contents($root . '/cache/alarmmanager/workers.json', '{"enabled":true}');
			$GLOBALS['config']['base_path'] = $root;

			try {
				alarmmanager_poller_bottom();
			} finally {
				unlink($root . '/cache/alarmmanager/workers.json');
				rmdir($root . '/cache/alarmmanager');
				rmdir($root . '/cache');
				rmdir($root);
				unset($GLOBALS['config']['base_path']);
			}
			self::assertSame(4, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_events')->fetchColumn(), 'Daemon mode still captures recovery');
			self::assertSame(0, alarmmanager_device_status_check());
			// Repair a stranded UP snapshot whose recovery timestamp predates its open outage.
			$fail += 20;
			$host['status_fail_date'] = $fail;
			$write = static fn (): bool => db_execute_prepared('UPDATE host SET status=1,status_fail_date=FROM_UNIXTIME(?) WHERE id=42', [$fail]);
			self::assertTrue(alarmmanager_device_core_commit($host, 3, $write));

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			self::assertSame(1, (int) $db->query('SELECT active FROM plugin_alarmmanager_states')->fetchColumn());
			self::assertSame(0, (int) $db->query('SELECT acknowledged FROM plugin_alarmmanager_states')->fetchColumn());
			$db->exec("UPDATE host SET status=3,status_rec_date=FROM_UNIXTIME($fail-1) WHERE id=42");
			$db->exec('REPLACE INTO plugin_alarmmanager_device_status (device_id,poller_id,epoch,status,fail_epoch,rec_epoch,updated_at)
				SELECT h.id,h.poller_id,g.epoch,h.status,UNIX_TIMESTAMP(h.status_fail_date),UNIX_TIMESTAMP(h.status_rec_date),UTC_TIMESTAMP(6)
				FROM host h JOIN plugin_alarmmanager_device_owners g ON g.device_id=h.id WHERE h.id=42');
			$GLOBALS['alarmmanager_fail_commit'] = true;
			self::assertSame(0, alarmmanager_device_status_check(), 'Failed repair retries even when the comparison snapshot already says UP');
			unset($GLOBALS['alarmmanager_fail_commit']);
			self::assertSame(1, alarmmanager_device_status_check(), 'Confirmed UP repairs the tracked episode despite a stale recovery timestamp');
			self::assertSame(0, alarmmanager_device_status_check(), 'Repair queues recovery only once');

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			self::assertSame(0, (int) $db->query('SELECT active FROM plugin_alarmmanager_states')->fetchColumn(), 'Recovery clears the active condition without acknowledgment');
			self::assertSame(0, (int) $db->query('SELECT acknowledged FROM plugin_alarmmanager_states')->fetchColumn(), 'No acknowledgment is created or required');
			self::assertSame($fail - 1, (int) $db->query('SELECT UNIX_TIMESTAMP(status_rec_date) FROM host WHERE id=42')->fetchColumn(), 'Repair never changes Cacti host data');
			// A missing recovery timestamp also uses the confirmed-UP observation.
			$fail += 20;
			$host['status_fail_date'] = $fail;
			self::assertTrue(alarmmanager_device_core_commit($host, 3, static fn (): bool => db_execute_prepared('UPDATE host SET status=1,status_fail_date=FROM_UNIXTIME(?) WHERE id=42', [$fail])));
			$db->exec('UPDATE host SET status=3,status_fail_date=NULL,status_rec_date=NULL WHERE id=42');
			self::assertSame(1, alarmmanager_device_status_check());
			self::assertSame(0, alarmmanager_device_status_check());
			// Even inconsistent future failure metadata cannot override a confirmed-UP status.
			$fail += 20;
			$host['status_fail_date'] = $fail;
			self::assertTrue(alarmmanager_device_core_commit($host, 3, static fn (): bool => db_execute_prepared('UPDATE host SET status=1,status_fail_date=FROM_UNIXTIME(?) WHERE id=42', [$fail])));
			$db->exec('UPDATE host SET status=3,status_fail_date=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY),status_rec_date=NULL WHERE id=42');
			self::assertSame(1, alarmmanager_device_status_check(), 'Confirmed-UP host status wins over timestamp ordering');
			self::assertSame(0, alarmmanager_device_status_check());

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			self::assertSame(0, (int) $db->query('SELECT active FROM plugin_alarmmanager_states')->fetchColumn());
			// A device moved to another poller must not strand the old local outage.
			$db->exec('UPDATE host SET poller_id=1,status=1,status_fail_date=UTC_TIMESTAMP() WHERE id=42');
			self::assertSame(1, alarmmanager_device_status_check());

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			self::assertSame(1, (int) $db->query('SELECT active FROM plugin_alarmmanager_states')->fetchColumn());
			alarmmanager_acknowledge($db->query('SELECT stream_key FROM plugin_alarmmanager_states')->fetchColumn(), true);
			$db->exec('UPDATE host SET poller_id=3 WHERE id=42');
			self::assertSame(0, alarmmanager_device_status_check(), 'Main does not detect new remote-owned outages');
			$db->exec('UPDATE host SET status=2 WHERE id=42');
			self::assertSame(0, alarmmanager_device_status_check(), 'Recovering is not UP after reassignment');
			$db->exec('UPDATE host SET status=3,status_rec_date=UTC_TIMESTAMP() WHERE id=42');
			self::assertSame(1, alarmmanager_device_status_check(), 'Confirmed host UP recovers the old locally raised outage');
			self::assertSame(0, alarmmanager_device_status_check(), 'Reassigned recovery is emitted once');

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			self::assertSame(0, (int) $db->query('SELECT active FROM plugin_alarmmanager_states')->fetchColumn());
			self::assertSame(1, (int) $db->query('SELECT acknowledged FROM plugin_alarmmanager_states')->fetchColumn(), 'Recovery is independent of prior acknowledgment');
			self::assertSame(3, (int) $db->query('SELECT poller_id FROM host WHERE id=42')->fetchColumn(), 'Host ownership stays with the remote poller');
			// Initial down creates an episode, so a later recovery has a matching raise.
			$db->exec("INSERT INTO host (id,poller_id,status,status_fail_date) VALUES (47,1,1,FROM_UNIXTIME($fail))");
			self::assertSame(1, alarmmanager_device_status_check());
			$db->exec("UPDATE host SET status_fail_date=FROM_UNIXTIME($fail+60) WHERE id=47");
			self::assertSame(1, alarmmanager_device_status_check(), 'New outage timestamp is detected even if final status is still down');
			$db->exec("UPDATE plugin_alarmmanager_device_owners SET owner='thold' WHERE device_id=47");
			$db->exec('UPDATE host SET status=3,status_rec_date=UTC_TIMESTAMP() WHERE id=47');
			self::assertSame(0, alarmmanager_device_status_check(), 'Explicit thold owner retains control');
			$seed = $db->query("SELECT payload FROM plugin_alarmmanager_events WHERE device_id=47 AND transition='raised' ORDER BY id DESC LIMIT 1")->fetchColumn();
			$db->prepare("UPDATE plugin_alarmmanager_device_owners SET owner='core',epoch=2,seed_event=? WHERE device_id=47")->execute([$seed]);
			$db->exec('UPDATE host SET status=1 WHERE id=47');
			self::assertSame(0, alarmmanager_device_status_check(), 'Ownership handoff seed is not resent as a new outage');
		} finally {
			unset($GLOBALS['alarmmanager_fail_commit']);
			$db->exec("DROP DATABASE `$database`");
		}
	}
}
