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
// Service endpoint: dedicated HMAC peer authentication, never a browser/session mutation.
header('Cache-Control: no-store');
header('Content-Type: application/json');

try {
	// Cheap structural gate: reject malformed transport without bootstrapping Cacti.
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		throw new RuntimeException('invalid_method');
	}

	if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
		throw new RuntimeException('invalid_size');
	}

	if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
		throw new RuntimeException('invalid_content_type');
	}

	$body = file_get_contents('php://input', false, null, 0, 1048577);

	if ($body === false || strlen($body) > 1048576) {
		throw new RuntimeException('invalid_size');
	}
	$conn_mode = 'offline';
	require_once(__DIR__ . '/../../include/global.php');
	require_once(__DIR__ . '/includes/remote.php');

	// Receiver gate: plugin enabled, schema provisioned and this node is the main poller.
	if (!alarmmanager_sync_receiver_ready()) {
		throw new RuntimeException('receiver_unavailable');
	}
	$poller    = filter_var($_SERVER['HTTP_X_ALARMMANAGER_POLLER'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 2147483647]]);
	$timestamp = filter_var($_SERVER['HTTP_X_ALARMMANAGER_TIME'] ?? '', FILTER_VALIDATE_INT);

	if (!$poller || !$timestamp) {
		throw new RuntimeException('invalid_peer');
	}
	$peer = alarmmanager_db_row('SELECT key_file FROM plugin_alarmmanager_sync_peers WHERE poller_id = ? AND enabled = 1', [$poller]);

	if (!$peer) {
		throw new RuntimeException('invalid_peer');
	}
	$key = alarmmanager_sync_key($peer['key_file']);
	alarmmanager_sync_verify($body, $poller, $timestamp, $_SERVER['HTTP_X_ALARMMANAGER_SIGNATURE'] ?? '', $key, 'history');
	$ack  = alarmmanager_sync_receive($body, $poller);
	$time = time();
	header('X-AlarmManager-Time: ' . $time);
	header('X-AlarmManager-Signature: ' . alarmmanager_sync_signature($ack, $poller, $time, $key, 'ack'));
	print $ack;
} catch (Throwable $error) {
	http_response_code(403);
	print '{"status":"rejected"}';
	alarmmanager_sync_log_rejection($error->getMessage() !== '' ? $error->getMessage() : 'unexpected_failure');
}

/**
 * Best-effort audit trail: every rejection lands in the Cacti log with the
 * reason and the claimed poller id. Credentials, signatures and bodies are
 * never logged, and a failure to log never changes the 403 response.
 */
function alarmmanager_sync_log_rejection(string $reason): void {
	try {
		if (!function_exists('cacti_log')) {
			require_once(__DIR__ . '/../../include/global.php');
		}

		$reason = preg_replace('/[^A-Za-z0-9_. :-]/', '', $reason);
		$poller = preg_replace('/[^0-9]/', '', (string) ($_SERVER['HTTP_X_ALARMMANAGER_POLLER'] ?? 'unknown'));
		cacti_log('[alarmmanager] sync request rejected: ' . $reason . ' (poller: ' . $poller . ')', false, 'alarmmanager');
	} catch (Throwable) {
		// Logging is best-effort only; the response above already went out.
	}
}
