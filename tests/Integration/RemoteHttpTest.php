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
if (PHP_SAPI !== 'cli' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1' || !getenv('ALARMMANAGER_TEST_DSN')) {
	exit(2);
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/workers.php');
require_once(__DIR__ . '/../../includes/remote.php');
$checks               = 0;
$databases            = [];
$connections          = [];
$children             = [];
$config               = ['poller_id'=>1];
$server               = alarmmanager_test_connect();
$alarmmanager_test_db = $server;
function hcheck(bool $ok,string $label):void {
	global $checks;

	if (!$ok) {
		throw new RuntimeException($label);
	}$checks++;
}
function hreject(callable $action,string $label):void {
	try {
		$action();
	} catch (Throwable $e) {
		hcheck(true,$label);

		return;
	}

throw new RuntimeException($label);
}
function huse(int $poller):void {
	global $config,$alarmmanager_test_db,$connections;
	$config               = ['poller_id'=>$poller];
	$alarmmanager_test_db = $connections[$poller];
	unset($GLOBALS['alarmmanager_route_cache']);
}
function hstart(array $command,string $log):mixed {
	$process = proc_open($command,[0=>['file', '/dev/null', 'r'], 1=>['file', $log, 'a'], 2=>['file', $log, 'a']],$pipes);

	if (!is_resource($process)) {
		throw new RuntimeException('fixture_process');
	}

return $process;
}
$fixture = sys_get_temp_dir() . '/alarmmanager_tls_' . bin2hex(random_bytes(6));
mkdir($fixture,0700);
$repo = realpath(__DIR__ . '/../..');

try {
	foreach ([1, 2] as $poller) {
		$name               = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$databases[$poller] = $name;
		$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
		$connections[$poller] = alarmmanager_test_connect();
		$connections[$poller]->exec("USE `$name`");
		huse($poller);
		$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT,name VARCHAR(255),value TEXT,PRIMARY KEY(user_id,name)) ENGINE=InnoDB');
		$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY,version VARCHAR(32),status INT) ENGINE=InnoDB');
		$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager','0.7.0',1)");
		hcheck(plugin_alarmmanager_install(),'fixture install');
		$uuid[$poller] = alarmmanager_node_uuid();
	}
	$keyFile = $fixture . '/peer.key';
	alarmmanager_remote_private_write($keyFile,base64_encode(random_bytes(32)) . "\n");
	$certificate = $fixture . '/cert.pem';
	$private     = $fixture . '/tls.key';
	$cmd         = ['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $private, '-out', $certificate, '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1'];
	$p           = hstart($cmd,$fixture . '/openssl.log');

	if (proc_close($p) !== 0) {
		throw new RuntimeException('certificate');
	}chmod($private,0600);
	// Actual public receiver, with a disposable offline Cacti database-bootstrap fixture.
	$web = $fixture . '/web';
	mkdir($web . '/include',0700,true);
	mkdir($web . '/plugins/alarmmanager',0700,true);

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file) {
		$relative = substr($file->getPathname(),strlen($repo) + 1);
		$target   = $web . '/plugins/alarmmanager/' . $relative;

		if ($file->isDir()) {
			if (!is_dir($target)) {
				mkdir($target,0700,true);
			}
		} else {
			copy($file->getPathname(),$target);
		}
	}
	$global = "<?php\nrequire_once(" . var_export(__DIR__ . '/bootstrap.php',true) . ");\n\$config=['poller_id'=>1];\n\$alarmmanager_test_db=alarmmanager_test_connect();\n\$alarmmanager_test_db->exec(" . var_export('USE `' . $databases[1] . '`',true) . ");\n";
	file_put_contents($web . '/include/global.php',$global);
	chmod($web . '/include/global.php',0600);
	huse(1);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_sync_peers (poller_id,instance_uuid,key_file) VALUES (2,?,?)',[$uuid[2], $keyFile]);
	putenv('ALARMMANAGER_TEST_WEB=1');
	$command = [PHP_BINARY];

	if (php_ini_loaded_file()) {
		$command = [...$command, '-c', php_ini_loaded_file()];
	} else {
		$command[] = '-n';

		foreach (['posix', 'ctype', 'pdo', 'mysqlnd', 'pdo_mysql'] as $extension) {
			$path = ini_get('extension_dir') . '/' . $extension . '.so';

			if (is_file($path)) {
				$command = [...$command, '-d', 'extension=' . $path];
			}
		}
	}

	// Current loaded extension directory can be supplied by the local PHP test runtime.
	if (getenv('ALARMMANAGER_TEST_EXTENSION_DIR')) {
		$command = [PHP_BINARY, '-n'];

		foreach (['posix', 'ctype', 'pdo', 'mysqlnd', 'pdo_mysql'] as $extension) {
			$command = [...$command, '-d', 'extension=' . getenv('ALARMMANAGER_TEST_EXTENSION_DIR') . '/' . $extension . '.so'];
		}
	}
	$children[] = hstart([...$command, '-S', '127.0.0.1:38570', '-t', $web],$fixture . '/web.log');
	$children[] = hstart(['python3', $repo . '/tests/fixtures/remote_tls_proxy.py', '--port', '38569', '--backend', '38570', '--cert', $certificate, '--key', $private, '--state', $fixture . '/counts.json'],$fixture . '/tls.log');

	for ($i = 0; $i < 50; $i++) {
		$socket = @stream_socket_client('tcp://127.0.0.1:38569',$errno,$error,0.1);

		if ($socket) {
			fclose($socket);

			break;
		}usleep(100000);
	}
	huse(2);
	$base = 'https://127.0.0.1:38569';
	$url  = $base . '/plugins/alarmmanager/alarmmanager_sync.php';
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_sync_peers (poller_id,instance_uuid,key_file,endpoint_url,ca_bundle) VALUES (1,?,?,?,?)',[$uuid[1], $keyFile, $base . '/offline', $certificate]);
	$routes = json_decode(file_get_contents($repo . '/examples/routing-delivery.json'),true);

	foreach ($routes['endpoints'] as &$endpoint) {
		$endpoint['transport']['url']        = $base . '/alarm/' . $endpoint['id'];
		$endpoint['transport']['ca_bundle']  = $certificate;
		$endpoint['transport']['credential'] = true;
	}unset($endpoint);
	alarmmanager_apply_configuration($routes);
	alarmmanager_save_credential(1,['headers'=>['Authorization'=>'Bearer fixture']]);
	alarmmanager_save_credential(2,['headers'=>['X-Fixture'=>'fixture']]);
	$event                    = json_decode(file_get_contents($repo . '/examples/event.json'),true);
	$event['collector_id']    = 2;
	$event['source_instance'] = 'tls-remote';
	$result                   = alarmmanager_submit(['contract_version'=>1, 'mode'=>'centralized', 'event'=>$event]);
	hcheck($result['status'] === 'accepted','remote ingest main unavailable');
	hreject(fn () =>alarmmanager_sync_send(),'main outage retains batch');
	$pending = alarmmanager_sync_export();
	hcheck($pending !== null,'pending history after outage');

	while ($claim = alarmmanager_claim_processing()) {
		alarmmanager_process_job($claim);
	}
	// Inject a real HTTPS fixture adapter; production cURL adapters retain their Phase-5 qualification.
	$adapter = static function ($event,$endpoint,$idempotency) {
		$settings   = alarmmanager_endpoint_transport($endpoint);
		$credential = alarmmanager_load_credential((int)$endpoint['id']);
		$headers    = "Content-Type: application/json\r\nIdempotency-Key: $idempotency\r\n";

		foreach ($credential['headers'] as $name=>$value) {
			$headers .= $name . ': ' . $value . "\r\n";
		}$ctx     = stream_context_create(['ssl'=>['verify_peer'=>true, 'verify_peer_name'=>true, 'cafile'=>$settings['ca_bundle']], 'http'=>['method'=>'POST', 'header'=>$headers, 'content'=>json_encode(alarmmanager_map_payload($settings['mapping'],$event)), 'timeout'=>2, 'follow_location'=>0]]);
		$response = @file_get_contents($settings['url'],false,$ctx);

		return ['outcome'=>$response !== false ? 'success' : 'retry', 'code'=>'https_fixture'];
	};
	$GLOBALS['alarmmanager_transport_handlers'] = ['restapi'=>$adapter, 'webhook'=>$adapter];

	while ($claim = alarmmanager_claim_delivery(['restapi', 'webhook'])) {
		alarmmanager_run_delivery($claim);
	}
	$counts = json_decode(file_get_contents($fixture . '/counts.json'),true);
	hcheck(array_sum($counts['alarms']) === 2,'local real HTTPS fanout while main unavailable');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_peers SET endpoint_url=? WHERE poller_id=1',[$base . '/redirect']);
	hreject(fn () =>alarmmanager_sync_send(),'redirect rejected');
	hcheck(alarmmanager_sync_export() === $pending,'redirect keeps exact pending bytes');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_peers SET endpoint_url=?,ca_bundle=NULL WHERE poller_id=1',[$url]);
	hreject(fn () =>alarmmanager_sync_send(),'untrusted self-signed cert rejected');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_peers SET ca_bundle=?,endpoint_url=? WHERE poller_id=1',[$certificate, $base . '/dropack']);
	hreject(fn () =>alarmmanager_sync_send(),'lost acknowledgment after central commit');
	hcheck(alarmmanager_sync_export() === $pending,'lost ack retains exact bytes');
	huse(1);
	hcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history') > 0,'actual receiver committed history before dropped ack');
	hcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries') === 0,'actual receiver never redispatched');
	huse(2);
	alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_peers SET endpoint_url=? WHERE poller_id=1',[$base . '/badack']);
	hreject(fn () =>alarmmanager_sync_send(),'tampered ack rejected');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_sync_peers SET endpoint_url=? WHERE poller_id=1',[$url]);
	hcheck(alarmmanager_sync_send(),'reconnect replay acknowledged');

	for ($i = 0; $i < 20 && alarmmanager_sync_export() !== null; $i++) {
		alarmmanager_sync_send();
	}hcheck(alarmmanager_sync_export() === null,'all outcomes synchronized and acknowledged');
	alarmmanager_sync_send();
	huse(1);
	hcheck((int)db_fetch_cell_prepared('SELECT TIMESTAMPDIFF(SECOND,last_seen,UTC_TIMESTAMP()) FROM plugin_alarmmanager_sync_peers WHERE poller_id=2') < 10,'signed quiet heartbeat health');
	hcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs') === 0,'no central processing after final reconciliation');
	$counts = json_decode(file_get_contents($fixture . '/counts.json'),true);
	hcheck(array_sum($counts['alarms']) === 2,'reconnect no notification resend');
	hcheck(!str_contains($pending,'Bearer fixture'),'history excludes endpoint credentials');
	print "PASS: $checks real TLS receiver/outage/reconnect checks (offline bootstrap and HTTPS transport fixtures).\n";
} finally {
	foreach ($children as $child) {
		proc_terminate($child);
		proc_close($child);
	}

foreach ($databases as $name) {
		$server->exec("DROP DATABASE IF EXISTS `$name`");
	}

if (is_dir($fixture)) {
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}rmdir($fixture);
	}
}
