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
	fwrite(STDERR, "Set ALARMMANAGER_TEST_ALLOW_CREATE=1 and a dedicated test-server DSN. This creates a new temporary database.\n");
	exit(2);
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
$alarmmanager_test_db = alarmmanager_test_connect();
$database             = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$alarmmanager_test_db->exec("USE `$database`");
$checks = 0;
$config = ['poller_id' => 2];
function alarmmanager_db_assert(bool $ok, string $message): void {
	global $checks;

	if (!$ok) {
		throw new RuntimeException($message);
	}
	$checks++;
}
function alarmmanager_db_sample(string $id, string $mode = 'centralized'): array {
	$event             = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
	$event['event_id'] = $id;

	return ['contract_version' => 1, 'mode' => $mode, 'status' => 'unhandled', 'event' => $event, 'producer_marker' => 'preserve'];
}

try {
	$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255) NOT NULL, value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
	$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.1.0', 1)");
	alarmmanager_db_assert(plugin_alarmmanager_install(), 'Initial installation failed.');
	alarmmanager_db_assert(plugin_alarmmanager_install(), 'Repeated installation failed.');
	alarmmanager_db_assert(plugin_alarmmanager_upgrade(), 'Upgrade failed.');
	alarmmanager_db_assert(alarmmanager_schema_ready(), 'Schema version missing.');
	$first = alarmmanager_submit(alarmmanager_db_sample('first'));
	alarmmanager_db_assert($first['status'] === 'accepted', 'Durable acceptance failed: ' . json_encode($first['error'] ?? null));
	alarmmanager_db_assert($first['producer_marker'] === 'preserve', 'Envelope was not preserved.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_jobs') === 1, 'Processing job missing.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared('SELECT processing_poller_id FROM plugin_alarmmanager_events') === 2, 'Local processing owner missing.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared('SELECT @@in_transaction') === 0, 'Acceptance preceded commit.');
	$again = alarmmanager_submit(alarmmanager_db_sample('first'));
	alarmmanager_db_assert($again['status'] === 'already_accepted' && $again['event_row_id'] === $first['event_row_id'], 'Duplicate was not recognized.');
	$conflict                     = alarmmanager_db_sample('first');
	$conflict['event']['summary'] = 'Different data under same ID';
	$result                       = alarmmanager_submit($conflict);
	alarmmanager_db_assert($result['status'] === 'failed' && $result['error'] === 'event_identity_conflict', 'Conflicting duplicate was accepted.');
	$alarmmanager_test_db->exec("UPDATE plugin_alarmmanager_jobs SET status = 'completed'");
	alarmmanager_submit(alarmmanager_db_sample('first'));
	alarmmanager_db_assert(db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_jobs') === 'completed', 'Duplicate reopened a completed job.');
	alarmmanager_db_assert(alarmmanager_submit(alarmmanager_db_sample('mode-switch', 'observe'))['status'] === 'accepted', 'Observation failed.');
	alarmmanager_db_assert(alarmmanager_submit(alarmmanager_db_sample('mode-switch'))['status'] === 'accepted', 'Observation consumed centralized ownership.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT mode) FROM plugin_alarmmanager_events WHERE event_id = 'mode-switch'") === 2, 'Mode identities were combined.');
	$alarmmanager_test_db->exec('UPDATE plugin_config SET status = 4');
	alarmmanager_db_assert(alarmmanager_submit(alarmmanager_db_sample('disabled'))['status'] === 'unhandled', 'Disabled plugin accepted events.');
	$alarmmanager_test_db->exec('DELETE FROM plugin_config');
	alarmmanager_db_assert(alarmmanager_submit(alarmmanager_db_sample('missing'))['status'] === 'unhandled', 'Unavailable plugin accepted events.');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.2.0', 1)");
	$alarmmanager_test_db->exec('RENAME TABLE plugin_config TO plugin_config_unavailable');
	$availability_failure = alarmmanager_submit(alarmmanager_db_sample('availability-failure'));
	alarmmanager_db_assert($availability_failure['status'] === 'failed', 'DB failure was treated as unavailable plugin.');
	$alarmmanager_test_db->exec('RENAME TABLE plugin_config_unavailable TO plugin_config');
	$invalid                     = alarmmanager_db_sample('invalid');
	$invalid['event']['summary'] = "\xff";
	alarmmanager_db_assert(alarmmanager_submit($invalid)['error'] === 'invalid_event', 'Malformed UTF-8 accepted.');
	$alarmmanager_test_db->exec('RENAME TABLE plugin_alarmmanager_jobs TO plugin_alarmmanager_jobs_unavailable');
	$result = alarmmanager_submit(alarmmanager_db_sample('rollback'));
	alarmmanager_db_assert($result['status'] === 'failed', 'Missing job table accepted.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = 'rollback'") === 0, 'Partial insertion did not roll back.');
	$alarmmanager_test_db->exec('RENAME TABLE plugin_alarmmanager_jobs_unavailable TO plugin_alarmmanager_jobs');
	$alarmmanager_fail_commit = true;
	alarmmanager_db_assert(alarmmanager_submit(alarmmanager_db_sample('commit-fault'))['status'] === 'failed', 'Unconfirmed commit accepted.');
	$alarmmanager_fail_commit = false;
	alarmmanager_db_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = 'commit-fault'") === 0, 'Commit fault did not roll back.');
	$alarmmanager_test_db->beginTransaction();
	alarmmanager_db_assert(alarmmanager_submit(alarmmanager_db_sample('outer-transaction'))['error'] === 'transaction_unavailable', 'Producer transaction was committed.');
	alarmmanager_db_assert($alarmmanager_test_db->inTransaction(), 'Producer transaction was rolled back.');
	$alarmmanager_test_db->rollBack();

	// Close the parent connection before forking; each child opens its own.
	$alarmmanager_test_db = null;
	$children             = [];

	for ($i = 0; $i < 8; $i++) {
		$pid = pcntl_fork();

		if ($pid === -1) {
			throw new RuntimeException('Fork failed.');
		}

		if ($pid === 0) {
			$alarmmanager_test_db = alarmmanager_test_connect();
			$alarmmanager_test_db->exec("USE `$database`");
			$status = alarmmanager_submit(alarmmanager_db_sample('concurrent'))['status'];
			exit($status === 'accepted' ? 10 : ($status === 'already_accepted' ? 11 : 12));
		}
		$children[] = $pid;
	}
	$accepted   = 0;
	$duplicates = 0;

	foreach ($children as $pid) {
		pcntl_waitpid($pid, $status);
		$code = pcntl_wexitstatus($status);
		$accepted   += $code   === 10 ? 1 : 0;
		$duplicates += $code === 11 ? 1 : 0;
	}
	$alarmmanager_test_db = alarmmanager_test_connect();
	$alarmmanager_test_db->exec("USE `$database`");
	alarmmanager_db_assert($accepted === 1 && $duplicates === 7, 'Competing submissions did not converge.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = 'concurrent'") === 1, 'Concurrent event duplication.');
	alarmmanager_db_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_jobs AS j INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id WHERE e.event_id = 'concurrent'") === 1, 'Concurrent job duplication.');
	$event_count = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
	$key         = db_fetch_cell_prepared("SELECT value FROM settings_user WHERE name = 'alarmmanager_key'");
	plugin_alarmmanager_uninstall();
	alarmmanager_db_assert(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === $event_count, 'Uninstall destroyed history.');
	alarmmanager_db_assert(db_fetch_cell_prepared("SELECT value FROM settings_user WHERE name = 'alarmmanager_key'") === $key, 'Uninstall destroyed key.');
	print 'PASS: ' . $checks . ' real database ingestion checks, including eight competing processes; ' . db_fetch_cell_prepared('SELECT VERSION()') . ".\n";
} finally {
	if (!$alarmmanager_test_db) {
		$alarmmanager_test_db = alarmmanager_test_connect();
	}

	if ($alarmmanager_test_db->inTransaction()) {
		$alarmmanager_test_db->rollBack();
	}
	$alarmmanager_test_db->exec("DROP DATABASE `$database`");
}
