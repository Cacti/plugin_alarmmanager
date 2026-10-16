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
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ConfigurationReplicationTest extends AlarmManagerTestCase {
	public function testMainSettingsReplicateWithoutReplacingCollectorHistory(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable test database creation must be enabled.');
		}
		require_once dirname(__DIR__, 2) . '/setup.php';
		require_once dirname(__DIR__, 2) . '/includes/replication.php';
		require_once dirname(__DIR__, 2) . '/includes/polling.php';
		require_once dirname(__DIR__, 2) . '/includes/ingestion.php';
		require_once dirname(__DIR__, 2) . '/tests/fixtures/device_metadata_schema.php';
		$server      = alarmmanager_test_connect();
		$connections = $names = [];
		$root        = sys_get_temp_dir() . '/alarmmanager_config_' . bin2hex(random_bytes(6));
		mkdir($root, 0700);
		$before = $GLOBALS['config'];

		try {
			foreach ([1, 2, 3] as $poller) {
				$names[$poller] = 'alarmmanager_test_' . bin2hex(random_bytes(6));
				$server->exec("CREATE DATABASE `{$names[$poller]}` CHARACTER SET utf8mb4");
				$db = $connections[$poller] = alarmmanager_test_connect();
				$db->exec("USE `{$names[$poller]}`");
				$GLOBALS['config']               = ['poller_id' => $poller, 'base_path' => $root . '/' . $poller];
				$GLOBALS['alarmmanager_test_db'] = $db;
				$db->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
				$db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id,name)) ENGINE=InnoDB');
				$db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
				$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager','0.10.1',1)");
				alarmmanager_test_device_metadata_schema($db);
				$db->exec("ALTER TABLE host ADD poller_id INT, ADD deleted VARCHAR(2) DEFAULT '', ADD disabled VARCHAR(2) DEFAULT '', ADD availability_method INT, ADD status INT");
				$this->assertTrue(plugin_alarmmanager_install());
				$keys[$poller]  = alarmmanager_secret_key();
				$nodes[$poller] = $db->query('SELECT instance_uuid FROM plugin_alarmmanager_node')->fetchColumn();

				if ($poller > 1) {
					$event                 = json_decode(file_get_contents(dirname(__DIR__, 2) . '/examples/event.json'), true);
					$event['collector_id'] = $poller;
					$this->assertSame('accepted', alarmmanager_submit(['contract_version' => 1, 'event' => $event])['status']);
				}
			}
			$GLOBALS['config']               = ['poller_id' => 1, 'base_path' => $root . '/1'];
			$GLOBALS['alarmmanager_test_db'] = $connections[1];
			$localHook                       = ['class' => 'all', 'remote_poller_id' => 1, 'rcnn_id' => false];
			$this->assertSame($localHook, alarmmanager_replicate_out($localHook), 'Native device replication for main-poller devices is a no-op.');
			$routes                          = ['version' => 1, 'endpoints' => [
				['id' => 1, 'name' => 'Slack webhook', 'type' => 'webhook', 'transport' => ['url' => 'https://hooks.slack.com/services/fixture-token', 'mapping' => ['default' => [['to' => 'text', 'source' => 'alarm.summary']]]]],
				['id' => 2, 'name' => 'Generic REST', 'type' => 'restapi', 'transport' => ['url' => 'https://api.example.test/alarms', 'credential' => true]],
				['id' => 3, 'name' => 'Script', 'type' => 'script', 'transport' => ['executable' => PHP_BINARY]],
				['id' => 4, 'name' => 'Email', 'type' => 'email', 'transport' => ['to' => ['ops@example.test'], 'mapping' => ['default' => [['to' => 'subject', 'constant' => 'Alarm'], ['to' => 'message', 'source' => 'alarm.summary']]]]]
			], 'profiles' => [], 'rules' => []];
			alarmmanager_apply_configuration($routes);
			$this->assertSame('on', $connections[1]->query('SELECT requires_sync FROM poller WHERE id=2')->fetchColumn(), 'Main saves schedule native Cacti replication.');
			alarmmanager_save_credential(2, ['headers' => ['Authorization' => 'Bearer generic-fixture']]);
			$daemon = array_replace(alarmmanager_supervisor_options(), ['enabled' => true, 'processing_workers' => 3, 'delivery_workers' => 7]);
			alarmmanager_save_supervisor_options($daemon);
			alarmmanager_db_write("REPLACE INTO settings VALUES ('alarmmanager_notifications_paused', '1')");
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source,scope,resource_id,definition) VALUES (?, ?, ?, ?)', ['syslog', 'default', 0, json_encode(['channels' => ['script']])]);
			$sourceCipher = $connections[1]->query('SELECT ciphertext FROM plugin_alarmmanager_credentials WHERE endpoint_id=2')->fetchColumn();

			foreach ([2, 3] as $poller) {
				$data = ['class' => 'all', 'remote_poller_id' => $poller, 'rcnn_id' => $connections[$poller]];
				$this->assertSame($data, alarmmanager_replicate_out($data));
				$db = $connections[$poller];
				$this->assertSame(4, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_endpoints WHERE enabled=1')->fetchColumn(), 'Every destination replicates without profiles.');
				$this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_events')->fetchColumn(), 'Local history preserved.');
				$this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_jobs')->fetchColumn(), 'Local queued jobs preserved.');
				$this->assertSame($nodes[$poller], $db->query('SELECT instance_uuid FROM plugin_alarmmanager_node')->fetchColumn());
				$this->assertSame(base64_encode($keys[$poller]), $db->query("SELECT value FROM settings_user WHERE name='alarmmanager_key'")->fetchColumn());
				$cipher = $db->query('SELECT ciphertext FROM plugin_alarmmanager_credentials WHERE endpoint_id=2')->fetchColumn();
				$this->assertNotSame($sourceCipher, $cipher, 'Remote credentials are re-encrypted, not copied ciphertext.');
				$this->assertSame('Bearer generic-fixture', alarmmanager_decrypt_credential($cipher, 2, $keys[$poller])['headers']['Authorization']);
				$this->assertSame('1', $db->query("SELECT value FROM settings WHERE name='alarmmanager_notifications_paused'")->fetchColumn());
				$revision = $db->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn();
				alarmmanager_replicate_out($data);
				$this->assertSame($revision, $db->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn(), 'Unchanged sync does not grow history.');
			}
			// A collector without a running resident service keeps processing through its poller.
			$GLOBALS['config']               = ['poller_id' => 2, 'base_path' => $root . '/2'];
			$GLOBALS['alarmmanager_test_db'] = $connections[2];
			alarmmanager_poller_bottom();
			$this->assertSame('completed', $connections[2]->query('SELECT status FROM plugin_alarmmanager_jobs')->fetchColumn(), 'Replicated daemon enablement must not strand alarms on collectors without the service.');
			$this->assertSame($daemon, alarmmanager_supervisor_options());
			$this->assertSame(0, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_attempts')->fetchColumn(), 'Replicated queue pause prevents sends.');
			$GLOBALS['config']               = ['poller_id' => 1, 'base_path' => $root . '/1'];
			$GLOBALS['alarmmanager_test_db'] = $connections[1];
			$connections[2]->exec("UPDATE plugin_alarmmanager_credentials SET ciphertext='invalid-local-edit'");
			alarmmanager_replicate_out(['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]]);
			$this->assertSame('Bearer generic-fixture', alarmmanager_decrypt_credential($connections[2]->query('SELECT ciphertext FROM plugin_alarmmanager_credentials WHERE endpoint_id=2')->fetchColumn(), 2, $keys[2])['headers']['Authorization'], 'Main remains authoritative after remote edits.');

			try {
				alarmmanager_replicate_configuration($connections[1], 2);
				$this->fail('Main database must never be treated as a remote target.');
			} catch (RuntimeException $error) {
				$this->assertSame('configuration_replication_target_invalid', $error->getMessage());
			}
			$routes['profiles']                = [['id' => 1, 'name' => 'Operations', 'endpoints' => [1, 2]]];
			$routes['rules']                   = [['id' => 1, 'name' => 'All alarms', 'profile_id' => 1, 'policy' => []]];
			$routes['endpoints'][0]['enabled'] = false;
			alarmmanager_apply_configuration($routes);
			alarmmanager_save_credential(2, ['basic' => ['username' => 'service', 'password' => 'changed-password']]);
			alarmmanager_db_write("UPDATE settings SET value='0' WHERE name='alarmmanager_notifications_paused'");
			$daemon['delivery_workers'] = 2;
			alarmmanager_save_supervisor_options($daemon);
			$data           = ['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]];
			$beforeRevision = $connections[2]->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn();
			$connections[2]->exec("CREATE TRIGGER fail_credentials BEFORE INSERT ON plugin_alarmmanager_credentials FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_failure'");

			try {
				alarmmanager_replicate_out($data);
				$this->fail('Failed remote write must not mark replication successful.');
			} catch (RuntimeException $error) {
				$this->assertSame('configuration_replication_failed', $error->getMessage());
			}
			$this->assertSame($beforeRevision, $connections[2]->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn(), 'Remote update rolled back.');
			$this->assertSame('1', $connections[2]->query("SELECT value FROM settings WHERE name='alarmmanager_notifications_paused'")->fetchColumn(), 'Failed update keeps the old queue setting.');
			$connections[2]->exec('DROP TRIGGER fail_credentials');
			alarmmanager_replicate_out($data);
			$this->assertSame('Operations', $connections[2]->query('SELECT name FROM plugin_alarmmanager_profiles')->fetchColumn());
			$this->assertSame('All alarms', $connections[2]->query('SELECT name FROM plugin_alarmmanager_rules')->fetchColumn());
			$this->assertSame(0, (int) $connections[2]->query('SELECT enabled FROM plugin_alarmmanager_endpoints WHERE id=1')->fetchColumn());
			$GLOBALS['config']               = ['poller_id' => 2, 'base_path' => $root . '/2'];
			$GLOBALS['alarmmanager_test_db'] = $connections[2];
			$this->assertSame('changed-password', alarmmanager_load_credential(2)['basic']['password']);
			$this->assertFalse(alarmmanager_notifications_paused());
			alarmmanager_apply_replicated_daemon();
			$this->assertSame($daemon, alarmmanager_supervisor_options(), 'Collector writes main daemon settings to its local worker configuration.');
			$this->assertFileExists($root . '/2/cache/alarmmanager/workers.json');
			$GLOBALS['config']               = ['poller_id' => 1, 'base_path' => $root . '/1'];
			$GLOBALS['alarmmanager_test_db'] = $connections[1];
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_credentials');
			$routes['endpoints'] = [];
			$routes['profiles']  = [];
			$routes['rules']     = [];
			alarmmanager_apply_configuration($routes);
			alarmmanager_replicate_out($data);
			$this->assertSame(0, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_credentials')->fetchColumn(), 'Removed authentication does not survive on a remote.');
			$this->assertSame(0, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_endpoints WHERE enabled=1')->fetchColumn(), 'Removed destinations cannot send on a remote.');
			$this->assertSame(1, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_jobs')->fetchColumn(), 'Settings replication never clears queued alarms.');
		} finally {
			$GLOBALS['config'] = $before;

			foreach ($names as $name) {
				$server->exec("DROP DATABASE IF EXISTS `$name`");
			}

			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($root);
		}
	}

	/**
	 * Zero-touch pairs: the main GUI generates the shared secret and replication delivers it; a
	 * legacy file-era collector row is overwritten; disable/rotate propagate on the next cycle.
	 */
	public function testZeroTouchSyncPeerProvisioningReplicatesToCollector(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable test database creation must be enabled.');
		}
		require_once dirname(__DIR__, 2) . '/setup.php';
		require_once dirname(__DIR__, 2) . '/includes/replication.php';
		require_once dirname(__DIR__, 2) . '/includes/remote.php';
		require_once dirname(__DIR__, 2) . '/tests/fixtures/device_metadata_schema.php';
		$server      = alarmmanager_test_connect();
		$connections = $names = [];
		$root        = sys_get_temp_dir() . '/alarmmanager_zerotouch_' . bin2hex(random_bytes(6));
		mkdir($root, 0700);
		$before = $GLOBALS['config'];
		$url    = 'https://main.example.test/cacti/plugins/alarmmanager/alarmmanager_sync.php';

		try {
			foreach ([1, 2] as $poller) {
				$names[$poller] = 'alarmmanager_test_' . bin2hex(random_bytes(6));
				$server->exec("CREATE DATABASE `{$names[$poller]}` CHARACTER SET utf8mb4");
				$db = $connections[$poller] = alarmmanager_test_connect();
				$db->exec("USE `{$names[$poller]}`");
				$GLOBALS['config']               = ['poller_id' => $poller, 'base_path' => $root . '/' . $poller];
				$GLOBALS['alarmmanager_test_db'] = $db;
				$db->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
				$db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id,name)) ENGINE=InnoDB');
				$db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
				$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager','0.10.1',1)");
				alarmmanager_test_device_metadata_schema($db);
				$this->assertTrue(plugin_alarmmanager_install());
				$nodes[$poller] = $db->query('SELECT instance_uuid FROM plugin_alarmmanager_node')->fetchColumn();
			}
			// Legacy file-era pair on the collector: zero-touch must overwrite it, never stack a second row.
			$connections[2]->exec("INSERT INTO plugin_alarmmanager_sync_peers (poller_id, instance_uuid, key_file) VALUES (1, '" . str_repeat('9', 32) . "', 'legacy.key')");

			// Provisioning runs only on the main poller, for collectors Cacti knows.
			$GLOBALS['config']               = ['poller_id' => 2, 'base_path' => $root . '/2'];
			$GLOBALS['alarmmanager_test_db'] = $connections[2];

			try {
				alarmmanager_sync_peer_enable(2, $url, null, false);
				$this->fail('Provisioning on a collector is rejected.');
			} catch (RuntimeException $error) {
				$this->assertSame('sync_peer_provision_invalid', $error->getMessage());
			}

			// The main poller only provisions real collector rows, never itself or unknown pollers.
			$GLOBALS['config']               = ['poller_id' => 1, 'base_path' => $root . '/1'];
			$GLOBALS['alarmmanager_test_db'] = $connections[1];

			try {
				alarmmanager_sync_peer_enable(1, $url, null, false);
				$this->fail('Provisioning the main poller itself is rejected.');
			} catch (RuntimeException $error) {
				$this->assertSame('sync_peer_provision_invalid', $error->getMessage());
			}

			try {
				alarmmanager_sync_peer_enable(9, $url, null, false);
				$this->fail('Provisioning an unknown collector is rejected.');
			} catch (RuntimeException $error) {
				$this->assertSame('sync_peer_missing', $error->getMessage());
			}

			try {
				alarmmanager_sync_peer_enable(2, 'http://main.example.test/cacti/plugins/alarmmanager/alarmmanager_sync.php', '---no', false);
				$this->fail('Input validation happens before anything is stored.');
			} catch (InvalidArgumentException $error) {
				$this->assertContains($error->getMessage(), ['invalid_transport_url', 'invalid_ca_pem']);
			}
			$this->assertSame(0, (int) $connections[1]->query('SELECT COUNT(*) FROM plugin_alarmmanager_sync_peers')->fetchColumn(), 'Rejected provisioning stored no pair.');

			alarmmanager_sync_peer_enable(2, $url, null, false);
			$pair = $connections[1]->query('SELECT pair_secret, endpoint_url, ca_pem, allow_http, enabled, instance_uuid, key_file FROM plugin_alarmmanager_sync_peers WHERE poller_id=2')->fetch(PDO::FETCH_ASSOC);
			$this->assertMatchesRegularExpression('/\A[A-Za-z0-9+\/]{43}=\z/', $pair['pair_secret'], 'Fresh provision generates a 32-byte base64 pair secret.');
			$this->assertSame($url, $pair['endpoint_url']);
			$this->assertNull($pair['ca_pem']);
			$this->assertSame(0, (int) $pair['allow_http']);
			$this->assertSame(1, (int) $pair['enabled']);
			$this->assertSame('', $pair['instance_uuid'], 'Pair ships unregistered; the first signed packet proves identity.');
			$this->assertSame('', $pair['key_file'], 'Provisioned pairs have no file-era key reference.');
			$this->assertSame('on', $connections[1]->query('SELECT requires_sync FROM poller WHERE id=2')->fetchColumn(), 'Provisioning schedules native replication.');

			// Re-enable keeps the existing secret so a collector may already hold a copy.
			alarmmanager_sync_peer_enable(2, $url, null, true);
			$second = $connections[1]->query('SELECT pair_secret, allow_http, enabled FROM plugin_alarmmanager_sync_peers WHERE poller_id=2')->fetch(PDO::FETCH_ASSOC);
			$this->assertSame($pair['pair_secret'], $second['pair_secret'], 'Re-enable never regenerates a live pair secret.');
			$this->assertSame(1, (int) $second['allow_http']);

			$this->assertSame(['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]], alarmmanager_replicate_out(['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]]));
			$pushed = $connections[2]->query('SELECT * FROM plugin_alarmmanager_sync_peers WHERE poller_id=1')->fetch(PDO::FETCH_ASSOC);
			$this->assertSame($pair['pair_secret'], $pushed['pair_secret'], 'The collector adopts the shared secret.');
			$this->assertSame($nodes[1], $pushed['instance_uuid'], 'Collector target row carries the main node identity.');
			$this->assertSame($url, $pushed['endpoint_url']);
			$this->assertSame(1, (int) $pushed['allow_http']);
			$this->assertSame(1, (int) $pushed['enabled']);
			$this->assertNull($pushed['ca_pem']);
			$this->assertSame('legacy.key', $pushed['key_file'], 'Push never touches the retired file column.');

			$revision = $connections[2]->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn();
			alarmmanager_replicate_out(['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]]);
			$this->assertSame($revision, $connections[2]->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn(), 'Unchanged pair state is skipped by the digest marker.');

			// Rotation: a new secret plus a forgotten identity reaches the collector next cycle.
			alarmmanager_sync_peer_rotate(2);
			$rotated = $connections[1]->query('SELECT pair_secret, instance_uuid FROM plugin_alarmmanager_sync_peers WHERE poller_id=2')->fetch(PDO::FETCH_ASSOC);
			$this->assertNotSame($pair['pair_secret'], $rotated['pair_secret'], 'Rotation generates a new pair secret.');
			$this->assertSame('', $rotated['instance_uuid'], 'Rotation forgets the registered identity for re-registration.');
			alarmmanager_replicate_out(['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]]);
			$this->assertSame($rotated['pair_secret'], $connections[2]->query('SELECT pair_secret FROM plugin_alarmmanager_sync_peers WHERE poller_id=1')->fetchColumn(), 'Rotated secret replays to the collector.');

			// Disable propagates; an idle collector keeps silent instead of failing every cycle.
			alarmmanager_sync_peer_disable(2);
			alarmmanager_replicate_out(['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]]);
			$this->assertSame(0, (int) $connections[2]->query('SELECT enabled FROM plugin_alarmmanager_sync_peers WHERE poller_id=1')->fetchColumn(), 'Disable propagates to the collector pair.');
			$GLOBALS['config']               = ['poller_id' => 2, 'base_path' => $root . '/2'];
			$GLOBALS['alarmmanager_test_db'] = $connections[2];
			$this->assertFalse(alarmmanager_sync_send(), 'A disabled pair idles silently.');
		} finally {
			$GLOBALS['config'] = $before;

			foreach ($names as $name) {
				$server->exec("DROP DATABASE IF EXISTS `$name`");
			}

			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($root);
		}
	}
}
