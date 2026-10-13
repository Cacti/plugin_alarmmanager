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
$options = getopt('', ['endpoint:', 'file:', 'preview', 'test-send', 'help']);

if (isset($options['help'])) {
	print "Usage: php cli/endpoint.php --endpoint=1 --file=examples/event.json --preview|--test-send\nTest-send queues one explicit test for delivery workers; preview sends nothing.\n";
	exit(0);
}

try {
	$id = filter_var($options['endpoint'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

	if (!$id || isset($options['preview']) === isset($options['test-send']) || !is_string($options['file'] ?? null)) {
		throw new RuntimeException('invalid_options');
	}
	$data = @file_get_contents($options['file'], false, null, 0, 65537);

	if ($data === false || strlen($data) > 65536) {
		throw new RuntimeException('invalid_input');
	}
	$event = json_decode($data, true, 24, JSON_THROW_ON_ERROR);

	if (!is_array($event)) {
		throw new RuntimeException('invalid_input');
	}
} catch (Throwable $error) {
	fwrite(STDERR, "Invalid endpoint/event options.\n");
	exit(2);
}
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/test_delivery.php');

try {
	if (isset($options['preview'])) {
		$endpoint = alarmmanager_db_row('SELECT id, type FROM plugin_alarmmanager_endpoints WHERE id = ?', [$id]);

		if (!$endpoint) {
			throw new RuntimeException('endpoint_unavailable');
		}
		print json_encode(alarmmanager_delivery_preview($event, $endpoint), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
	} else {
		print 'Test delivery queued; event row ' . alarmmanager_enqueue_test($event, $id) . ".\n";
	}
} catch (Throwable $error) {
	fwrite(STDERR, "Endpoint action failed; no credentials or transport diagnostics displayed.\n");
	exit(1);
}
