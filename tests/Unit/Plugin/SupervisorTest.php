<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once(__DIR__ . '/../../../includes/supervisor.php');
if (!function_exists('posix_kill')) { fwrite(STDERR, "pcntl and posix required.\n"); exit(2); }
$checks = 0;
function am_supervisor_assert(bool $value, string $message): void { global $checks; if (!$value) { throw new RuntimeException($message); } $checks++; }
function am_supervisor_records(string $path): array {
	$lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
	return array_map(static fn($line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
}
function am_supervisor_active(array $rows): array {
	$active = [];
	foreach ($rows as $row) { if ($row['op'] === 'start') { $active[$row['pid']] = $row; } else { unset($active[$row['pid']]); } }
	return $active;
}
function am_supervisor_wait(callable $ready): bool {
	$deadline = microtime(true) + 5;
	do { if ($ready()) { return true; } usleep(20000); } while (microtime(true) < $deadline);
	return false;
}
$prefix = sys_get_temp_dir() . '/alarmmanager-supervisor-' . bin2hex(random_bytes(6));
$configuration = $prefix . '.json'; $log = $prefix . '.log'; $marker = $prefix . '.marker';
$options = ['processing_workers' => 1, 'delivery_workers' => 1, 'shutdown_seconds' => 1, 'respawn_base_ms' => 100];
file_put_contents($configuration, json_encode($options, JSON_THROW_ON_ERROR));
putenv('ALARMMANAGER_SUPERVISOR_TEST_LOG=' . $log); putenv('ALARMMANAGER_SUPERVISOR_TEST_CRASH_MARKER=' . $marker);
$pid = pcntl_fork();
if ($pid === 0) { alarmmanager_supervise($configuration, __DIR__ . '/../../fixtures/supervisor_worker.php'); exit(0); }
try {
	am_supervisor_assert($pid > 0, 'Supervisor fork failed.');
	am_supervisor_assert(am_supervisor_wait(static function () use ($log): bool { $rows = am_supervisor_records($log); return count(array_filter($rows, static fn($row) => $row['op'] === 'start' && $row['role'] === 'processing')) >= 2 && count(am_supervisor_active($rows)) === 2; }), 'Failed worker was not respawned.');
	$rows = am_supervisor_records($log); $crash = array_values(array_filter($rows, static fn($row) => $row['op'] === 'crash'))[0];
	$restarted = array_values(array_filter($rows, static fn($row) => $row['op'] === 'start' && $row['role'] === 'processing' && $row['time'] > $crash['time']))[0];
	am_supervisor_assert($restarted['time'] - $crash['time'] >= 0.09, 'Respawn had no backoff.');
	$options['processing_workers'] = 2; file_put_contents($configuration, json_encode($options, JSON_THROW_ON_ERROR)); posix_kill($pid, SIGHUP);
	am_supervisor_assert(am_supervisor_wait(static function () use ($log): bool { $active = am_supervisor_active(am_supervisor_records($log)); return count(array_filter($active, static fn($row) => $row['role'] === 'processing')) === 2 && count(array_filter($active, static fn($row) => $row['role'] === 'delivery')) === 1; }), 'Reload did not resize pools.');
	file_put_contents($configuration, '{bad'); posix_kill($pid, SIGHUP); usleep(200000);
	am_supervisor_assert(count(am_supervisor_active(am_supervisor_records($log))) === 3, 'Invalid reload changed pools.');
	posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); $pid = 0;
	am_supervisor_assert(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Supervisor shutdown failed.');
	am_supervisor_assert(am_supervisor_active(am_supervisor_records($log)) === [], 'Graceful shutdown left fixture workers running.');

	// A stubborn fixture validates bounded escalation rather than hanging forever.
	file_put_contents($configuration, json_encode(['processing_workers' => 1, 'delivery_workers' => 1, 'shutdown_seconds' => 1, 'respawn_base_ms' => 100], JSON_THROW_ON_ERROR));
	file_put_contents($log, ''); putenv('ALARMMANAGER_SUPERVISOR_TEST_IGNORE_DELIVERY=1');
	$pid = pcntl_fork();
	if ($pid === 0) { alarmmanager_supervise($configuration, __DIR__ . '/../../fixtures/supervisor_worker.php'); exit(0); }
	am_supervisor_assert(am_supervisor_wait(static fn() => count(am_supervisor_active(am_supervisor_records($log))) === 2), 'Escalation fixture did not start.');
	$active = am_supervisor_active(am_supervisor_records($log));
	$started = microtime(true); posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); $pid = 0;
	am_supervisor_assert(microtime(true) - $started < 2.5 && pcntl_wexitstatus($status) === 0, 'Shutdown escalation was unbounded.');
	foreach ($active as $child) { am_supervisor_assert(!posix_kill($child['pid'], 0), 'Escalation left child running.'); }
	print "PASS: $checks supervisor checks (fresh child processes, crash backoff, reload and bounded shutdown).\n";
} finally {
	if ($pid > 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
	foreach ([$configuration, $log, $marker] as $file) { if (file_exists($file)) { unlink($file); } }
}
