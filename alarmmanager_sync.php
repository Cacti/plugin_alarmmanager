<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Service endpoint: dedicated HMAC peer authentication, never a browser/session mutation.
header('Cache-Control: no-store'); header('Content-Type: application/json');
try {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576 || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) { throw new RuntimeException('invalid_request'); }
	$body = file_get_contents('php://input', false, null, 0, 1048577); if ($body === false || strlen($body) > 1048576) { throw new RuntimeException('invalid_request'); }
	$conn_mode = 'offline'; require_once(__DIR__ . '/../../include/global.php'); require_once(__DIR__ . '/includes/remote.php');
	$poller = filter_var($_SERVER['HTTP_X_ALARMMANAGER_POLLER'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 2147483647]]);
	$timestamp = filter_var($_SERVER['HTTP_X_ALARMMANAGER_TIME'] ?? '', FILTER_VALIDATE_INT);
	if (!$poller || !$timestamp || alarmmanager_local_poller() !== 1) { throw new RuntimeException('invalid_peer'); }
	$peer = alarmmanager_db_row('SELECT key_file FROM plugin_alarmmanager_sync_peers WHERE poller_id = ? AND enabled = 1', [$poller]); if (!$peer) { throw new RuntimeException('invalid_peer'); }
	$key = alarmmanager_sync_key($peer['key_file']);
	alarmmanager_sync_verify($body, $poller, $timestamp, $_SERVER['HTTP_X_ALARMMANAGER_SIGNATURE'] ?? '', $key, 'history');
	$ack = alarmmanager_sync_receive($body, $poller); $time = time();
	header('X-AlarmManager-Time: ' . $time); header('X-AlarmManager-Signature: ' . alarmmanager_sync_signature($ack, $poller, $time, $key, 'ack')); print $ack;
} catch (Throwable $error) { http_response_code(403); print '{"status":"rejected"}'; }
