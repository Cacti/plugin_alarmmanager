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
require_once(__DIR__ . '/routing.php');
require_once(__DIR__ . '/credential_store.php');
require_once(__DIR__ . '/process_transport.php');

function alarmmanager_endpoint_transport(array $endpoint): array {
	$row      = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_transports WHERE endpoint_id = ?', [(int) $endpoint['id']]);
	$settings = $row ? json_decode($row['definition'], true, 24, JSON_THROW_ON_ERROR) : [];
	$settings = alarmmanager_transport_configuration($endpoint['type'], $settings);

	if (!$settings) {
		throw new InvalidArgumentException('transport_not_configured');
	}

	return $settings;
}
/** Preview deliberately does not load/decrypt secrets or invoke a transport. */
function alarmmanager_delivery_preview(array $event, array $endpoint): array {
	$event    = alarmmanager_validate_event($event);
	$settings = alarmmanager_endpoint_transport($endpoint);

	return ['endpoint_id' => (int) $endpoint['id'], 'type' => $endpoint['type'], 'payload' => alarmmanager_map_payload($settings['mapping'], $event), 'credentials' => !empty($settings['credential']) ? '[REDACTED]' : 'none'];
}
function alarmmanager_retry_after(?string $value, ?int $now = null): int {
	if ($value === null) {
		return 0;
	}
	$value = trim($value);

	if (preg_match('/\A[0-9]{1,10}\z/D', $value)) {
		return min(3600, (int) $value);
	}
	$time = strtotime($value);

	return $time === false ? 0 : min(3600, max(0, $time - ($now ?? time())));
}
function alarmmanager_http_result(int $status, string $body, array $settings, ?string $retry_after = null): array {
	if (in_array($status, $settings['success_codes'], true)) {
		if ($settings['response_match'] !== null) {
			try {
				$data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
			} catch (Throwable $error) {
				return ['outcome' => 'failure', 'code' => 'http_response_invalid'];
			}

			foreach (explode('.', $settings['response_match']['path']) as $part) {
				if (!is_array($data) || !array_key_exists($part, $data)) {
					return ['outcome' => 'failure', 'code' => 'http_response_mismatch'];
				} $data = $data[$part];
			}

			if ($data !== $settings['response_match']['equals']) {
				return ['outcome' => 'failure', 'code' => 'http_response_mismatch'];
			}
		}

		return ['outcome' => 'success', 'code' => 'http_' . $status];
	}
	$retry = in_array($status, [408, 425, 429], true) || $status >= 500;

	return ['outcome' => $retry ? 'retry' : 'failure', 'code' => 'http_' . $status, 'retry_after' => $retry ? alarmmanager_retry_after($retry_after) : 0];
}
function alarmmanager_http_transport(array $event, array $endpoint, string $idempotency): array {
	if (!function_exists('curl_init')) {
		return ['outcome' => 'failure', 'code' => 'curl_unavailable'];
	}
	$settings = alarmmanager_endpoint_transport($endpoint);
	$payload  = json_encode(alarmmanager_map_payload($settings['mapping'], $event), JSON_THROW_ON_ERROR);
	$headers  = ['Content-Type: application/json', 'Idempotency-Key: ' . $idempotency, 'Expect:'];

	if ($settings['credential']) {
		$credential = alarmmanager_load_credential((int) $endpoint['id']);

		foreach ($credential['headers'] as $name => $value) {
			$headers[] = $name . ': ' . $value;
		}

		if (isset($credential['basic'])) {
			$headers[] = 'Authorization: Basic ' . base64_encode($credential['basic']['username'] . ':' . $credential['basic']['password']);
		}
	}
	$curl         = curl_init();
	$body         = '';
	$header_bytes = 0;
	$too_large    = false;
	$retry_after  = null;

	try {
		curl_setopt_array($curl, [CURLOPT_URL => $settings['url'], CURLOPT_CUSTOMREQUEST => $settings['method'], CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER                   => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER               => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => min((int) $endpoint['timeout_seconds'], $settings['connect_timeout_seconds']),
			CURLOPT_TIMEOUT                      => (int) $endpoint['timeout_seconds'], CURLOPT_NOSIGNAL => true, CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
			CURLOPT_WRITEFUNCTION                => static function ($handle, string $chunk) use (&$body, &$too_large): int {
				if (strlen($body) + strlen($chunk) > 65536) {
					$too_large = true;

					return 0;
				} $body .= $chunk;

				return strlen($chunk);
			},
			CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$header_bytes, &$too_large, &$retry_after): int {
				$header_bytes += strlen($line);

				if ($header_bytes > 16384) {
					$too_large = true;

					return 0;
				}

				if (str_starts_with($line, 'HTTP/')) {
					$retry_after = null;
				}

				if (stripos($line, 'Retry-After:') === 0) {
					$retry_after = trim(substr($line, 12));
				}

				return strlen($line);
			}]);

		if ($settings['ca_bundle'] !== null) {
			curl_setopt($curl, CURLOPT_CAINFO, $settings['ca_bundle']);
		}
		$ok     = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$errno  = curl_errno($curl);

		if ($too_large) {
			return ['outcome' => 'failure', 'code' => 'http_response_limit'];
		}

		if ($ok === false) {
			return ['outcome' => in_array($errno, [CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE, CURLE_URL_MALFORMAT, CURLE_UNSUPPORTED_PROTOCOL], true) ? 'failure' : 'retry', 'code' => $errno === CURLE_OPERATION_TIMEDOUT ? 'http_timeout' : 'http_network_error'];
		}

		return alarmmanager_http_result($status, $body, $settings, $retry_after);
	} finally {
		curl_close($curl);
	}
}
function alarmmanager_email_transport(array $event, array $endpoint, string $idempotency): array {
	global $config;
	$settings = alarmmanager_endpoint_transport($endpoint);
	$payload  = alarmmanager_map_payload($settings['mapping'], $event);

	if (!is_string($payload->subject ?? null) || !is_string($payload->body ?? null) || strlen($payload->subject) > 512 || preg_match('/[\r\n\x00]/', $payload->subject)) {
		throw new InvalidArgumentException('email_payload_invalid');
	}

	foreach ($settings['attachments'] as $attachment) {
		$data = $attachment['attachment'];

		if (str_starts_with($data, '/') && (!is_file($data) || !is_readable($data) || filesize($data) > 1048576)) {
			throw new InvalidArgumentException('email_attachment_invalid');
		}
	}
	$message = ['to' => $settings['to'], 'from' => $settings['from'], 'subject' => $payload->subject, 'body' => $payload->body, 'attachments' => $settings['attachments'], 'html' => $settings['html'], 'idempotency' => $idempotency];
	$command = [PHP_BINARY];

	if (php_ini_loaded_file() !== false) {
		$command = [...$command, '-c', php_ini_loaded_file()];
	}
	$command[] = $config['base_path'] . '/plugins/alarmmanager/includes/mail_child.php';
	$result    = alarmmanager_transport_process($command, json_encode($message, JSON_THROW_ON_ERROR), (int) $endpoint['timeout_seconds']);

	if ($result['code'] !== 'exited') {
		return ['outcome' => $result['code'] === 'timeout' ? 'retry' : 'failure', 'code' => 'email_' . $result['code']];
	}

	try {
		$response = json_decode($result['stdout'], true, 4, JSON_THROW_ON_ERROR);
	} catch (Throwable $error) {
		return ['outcome' => 'retry', 'code' => 'email_child_failed'];
	}

	if (!is_array($response) || !in_array($response['code'] ?? '', ['email_sent', 'email_failed', 'cacti_mailer_unavailable'], true) || !in_array($response['outcome'] ?? '', ['success', 'retry', 'failure'], true)) {
		return ['outcome' => 'retry', 'code' => 'email_child_failed'];
	}

	return $response;
}
function alarmmanager_script_transport(array $event, array $endpoint, string $idempotency): array {
	$settings = alarmmanager_endpoint_transport($endpoint);
	$payload  = json_encode(alarmmanager_map_payload($settings['mapping'], $event), JSON_THROW_ON_ERROR);

	if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid') || !is_file($settings['executable']) || !is_executable($settings['executable'])) {
		return ['outcome' => 'failure', 'code' => 'script_unavailable'];
	}
	$argv = [$settings['executable'], ...$settings['arguments']];

	if ($settings['compatibility'] === 'syslog_ticket') {
		$argv[] = '--alert-name=' . ($event['context']['rule_name'] ?? $event['alarm_key']);
		$argv[] = '--severity=' . $event['severity'];
		$argv[] = '--hostlist=' . ($event['device']['hostname'] ?? '');
		$argv[] = '--message=' . $event['summary'];
	}
	$environment = ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'ALARM_IDEMPOTENCY_KEY' => $idempotency, 'ALARM_TRANSITION' => $event['transition']];
	$result      = alarmmanager_transport_process($argv, $payload, (int) $endpoint['timeout_seconds'], $environment);

	if ($result['code'] !== 'exited') {
		return ['outcome' => $result['code'] === 'timeout' ? 'retry' : 'failure', 'code' => 'script_' . $result['code']];
	}
	$exit = $result['exit_code'];

	return ['outcome' => $exit === 0 ? 'success' : (in_array($exit, $settings['retry_exit_codes'], true) ? 'retry' : 'failure'), 'code' => $exit === 0 ? 'script_completed' : 'script_exit_failed'];
}
