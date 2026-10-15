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
final class ApiTest extends AlarmManagerTestCase {
	private function request(string $url, ?string $token, string $body, string $method = 'POST', string $type = 'application/json'): array {
		$curl = curl_init($url);
		curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'Authorization: Bearer ' . ($token ?? '')], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
		$response = curl_exec($curl);
		$status   = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		return [$status, json_decode($response, true, 16, JSON_THROW_ON_ERROR)];
	}
	public function testHttpAuthenticationScopesDurableRetriesAndProducerOutbox(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1' || !extension_loaded('curl')) {
			$this->markTestSkipped('Disposable database opt-in and cURL required.');
		}
		require_once dirname(__DIR__, 2) . '/setup.php';
		require_once dirname(__DIR__, 2) . '/includes/api.php';
		require_once dirname(__DIR__, 2) . '/integrations/producer_bridge.php';
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$GLOBALS['config']               = ['poller_id' => 1];
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$temp                            = sys_get_temp_dir() . '/alarmmanager-api-' . bin2hex(random_bytes(6));
		mkdir($temp, 0700);
		$server = null;

		try {
			$db->exec("CREATE DATABASE `$database`");
			$db->exec("USE `$database`");
			require_once __DIR__ . '/../fixtures/device_metadata_schema.php';
			alarmmanager_test_device_metadata_schema($db);
			$db->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(191) NOT NULL, value TEXT NOT NULL, PRIMARY KEY(user_id,name)) ENGINE=InnoDB');
			$db->exec('CREATE TABLE plugin_config (directory VARCHAR(32) PRIMARY KEY, status INT NOT NULL, version VARCHAR(32) NOT NULL) ENGINE=InnoDB');
			$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager',1,'0.9.0')");
			alarmmanager_setup_key();
			$this->assertTrue(alarmmanager_schema_install());
			$this->assertTrue(alarmmanager_schema_install(), 'Repeat upgrade preserves the API schema');
			$this->assertSame(9, (int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema'));
			$db->exec("INSERT INTO plugin_alarmmanager_profiles(id,name,enabled,endpoints_json) VALUES (1,'Fixture route',1,'[]')");
			$db->exec("INSERT INTO plugin_alarmmanager_rules(id,name,priority,enabled,profile_id,stop_processing,definition) VALUES (1,'Fixture rule',1,1,1,0,'{}')");
			$definition = ['name' => 'External monitor', 'source' => 'sample', 'source_instance' => 'lab-cacti', 'collector_id' => 1, 'mode' => 'observe', 'profile_id' => 0, 'device_ids' => [42], 'all_devices' => false, 'unmapped_devices' => false];
			$created    = alarmmanager_api_client_save($definition);
			$token      = $created['token'];
			$this->assertNotSame($token, db_fetch_cell_prepared('SELECT token_hash FROM plugin_alarmmanager_api_clients'));
			mkdir($temp . '/include');
			symlink(getenv('ALARMMANAGER_CACTI_ROOT') ? getenv('ALARMMANAGER_CACTI_ROOT') . '/include/vendor' : dirname((new ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2), $temp . '/include/vendor');
			mkdir($temp . '/plugins');
			mkdir($temp . '/plugins/alarmmanager');
			copy(dirname(__DIR__, 2) . '/alarmmanager_api.php', $temp . '/plugins/alarmmanager/alarmmanager_api.php');
			symlink(dirname(__DIR__, 2) . '/includes', $temp . '/plugins/alarmmanager/includes');
			$bootstrap = '<?php require ' . var_export(__DIR__ . '/bootstrap.php', true) . '; $GLOBALS["alarmmanager_test_db"]=alarmmanager_test_connect(); $GLOBALS["alarmmanager_test_db"]->exec(' . var_export("USE `$database`", true) . '); $GLOBALS["config"]=["poller_id"=>1];';
			file_put_contents($temp . '/include/global.php', $bootstrap);
			$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
			$port   = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
			fclose($socket);
			$env                          = getenv();
			$env['ALARMMANAGER_TEST_WEB'] = '1';
			$server                       = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $temp], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes, null, $env);
			$url                          = 'http://127.0.0.1:' . $port . '/plugins/alarmmanager/alarmmanager_api.php';

			for ($attempt = 0; $attempt < 30; $attempt++) {
				$conn = @fsockopen('127.0.0.1', $port);

				if ($conn) {
					fclose($conn);

					break;
				}
				usleep(100000);
			}
			$event = json_decode(file_get_contents(dirname(__DIR__, 2) . '/examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
			$body  = json_encode(['contract_version' => 1, 'mode' => 'observe', 'event' => $event]);
			$this->assertSame(401, $this->request($url, 'invalid', $body)[0]);
			$this->assertSame(405, $this->request($url, $token, '', 'GET')[0]);
			$this->assertSame(415, $this->request($url, $token, $body, 'POST', 'text/plain')[0]);
			$this->assertSame(413, $this->request($url, $token, str_repeat('x', 32769))[0]);
			$this->assertSame(400, $this->request($url, $token, '{')[0]);
			[$status, $ack] = $this->request($url, $token, $body);
			$this->assertSame(202, $status);
			$this->assertFalse($ack['owned']);
			$this->assertSame('accepted', $ack['status']);
			[$status, $duplicate] = $this->request($url, $token, $body);
			$this->assertSame(200, $status);
			$this->assertSame('already_accepted', $duplicate['status']);
			$this->assertSame($ack['event_row_id'], $duplicate['event_row_id']);
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs'));

			foreach ([['source', 'thold'], ['source_instance', 'another'], ['collector_id', 2], ['device', ['id' => 43]], ['device', ['hostname' => 'unmapped']]] as [$key, $value]) {
				$bad       = $event;
				$bad[$key] = $value;
				$this->assertSame(403, $this->request($url, $token, json_encode(['contract_version' => 1, 'mode' => 'observe', 'event' => $bad]))[0]);
			}
			$changed            = $event;
			$changed['details'] = 'Changed identity payload';
			$this->assertSame(409, $this->request($url, $token, json_encode(['contract_version' => 1, 'mode' => 'observe', 'event' => $changed]))[0]);
			$GLOBALS['alarmmanager_fail_commit'] = true;
			$rollback                            = $event;
			$rollback['event_id']                = 'rollback-event';
			$failed                              = alarmmanager_api_receive('Bearer ' . $token, json_encode(['contract_version' => 1, 'mode' => 'observe', 'event' => $rollback]));
			$this->fail('Unconfirmed commit returned acceptance');
		} catch (RuntimeException $error) {
			if (empty($GLOBALS['alarmmanager_fail_commit'])) {
				throw $error;
			}
			$this->assertSame('storage_failure', $error->getMessage());
			$GLOBALS['alarmmanager_fail_commit'] = false;
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id='rollback-event'"));
			$rotated = alarmmanager_api_client_save($definition, $created['id'], 1, true, true);
			$this->assertSame(401, $this->request($url, $token, $body)[0]);
			$this->assertSame(200, $this->request($url, $rotated['token'], $body)[0]);
			alarmmanager_api_client_save($definition, $created['id'], 2, false);
			$this->assertSame(401, $this->request($url, $rotated['token'], $body)[0]);
			$this->assertSame('already_accepted', db_fetch_cell_prepared('SELECT last_status FROM plugin_alarmmanager_api_clients WHERE id=?', [$created['id']]));
			// A producer retry uses its own durable receipt after a lost HTTP acknowledgement.
			$producer = array_replace($definition, ['name' => 'Generic producer', 'source' => 'generic_monitor', 'source_instance' => 'cacti-poller-1', 'mode' => 'centralized', 'profile_id' => 1]);
			$account  = alarmmanager_api_client_save($producer);
			mkdir($temp . '/private', 0700);
			putenv('ALARMMANAGER_PRODUCER_API_DIR=' . $temp . '/private');
			$settings = ['url' => $url, 'token' => $account['token'], 'mode' => 'centralized', 'profile_id' => 1, 'channels' => ['email'], 'complete_history' => true];
			$file     = $temp . '/private/generic_monitor.json';
			file_put_contents($file, json_encode($settings));
			chmod($file, 0600);
			AlarmManagerProducerBridge::installApiOutbox();
			$input  = ['transition' => 'raised', 'severity' => 'major', 'occurred_at' => $event['occurred_at'], 'device' => $event['device'], 'summary' => 'API producer alarm', 'details' => '', 'rule_name' => 'Generic rule', 'rule_type' => 'generic', 'metric_value' => '', 'suppressed' => false, 'suppression_reason' => ''];
			$result = AlarmManagerProducerBridge::publish('generic_monitor', 42, 0, 'first-alarm', $input);
			$this->assertSame('accepted', $result['status']);
			$this->assertTrue($result['owned']);
			$receipt = hash('sha256', 'generic_monitor|1|42|first-alarm');
			// Simulate loss of the local acknowledgement AFTER the receiver committed.
			$db->exec("UPDATE plugin_alarmmanager_producer_receipts SET status='pending' WHERE receipt='$receipt'");
			$this->assertSame('accepted', AlarmManagerProducerBridge::retry($receipt));
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id=?', [$receipt]));
			$settings['url'] = 'http://127.0.0.1:1/unavailable';
			file_put_contents($file, json_encode($settings));
			$pending = AlarmManagerProducerBridge::publish('generic_monitor', 42, 0, 'outage-alarm', $input);
			$this->assertSame('pending', $pending['status']);
			$db->beginTransaction();
			$stillPending = AlarmManagerProducerBridge::publish('generic_monitor', 42, 0, 'outage-alarm', $input);
			$this->assertTrue($stillPending['owned'], 'Caller-owned transactions cannot revert uncertain API ownership');
			$db->rollBack();
			$this->assertTrue($pending['owned'], 'No legacy fallback after an uncertain HTTP handoff');
			$pendingReceipt = hash('sha256', 'generic_monitor|1|42|outage-alarm');
			$this->assertSame('pending', db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_producer_receipts WHERE receipt=?', [$pendingReceipt]));
			$settings['url'] = $url;
			file_put_contents($file, json_encode($settings));
			$this->assertSame('accepted', AlarmManagerProducerBridge::retry($pendingReceipt));
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs j JOIN plugin_alarmmanager_events e ON e.id=j.event_row_id WHERE e.event_id=?', [$pendingReceipt]));
			file_put_contents($temp . '/include/global.php', '<?php echo "PRIVATE_BOOTSTRAP_DIAGNOSTIC"; exit;');
			[$bootstrapStatus, $bootstrapResponse] = $this->request($url, $account['token'], $body);
			$this->assertSame(503, $bootstrapStatus, 'Bootstrap exit returns API unavailable');
			$this->assertSame(['status'=>'rejected', 'error'=>'unavailable'], $bootstrapResponse, 'Bootstrap diagnostics never leak into the JSON API');
		} finally {
			$GLOBALS['alarmmanager_fail_commit'] = false;
			putenv('ALARMMANAGER_PRODUCER_API_DIR');

			if (is_resource($server)) {
				proc_terminate($server);
				proc_close($server);
			}
			$db->exec("DROP DATABASE IF EXISTS `$database`");

			if (is_dir($temp)) {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

				foreach ($iterator as $file) {
					$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
				}
				rmdir($temp);
			}
		}
	}
}
