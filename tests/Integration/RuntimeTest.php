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
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1' || !getenv('ALARMMANAGER_TEST_DSN')) {
	fwrite(STDERR, "Dedicated test-server DSN and create opt-in required.\n");
	exit(2);
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/workers.php');
$alarmmanager_test_db = alarmmanager_test_connect();
$database             = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$alarmmanager_test_db->exec("USE `$database`");
$config = ['poller_id' => 2];
$checks = 0;
function am_check(bool $value, string $message): void {
	global $checks;

	if (!$value) {
		throw new RuntimeException($message);
	} $checks++;
}
function am_fixture(string $id, int $seq = 1, string $kind = 'raised', string $episode = 'outage-1', string $key = 'device:42', string $mode = 'centralized'): array {
	$event               = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
	$event['event_id']   = $id;
	$event['sequence']   = $seq;
	$event['transition'] = $kind;
	$event['episode_id'] = $episode;
	$event['alarm_key']  = $key;

	return ['contract_version' => 1, 'status' => 'unhandled', 'mode' => $mode, 'event' => $event];
}
function am_submit(array $envelope): int {
	$response = alarmmanager_submit($envelope);

	if ($response['status'] !== 'accepted') {
		throw new RuntimeException('Fixture ingestion failed: ' . ($response['error'] ?? $response['status']));
	}

	return $response['event_row_id'];
}
function am_process_all(): int {
	$count = 0;

	while ($claim = alarmmanager_claim_processing()) {
		if (!alarmmanager_process_job($claim)) {
			throw new RuntimeException('Processing ownership lost.');
		}

		if (++$count > 10000) {
			throw new RuntimeException('Unbounded processing test.');
		}
	}

	return $count;
}
function am_routes(array $policy = [], bool $overlap = false, bool $stop = false): void {
	$routes                       = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true, 32, JSON_THROW_ON_ERROR);
	$routes['rules'][0]['policy'] = $policy;
	$routes['rules'][0]['stop']   = $stop;

	foreach ($routes['endpoints'] as &$endpoint) {
		$endpoint['max_concurrency'] = 1;
		$endpoint['timeout_seconds'] = 1;
	} unset($endpoint);

	if ($overlap) {
		$routes['rules'][] = ['id' => 2, 'name' => 'Overlap', 'profile_id' => 1, 'priority' => 200, 'policy' => $policy];
	}
	alarmmanager_apply_configuration($routes);
}
function am_reset(): void {
	alarmmanager_transaction(function (): void {
		foreach (['attempts', 'deliveries', 'decisions', 'notification_state', 'episodes', 'states', 'failed_jobs', 'workers', 'jobs', 'events'] as $table) {
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_' . $table);
		}
		alarmmanager_db_write("UPDATE plugin_alarmmanager_endpoints SET last_claim_at = '1970-01-01', next_start_at = '1970-01-01'");
	});
}
function am_count(string $table): int {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_' . $table);
}

try {
	$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255) NOT NULL, value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
	$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.2.0', 1)");
	am_check(plugin_alarmmanager_install() && plugin_alarmmanager_upgrade(), 'Runtime schema install/upgrade failed.');
	am_check(alarmmanager_schema_ready(), 'Runtime schema not ready.');
	am_routes([], true);
	$raise = am_submit(am_fixture('raise'));
	am_process_all();
	am_check(am_count('deliveries') === 2, 'REST API/webhook fan-out failed.');
	am_check((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_decisions AS d INNER JOIN plugin_alarmmanager_configuration_history AS h ON h.revision = d.config_revision') === 4, 'Routing decisions lost their configuration snapshot.');
	am_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'duplicate_route'") === 2, 'Overlap was not recorded.');
	am_check((int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 1, 'Raise did not activate condition.');
	am_check(am_process_all() === 0 && am_count('deliveries') === 2, 'Reprocessing duplicated destinations.');
	am_submit(am_fixture('recover', 2, 'recovered'));
	am_process_all();
	am_check((int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 0, 'Recovery did not clear condition.');
	am_submit(am_fixture('new-raise', 3, 'raised', 'outage-2'));
	am_process_all();
	am_submit(am_fixture('wrong-recover', 4, 'recovered', 'outage-1'));
	am_process_all();
	am_check((int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 1, 'Old recovery closed new episode.');
	am_check((int) db_fetch_cell_prepared('SELECT last_sequence FROM plugin_alarmmanager_states') === 3, 'Wrong episode advanced current sequence.');
	am_submit(am_fixture('stale', 2, 'updated', 'outage-2'));
	am_process_all();
	am_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'stale'") === 1, 'Stale event was not rejected.');
	am_submit(am_fixture('closed-reopen', 5, 'raised', 'outage-1'));
	am_process_all();
	am_check(db_fetch_cell_prepared('SELECT episode_id FROM plugin_alarmmanager_states') === 'outage-2', 'Closed episode reopened.');
	am_check(am_count('deliveries') === 6, 'Ignored/stale events created delivery jobs.');
	am_reset();
	am_routes();
	am_submit(am_fixture('occurrence', 1, 'occurrence'));
	am_process_all();
	am_check((int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 0 && am_count('episodes') === 0, 'Occurrence created permanent active alarm.');
	am_reset();
	am_routes([], true, true);
	am_submit(am_fixture('stop'));
	am_process_all();
	am_check(am_count('decisions') === 2, 'Stop rule continued processing.');
	am_reset();
	am_routes(['match' => ['sources' => ['other']]]);
	am_submit(am_fixture('no-match'));
	am_process_all();
	am_check(am_count('deliveries') === 0 && db_fetch_cell_prepared('SELECT reason FROM plugin_alarmmanager_decisions') === 'no_route', 'No-match policy incorrect.');
	am_reset();
	am_routes(['maintenance_schedule' => ['timezone' => 'UTC', 'days' => [2], 'start' => '00:00', 'end' => '00:00']]);
	am_submit(am_fixture('maintenance'));
	am_process_all();
	am_check(am_count('deliveries') === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'maintenance'") === 2, 'Maintenance suppression failed.');
	am_reset();
	am_routes();
	$suppressed                                          = am_fixture('source-suppressed');
	$suppressed['event']['context']['source_suppressed'] = true;
	am_submit($suppressed);
	am_process_all();
	am_check(am_count('deliveries') === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'source_suppressed'") === 2, 'Source suppression precedence failed.');
	am_reset();
	am_routes();
	$sample = am_fixture('ack-raise');
	am_submit($sample);
	am_process_all();
	$stream = alarmmanager_stream_key($sample['event'], 'centralized');
	alarmmanager_acknowledge($stream, true);
	am_submit(am_fixture('ack-reminder', 2, 'reminder'));
	am_process_all();
	am_check(am_count('deliveries') === 2 && (int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 1, 'Acknowledgment changed condition or sent reminder.');
	am_submit(am_fixture('ack-recovery', 3, 'recovered'));
	am_process_all();
	am_check(am_count('deliveries') === 4, 'Acknowledgment silently suppressed recovery.');
	am_reset();
	am_routes(['cooldown_seconds' => 10]);
	am_submit(am_fixture('cool-raise'));
	am_process_all();
	am_submit(am_fixture('cool-reminder', 2, 'reminder'));
	am_process_all();
	am_check(am_count('deliveries') === 2, 'Cooldown failed.');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_notification_state SET queued_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 SECOND)');
	am_submit(am_fixture('cool-boundary', 3, 'reminder'));
	am_process_all();
	am_check(am_count('deliveries') === 4, 'Cooldown boundary was not inclusive.');
	am_reset();
	am_routes(['duplicate_seconds' => 60]);
	am_submit(am_fixture('dup-one', 1, 'occurrence'));
	am_process_all();
	am_submit(am_fixture('dup-two', 2, 'occurrence'));
	am_process_all();
	am_check(am_count('deliveries') === 2 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'duplicate'") === 2, 'Content duplicate suppression failed.');
	am_reset();
	am_routes();
	am_submit(am_fixture('observe', 1, 'raised', 'outage-1', 'device:42', 'observe'));
	am_process_all();
	am_check(am_count('deliveries') === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'observe'") === 2, 'Observation dispatched.');
	am_submit(am_fixture('observe'));
	am_process_all();
	am_check(am_count('deliveries') === 2 && am_count('states') === 2, 'Observation changed centralized ownership/state.');
	am_reset();
	am_routes();
	am_submit(am_fixture('out-of-order-recovery', 2, 'recovered'));
	am_submit(am_fixture('out-of-order-raise', 1));
	$claim = alarmmanager_claim_processing();
	am_check((int) db_fetch_cell_prepared('SELECT sequence FROM plugin_alarmmanager_events WHERE id = ?', [$claim['event_row_id']]) === 1, 'Processing violated stream sequence.');
	alarmmanager_process_job($claim);
	am_process_all();
	am_check((int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 0, 'Out-of-order arrival produced wrong final state.');

	// A bounded transaction retry must not duplicate partially applied decisions.
	$revision                                        = (int) db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1');
	$sql                                             = 'UPDATE plugin_alarmmanager_schema SET config_revision = config_revision + 1 WHERE id = 1';
	$GLOBALS['alarmmanager_test_failure_plan'][$sql] = [1213];
	alarmmanager_transaction(function () use ($sql): void {
		alarmmanager_decision(999999, 999999, 0, 'contention_fixture');
		alarmmanager_db_write($sql);
	});
	am_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'contention_fixture'") === 1, 'Transaction retry duplicated decisions.');
	am_check((int) db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1') === $revision + 1, 'Transaction retry duplicated revision update.');

	// Independent retries and per-destination raise/recovery order.
	am_reset();
	am_routes();
	am_submit(am_fixture('delivery-raise'));
	am_submit(am_fixture('delivery-recover', 2, 'recovered'));
	am_process_all();
	$first = alarmmanager_claim_delivery(['restapi', 'webhook']);
	am_check((int) $first['endpoint']['id'] === 1, 'Unexpected first destination.');
	alarmmanager_finish_delivery($first, ['outcome' => 'retry', 'code' => 'fixture_down', 'retry_after' => 30]);
	$healthy = alarmmanager_claim_delivery(['restapi', 'webhook']);
	am_check((int) $healthy['endpoint']['id'] === 2, 'Failed destination monopolized capacity.');
	alarmmanager_finish_delivery($healthy, ['outcome' => 'success', 'code' => 'ok']);
	$healthy_recover = alarmmanager_claim_delivery(['restapi', 'webhook']);
	am_check((int) $healthy_recover['endpoint']['id'] === 2, 'Recovery bypassed retrying raise on same endpoint.');
	alarmmanager_finish_delivery($healthy_recover, ['outcome' => 'success', 'code' => 'ok']);
	am_check(alarmmanager_claim_delivery(['restapi', 'webhook']) === null, 'Delayed raise did not block only its own recovery.');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET available_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$first['id']]);
	$retry = alarmmanager_claim_delivery(['restapi', 'webhook']);
	am_check($retry['id'] === $first['id'] && $retry['idempotency_key'] === $first['idempotency_key'] && $retry['lease_token'] !== $first['lease_token'], 'Retry changed identity or ownership token.');
	am_check(!alarmmanager_finish_delivery($first, ['outcome' => 'success', 'code' => 'ok']), 'Stale completion finalized reclaimed work.');
	alarmmanager_finish_delivery($retry, ['outcome' => 'success', 'code' => 'ok']);
	$recover = alarmmanager_claim_delivery(['restapi']);
	alarmmanager_finish_delivery($recover, ['outcome' => 'success', 'code' => 'ok']);
	am_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'") === 4 && am_count('attempts') === 5, 'Independent retry resent successful destination.');

	am_reset();
	am_routes();
	am_submit(am_fixture('capacity-1', 1, 'raised', 'outage-1', 'device:1'));
	am_submit(am_fixture('capacity-2', 1, 'raised', 'outage-1', 'device:2'));
	am_process_all();
	$one = alarmmanager_claim_delivery(['restapi']);
	am_check($one !== null && alarmmanager_claim_delivery(['restapi']) === null, 'Shared endpoint concurrency bound failed.');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$one['id']]);
	am_check(alarmmanager_reclaim('delivery') === 1, 'Delivery lease recovery failed.');
	am_check(!alarmmanager_finish_delivery($one, ['outcome' => 'success', 'code' => 'ok']), 'Expired completion was accepted.');
	am_check(db_fetch_cell_prepared('SELECT outcome FROM plugin_alarmmanager_attempts') === 'abandoned', 'Expired attempt not recorded.');
	am_reset();
	am_routes();
	alarmmanager_db_write('UPDATE plugin_alarmmanager_endpoints SET max_concurrency = 2, min_interval_ms = 10000 WHERE id = 1');
	am_submit(am_fixture('rate-1', 1, 'raised', 'outage-1', 'device:1'));
	am_submit(am_fixture('rate-2', 1, 'raised', 'outage-1', 'device:2'));
	am_process_all();
	$rate_first = alarmmanager_claim_delivery(['restapi']);
	am_check($rate_first !== null && alarmmanager_claim_delivery(['restapi']) === null, 'Shared rate limit failed.');
	alarmmanager_db_write("UPDATE plugin_alarmmanager_endpoints SET next_start_at = '1970-01-01' WHERE id = 1");
	am_check(alarmmanager_claim_delivery(['restapi']) !== null, 'Second concurrency slot was unavailable after rate window.');

	am_reset();
	am_routes();
	am_submit(am_fixture('terminal'));
	am_process_all();
	alarmmanager_db_write('UPDATE plugin_alarmmanager_endpoints SET max_attempts = 1 WHERE id = 1');
	$terminal = alarmmanager_claim_delivery(['restapi']);
	alarmmanager_finish_delivery($terminal, ['outcome' => 'retry', 'code' => 'fixture_failure']);
	am_check(db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_deliveries WHERE id = ?', [$terminal['id']]) === 'failed' && am_count('failed_jobs') === 1, 'Attempt limit/dead-letter failed.');
	am_check(alarmmanager_claim_delivery([]) === null, 'Missing adapter was falsely dispatched.');

	am_reset();
	am_routes();
	$aged                          = am_fixture('aged-low', 1, 'raised', 'outage-1', 'device:1');
	$aged['event']['severity']     = 'info';
	$aged_id                       = am_submit($aged);
	$critical                      = am_fixture('new-critical', 1, 'raised', 'outage-1', 'device:2');
	$critical['event']['severity'] = 'critical';
	am_submit($critical);
	alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET created_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 120 SECOND) WHERE event_row_id = ?', [$aged_id]);
	$aged_claim = alarmmanager_claim_processing();
	am_check($aged_claim['event_row_id'] === $aged_id, 'Aging did not prevent low-priority starvation.');
	alarmmanager_process_job($aged_claim);
	am_process_all();
	am_reset();
	am_routes();
	$broken_id = am_submit(am_fixture('failed-predecessor'));
	am_submit(am_fixture('blocked-recovery', 2, 'recovered'));
	alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET attempts = 4 WHERE event_row_id = ?', [$broken_id]);
	$broken = alarmmanager_claim_processing();
	alarmmanager_fail_processing($broken, 'fixture_failure');
	am_check(alarmmanager_claim_processing() === null, 'Terminal failure allowed recovery to pass unprocessed raise.');
	$other     = am_submit(am_fixture('unrelated-stream', 1, 'raised', 'outage-1', 'device:99'));
	$unrelated = alarmmanager_claim_processing();
	am_check($unrelated['event_row_id'] === $other && alarmmanager_process_job($unrelated), 'Failed stream blocked unrelated work.');
	am_check(alarmmanager_metrics()['processing_blocked_by_failed_predecessor'] === 1, 'Blocked processing health metric missing.');
	// Competing database connections must share the same endpoint semaphore.
	am_reset();
	am_routes();

	for ($i = 0; $i < 4; $i++) {
		am_submit(am_fixture('parallel-cap-' . $i, 1, 'raised', 'outage-1', 'device:' . $i));
	} am_process_all();
	$prefix               = sys_get_temp_dir() . '/alarmmanager-cap-' . bin2hex(random_bytes(6));
	$alarmmanager_test_db = null;
	$children             = [];

	for ($i = 0; $i < 4; $i++) {
		$child = pcntl_fork();

		if ($child < 0) {
			throw new RuntimeException('capacity_fork_failure');
		}

		if ($child === 0) {
			$alarmmanager_test_db = alarmmanager_test_connect();
			$alarmmanager_test_db->exec("USE `$database`");
			file_put_contents($prefix . '-' . $i, json_encode(alarmmanager_claim_delivery(['restapi']), JSON_THROW_ON_ERROR));
			exit(0);
		}
		$children[$i] = $child;
	}
	$claimed = 0;

	foreach ($children as $i => $child) {
		pcntl_waitpid($child, $status);

		if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
			throw new RuntimeException('capacity_child_failure');
		}
		$claimed += json_decode(file_get_contents($prefix . '-' . $i), true, 16, JSON_THROW_ON_ERROR) !== null ? 1 : 0;
		unlink($prefix . '-' . $i);
	}
	$alarmmanager_test_db = alarmmanager_test_connect();
	$alarmmanager_test_db->exec("USE `$database`");
	am_check($claimed === 1 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE endpoint_id = 1 AND status = 'leased'") === 1, 'Competing workers exceeded endpoint concurrency.');

	// Kill a real process after claiming; connections are opened after fork.
	am_reset();
	am_routes();
	am_submit(am_fixture('killed-worker'));
	$claim_file           = sys_get_temp_dir() . '/alarmmanager-claim-' . bin2hex(random_bytes(6));
	$alarmmanager_test_db = null;
	$victim               = pcntl_fork();

	if ($victim === 0) {
		$alarmmanager_test_db = alarmmanager_test_connect();
		$alarmmanager_test_db->exec("USE `$database`");
		file_put_contents($claim_file, json_encode(alarmmanager_claim_processing(2), JSON_THROW_ON_ERROR));
		sleep(30);
		exit(0);
	}

	if ($victim < 0) {
		throw new RuntimeException('Fork failed.');
	}

	for ($i = 0; $i < 100 && !file_exists($claim_file); $i++) {
		usleep(10000);
	}
	$old = json_decode(file_get_contents($claim_file), true, 16, JSON_THROW_ON_ERROR);
	posix_kill($victim, SIGKILL);
	pcntl_waitpid($victim, $status);
	$alarmmanager_test_db = alarmmanager_test_connect();
	$alarmmanager_test_db->exec("USE `$database`");
	am_check(pcntl_wifsignaled($status), 'Worker termination was not exercised.');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$old['id']]);
	am_check(alarmmanager_reclaim('processing') === 1, 'Killed processing job not reclaimed.');
	$new = alarmmanager_claim_processing();
	am_check($new['lease_token'] !== $old['lease_token'] && !alarmmanager_process_job($old), 'Killed worker retained completion ownership.');
	am_check(alarmmanager_process_job($new) && am_count('deliveries') === 2, 'Reclaimed job did not converge.');
	unlink($claim_file);

	// Registered fixture transport: timeout and redacted attempt result.
	am_reset();
	am_routes();
	am_submit(am_fixture('slow'));
	am_process_all();
	$GLOBALS['alarmmanager_transport_handlers'] = ['restapi' => static function (): array {
		sleep(5);

		return ['outcome' => 'success', 'code' => 'ok'];
	}];
	$slow    = alarmmanager_claim_delivery(['restapi']);
	$started = microtime(true);
	alarmmanager_run_delivery($slow);
	am_check(microtime(true) - $started < 2.5 && db_fetch_cell_prepared('SELECT code FROM plugin_alarmmanager_attempts') === 'transport_timeout', 'Transport timeout was not bounded.');
	$fast = alarmmanager_claim_delivery(['webhook']);
	am_check($fast !== null, 'Slow endpoint blocked healthy endpoint.');
	alarmmanager_finish_delivery($fast, ['outcome' => 'success', 'code' => 'ok']);
	$GLOBALS['alarmmanager_transport_handlers'] = [];
	$metrics                                    = alarmmanager_metrics();
	am_check(isset($metrics['queues']['processing'], $metrics['queues']['delivery']) && !$metrics['blocked_destination_types'], 'Queue health/adapter status missing.');
	alarmmanager_db_write('UPDATE plugin_config SET status = 4');
	am_check(alarmmanager_claim_processing() === null && alarmmanager_claim_delivery(['webhook']) === null, 'Disable allowed new claims.');
	alarmmanager_db_write('UPDATE plugin_config SET status = 1');
	am_reset();
	am_routes();
	am_submit(am_fixture('shutdown-one', 1, 'raised', 'outage-1', 'device:1'));
	am_submit(am_fixture('shutdown-two', 1, 'raised', 'outage-1', 'device:2'));
	am_process_all();
	$alarmmanager_test_db = null;
	$child                = pcntl_fork();

	if ($child === 0) {
		$alarmmanager_test_db = alarmmanager_test_connect();
		$alarmmanager_test_db->exec("USE `$database`");
		$stop = false;
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
		$GLOBALS['alarmmanager_transport_handlers'] = ['restapi' => static function (): array {
			$end = microtime(true) + 0.5;

			while (microtime(true) < $end) {
				usleep(10000);
			}

return ['outcome' => 'success', 'code' => 'fixture_ok'];
		}];
		alarmmanager_worker_loop('delivery', ['once' => false, 'max_jobs' => 100, 'max_seconds' => 5, 'lease_seconds' => 180, 'idle_ms' => 20], static function () use (&$stop): bool { return $stop; });
		exit(0);
	}

	if ($child < 0) {
		throw new RuntimeException('shutdown_fork_failure');
	}
	$alarmmanager_test_db = alarmmanager_test_connect();
	$alarmmanager_test_db->exec("USE `$database`");

	for ($i = 0; $i < 100; $i++) {
		if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_workers WHERE status = 'busy'") > 0) {
			break;
		}
		usleep(10000);
	}
	posix_kill($child, SIGTERM);
	pcntl_waitpid($child, $status);
	am_check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Worker graceful shutdown failed.');
	am_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'") === 1, 'Shutdown did not finish exactly the in-flight job.');
	am_check((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_workers WHERE status = 'stopped'") === 1, 'Worker stopped heartbeat missing.');
	am_check(am_count('attempts') === 1, 'Shutdown claimed another job.');
	print "PASS: $checks real MariaDB lifecycle/queue checks, including worker kill, fencing, retries, timeout and graceful shutdown.\n";
} finally {
	if (!$alarmmanager_test_db) {
		$alarmmanager_test_db = alarmmanager_test_connect();
	}

	if ($alarmmanager_test_db->inTransaction()) {
		$alarmmanager_test_db->rollBack();
	}
	$alarmmanager_test_db->exec("DROP DATABASE `$database`");
}
