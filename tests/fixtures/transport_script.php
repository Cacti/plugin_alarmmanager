<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$mode = $argv[1] ?? 'ok';
if ($mode === 'descendants') { $pid = pcntl_fork(); if ($pid === 0) { sleep(10); exit; } file_put_contents($argv[2], (string) $pid); sleep(10); exit; }
if ($mode === 'timeout') { sleep(10); exit; }
if ($mode === 'large') { while (true) { print str_repeat('x', 8192); } }
if ($mode === 'retry') { exit(75); }
if ($mode === 'failure') { exit(2); }
$payload = stream_get_contents(STDIN);
if (isset($argv[2])) {
	file_put_contents($argv[2], json_encode(['payload' => json_decode($payload, true), 'arguments' => array_slice($argv, 3), 'environment_filtered' => getenv('ALARMMANAGER_FIXTURE_PRIVATE') === false, 'idempotency' => getenv('ALARM_IDEMPOTENCY_KEY')], JSON_THROW_ON_ERROR));
}
exit(0);
