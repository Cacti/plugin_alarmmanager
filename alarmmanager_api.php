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
// Machine endpoint: bearer authentication, independent of Cacti browser sessions/CSRF.
header('Cache-Control: no-store');
header('Content-Type: application/json');
$api_response_sent = false;
$api_buffer_level  = ob_get_level();
ob_start();
// Cacti's bootstrap can exit on DB failure; preserve the API's JSON/503 contract.
register_shutdown_function(static function () use (&$api_response_sent, $api_buffer_level): void {
	if (!$api_response_sent) {
		while (ob_get_level() > $api_buffer_level) {
			ob_end_clean();
		}
		http_response_code(503);
		header('Content-Type: application/json');
		print '{"status":"rejected","error":"unavailable"}';
	}
});

try {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		header('Allow: POST');
		http_response_code(405);

		throw new RuntimeException('api_method_not_allowed');
	}

	if (empty($_SERVER['HTTPS']) || strtolower((string) $_SERVER['HTTPS']) === 'off') {
		if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
			http_response_code(403);

			throw new RuntimeException('api_https_required');
		}
	}

	if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
		http_response_code(413);

		throw new RuntimeException('api_payload_too_large');
	}

	if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
		http_response_code(415);

		throw new RuntimeException('api_json_required');
	}
	$body = file_get_contents('php://input', false, null, 0, 32769);

	if ($body === false || strlen($body) > 32768) {
		http_response_code(413);

		throw new RuntimeException('api_payload_too_large');
	}
	$conn_mode       = 'offline';

	if (!empty($_GET) || !empty($_POST)) {
		throw new InvalidArgumentException('api_contract_invalid');
	}
	// Machine credentials replace browser CSRF only on this dedicated JSON endpoint.
	require_once(__DIR__ . '/../../include/vendor/csrf/csrf-conf.php');
	$GLOBALS['csrf']['disable'] = true;
	require_once(__DIR__ . '/../../include/global.php');
	require_once(__DIR__ . '/includes/api.php');
	require_once(__DIR__ . '/includes/schema.php');

	if (!alarmmanager_runtime_enabled() || !alarmmanager_schema_ready()) {
		throw new RuntimeException('api_unavailable');
	}
	$result = alarmmanager_api_receive($_SERVER['HTTP_AUTHORIZATION'] ?? '', $body);
	http_response_code($result['status'] === 'accepted' ? 202 : 200);
	print json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
	$code = $error->getMessage();

	if (http_response_code() < 400) {
		http_response_code(match ($code) {
			'api_unauthorized' => 401, 'api_scope_denied' => 403, 'event_identity_conflict' => 409,
			default            => $error instanceof InvalidArgumentException || $error instanceof JsonException ? 400 : 503
		});
	}

	if (http_response_code() === 401) {
		header('WWW-Authenticate: Bearer');
	}
	print json_encode(['status' => 'rejected', 'error' => match (http_response_code()) {
		401 => 'unauthorized', 403 => $code === 'api_https_required' ? 'https_required' : 'scope_denied', 409 => 'event_identity_conflict',
		405 => 'method_not_allowed', 413 => 'payload_too_large', 415 => 'json_required', 400 => 'invalid_event', default => 'unavailable'
	}], JSON_THROW_ON_ERROR);
}

$api_response_sent = true;
ob_end_flush();
