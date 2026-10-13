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
final class RuntimeTest extends AlarmManagerTestCase {
	/**
	 * Processing lifecycle, routing policies, queue fencing, transport timeout and graceful shutdown.
	 */
	public function testRuntimeLifecycleQueueFencingAndShutdown(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Dedicated test-server DSN and create opt-in required.');
		}

		if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !defined('SIGKILL') || !defined('SIGTERM')) {
			$this->markTestSkipped('pcntl and posix are required for the worker lifecycle fixtures.');
		}

		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/workers.php');
		$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$GLOBALS['alarmmanager_test_db']->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
		$GLOBALS['config'] = ['poller_id' => 2];

		try {
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255) NOT NULL, value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.2.0', 1)");
			$this->assertTrue(plugin_alarmmanager_install() && plugin_alarmmanager_upgrade(), 'Runtime schema install/upgrade failed.');
			$this->assertTrue(alarmmanager_schema_ready(), 'Runtime schema not ready.');
			$this->am_routes([], true);
			$raise = $this->am_submit($this->am_fixture('raise'));
			$this->am_process_all();
			$this->assertSame(2, $this->am_count('deliveries'), 'REST API/webhook fan-out failed.');
			$this->assertSame(4, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_decisions AS d INNER JOIN plugin_alarmmanager_configuration_history AS h ON h.revision = d.config_revision'), 'Routing decisions lost their configuration snapshot.');
			$this->assertSame(2, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'duplicate_route'"), 'Overlap was not recorded.');
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states'), 'Raise did not activate condition.');
			$this->assertTrue($this->am_process_all() === 0 && $this->am_count('deliveries') === 2, 'Reprocessing duplicated destinations.');
			$this->am_submit($this->am_fixture('recover', 2, 'recovered'));
			$this->am_process_all();
			$this->assertSame(0, (int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states'), 'Recovery did not clear condition.');
			$this->am_submit($this->am_fixture('new-raise', 3, 'raised', 'outage-2'));
			$this->am_process_all();
			$this->am_submit($this->am_fixture('wrong-recover', 4, 'recovered', 'outage-1'));
			$this->am_process_all();
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states'), 'Old recovery closed new episode.');
			$this->assertSame(3, (int) db_fetch_cell_prepared('SELECT last_sequence FROM plugin_alarmmanager_states'), 'Wrong episode advanced current sequence.');
			$this->am_submit($this->am_fixture('stale', 2, 'updated', 'outage-2'));
			$this->am_process_all();
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'stale'"), 'Stale event was not rejected.');
			$this->am_submit($this->am_fixture('closed-reopen', 5, 'raised', 'outage-1'));
			$this->am_process_all();
			$this->assertSame('outage-2', db_fetch_cell_prepared('SELECT episode_id FROM plugin_alarmmanager_states'), 'Closed episode reopened.');
			$this->assertSame(6, $this->am_count('deliveries'), 'Ignored/stale events created delivery jobs.');
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('occurrence', 1, 'occurrence'));
			$this->am_process_all();
			$this->assertTrue((int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 0 && $this->am_count('episodes') === 0, 'Occurrence created permanent active alarm.');
			$this->am_reset();
			$this->am_routes([], true, true);
			$this->am_submit($this->am_fixture('stop'));
			$this->am_process_all();
			$this->assertSame(2, $this->am_count('decisions'), 'Stop rule continued processing.');
			$this->am_reset();
			$this->am_routes(['match' => ['sources' => ['other']]]);
			$this->am_submit($this->am_fixture('no-match'));
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 0 && db_fetch_cell_prepared('SELECT reason FROM plugin_alarmmanager_decisions') === 'no_route', 'No-match policy incorrect.');
			$this->am_reset();
			$this->am_routes(['maintenance_schedule' => ['timezone' => 'UTC', 'days' => [2], 'start' => '00:00', 'end' => '00:00']]);
			$this->am_submit($this->am_fixture('maintenance'));
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'maintenance'") === 2, 'Maintenance suppression failed.');
			$this->am_reset();
			$this->am_routes();
			$suppressed                                            = $this->am_fixture('source-suppressed');
			$suppressed['event']['context']['source_suppressed']   = true;
			$this->am_submit($suppressed);
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'source_suppressed'") === 2, 'Source suppression precedence failed.');
			$this->am_reset();
			$this->am_routes();
			$sample = $this->am_fixture('ack-raise');
			$this->am_submit($sample);
			$this->am_process_all();
			$stream = alarmmanager_stream_key($sample['event'], 'centralized');
			alarmmanager_acknowledge($stream, true);
			$this->am_submit($this->am_fixture('ack-reminder', 2, 'reminder'));
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 2 && (int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states') === 1, 'Acknowledgment changed condition or sent reminder.');
			$this->am_submit($this->am_fixture('ack-recovery', 3, 'recovered'));
			$this->am_process_all();
			$this->assertSame(4, $this->am_count('deliveries'), 'Acknowledgment silently suppressed recovery.');
			$this->am_reset();
			$this->am_routes(['cooldown_seconds' => 10]);
			$this->am_submit($this->am_fixture('cool-raise'));
			$this->am_process_all();
			$this->am_submit($this->am_fixture('cool-reminder', 2, 'reminder'));
			$this->am_process_all();
			$this->assertSame(2, $this->am_count('deliveries'), 'Cooldown failed.');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_notification_state SET queued_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 SECOND)');
			$this->am_submit($this->am_fixture('cool-boundary', 3, 'reminder'));
			$this->am_process_all();
			$this->assertSame(4, $this->am_count('deliveries'), 'Cooldown boundary was not inclusive.');
			$this->am_reset();
			$this->am_routes(['duplicate_seconds' => 60]);
			$this->am_submit($this->am_fixture('dup-one', 1, 'occurrence'));
			$this->am_process_all();
			$this->am_submit($this->am_fixture('dup-two', 2, 'occurrence'));
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 2 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'duplicate'") === 2, 'Content duplicate suppression failed.');
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('observe', 1, 'raised', 'outage-1', 'device:42', 'observe'));
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'observe'") === 2, 'Observation dispatched.');
			$this->am_submit($this->am_fixture('observe'));
			$this->am_process_all();
			$this->assertTrue($this->am_count('deliveries') === 2 && $this->am_count('states') === 2, 'Observation changed centralized ownership/state.');
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('out-of-order-recovery', 2, 'recovered'));
			$this->am_submit($this->am_fixture('out-of-order-raise', 1));
			$claim = alarmmanager_claim_processing();
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT sequence FROM plugin_alarmmanager_events WHERE id = ?', [$claim['event_row_id']]), 'Processing violated stream sequence.');
			alarmmanager_process_job($claim);
			$this->am_process_all();
			$this->assertSame(0, (int) db_fetch_cell_prepared('SELECT active FROM plugin_alarmmanager_states'), 'Out-of-order arrival produced wrong final state.');

			// A bounded transaction retry must not duplicate partially applied decisions.
			$revision                                        = (int) db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1');
			$sql                                             = 'UPDATE plugin_alarmmanager_schema SET config_revision = config_revision + 1 WHERE id = 1';
			$GLOBALS['alarmmanager_test_failure_plan'][$sql] = [1213];
			alarmmanager_transaction(function () use ($sql): void {
				alarmmanager_decision(999999, 999999, 0, 'contention_fixture');
				alarmmanager_db_write($sql);
			});
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason = 'contention_fixture'"), 'Transaction retry duplicated decisions.');
			$this->assertSame($revision + 1, (int) db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1'), 'Transaction retry duplicated revision update.');

			// Independent retries and per-destination raise/recovery order.
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('delivery-raise'));
			$this->am_submit($this->am_fixture('delivery-recover', 2, 'recovered'));
			$this->am_process_all();
			$first = alarmmanager_claim_delivery(['restapi', 'webhook']);
			$this->assertSame(1, (int) $first['endpoint']['id'], 'Unexpected first destination.');
			alarmmanager_finish_delivery($first, ['outcome' => 'retry', 'code' => 'fixture_down', 'retry_after' => 30]);
			$healthy = alarmmanager_claim_delivery(['restapi', 'webhook']);
			$this->assertSame(2, (int) $healthy['endpoint']['id'], 'Failed destination monopolized capacity.');
			alarmmanager_finish_delivery($healthy, ['outcome' => 'success', 'code' => 'ok']);
			$healthy_recover = alarmmanager_claim_delivery(['restapi', 'webhook']);
			$this->assertSame(2, (int) $healthy_recover['endpoint']['id'], 'Recovery bypassed retrying raise on same endpoint.');
			alarmmanager_finish_delivery($healthy_recover, ['outcome' => 'success', 'code' => 'ok']);
			$this->assertNull(alarmmanager_claim_delivery(['restapi', 'webhook']), 'Delayed raise did not block only its own recovery.');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET available_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$first['id']]);
			$retry = alarmmanager_claim_delivery(['restapi', 'webhook']);
			$this->assertTrue($retry['id'] === $first['id'] && $retry['idempotency_key'] === $first['idempotency_key'] && $retry['lease_token'] !== $first['lease_token'], 'Retry changed identity or ownership token.');
			$this->assertFalse(alarmmanager_finish_delivery($first, ['outcome' => 'success', 'code' => 'ok']), 'Stale completion finalized reclaimed work.');
			alarmmanager_finish_delivery($retry, ['outcome' => 'success', 'code' => 'ok']);
			$recover = alarmmanager_claim_delivery(['restapi']);
			alarmmanager_finish_delivery($recover, ['outcome' => 'success', 'code' => 'ok']);
			$this->assertTrue((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'") === 4 && $this->am_count('attempts') === 5, 'Independent retry resent successful destination.');

			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('capacity-1', 1, 'raised', 'outage-1', 'device:1'));
			$this->am_submit($this->am_fixture('capacity-2', 1, 'raised', 'outage-1', 'device:2'));
			$this->am_process_all();
			$one = alarmmanager_claim_delivery(['restapi']);
			$this->assertTrue($one !== null && alarmmanager_claim_delivery(['restapi']) === null, 'Shared endpoint concurrency bound failed.');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$one['id']]);
			$this->assertSame(1, alarmmanager_reclaim('delivery'), 'Delivery lease recovery failed.');
			$this->assertFalse(alarmmanager_finish_delivery($one, ['outcome' => 'success', 'code' => 'ok']), 'Expired completion was accepted.');
			$this->assertSame('abandoned', db_fetch_cell_prepared('SELECT outcome FROM plugin_alarmmanager_attempts'), 'Expired attempt not recorded.');
			$this->am_reset();
			$this->am_routes();
			alarmmanager_db_write('UPDATE plugin_alarmmanager_endpoints SET max_concurrency = 2, min_interval_ms = 10000 WHERE id = 1');
			$this->am_submit($this->am_fixture('rate-1', 1, 'raised', 'outage-1', 'device:1'));
			$this->am_submit($this->am_fixture('rate-2', 1, 'raised', 'outage-1', 'device:2'));
			$this->am_process_all();
			$rate_first = alarmmanager_claim_delivery(['restapi']);
			$this->assertTrue($rate_first !== null && alarmmanager_claim_delivery(['restapi']) === null, 'Shared rate limit failed.');
			alarmmanager_db_write("UPDATE plugin_alarmmanager_endpoints SET next_start_at = '1970-01-01' WHERE id = 1");
			$this->assertNotNull(alarmmanager_claim_delivery(['restapi']), 'Second concurrency slot was unavailable after rate window.');

			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('terminal'));
			$this->am_process_all();
			alarmmanager_db_write('UPDATE plugin_alarmmanager_endpoints SET max_attempts = 1 WHERE id = 1');
			$terminal = alarmmanager_claim_delivery(['restapi']);
			alarmmanager_finish_delivery($terminal, ['outcome' => 'retry', 'code' => 'fixture_failure']);
			$this->assertTrue(db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_deliveries WHERE id = ?', [$terminal['id']]) === 'failed' && $this->am_count('failed_jobs') === 1, 'Attempt limit/dead-letter failed.');
			$this->assertNull(alarmmanager_claim_delivery([]), 'Missing adapter was falsely dispatched.');

			$this->am_reset();
			$this->am_routes();
			$aged                          = $this->am_fixture('aged-low', 1, 'raised', 'outage-1', 'device:1');
			$aged['event']['severity']     = 'info';
			$aged_id                       = $this->am_submit($aged);
			$critical                      = $this->am_fixture('new-critical', 1, 'raised', 'outage-1', 'device:2');
			$critical['event']['severity'] = 'critical';
			$this->am_submit($critical);
			alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET created_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 120 SECOND) WHERE event_row_id = ?', [$aged_id]);
			$aged_claim = alarmmanager_claim_processing();
			$this->assertSame($aged_id, $aged_claim['event_row_id'], 'Aging did not prevent low-priority starvation.');
			alarmmanager_process_job($aged_claim);
			$this->am_process_all();
			$this->am_reset();
			$this->am_routes();
			$broken_id = $this->am_submit($this->am_fixture('failed-predecessor'));
			$this->am_submit($this->am_fixture('blocked-recovery', 2, 'recovered'));
			alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET attempts = 4 WHERE event_row_id = ?', [$broken_id]);
			$broken = alarmmanager_claim_processing();
			alarmmanager_fail_processing($broken, 'fixture_failure');
			$this->assertNull(alarmmanager_claim_processing(), 'Terminal failure allowed recovery to pass unprocessed raise.');
			$other     = $this->am_submit($this->am_fixture('unrelated-stream', 1, 'raised', 'outage-1', 'device:99'));
			$unrelated = alarmmanager_claim_processing();
			$this->assertTrue($unrelated['event_row_id'] === $other && alarmmanager_process_job($unrelated), 'Failed stream blocked unrelated work.');
			$this->assertSame(1, alarmmanager_metrics()['processing_blocked_by_failed_predecessor'], 'Blocked processing health metric missing.');
			// Competing database connections must share the same endpoint semaphore.
			$this->am_reset();
			$this->am_routes();

			for ($i = 0; $i < 4; $i++) {
				$this->am_submit($this->am_fixture('parallel-cap-' . $i, 1, 'raised', 'outage-1', 'device:' . $i));
			} $this->am_process_all();
			$prefix                          = sys_get_temp_dir() . '/alarmmanager-cap-' . bin2hex(random_bytes(6));
			$GLOBALS['alarmmanager_test_db'] = null;
			$children                        = [];

			for ($i = 0; $i < 4; $i++) {
				$child = pcntl_fork();

				if ($child < 0) {
					throw new RuntimeException('capacity_fork_failure');
				}

				if ($child === 0) {
					$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
					$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
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
			$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
			$this->assertTrue($claimed === 1 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE endpoint_id = 1 AND status = 'leased'") === 1, 'Competing workers exceeded endpoint concurrency.');

			// Kill a real process after claiming; connections are opened after fork.
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('killed-worker'));
			$claim_file                      = sys_get_temp_dir() . '/alarmmanager-claim-' . bin2hex(random_bytes(6));
			$GLOBALS['alarmmanager_test_db'] = null;
			$victim                          = pcntl_fork();

			if ($victim === 0) {
				$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
				$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
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
			$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
			$this->assertTrue(pcntl_wifsignaled($status), 'Worker termination was not exercised.');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_jobs SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$old['id']]);
			$this->assertSame(1, alarmmanager_reclaim('processing'), 'Killed processing job not reclaimed.');
			$new = alarmmanager_claim_processing();
			$this->assertTrue($new['lease_token'] !== $old['lease_token'] && !alarmmanager_process_job($old), 'Killed worker retained completion ownership.');
			$this->assertTrue(alarmmanager_process_job($new) && $this->am_count('deliveries') === 2, 'Reclaimed job did not converge.');
			unlink($claim_file);

			// Registered fixture transport: timeout and redacted attempt result.
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('slow'));
			$this->am_process_all();
			$GLOBALS['alarmmanager_transport_handlers'] = ['restapi' => static function (): array {
				sleep(5);

				return ['outcome' => 'success', 'code' => 'ok'];
			}];
			$slow    = alarmmanager_claim_delivery(['restapi']);
			$started = microtime(true);
			alarmmanager_run_delivery($slow);
			$this->assertTrue(microtime(true) - $started < 2.5 && db_fetch_cell_prepared('SELECT code FROM plugin_alarmmanager_attempts') === 'transport_timeout', 'Transport timeout was not bounded.');
			$fast = alarmmanager_claim_delivery(['webhook']);
			$this->assertNotNull($fast, 'Slow endpoint blocked healthy endpoint.');
			alarmmanager_finish_delivery($fast, ['outcome' => 'success', 'code' => 'ok']);
			$GLOBALS['alarmmanager_transport_handlers'] = [];
			$metrics                                    = alarmmanager_metrics();
			$this->assertTrue(isset($metrics['queues']['processing'], $metrics['queues']['delivery']) && !$metrics['blocked_destination_types'], 'Queue health/adapter status missing.');
			alarmmanager_db_write('UPDATE plugin_config SET status = 4');
			$this->assertTrue(alarmmanager_claim_processing() === null && alarmmanager_claim_delivery(['webhook']) === null, 'Disable allowed new claims.');
			alarmmanager_db_write('UPDATE plugin_config SET status = 1');
			$this->am_reset();
			$this->am_routes();
			$this->am_submit($this->am_fixture('shutdown-one', 1, 'raised', 'outage-1', 'device:1'));
			$this->am_submit($this->am_fixture('shutdown-two', 1, 'raised', 'outage-1', 'device:2'));
			$this->am_process_all();
			$GLOBALS['alarmmanager_test_db'] = null;
			$child                           = pcntl_fork();

			if ($child === 0) {
				$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
				$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
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
			$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");

			for ($i = 0; $i < 100; $i++) {
				if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_workers WHERE status = 'busy'") > 0) {
					break;
				}
				usleep(10000);
			}
			posix_kill($child, SIGTERM);
			pcntl_waitpid($child, $status);
			$this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Worker graceful shutdown failed.');
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'"), 'Shutdown did not finish exactly the in-flight job.');
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_workers WHERE status = 'stopped'"), 'Worker stopped heartbeat missing.');
			$this->assertSame(1, $this->am_count('attempts'), 'Shutdown claimed another job.');
		} finally {
			if (!$GLOBALS['alarmmanager_test_db']) {
				$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			}

			if ($GLOBALS['alarmmanager_test_db']->inTransaction()) {
				$GLOBALS['alarmmanager_test_db']->rollBack();
			}
			$GLOBALS['alarmmanager_test_db']->exec("DROP DATABASE `$database`");
		}
	}

	/**
	 * Fixture envelope built from the shared example event.
	 */
	private function am_fixture(string $id, int $seq = 1, string $kind = 'raised', string $episode = 'outage-1', string $key = 'device:42', string $mode = 'centralized'): array {
		$event               = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
		$event['event_id']   = $id;
		$event['sequence']   = $seq;
		$event['transition'] = $kind;
		$event['episode_id'] = $episode;
		$event['alarm_key']  = $key;

		return ['contract_version' => 1, 'status' => 'unhandled', 'mode' => $mode, 'event' => $event];
	}

	/**
	 * Submit one fixture envelope and require durable acceptance.
	 */
	private function am_submit(array $envelope): int {
		$response = alarmmanager_submit($envelope);

		if ($response['status'] !== 'accepted') {
			throw new RuntimeException('Fixture ingestion failed: ' . ($response['error'] ?? $response['status']));
		}

		return $response['event_row_id'];
	}

	/**
	 * Drain the processing queue with bounded iteration.
	 */
	private function am_process_all(): int {
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

	/**
	 * Install one fixture routing configuration with capped endpoints and optional overlap/stop rules.
	 */
	private function am_routes(array $policy = [], bool $overlap = false, bool $stop = false): void {
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

	/**
	 * Clear the plugin tables between queue scenarios.
	 */
	private function am_reset(): void {
		alarmmanager_transaction(function (): void {
			foreach (['attempts', 'deliveries', 'decisions', 'notification_state', 'episodes', 'states', 'failed_jobs', 'workers', 'jobs', 'events'] as $table) {
				alarmmanager_db_write('DELETE FROM plugin_alarmmanager_' . $table);
			}
			alarmmanager_db_write("UPDATE plugin_alarmmanager_endpoints SET last_claim_at = '1970-01-01', next_start_at = '1970-01-01'");
		});
	}

	/**
	 * Count the rows in one plugin table.
	 */
	private function am_count(string $table): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_' . $table);
	}
}
