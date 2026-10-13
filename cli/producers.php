<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', ['retry-pending', 'limit:', 'help']);
if (isset($options['help'])) { print "Usage: php cli/producers.php --retry-pending [--limit=100]\nRetries bounded pending producer receipts using their original event IDs. Never invokes legacy delivery or reopens accepted/observed/legacy receipts.\n"; exit(0); }
$limit = filter_var($options['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
if (!isset($options['retry-pending']) || !$limit) { fwrite(STDERR, "Invalid producer options.\n"); exit(2); }
$conn_mode = 'offline'; require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/ingestion.php');
require_once(__DIR__ . '/../integrations/producer_bridge.php');
try {
	$rows = alarmmanager_db_rows("SELECT receipt FROM plugin_alarmmanager_producer_receipts WHERE status = 'pending' ORDER BY created_at, receipt LIMIT " . (int) $limit);
	$results = [];
	foreach ($rows as $row) {
		try { $status = AlarmManagerProducerBridge::retry($row['receipt'], 'alarmmanager_submit'); } catch (Throwable $error) { $status = 'pending'; }
		$results[$status] = ($results[$status] ?? 0) + 1;
	}
	print json_encode(['checked' => count($rows), 'results' => $results], JSON_THROW_ON_ERROR) . "\n";
	if (!empty($results['pending'])) { exit(1); }
} catch (Throwable $error) { fwrite(STDERR, "Producer recovery unavailable; no payloads or credentials displayed.\n"); exit(1); }
