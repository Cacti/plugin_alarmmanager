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
final class IngestionTest extends AlarmManagerTestCase {
	/**
	 * Durable ingestion, identity conflict, failure rollback and competing process convergence.
	 */
	public function testDurableIngestionLifecycleAndConcurrency(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Set ALARMMANAGER_TEST_ALLOW_CREATE=1 and a dedicated test-server DSN. This creates a new temporary database.');
		}

		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('pcntl is required for the competing-process fixtures.');
		}

		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$GLOBALS['alarmmanager_test_db']->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
		$GLOBALS['config'] = ['poller_id' => 2];

		try {
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255) NOT NULL, value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.1.0', 1)");
			$this->assertTrue(plugin_alarmmanager_install(), 'Initial installation failed.');
			$this->assertTrue(plugin_alarmmanager_install(), 'Repeated installation failed.');
			$this->assertTrue(plugin_alarmmanager_upgrade(), 'Upgrade failed.');
			$this->assertTrue(alarmmanager_schema_ready(), 'Schema version missing.');
			$first = alarmmanager_submit($this->alarmmanager_db_sample('first'));
			$this->assertTrue($first['status'] === 'accepted', 'Durable acceptance failed: ' . json_encode($first['error'] ?? null));
			$this->assertSame('preserve', $first['producer_marker'], 'Envelope was not preserved.');
			$this->assertSame(1, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs'), 'Processing job missing.');
			$this->assertSame(2, (int) db_fetch_cell_prepared('SELECT processing_poller_id FROM plugin_alarmmanager_events'), 'Local processing owner missing.');
			$this->assertSame(0, (int) db_fetch_cell_prepared('SELECT @@in_transaction'), 'Acceptance preceded commit.');
			$again = alarmmanager_submit($this->alarmmanager_db_sample('first'));
			$this->assertTrue($again['status'] === 'already_accepted' && $again['event_row_id'] === $first['event_row_id'], 'Duplicate was not recognized.');
			$conflict                     = $this->alarmmanager_db_sample('first');
			$conflict['event']['summary'] = 'Different data under same ID';
			$result                       = alarmmanager_submit($conflict);
			$this->assertTrue($result['status'] === 'failed' && $result['error'] === 'event_identity_conflict', 'Conflicting duplicate was accepted.');
			$GLOBALS['alarmmanager_test_db']->exec("UPDATE plugin_alarmmanager_jobs SET status = 'completed'");
			alarmmanager_submit($this->alarmmanager_db_sample('first'));
			$this->assertSame('completed', db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_jobs'), 'Duplicate reopened a completed job.');
			$this->assertTrue(alarmmanager_submit($this->alarmmanager_db_sample('mode-switch', 'observe'))['status'] === 'accepted', 'Observation failed.');
			$this->assertTrue(alarmmanager_submit($this->alarmmanager_db_sample('mode-switch'))['status'] === 'accepted', 'Observation consumed centralized ownership.');
			$this->assertSame(2, (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT mode) FROM plugin_alarmmanager_events WHERE event_id = 'mode-switch'"), 'Mode identities were combined.');
			$GLOBALS['alarmmanager_test_db']->exec('UPDATE plugin_config SET status = 4');
			$this->assertTrue(alarmmanager_submit($this->alarmmanager_db_sample('disabled'))['status'] === 'unhandled', 'Disabled plugin accepted events.');
			$GLOBALS['alarmmanager_test_db']->exec('DELETE FROM plugin_config');
			$this->assertTrue(alarmmanager_submit($this->alarmmanager_db_sample('missing'))['status'] === 'unhandled', 'Unavailable plugin accepted events.');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.2.0', 1)");
			$GLOBALS['alarmmanager_test_db']->exec('RENAME TABLE plugin_config TO plugin_config_unavailable');
			$availability_failure = alarmmanager_submit($this->alarmmanager_db_sample('availability-failure'));
			$this->assertTrue($availability_failure['status'] === 'failed', 'DB failure was treated as unavailable plugin.');
			$GLOBALS['alarmmanager_test_db']->exec('RENAME TABLE plugin_config_unavailable TO plugin_config');
			$invalid                     = $this->alarmmanager_db_sample('invalid');
			$invalid['event']['summary'] = "\xff";
			$this->assertTrue(alarmmanager_submit($invalid)['error'] === 'invalid_event', 'Malformed UTF-8 accepted.');
			$GLOBALS['alarmmanager_test_db']->exec('RENAME TABLE plugin_alarmmanager_jobs TO plugin_alarmmanager_jobs_unavailable');
			$result = alarmmanager_submit($this->alarmmanager_db_sample('rollback'));
			$this->assertTrue($result['status'] === 'failed', 'Missing job table accepted.');
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = 'rollback'"), 'Partial insertion did not roll back.');
			$GLOBALS['alarmmanager_test_db']->exec('RENAME TABLE plugin_alarmmanager_jobs_unavailable TO plugin_alarmmanager_jobs');
			$GLOBALS['alarmmanager_fail_commit'] = true;
			$this->assertTrue(alarmmanager_submit($this->alarmmanager_db_sample('commit-fault'))['status'] === 'failed', 'Unconfirmed commit accepted.');
			$GLOBALS['alarmmanager_fail_commit'] = false;
			$this->assertSame(0, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = 'commit-fault'"), 'Commit fault did not roll back.');
			$GLOBALS['alarmmanager_test_db']->beginTransaction();
			$this->assertTrue(alarmmanager_submit($this->alarmmanager_db_sample('outer-transaction'))['error'] === 'transaction_unavailable', 'Producer transaction was committed.');
			$this->assertTrue($GLOBALS['alarmmanager_test_db']->inTransaction(), 'Producer transaction was rolled back.');
			$GLOBALS['alarmmanager_test_db']->rollBack();

			// Close the parent connection before forking; each child opens its own.
			$GLOBALS['alarmmanager_test_db'] = null;
			$children                        = [];

			for ($i = 0; $i < 8; $i++) {
				$pid = pcntl_fork();

				if ($pid === -1) {
					throw new RuntimeException('Fork failed.');
				}

				if ($pid === 0) {
					$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
					$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
					$status = alarmmanager_submit($this->alarmmanager_db_sample('concurrent'))['status'];
					exit($status === 'accepted' ? 10 : ($status === 'already_accepted' ? 11 : 12));
				}
				$children[] = $pid;
			}
			$accepted   = 0;
			$duplicates = 0;

			foreach ($children as $pid) {
				pcntl_waitpid($pid, $status);
				$code = pcntl_wexitstatus($status);
				$accepted   += $code === 10 ? 1 : 0;
				$duplicates += $code === 11 ? 1 : 0;
			}
			$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
			$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
			$this->assertTrue($accepted === 1 && $duplicates === 7, 'Competing submissions did not converge.');
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = 'concurrent'"), 'Concurrent event duplication.');
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_jobs AS j INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id WHERE e.event_id = 'concurrent'"), 'Concurrent job duplication.');
			$event_count = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
			$key         = db_fetch_cell_prepared("SELECT value FROM settings_user WHERE name = 'alarmmanager_key'");
			plugin_alarmmanager_uninstall();
			$this->assertTrue(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === $event_count, 'Uninstall destroyed history.');
			$this->assertTrue(db_fetch_cell_prepared("SELECT value FROM settings_user WHERE name = 'alarmmanager_key'") === $key, 'Uninstall destroyed key.');
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
	private function alarmmanager_db_sample(string $id, string $mode = 'centralized'): array {
		$event             = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
		$event['event_id'] = $id;

		return ['contract_version' => 1, 'mode' => $mode, 'status' => 'unhandled', 'event' => $event, 'producer_marker' => 'preserve'];
	}
}
