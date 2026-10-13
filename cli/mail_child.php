<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
try {
	$input = stream_get_contents(STDIN, 1048577);
	if (strlen($input) > 1048576) { throw new RuntimeException('mail_input_limit'); }
	$message = json_decode($input, true, 24, JSON_THROW_ON_ERROR);
	if (!is_array($message)) { throw new RuntimeException('mail_input_invalid'); }
	$conn_mode = 'offline';
	require_once(__DIR__ . '/../../../include/cli_check.php');
	require_once(__DIR__ . '/../includes/email_boundary.php');
	print json_encode(alarmmanager_email_send($message), JSON_THROW_ON_ERROR);
} catch (Throwable $error) { print '{"outcome":"retry","code":"email_failed"}'; exit(1); }
