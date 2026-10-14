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
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$options = getopt('', ['role:', 'once', 'max-jobs:', 'max-seconds:', 'lease-seconds:', 'idle-ms:', 'help']);

if (isset($options['help'])) {
	print "Usage: php includes/worker.php --role=processing|delivery [--once --max-jobs=100 --max-seconds=30] [--lease-seconds=180 --idle-ms=250]\nInternal entry point: spawned by alarmmanager_daemon.php; administrators do not run it directly.\n";
	exit(0);
}
$role = $options['role'] ?? '';

if (!in_array($role, ['processing', 'delivery'], true) || (!function_exists('pcntl_signal') || !function_exists('pcntl_alarm') || !function_exists('pcntl_async_signals'))) {
	fwrite(STDERR, "A valid role and pcntl are required.\n");
	exit(2);
}
$settings = ['once' => isset($options['once'])];

foreach (['max_jobs' => ['max-jobs', $settings['once'] ? 100 : 1000000000, 1, 1000000000], 'max_seconds' => ['max-seconds', $settings['once'] ? 30 : 31536000, 1, 31536000], 'lease_seconds' => ['lease-seconds', 180, 15, 600], 'idle_ms' => ['idle-ms', 250, 10, 10000]] as $key => [$argument, $default, $min, $max]) {
	$settings[$key] = filter_var($options[$argument] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

	if ($settings[$key] === false) {
		fwrite(STDERR, "Invalid worker options.\n");
		exit(2);
	}
}
$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
pcntl_signal(SIGINT, function () use (&$stop): void { $stop = true; });
// Force Cacti's documented local/offline bootstrap: never wait for the main DB.
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/workers.php');

try {
	alarmmanager_worker_loop($role, $settings, static function () use (&$stop): bool { return $stop; });
} catch (Throwable $error) {
	alarmmanager_log('worker storage/runtime failure: ' . $error->getMessage());
	fwrite(STDERR, "Alarm Manager worker storage/runtime failure.\n");
	exit(1);
}
