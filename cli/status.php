<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (in_array('--help', $argv, true)) { print "Usage: php cli/status.php\n"; exit; }
// Force Cacti's documented local/offline bootstrap: never wait for the main DB.
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/workers.php');
try { print json_encode(alarmmanager_metrics(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"; }
catch (Throwable $error) { fwrite(STDERR, "Cannot read Alarm Manager status.\n"); exit(1); }
