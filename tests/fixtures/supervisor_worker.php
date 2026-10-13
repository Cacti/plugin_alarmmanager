<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { exit(2); }
$options = getopt('', ['role:', 'lease-seconds:', 'idle-ms:']);
$role = $options['role'] ?? '';
$log = getenv('ALARMMANAGER_SUPERVISOR_TEST_LOG');
if (!$log || !in_array($role, ['processing', 'delivery'], true)) { exit(2); }
function am_supervisor_fixture_log(string $op): void {
	global $role, $log;
	file_put_contents($log, json_encode(['op' => $op, 'role' => $role, 'pid' => getmypid(), 'time' => microtime(true)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
}
am_supervisor_fixture_log('start');
$marker = getenv('ALARMMANAGER_SUPERVISOR_TEST_CRASH_MARKER');
if ($role === 'processing' && $marker && ($handle = @fopen($marker, 'x'))) { fclose($handle); am_supervisor_fixture_log('crash'); exit(7); }
$stop = false; pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
if ($role === 'delivery' && getenv('ALARMMANAGER_SUPERVISOR_TEST_IGNORE_DELIVERY') === '1') { pcntl_signal(SIGTERM, SIG_IGN); }
while (!$stop) { usleep(10000); }
am_supervisor_fixture_log('stop');
