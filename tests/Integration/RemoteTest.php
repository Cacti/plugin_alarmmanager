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
$checks      = 0;
$config      = ['poller_id' => 1];
$databases   = [];
$connections = [];
function rcheck(bool $ok, string $label): void {
	global $checks;

	if (!$ok) {
		throw new RuntimeException($label);
	} $checks++;
}
function rreject(callable $action, string $label): void {
	try {
		$action();
	} catch (Throwable $error) {
		rcheck(true, $label);

		return;
	}

throw new RuntimeException($label);
}
function ruse(int $poller): void {
	global $alarmmanager_test_db, $connections, $config;
	$config               = ['poller_id' => $poller];
	$alarmmanager_test_db = $connections[$poller];
	unset($GLOBALS['alarmmanager_route_cache']);
}
function rgrant(int $poller, string $owner = 'core', int $epoch = 1): array {
	return ['device_id' => 42, 'deployment_uuid' => str_repeat('d',32), 'poller_id' => $poller, 'epoch' => $epoch, 'owner' => $owner, 'mode' => 'centralized', 'profile_id' => 1, 'channels' => ['email', 'script'], 'valid_from' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)];
}
function rhost(string $kind, int $fail, int $recover = 0): array {
	return ['id'=>42, 'hostname'=>'router.test', 'description'=>'Fixture router', 'status'=>$kind === 'raised' ? 1 : 3, 'status_fail_date'=>$fail, 'status_rec_date'=>$recover];
}
function rwrite(array $host): callable {
	return static function () use ($host): bool { return db_execute_prepared('UPDATE host SET status=?, status_fail_date=FROM_UNIXTIME(?), status_rec_date=FROM_UNIXTIME(?), status_event_count=0 WHERE id=?',[$host['status'], $host['status_fail_date'], $host['status_rec_date'], $host['id']],false); };
}
$server               = alarmmanager_test_connect();
$alarmmanager_test_db = $server;

try {
	foreach ([1, 2, 3] as $poller) {
		$database           = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$databases[$poller] = $database;
		$server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$connections[$poller] = alarmmanager_test_connect();
		$connections[$poller]->exec("USE `$database`");
		ruse($poller);
		$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY (user_id,name)) ENGINE=InnoDB');
		$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY,version VARCHAR(32),status INT) ENGINE=InnoDB');
		$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager','0.7.0',1)");
		$alarmmanager_test_db->exec("CREATE TABLE host (id INT PRIMARY KEY,poller_id INT,deleted VARCHAR(2) DEFAULT '',status INT,status_event_count INT DEFAULT 0,status_fail_date DATETIME NULL,status_rec_date DATETIME NULL) ENGINE=InnoDB");
		$alarmmanager_test_db->exec("INSERT INTO host (id,poller_id,status) VALUES (42,$poller,3)");
		rcheck(plugin_alarmmanager_install(),'node install');
		rcheck(plugin_alarmmanager_install(),'node repeat setup');
		rcheck(plugin_alarmmanager_upgrade(),'node upgrade');
		$ids[$poller]  = alarmmanager_node_uuid();
		$keys[$poller] = alarmmanager_secret_key();
	}
	rcheck(count(array_unique($ids)) === 3,'independent durable node identities');
	rcheck($keys[1] !== $keys[2],'remote setup key independent');
	ruse(1);
	$routes = json_decode(file_get_contents(__DIR__ . '/../../examples/routing-delivery.json'),true);

	foreach ($routes['endpoints'] as &$endpoint) {
		$endpoint['transport']['credential'] = true;
		$endpoint['transport']['ca_bundle']  = null;
	}unset($endpoint);
	alarmmanager_apply_configuration($routes);
	alarmmanager_save_credential(1,['headers'=>['Authorization'=>'Bearer remote-fixture']]);
	alarmmanager_save_credential(2,['headers'=>['X-API-Key'=>'remote-fixture']]);
	$grant = alarmmanager_device_grant_validate(rgrant(2));
	alarmmanager_transaction(static function () use ($grant):void {alarmmanager_install_device_grant($grant); });
	$bundle = alarmmanager_remote_export_configuration(2,[1]);
	rcheck(count($bundle['credentials']) === 2,'selected credentials exported');
	ruse(2);
	alarmmanager_remote_provision($bundle);
	rcheck(alarmmanager_secret_key() === $keys[2],'provision preserves local setup key');
	rcheck(alarmmanager_load_credential(1)['headers']['Authorization'] === 'Bearer remote-fixture','remote credentials usable');
	$bad                                                         = $bundle;
	$bad['credentials'][0]['record']['headers']['Authorization'] = "invalid\r\nheader";
	$revision                                                    = (int)db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema');
	rreject(fn () =>alarmmanager_remote_provision($bad),'invalid provisioning rejected');
	rcheck((int)db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema') === $revision,'invalid provisioning no partial config change');
	alarmmanager_remote_provision($bundle);
	rcheck(alarmmanager_node_uuid() === $ids[2],'provision preserves node identity');
	$fail = time() - 30;
	$down = rhost('raised',$fail);
	rcheck(alarmmanager_device_core_commit($down,3,rwrite($down)),'confirmed down atomic commit');
	rcheck((int)db_fetch_cell_prepared('SELECT status FROM host WHERE id=42') === 1,'host status persisted');
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === 1,'one device event');
	$decision = alarmmanager_device_thold_decision($down,'raised');
	rcheck($decision['owned'],'core/thold duplicate notification fenced');
	rcheck(!alarmmanager_device_core_commit($down,3,rwrite($down)),'stale concurrent transition rejected');
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === 1,'stale no second event');
	$alarmmanager_test_db->exec('RENAME TABLE plugin_alarmmanager_jobs TO jobs_unavailable');
	$up = rhost('recovered',$fail,time());
	rcheck(!alarmmanager_device_core_commit($up,1,rwrite($up)),'queue failure rejects status transition');
	rcheck((int)db_fetch_cell_prepared('SELECT status FROM host WHERE id=42') === 1,'status rolls back with event');
	$alarmmanager_test_db->exec('RENAME TABLE jobs_unavailable TO plugin_alarmmanager_jobs');
	$connections[1]->exec('UPDATE plugin_config SET status=4'); // Main disabled/unreachable is irrelevant to the local collector pipeline.

	while ($claim = alarmmanager_claim_processing()) {
		alarmmanager_process_job($claim);
	}
	$sent                                       = [];
	$GLOBALS['alarmmanager_transport_handlers'] = ['restapi'=> static function ($event,$endpoint,$key) use (&$sent) {
		alarmmanager_load_credential((int)$endpoint['id']);
		$sent[] = $endpoint['id'] . ':' . $key;

		return ['outcome'=>'success', 'code'=>'fixture_sent'];
	}, 'webhook'=>static function ($event,$endpoint,$key) use (&$sent) {
		alarmmanager_load_credential((int)$endpoint['id']);
		$sent[] = $endpoint['id'] . ':' . $key;

		return ['outcome'=>'success', 'code'=>'fixture_sent'];
	}];

	while ($claim = alarmmanager_claim_delivery(['restapi', 'webhook'])) {
		alarmmanager_run_delivery($claim);
	}
	rcheck(count($sent) === 2,'disconnected local fanout delivered');
	rcheck((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status='completed'") === 2,'remote outcomes durable');
	$remoteNode   = alarmmanager_node_uuid();
	$remoteEvents = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
	ruse(1);
	$alarmmanager_test_db->exec('UPDATE plugin_config SET status=1');
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_sync_peers (poller_id,instance_uuid,key_file) VALUES (2,?,?)',[$remoteNode, 'unused-fixture']);
	$centralNode = alarmmanager_node_uuid();
	ruse(2);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_sync_peers (poller_id,instance_uuid,key_file) VALUES (1,?,?)',[$centralNode, 'unused-fixture']);
	$body = alarmmanager_sync_export(100);
	rcheck(is_string($body),'remote batch created');
	rcheck(alarmmanager_sync_export(100) === $body,'unacknowledged exact bytes retained');
	$key       = random_bytes(32);
	$signature = alarmmanager_sync_signature($body,2,time(),$key,'history');
	alarmmanager_sync_verify($body,2,time(),$signature,$key,'history');
	rcheck(true,'signed transfer verified');
	rreject(fn () =>alarmmanager_sync_verify($body . ' ',2,time(),$signature,$key,'history'),'tampering rejected');
	rreject(fn () =>alarmmanager_sync_verify($body,2,time() - 600,$signature,$key,'history'),'stale signature rejected');
	ruse(1);
	$ack = alarmmanager_sync_receive($body,2);
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history') > 0,'central history imported');
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs') === 0,'reconciliation creates no processing jobs');
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries') === 0,'reconciliation creates no delivery jobs');
	$historyCount = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history');
	rcheck(alarmmanager_sync_receive($body,2) === $ack,'lost ack replay idempotent');
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history') === $historyCount,'replay no history duplicates');
	$mirror   = alarmmanager_db_row("SELECT definition FROM plugin_alarmmanager_remote_history WHERE entity='event' LIMIT 1");
	$original = json_decode($mirror['definition'],true);
	$seed     = alarmmanager_validate_event(json_decode($original['payload'],true));
	rcheck($seed['event_id'] === json_decode($body,true)['records'][0]['data']['event_id'],'original event id preserved');
	rcheck($seed['collector_id'] === 2,'original collector retained');
	ruse(2);
	$badAck           = json_decode($ack,true);
	$badAck['digest'] = str_repeat('0',64);
	rreject(fn () =>alarmmanager_sync_acknowledge(json_encode($badAck)),'wrong ack refused');
	rcheck(alarmmanager_sync_export(100) === $body,'wrong ack keeps pending');
	alarmmanager_sync_acknowledge($ack);
	rcheck(alarmmanager_sync_export(100) === null,'ack advances outbox cursor');
	rcheck(count($sent) === 2,'sync never resends successful delivery');
	$up = rhost('recovered',$fail,time());
	rcheck(alarmmanager_device_core_commit($up,1,rwrite($up)),'recovery committed');

	while ($claim = alarmmanager_claim_processing()) {
		alarmmanager_process_job($claim);
	}

while ($claim = alarmmanager_claim_delivery(['restapi', 'webhook'])) {
		alarmmanager_run_delivery($claim);
	}rcheck(count($sent) === 4,'recovery delivered locally');
	$newBody = alarmmanager_sync_export(100);
	ruse(1);
	$newAck     = alarmmanager_sync_receive($newBody,2);
	$finalCount = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history');
	alarmmanager_sync_receive($body,2);
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_remote_history') === $finalCount,'older batch cannot rewind new history');
	ruse(2);
	alarmmanager_sync_acknowledge($newAck);
	$newFail = $fail + 60;
	$ongoing = rhost('raised',$newFail);
	rcheck(alarmmanager_device_core_commit($ongoing,3,rwrite($ongoing)),'new outage after recovery');
	rcheck(!alarmmanager_device_core_commit($up,1,rwrite($up)),'stale old recovery cannot overwrite new outage');

	while ($claim = alarmmanager_claim_processing()) {
		alarmmanager_process_job($claim);
	}

while ($claim = alarmmanager_claim_delivery(['restapi', 'webhook'])) {
		alarmmanager_run_delivery($claim);
	}
	$ongoingBody = alarmmanager_sync_export(100);
	ruse(1);
	$ongoingAck = alarmmanager_sync_receive($ongoingBody,2);
	$latest     = alarmmanager_db_row("SELECT definition FROM plugin_alarmmanager_remote_history WHERE device_id=42 AND source='cacti_device' ORDER BY sequence DESC LIMIT 1");
	$seed       = alarmmanager_validate_event(json_decode(json_decode($latest['definition'],true)['payload'],true));
	ruse(2);
	alarmmanager_sync_acknowledge($ongoingAck);
	$fail = $newFail;
	$down = $ongoing;
	// Reassign after lease cutoff; preserve an ongoing episode without requeueing its raise.
	$grantOld             = alarmmanager_device_grant(42);
	$future               = rgrant(3,'core',2);
	$future['seed_event'] = $seed;
	rreject(fn () =>alarmmanager_device_grant_validate(array_diff_key($future,['seed_event'=>1])),'handoff seed required');
	$future = alarmmanager_device_grant_validate($future);
	rreject(fn () =>alarmmanager_transaction(fn () =>alarmmanager_install_device_grant($future)),'overlapping reassignment refused');
	$alarmmanager_test_db->exec('UPDATE plugin_alarmmanager_device_owners SET valid_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 900 SECOND)');
	$connections[1]->exec('UPDATE plugin_alarmmanager_device_owners SET valid_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 900 SECOND)');
	rcheck(!alarmmanager_device_event_authorized($seed,10),'old collector lease fenced');
	rcheck(alarmmanager_device_thold_decision(rhost('raised',time()),'raised')['owned'],'expired source cannot fall back to legacy device sends');
	ruse(1);
	$future               = rgrant(3,'core',2);
	$future['seed_event'] = $seed;
	$future               = alarmmanager_device_grant_validate($future);
	alarmmanager_transaction(fn () =>alarmmanager_install_device_grant($future));
	$next = alarmmanager_remote_export_configuration(3,[1]);
	ruse(3);
	alarmmanager_remote_provision($next);
	rcheck((int)db_fetch_cell_prepared('SELECT status FROM host WHERE id=42') === 1,'handoff restores current down state');
	rcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs') === 0,'handoff seed creates no jobs');
	rcheck(alarmmanager_device_thold_decision($down,'raised')['owned'],'handoff raise remains owned without resend');
	$newUp = rhost('recovered',$fail,time() + 1);
	rcheck(alarmmanager_device_core_commit($newUp,1,rwrite($newUp)),'new collector recovery accepted');

	while ($claim = alarmmanager_claim_processing()) {
		alarmmanager_process_job($claim);
	}rcheck((int)db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states LIMIT 1') === 0,'new collector closes same episode');
	$latest = alarmmanager_db_row('SELECT payload FROM plugin_alarmmanager_events ORDER BY id DESC LIMIT 1');
	$latest = json_decode($latest['payload'],true);
	rcheck($latest['episode_id'] === $seed['episode_id'] && $latest['sequence'] > $seed['sequence'],'identity and order across collectors');
	// Native Cacti dates have second resolution. Separate outages in the same second still need new episodes.
	ruse(3);
	$sameSecond = $fail + 120;
	$one        = rhost('raised',$sameSecond);
	rcheck(alarmmanager_device_core_commit($one,3,rwrite($one)),'same-second first raise');
	$oneEvent = json_decode(db_fetch_cell_prepared('SELECT payload FROM plugin_alarmmanager_events ORDER BY id DESC LIMIT 1'),true);
	$oneUp    = rhost('recovered',$sameSecond,$sameSecond);
	rcheck(alarmmanager_device_core_commit($oneUp,1,rwrite($oneUp)),'same-second first recovery');
	rcheck(alarmmanager_device_core_commit($one,3,rwrite($one)),'same-second second raise');
	$twoEvent = json_decode(db_fetch_cell_prepared('SELECT payload FROM plugin_alarmmanager_events ORDER BY id DESC LIMIT 1'),true);
	rcheck($oneEvent['event_id'] !== $twoEvent['event_id'] && $oneEvent['episode_id'] !== $twoEvent['episode_id'],'durable counters disambiguate timestamp collisions');
	$badMode = alarmmanager_submit(['contract_version'=>1, 'mode'=>'observe', 'event'=>$twoEvent]);
	rcheck($badMode['status'] === 'failed','public device submission respects configured mode');
	$untrusted                                  = $twoEvent;
	$untrusted['context']['routing_profile_id'] = 2;
	rcheck(!alarmmanager_device_event_authorized($untrusted),'device profile cannot be overridden');
	// AUTO_INCREMENT allocation order is not commit order. Prefix locking must not skip a late commit.
	ruse(2);
	$concurrentDir = sys_get_temp_dir() . '/alarmmanager_commit_' . bin2hex(random_bytes(6));
	mkdir($concurrentDir,0700);
	$childFile = $concurrentDir . '/child.php';
	$ready     = $concurrentDir . '/ready';
	$childCode = '<?php require_once(' . var_export(__DIR__ . '/bootstrap.php',true) . '); $alarmmanager_test_db=alarmmanager_test_connect(); $alarmmanager_test_db->exec(' . var_export('USE `' . $databases[2] . '`',true) . "); \$alarmmanager_test_db->beginTransaction(); \$alarmmanager_test_db->exec(\"INSERT INTO plugin_alarmmanager_decisions (event_row_id,rule_id,endpoint_id,reason,created_at) VALUES (1,991,0,'commit-a',UTC_TIMESTAMP(6))\"); file_put_contents(" . var_export($ready,true) . ",'ready'); usleep(500000); \$alarmmanager_test_db->commit();";
	file_put_contents($childFile,$childCode);
	$command = [PHP_BINARY, '-n'];

	foreach (['pdo', 'mysqlnd', 'pdo_mysql'] as $extension) {
		$command = [...$command, '-d', 'extension=' . getenv('ALARMMANAGER_TEST_EXTENSION_DIR') . '/' . $extension . '.so'];
	}
	$child = proc_open([...$command, $childFile],[0=>['file', '/dev/null', 'r'], 1=>['file', '/dev/null', 'w'], 2=>['file', $concurrentDir . '/child.log', 'w']],$pipes);

	for ($i = 0; $i < 100 && !is_file($ready); $i++) {
		usleep(10000);
	}rcheck(is_file($ready),'concurrent source transaction staged');
	alarmmanager_db_write("INSERT INTO plugin_alarmmanager_decisions (event_row_id,rule_id,endpoint_id,reason,created_at) VALUES (1,992,0,'commit-b',UTC_TIMESTAMP(6))");
	$concurrentBody = alarmmanager_sync_export(100);
	$records        = json_decode($concurrentBody,true)['records'];
	$reasons        = [];

	foreach ($records as $record) {
		if ($record['entity'] === 'decision') {
			$reasons[] = $record['data']['reason'];
		}
	}
	rcheck(in_array('commit-a',$reasons,true) && in_array('commit-b',$reasons,true),'reverse commit order retains both history records');
	rcheck(proc_close($child) === 0,'concurrent source committed');

	foreach (glob($concurrentDir . '/*') as $file) {
		unlink($file);
	}rmdir($concurrentDir);
	// Measure confirmed transition overhead against native status writes in this disposable DB.
	ruse(3);
	$timings     = [];
	$queryCounts = [];

	for ($i = 0; $i < 40; $i++) {
		$kind    = $i % 2  === 0 ? 'recovered' : 'raised';
		$status  = $kind === 'raised' ? 1 : 3;
		$current = alarmmanager_db_row('SELECT status, UNIX_TIMESTAMP(status_fail_date) AS fail FROM host WHERE id=42');
		$sample  = rhost($kind,$kind === 'raised' ? time() : (int)$current['fail'],time());
		$q       = $GLOBALS['alarmmanager_test_queries'];
		$start   = hrtime(true);

		if (!alarmmanager_device_core_commit($sample,(int)$current['status'],rwrite($sample))) {
			throw new RuntimeException('measurement_transition');
		}
		$timings[]     = (hrtime(true) - $start) / 1e6;
		$queryCounts[] = $GLOBALS['alarmmanager_test_queries'] - $q;
	}
	sort($timings);
	$native = [];

	for ($i = 0; $i < 40; $i++) {
		$start = hrtime(true);
		rwrite($sample)();
		$native[] = (hrtime(true) - $start) / 1e6;
	}
	$measurement = ['fixture'=>'PHP 8.3.6 / MariaDB 10.11.14 / PDO adapters; no installed poller', 'confirmed_transitions'=>40, 'median_ms'=>$timings[20], 'p95_ms'=>$timings[37], 'queries_per_transition'=>array_sum($queryCounts) / 40, 'native_status_write_mean_ms'=>array_sum($native) / 40, 'php_peak_bytes'=>memory_get_peak_usage(true), 'normal_samples'=>'Source cache fixture verifies one grant lookup per cache window and zero per-sample configuration queries.'];

	if (getenv('ALARMMANAGER_DEVICE_MEASUREMENT')) {
		file_put_contents(getenv('ALARMMANAGER_DEVICE_MEASUREMENT'),json_encode($measurement,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	}
	print "PASS: $checks real database remote/device checks (three local databases; transport fixture).\n";
} finally {
	foreach ($databases as $database) {
		$server->exec("DROP DATABASE IF EXISTS `$database`");
	}
}
