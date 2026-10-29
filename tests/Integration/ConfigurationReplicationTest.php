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
			], 'rules' => []];
			$routes['endpoints'][] = ['id' => 5] + alarmmanager_integration_templates()['teams'];
			unset($routes['endpoints'][4]['auth'], $routes['endpoints'][4]['hint']);
			$teamsUrl                                           = 'https://prod-01.westus.logic.azure.com/workflows/fixture/triggers/manual/paths/invoke?sig=teams-fixture';
			$alarmQuery                                         = json_encode(['version' => 1, 'conditions' => [['field' => 'severity', 'operator' => '=', 'value' => 'critical']]], JSON_THROW_ON_ERROR);
			$routes['rules']                                    = [['id' => 1, 'name' => 'Critical alarms', 'endpoints' => [1, 2], 'policy' => ['alarm_query' => $alarmQuery]]];
			$routes['rules'][0]['policy']['template_overrides'] = [1 => ['default' => [['to' => 'text', 'source' => 'alarm.details']]]];
			alarmmanager_apply_configuration($routes);
			$this->assertSame('on', $connections[1]->query('SELECT requires_sync FROM poller WHERE id=2')->fetchColumn(), 'Main saves schedule native Cacti replication.');
			alarmmanager_save_credential(2, ['headers' => ['Authorization' => 'Bearer generic-fixture']]);
			alarmmanager_save_credential(5, ['teams_webhook_url' => $teamsUrl]);
			// Cacti's native settings replication carries the daemon choices to collectors.
			$daemon = array_replace(alarmmanager_supervisor_defaults(), ['enabled' => true, 'processing_workers' => 3, 'delivery_workers' => 7]);

			foreach ([1, 2, 3] as $node) {
				foreach (['alarmmanager_daemon_enabled' => 'on', 'alarmmanager_daemon_processing_workers' => '3', 'alarmmanager_daemon_delivery_workers' => '7'] as $name => $value) {
					$connections[$node]->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
				}
			}
			alarmmanager_db_write("REPLACE INTO settings VALUES ('alarmmanager_notifications_paused', '1')");
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source,scope,resource_id,definition) VALUES (?, ?, ?, ?)', ['syslog', 'default', 0, json_encode(['profile_id' => 7, 'channels' => ['script']])]);
			$sourceCipher = $connections[1]->query('SELECT ciphertext FROM plugin_alarmmanager_credentials WHERE endpoint_id=2')->fetchColumn();

			foreach ([2, 3] as $poller) {
				$data = ['class' => 'all', 'remote_poller_id' => $poller, 'rcnn_id' => $connections[$poller]];
				$this->assertSame($data, alarmmanager_replicate_out($data));
				$db = $connections[$poller];
				$this->assertSame(['channels' => ['script'], 'complete_history' => false], json_decode($db->query("SELECT definition FROM plugin_alarmmanager_producer_config WHERE source='syslog'")->fetchColumn(), true), 'Retired profile settings are discarded during replication.');
				$this->assertSame(5, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_endpoints WHERE enabled=1')->fetchColumn(), 'Every destination replicates without profiles.');
				$this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_events')->fetchColumn(), 'Local history preserved.');
				$this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_jobs')->fetchColumn(), 'Local queued jobs preserved.');
				$this->assertSame($nodes[$poller], $db->query('SELECT instance_uuid FROM plugin_alarmmanager_node')->fetchColumn());
				$this->assertSame(base64_encode($keys[$poller]), $db->query("SELECT value FROM settings_user WHERE name='alarmmanager_key'")->fetchColumn());
				$this->assertSame($teamsUrl, alarmmanager_load_credential(5)['teams_webhook_url']);
				$this->assertTrue(alarmmanager_endpoint_transport(['id' => 5, 'type' => 'webhook'])['teams_workflow']);
				$cipher = $db->query('SELECT ciphertext FROM plugin_alarmmanager_credentials WHERE endpoint_id=2')->fetchColumn();
				$this->assertNotSame($sourceCipher, $cipher, 'Remote credentials are re-encrypted, not copied ciphertext.');
				$this->assertSame('Bearer generic-fixture', alarmmanager_decrypt_credential($cipher, 2, $keys[$poller])['headers']['Authorization']);
				$this->assertSame('1', $db->query("SELECT value FROM settings WHERE name='alarmmanager_notifications_paused'")->fetchColumn());
				$definition = json_decode($db->query('SELECT definition FROM plugin_alarmmanager_configuration_history ORDER BY revision DESC LIMIT 1')->fetchColumn(), true, 24, JSON_THROW_ON_ERROR);
				$this->assertSame($alarmQuery, $definition['rules'][0]['policy']['alarm_query'], 'Alarm conditions synchronize to collectors.');
				$this->assertSame(alarmmanager_mapping_validate($routes['rules'][0]['policy']['template_overrides'][1]), $definition['rules'][0]['policy']['template_overrides'][1], 'Rule message templates synchronize with the rule.');
				$revision = $db->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn();
				alarmmanager_replicate_out($data);
				$this->assertSame($revision, $db->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn(), 'Unchanged sync does not grow history.');
			}
			// A collector without a running resident service keeps processing through its poller.
			$GLOBALS['config']               = ['poller_id' => 2, 'base_path' => $root . '/2'];
			$GLOBALS['alarmmanager_test_db'] = $connections[2];
			$GLOBALS['remote_db_cnn_id']     = $connections[1];
			mkdir($root . '/2/cache/alarmmanager', 0700, true);
			file_put_contents($root . '/2/cache/alarmmanager/status.json', '{}');
			alarmmanager_poller_bottom();
			$this->assertGreaterThan(0, (int) $connections[1]->query('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history WHERE poller_id = 2')->fetchColumn(), 'Poller hook returns history over Cacti connection.');
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
			$routes['rules']                              = [['id' => 1, 'name' => 'All alarms', 'endpoints' => [1, 2], 'policy' => []]];
			$device_query                                 = json_encode(['version' => 1, 'conditions' => [['field' => 'host_template_id', 'operator' => '=', 'value' => '1']]], JSON_THROW_ON_ERROR);
			$routes['rules'][0]['policy']['device_query'] = $device_query;
			$routes['endpoints'][0]['enabled']            = false;
			alarmmanager_apply_configuration($routes);
			alarmmanager_save_credential(2, ['basic' => ['username' => 'service', 'password' => 'changed-password']]);
			alarmmanager_db_write("UPDATE settings SET value='0' WHERE name='alarmmanager_notifications_paused'");
			$daemon['delivery_workers'] = 2;
			$connections[2]->exec("UPDATE settings SET value = '2' WHERE name = 'alarmmanager_daemon_delivery_workers'");
			$data           = ['class' => 'all', 'remote_poller_id' => 2, 'rcnn_id' => $connections[2]];
			$beforeRevision = $connections[2]->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn();
			$connections[2]->exec("CREATE TRIGGER fail_credentials BEFORE INSERT ON plugin_alarmmanager_credentials FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_failure'");

			$this->assertSame($data, alarmmanager_replicate_out($data), 'A failed remote write must not escape the Cacti hook and disable the plugin.');
			$this->assertSame($beforeRevision, $connections[2]->query('SELECT config_revision FROM plugin_alarmmanager_schema')->fetchColumn(), 'Remote update rolled back.');
			$this->assertSame('1', $connections[2]->query("SELECT value FROM settings WHERE name='alarmmanager_notifications_paused'")->fetchColumn(), 'Failed update keeps the old queue setting.');
			$connections[2]->exec('DROP TRIGGER fail_credentials');
			alarmmanager_replicate_out($data);
			$this->assertSame('[1,2]', $connections[2]->query('SELECT endpoints_json FROM plugin_alarmmanager_rules')->fetchColumn());
			$this->assertSame($device_query, json_decode($connections[2]->query('SELECT definition FROM plugin_alarmmanager_rules')->fetchColumn(), true)['device_query']);
			$this->assertSame('All alarms', $connections[2]->query('SELECT name FROM plugin_alarmmanager_rules')->fetchColumn());
			$this->assertSame(0, (int) $connections[2]->query('SELECT enabled FROM plugin_alarmmanager_endpoints WHERE id=1')->fetchColumn());
			$GLOBALS['config']               = ['poller_id' => 2, 'base_path' => $root . '/2'];
			$GLOBALS['alarmmanager_test_db'] = $connections[2];
			$this->assertSame('changed-password', alarmmanager_load_credential(2)['basic']['password']);
			$this->assertFalse(alarmmanager_notifications_paused());
			alarmmanager_daemon_sync();
			$this->assertSame($daemon, alarmmanager_supervisor_options(), 'Collector writes the replicated daemon settings to its local worker configuration.');
			$this->assertFileExists($root . '/2/cache/alarmmanager/workers.json');
			$GLOBALS['config']               = ['poller_id' => 1, 'base_path' => $root . '/1'];
			$GLOBALS['alarmmanager_test_db'] = $connections[1];
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_credentials');
			$routes['endpoints'] = [];
			$routes['rules']     = [];
			alarmmanager_apply_configuration($routes);
			alarmmanager_replicate_out($data);
			$this->assertSame(0, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_credentials')->fetchColumn(), 'Removed authentication does not survive on a remote.');
			$this->assertSame(0, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_endpoints WHERE enabled=1')->fetchColumn(), 'Removed destinations cannot send on a remote.');
			$this->assertSame(1, (int) $connections[2]->query('SELECT COUNT(*) FROM plugin_alarmmanager_jobs')->fetchColumn(), 'Settings replication never clears queued alarms.');
		} finally {
			unset($GLOBALS['remote_db_cnn_id']);
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
