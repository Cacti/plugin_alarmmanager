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
// Copy to the producer's cli/alarmmanager_api.php; no Alarm Manager runtime include is used.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$options = getopt('', ['install', 'retry-pending', 'source:', 'limit:', 'help']);

if (isset($options['help'])) {
	print "Usage: php cli/alarmmanager_api.php --install\n       php cli/alarmmanager_api.php --retry-pending --source=NAME [--limit=100]\nInstall the producer-owned outbox before enabling API submission. Retry pending events with their original IDs; never invoke legacy delivery.\n";
	exit(0);
}
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/alarmmanager_bridge.php');

try {
	if (isset($options['install']) && !isset($options['retry-pending'])) {
		AlarmManagerProducerBridge::installApiOutbox();
		print "Producer API outbox installed.\n";
		exit(0);
	}
	$source = $options['source'] ?? '';
	$limit  = filter_var($options['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

	if (!isset($options['retry-pending']) || isset($options['install']) || !$limit || !preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,31}\z/D', $source)) {
		throw new RuntimeException('invalid_options');
	}
	$rows = db_fetch_assoc_prepared("SELECT receipt FROM plugin_alarmmanager_producer_receipts WHERE source = ? AND status = 'pending' ORDER BY created_at, receipt LIMIT " . $limit, [$source], false);

	if ($rows === false) {
		throw new RuntimeException('outbox_unavailable');
	}
	$pending = 0;

	foreach ($rows as $row) {
		try {
			$pending += AlarmManagerProducerBridge::retry($row['receipt']) === 'pending' ? 1 : 0;
		} catch (Throwable $error) {
			$pending++;
		}
	}
	print json_encode(['checked' => count($rows), 'pending' => $pending], JSON_THROW_ON_ERROR) . "\n";
	exit($pending > 0 ? 1 : 0);
} catch (Throwable $error) {
	fwrite(STDERR, "Producer API recovery unavailable; pending events remain queued.\n");
	exit(1);
}
