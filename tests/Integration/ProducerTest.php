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
final class ProducerTest extends AlarmManagerTestCase {
	/**
	 * Producer bridge publication, receipts, fallbacks, recovery and competing retry processes.
	 */
	public function testProducerBridgeLifecycleAndConcurrency(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Set ALARMMANAGER_TEST_ALLOW_CREATE=1 and a dedicated test-server DSN to run the producer lane.');
		}

		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('pcntl is required for the competing-process fixtures.');
		}

		// The producer bridge probes these Cacti hook functions with function_exists().
		if (!function_exists('api_plugin_is_enabled')) {
			function api_plugin_is_enabled($name) {
				return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [$name], '', false) === 1;
			}
		}

		if (!function_exists('api_plugin_hook_function')) {
			function api_plugin_hook_function($name, $envelope) {
				$hook_mode = $GLOBALS['hook_mode'] ?? 'normal';

				if ($hook_mode === 'no_handler') {
					return $envelope;
				}

				if ($hook_mode === 'fake_accepted') {
					$envelope['status'] = 'accepted';

					return $envelope;
				}

				if ($hook_mode === 'throw_before') {
					throw new RuntimeException('fixture');
				}
				$result = alarmmanager_submit($envelope);

				if ($hook_mode === 'throw_after') {
					throw new RuntimeException('fixture');
				}

				return $result;
			}
		}

		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/processor.php');
		self::alarmmanager_test_load('includes/queue.php');
		self::alarmmanager_test_load('integrations/producer_bridge.php');
		$GLOBALS['hook_mode']            = 'normal';
		$GLOBALS['config']               = ['poller_id' => 2];
		$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$GLOBALS['alarmmanager_test_db']->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");

		try {
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255), value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.6.0', 1)");

			require_once __DIR__ . '/../fixtures/device_metadata_schema.php';
			alarmmanager_test_device_metadata_schema($GLOBALS['alarmmanager_test_db']);
			$this->assertSame(str_repeat('é', 255), AlarmManagerProducerBridge::text(str_repeat('é', 300), 511), 'UTF-8 truncation retains complete characters');
			$this->assertTrue(plugin_alarmmanager_install(), 'install');
			$this->assertTrue(plugin_alarmmanager_install(), 'repeat install');
			$this->assertSame(10, (int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema'), 'schema v10');
			$this->assertTrue($this->producer_publish('local-default')['owned'], 'Local delivery is the default without a mode policy.');
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events'));
			$this->producer_policy('thold', 'default', 0, 'observe');
			$this->assertTrue($this->producer_publish('old-policy')['owned'], 'Old saved policy now delivers locally.');
			$this->producer_policy('thold', 'template', 10, 'centralized');
			$this->assertArrayNotHasKey('mode', AlarmManagerProducerBridge::configuration('thold', 1, 10));
			$this->producer_policy('thold', 'resource', 1, 'legacy');
			$this->assertTrue($this->producer_publish('resource-policy')['owned'], 'Old resource policies use local delivery.');
			$this->producer_policy('thold', 'resource', 1, 'centralized', ['email']);
			$first = $this->producer_publish('raised');
			$this->assertTrue($first['owned'] && AlarmManagerProducerBridge::owns($first, 'email'), 'accepted email ownership');
			$this->assertFalse(AlarmManagerProducerBridge::owns($first, 'script'), 'unselected scripts retained');
			$count = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
			$this->assertTrue($this->producer_publish('raised')['owned'], 'duplicate owns');
			$this->assertSame($count, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events'), 'duplicate no events');
			$GLOBALS['alarmmanager_test_db']->exec('UPDATE plugin_config SET status = 4');
			$this->assertTrue($this->producer_publish('raised')['owned'], 'accepted ownership persists disabled');
			$this->assertFalse($this->producer_publish('new-disabled')['owned'], 'new disabled fallback');
			$this->assertArrayNotHasKey('mode', AlarmManagerProducerBridge::configuration('thold', 1, 10));
			$GLOBALS['alarmmanager_test_db']->exec('DELETE FROM plugin_config');
			$this->assertTrue($this->producer_publish('raised')['owned'], 'accepted ownership persists uninstall');
			$this->assertFalse($this->producer_publish('new-uninstalled')['owned'], 'missing fallback');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.7.0', 1)");

			foreach (['no_handler', 'fake_accepted', 'throw_before'] as $hook_mode) {
				$GLOBALS['hook_mode'] = $hook_mode;
				$this->assertFalse($this->producer_publish($hook_mode)['owned'], $hook_mode . ' fallback');
				$this->assertSame('legacy', db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [hash('sha256', 'thold|2|1|' . $hook_mode)]), 'fallback decision persisted');
				$saved_mode           = $hook_mode;
				$GLOBALS['hook_mode'] = 'normal';
				$this->assertFalse($this->producer_publish($saved_mode)['owned'], 'failed handoff never redispatched');
			}
			$GLOBALS['hook_mode'] = 'throw_after';
			$this->assertTrue($this->producer_publish('lost-response')['owned'], 'lost hook response recovered from receipt');
			$GLOBALS['hook_mode'] = 'normal';
			$GLOBALS['alarmmanager_test_db']->exec('RENAME TABLE plugin_alarmmanager_jobs TO jobs_unavailable');
			$this->assertFalse($this->producer_publish('failed-ingestion')['owned'], 'failed ingestion fallback');
			$this->assertSame(0, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = ?', [hash('sha256', 'thold|2|1|failed-ingestion')]), 'event receipt rollback together');
			$GLOBALS['alarmmanager_test_db']->exec('RENAME TABLE jobs_unavailable TO plugin_alarmmanager_jobs');
			$this->assertFalse($this->producer_publish('failed-ingestion')['owned'], 'legacy failure receipt remains legacy');
			$this->assertSame('legacy', AlarmManagerProducerBridge::retry(hash('sha256', 'thold|2|1|failed-ingestion')), 'recovery never reopens legacy receipt');
			$legacyEnvelope = json_decode(db_fetch_cell_prepared('SELECT envelope FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [hash('sha256', 'thold|2|1|failed-ingestion')]), true);
			$this->assertTrue(alarmmanager_submit($legacyEnvelope)['status'] === 'failed', 'late direct handoff cannot consume legacy receipt');
			// A durable pending envelope represents an interrupted source process, before legacy fallback.
			$pending                              = db_fetch_assoc_prepared('SELECT * FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [hash('sha256', 'thold|2|1|fake_accepted')])[0];
			$pendingEnvelope                      = json_decode($pending['envelope'], true);
			$pendingId                            = hash('sha256', 'pending-crash');
			$pendingEnvelope['producer_receipt']  = $pendingId;
			$pendingEnvelope['event']['event_id'] = $pendingId;
			$pendingEnvelope['event']['sequence'] += 100;
			$pendingEnvelope['event']['alarm_key'] = 'crash-fixture';
			alarmmanager_db_write("INSERT INTO plugin_alarmmanager_producer_receipts (receipt, source, stream_key, status, envelope, channels, created_at, updated_at) VALUES (?, 'thold', ?, 'pending', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$pendingId, $pending['stream_key'], json_encode($pendingEnvelope), $pending['channels']]);
			$GLOBALS['hook_mode'] = 'no_handler';
			$this->assertSame('pending', AlarmManagerProducerBridge::retry($pendingId), 'recovery unavailable preserves pending');
			$GLOBALS['hook_mode'] = 'normal';
			$this->assertSame('accepted', AlarmManagerProducerBridge::retry($pendingId), 'crash receipt retried durably');
			$countBefore = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
			$this->assertSame('accepted', AlarmManagerProducerBridge::retry($pendingId), 'recovery accepted receipt remains accepted');
			$this->assertSame($countBefore, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events'), 'recovery no duplicate');

			$this->assertFalse($this->producer_publish('suppressed-disabled-history', 'raised', true)['owned'], 'suppressed history disabled');
			$this->producer_policy('thold', 'resource', 1, 'centralized', ['email'], true);
			$this->assertTrue($this->producer_publish('suppressed', 'raised', true)['owned'], 'suppressed durable publication');
			$this->assertTrue($this->producer_publish('recovered', 'recovered')['owned'], 'recovery accepted');
			$this->assertTrue($this->producer_publish('new-outage')['owned'], 'new outage');
			$rows = db_fetch_assoc_prepared('SELECT episode_id, transition, sequence FROM plugin_alarmmanager_events WHERE mode = ? AND source = ? AND alarm_key = ? ORDER BY sequence', ['centralized', 'thold', 'thold:1:']);
			$this->assertSame(count($rows), count(array_unique(array_column($rows, 'sequence'))), 'source ordering unique');
			$recovery = array_values(array_filter($rows, fn ($r) => $r['transition'] === 'recovered'))[0];
			$this->assertNotSame(end($rows)['episode_id'], $recovery['episode_id'], 'new episode after recovery');
			$this->producer_policy('syslog', 'default', 0, 'centralized', ['email', 'ticket', 'script'], true);
			$this->assertTrue($this->producer_publish('syslog-occurrence', 'occurrence', false, 'syslog')['owned'], 'syslog occurrence');
			$fixture = json_decode(file_get_contents(__DIR__ . '/../../examples/routing-delivery.json'), true, 24, JSON_THROW_ON_ERROR);

			foreach ($fixture['endpoints'] as &$endpoint) {
				unset($endpoint['transport']);
			} unset($endpoint);

			foreach ($fixture['rules'] as &$rule) {
				$rule['policy'] = [];
			} unset($rule);
			alarmmanager_apply_configuration($fixture);

			while ($claim = alarmmanager_claim_processing(30)) {
				alarmmanager_process_job($claim);
			}
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries d JOIN plugin_alarmmanager_events e ON e.id = d.event_row_id WHERE e.mode = 'observe'"), 'observe no jobs');
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries d JOIN plugin_alarmmanager_events e ON e.id = d.event_row_id WHERE JSON_EXTRACT(e.payload, '$.context.source_suppressed') = true"), 'source suppression no jobs');
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_states s JOIN plugin_alarmmanager_events e ON e.id = s.last_event_id WHERE e.source = 'syslog' AND s.active = 1"), 'syslog not permanently active');
			$this->assertGreaterThan(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries'), 'centralized fanout');
			// Competing retry processes retain one identity and one owner.
			$GLOBALS['alarmmanager_test_db'] = null;
			$children                        = [];

			for ($i = 0; $i < 4; $i++) {
				$pid = pcntl_fork();

				if ($pid < 0) {
					throw new RuntimeException('fork');
				}

				if ($pid === 0) {
					$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
					$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
					exit($this->producer_publish('concurrent')['owned'] ? 0 : 1);
				}
				$children[] = $pid;
			}

			foreach ($children as $pid) {
				pcntl_waitpid($pid, $status);
				$this->assertSame(0, pcntl_wexitstatus($status), 'concurrent owns');
			}
			$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = ?', [hash('sha256', 'thold|2|1|concurrent')]), 'concurrent single identity');
			$GLOBALS['alarmmanager_test_db']->beginTransaction();
			$this->assertTrue($this->producer_publish('raised')['owned'], 'accepted ownership survives caller transaction');
			$this->assertFalse($this->producer_publish('outer')['owned'], 'outer transaction fallback');
			$this->assertTrue($GLOBALS['alarmmanager_test_db']->inTransaction(), 'outer preserved');
			$GLOBALS['alarmmanager_test_db']->rollBack();
		} finally {
			if (!$GLOBALS['alarmmanager_test_db']) {
				$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			} $GLOBALS['alarmmanager_test_db']->exec("DROP DATABASE IF EXISTS `$database`");
		}
	}

	/**
	 * Persist one producer policy row through the validated bridge definition.
	 */
	private function producer_policy(string $source, string $scope, int $id, string $mode, array $channels = ['email', 'script'], bool $history = false): void {
		$definition = AlarmManagerProducerBridge::validate(['mode' => $mode, 'profile_id' => 1, 'channels' => $channels, 'complete_history' => $history]);
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source, scope, resource_id, definition) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE definition = VALUES(definition)', [$source, $scope, $id, json_encode($definition)]);
	}

	/**
	 * Publish one fixture alarm through the producer bridge.
	 */
	private function producer_publish(string $identity, string $transition = 'raised', bool $suppressed = false, string $source = 'thold', int $resource = 1): array {
		return AlarmManagerProducerBridge::publish($source, $resource, 10, $identity, ['transition' => $transition, 'severity' => $transition === 'recovered' ? 'clear' : 'major', 'occurred_at' => '2026-10-13T12:00:00Z', 'summary' => 'Fixture "alarm"', 'device' => ['id' => 5, 'hostname' => 'router.test'], 'suppressed' => $suppressed, 'suppression_reason' => $suppressed ? 'source_maintenance' : '']);
	}
}
