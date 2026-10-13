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
	fwrite(STDERR, "Dedicated disposable test server required.\n");
	exit(2);
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/workers.php');
$volume = filter_var(getenv('ALARMMANAGER_BENCH_EVENTS') ?: 500, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 10000]]);

if ($volume === false) {
	exit(2);
}
$config               = ['poller_id' => 2];
$alarmmanager_test_db = alarmmanager_test_connect();
$database             = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$alarmmanager_test_db->exec("USE `$database`");
function am_benchmark_pool(string $role, string $database, int $workers): array {
	global $alarmmanager_test_db;
	$alarmmanager_test_db = null;
	$prefix               = sys_get_temp_dir() . '/alarmmanager-pool-' . bin2hex(random_bytes(6));
	$children             = [];
	$started              = microtime(true);

	for ($i = 0; $i < $workers; $i++) {
		$pid = pcntl_fork();

		if ($pid === -1) {
			throw new RuntimeException('fork_failure');
		}

		if ($pid === 0) {
			$alarmmanager_test_db = alarmmanager_test_connect();
			$alarmmanager_test_db->exec("USE `$database`");
			$GLOBALS['alarmmanager_transport_handlers'] = [
				'restapi' => static function (): array {
					usleep(1000);

					return ['outcome' => 'success', 'code' => 'fixture_ok'];
				},
				'webhook' => static function (): array {
					usleep(1000);

					return ['outcome' => 'success', 'code' => 'fixture_ok'];
				}
			];
			$worker_id                            = bin2hex(random_bytes(16));
			$count                                = 0;
			$GLOBALS['alarmmanager_test_queries'] = 0;

			try {
				for ($tries = 0; $tries < 100000 && microtime(true) - $started < 120; $tries++) {
					if (alarmmanager_worker_step($role, $worker_id)) {
						$count++;

						continue;
					}
					$table     = alarmmanager_queue_table($role);
					$remaining = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM $table WHERE status IN ('pending', 'leased')");

					if ($remaining === 0) {
						break;
					}
					usleep(1000);
				}
				alarmmanager_heartbeat($worker_id, $role, 'stopped');
				file_put_contents($prefix . '-' . $i, json_encode(['jobs' => $count, 'database_queries' => $GLOBALS['alarmmanager_test_queries'] ?? 0, 'database_error_codes' => $GLOBALS['alarmmanager_test_database_errors'] ?? [], 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));
				exit(0);
			} catch (Throwable $error) {
				fwrite(STDERR, 'Benchmark worker failed: ' . get_class($error) . ' ' . $error->getMessage() . ' ' . json_encode($GLOBALS['alarmmanager_test_database_errors'] ?? []) . "\n");
				exit(1);
			}
		}
		$children[$i] = $pid;
	}
	$reports = [];
	$failed  = false;

	foreach ($children as $i => $pid) {
		pcntl_waitpid($pid, $status);

		if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
			$failed = true;
		}

		if (file_exists($prefix . '-' . $i)) {
			$reports[] = json_decode(file_get_contents($prefix . '-' . $i), true, 8, JSON_THROW_ON_ERROR);
			unlink($prefix . '-' . $i);
		}
	}

	if ($failed) {
		throw new RuntimeException('benchmark_worker_failure');
	}
	$elapsed              = microtime(true) - $started;
	$alarmmanager_test_db = alarmmanager_test_connect();
	$alarmmanager_test_db->exec("USE `$database`");

	return ['elapsed_seconds' => $elapsed, 'workers' => $reports];
}

try {
	$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255) NOT NULL, value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
	$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.4.0', 1)");

	if (!plugin_alarmmanager_install()) {
		throw new RuntimeException('install_failure');
	}
	$routes = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true, 32, JSON_THROW_ON_ERROR);
	alarmmanager_apply_configuration($routes);
	$sample  = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
	$started = microtime(true);

	for ($i = 0; $i < $volume; $i++) {
		$event                = $sample;
		$event['event_id']    = 'outage-' . $i;
		$event['alarm_key']   = 'device:' . $i;
		$event['resource_id'] = 'device:' . $i;
		$result               = alarmmanager_submit(['contract_version' => 1, 'mode' => 'centralized', 'event' => $event]);

		if ($result['status'] !== 'accepted') {
			throw new RuntimeException('benchmark_ingestion_failure');
		}
	}
	$ingest_seconds   = microtime(true) - $started;
	$lock_waits_start = (int) $alarmmanager_test_db->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->fetch(PDO::FETCH_NUM)[1];
	$lock_time_start  = (int) $alarmmanager_test_db->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_time'")->fetch(PDO::FETCH_NUM)[1];
	$processing       = am_benchmark_pool('processing', $database, 4);
	$delivery         = am_benchmark_pool('delivery', $database, 4);
	$event_count      = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
	$jobs             = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_jobs WHERE status = 'completed'");
	$delivered        = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'");
	$attempts         = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_attempts');

	if ($event_count !== $volume || $jobs !== $volume || $delivered !== $volume * 2 || $attempts !== $volume * 2) {
		throw new RuntimeException('benchmark_count_mismatch');
	}

	if (count(array_filter($processing['workers'], static fn ($row) => $row['jobs'] > 0)) < 2 || count(array_filter($delivery['workers'], static fn ($row) => $row['jobs'] > 0)) < 2) {
		throw new RuntimeException('pool_not_parallel');
	}
	$result = ['synthetic_only' => true, 'php' => PHP_VERSION, 'database' => db_fetch_cell_prepared('SELECT VERSION()'), 'events' => $volume, 'destinations_per_event' => 2,
		'ingestion_seconds'        => $ingest_seconds, 'ingestion_events_per_second' => $volume / $ingest_seconds,
		'processing'               => $processing, 'processing_events_per_second' => $volume / $processing['elapsed_seconds'],
		'delivery'                 => $delivery, 'delivery_jobs_per_second' => $delivered / $delivery['elapsed_seconds'],
		'innodb_row_lock_waits'    => (int) $alarmmanager_test_db->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->fetch(PDO::FETCH_NUM)[1] - $lock_waits_start, 'innodb_row_lock_time_ms' => (int) $alarmmanager_test_db->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_time'")->fetch(PDO::FETCH_NUM)[1] - $lock_time_start,
		'parent_peak_memory_bytes' => memory_get_peak_usage(true), 'event_count' => $event_count, 'completed_processing_jobs' => $jobs, 'completed_delivery_jobs' => $delivered, 'attempts' => $attempts, 'processing_retries' => (int) db_fetch_cell_prepared('SELECT COALESCE(SUM(GREATEST(CAST(attempts AS SIGNED) - 1, 0)), 0) FROM plugin_alarmmanager_jobs'), 'oldest_remaining_age_seconds' => (int) db_fetch_cell_prepared("SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP())), 0) FROM plugin_alarmmanager_jobs WHERE status IN ('pending', 'leased')")];
	print json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
	if (!$alarmmanager_test_db) {
		$alarmmanager_test_db = alarmmanager_test_connect();
	}

	if ($alarmmanager_test_db->inTransaction()) {
		$alarmmanager_test_db->rollBack();
	}
	$alarmmanager_test_db->exec("DROP DATABASE `$database`");
}
