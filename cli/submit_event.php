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
$options = getopt('', ['file:', 'mode:', 'help']);

if (isset($options['help']) || !isset($options['file'])) {
	print "Usage: php cli/submit_event.php --file=examples/event.json [--mode=observe|centralized]\n";
	exit(isset($options['help']) ? 0 : 2);
}
$mode = $options['mode'] ?? 'observe';

if (!in_array($mode, ['observe', 'centralized'], true) || !is_string($options['file'])) {
	fwrite(STDERR, "Invalid arguments.\n");
	exit(2);
}
$input = @file_get_contents($options['file'], false, null, 0, 65537);

try {
	if ($input === false || strlen($input) > 65536) {
		throw new RuntimeException('Invalid input.');
	}
	$event = json_decode($input, true, 32, JSON_THROW_ON_ERROR);

	if (!is_array($event)) {
		throw new RuntimeException('Invalid event.');
	}
} catch (Throwable $error) {
	fwrite(STDERR, "Cannot read a bounded JSON event file.\n");
	exit(2);
}
// cli_check.php bootstraps Cacti's local poller configuration and DB helpers.
// Force Cacti's documented local/offline bootstrap: never wait for the main DB.
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
$envelope = ['contract_version' => 1, 'mode' => $mode, 'status' => 'unhandled', 'event' => $event];
$result   = api_plugin_hook_function('alarmmanager_submit', $envelope);
$status   = is_array($result) ? ($result['status'] ?? 'failed') : 'failed';
print json_encode(['status' => $status, 'error' => $result['error'] ?? null, 'event_row_id' => $result['event_row_id'] ?? null], JSON_THROW_ON_ERROR) . "\n";
exit(in_array($status, ['accepted', 'already_accepted'], true) ? 0 : 1);
