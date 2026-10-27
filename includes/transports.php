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
require_once(__DIR__ . '/routing.php');
require_once(__DIR__ . '/credentials.php');

require_once(__DIR__ . '/integrations.php');

function alarmmanager_endpoint_transport(array $endpoint): array {
	$row      = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_transports WHERE endpoint_id = ?', [(int) $endpoint['id']]);
	$settings = $row ? json_decode($row['definition'], true, 24, JSON_THROW_ON_ERROR) : [];
	$settings = alarmmanager_transport_configuration($endpoint['type'], $settings);

	if (!$settings) {
		throw new InvalidArgumentException('transport_not_configured');
	}

	if (array_key_exists('mapping_override', $endpoint)) {
		$settings['mapping'] = alarmmanager_mapping_validate($endpoint['mapping_override']);
	}

	return $settings;
}
/** Preview deliberately does not load/decrypt secrets or invoke a transport. */
function alarmmanager_delivery_preview(array $event, array $endpoint): array {
	$event    = alarmmanager_validate_event($event);
	$settings = alarmmanager_endpoint_transport($endpoint);

	return ['endpoint_id' => (int) $endpoint['id'], 'type' => $endpoint['type'], 'payload' => alarmmanager_notification_payload($settings, $event, in_array($endpoint['type'], ['restapi', 'webhook', 'script'], true) ? alarmmanager_delivery_reference($event, (int) $endpoint['id'], $settings['mapping'], $settings['transition_overrides'] ?? null) : []), 'credentials' => !empty($settings['credential']) ? '[REDACTED]' : 'none'];
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

		return alarmmanager_transport_reference_result(['outcome' => 'success', 'code' => !empty($settings['teams_workflow']) ? 'teams_workflow_accepted' : 'http_' . $status], $body, $settings);
	}
	$retry = in_array($status, [408, 425, 429], true) || $status >= 500;

	return ['outcome' => $retry ? 'retry' : 'failure', 'code' => 'http_' . $status, 'retry_after' => $retry ? alarmmanager_retry_after($retry_after) : 0];
}
/**
 * @param  array<string, mixed> $result
 * @param  array<string, mixed> $settings
 * @return array<string, mixed>
 */
function alarmmanager_transport_reference_result(array $result, string $body, array $settings): array {
	if ($result['outcome'] === 'success' && ($settings['response_reference'] ?? null) !== null) {
		try {
			$result['external_reference'] = alarmmanager_response_reference($body, $settings['response_reference']);
		} catch (Throwable $error) {
			return ['outcome' => 'failure', 'code' => 'external_reference_invalid'];
		}
	}

	return $result;
}

function alarmmanager_http_transport(array $event, array $endpoint, string $idempotency): array {
	if (!function_exists('curl_init')) {
		return ['outcome' => 'failure', 'code' => 'curl_unavailable'];
	}
	$settings  = alarmmanager_endpoint_transport($endpoint);
	$reference = alarmmanager_delivery_reference($event, (int) $endpoint['id'], $settings['mapping'], $settings['transition_overrides'] ?? null);
	$target    = alarmmanager_transition_target($settings, $event, $reference);

	if ($target === null) {
		return ['outcome' => 'retry', 'code' => 'integration_reference_missing'];
	}
	$form    = ($settings['body_encoding'] ?? 'json') === 'form';
	$payload = alarmmanager_notification_payload($settings, $event, $reference);

	if (isset($endpoint['recipient'])) {
		if (!in_array($settings['provider'] ?? '', ['twilio', 'twilio_whatsapp'], true)) {
			throw new InvalidArgumentException('recipient_destination_invalid');
		}
		$numbers     = alarmmanager_twilio_recipients([$endpoint['recipient']]);
		$payload->To = (($settings['provider'] === 'twilio_whatsapp') ? 'whatsapp:' : '') . $numbers[0];
	}

	$headers = ['Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json'), 'Idempotency-Key: ' . $idempotency, 'Expect:'];

	if ($settings['credential']) {
		$credential = alarmmanager_load_credential((int) $endpoint['id']);

		if (!empty($settings['teams_workflow'])) {
			$target['url'] = alarmmanager_teams_webhook_url($credential);
		}

		[$target, $payload] = alarmmanager_provider_request($settings, $target, $payload, $credential);

		foreach ($credential['headers'] as $name => $value) {
			$headers[] = $name . ': ' . $value;
		}

		if (isset($credential['basic'])) {
			$headers[] = 'Authorization: Basic ' . base64_encode($credential['basic']['username'] . ':' . $credential['basic']['password']);
		}
	}
	$payload      = $form ? http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : json_encode($payload, JSON_THROW_ON_ERROR);
	$curl         = curl_init();
	$body         = '';
	$header_bytes = 0;
	$too_large    = false;
	$retry_after  = null;

	try {
		curl_setopt_array($curl, [CURLOPT_URL => $target['url'], CURLOPT_CUSTOMREQUEST => $target['method'], CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER                   => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER               => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => min((int) $endpoint['timeout_seconds'], $settings['connect_timeout_seconds']),
			CURLOPT_TIMEOUT                      => (int) $endpoint['timeout_seconds'], CURLOPT_NOSIGNAL => true, CURLOPT_PROXY => '', CURLOPT_NETRC => false,
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
	$payload  = json_encode(alarmmanager_map_payload($settings['mapping'], $event, alarmmanager_delivery_reference($event, (int) $endpoint['id'], $settings['mapping'], $settings['transition_overrides'] ?? null)), JSON_THROW_ON_ERROR);

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

	return alarmmanager_transport_reference_result(['outcome' => $exit === 0 ? 'success' : (in_array($exit, $settings['retry_exit_codes'], true) ? 'retry' : 'failure'), 'code' => $exit === 0 ? 'script_completed' : 'script_exit_failed'], $result['stdout'], $settings);
}

/** Collapse a multi-line message to one line without control characters, for syslog and SNMP receivers. */
function alarmmanager_one_line(string $text): string {
	return trim(preg_replace('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', ' ', preg_replace('/\s*[\r\n]+\s*/', ' | ', trim($text))));
}
/**
 * CACTI-MIB cactiEventDescription text: severity, state and message, then the identifiers a
 * receiver needs to pair a raise with its recovery. The message is shortened, never the identifiers.
 * @param array<string, mixed> $event
 */
function alarmmanager_snmptrap_description(array $event, string $message): string {
	$host   = $event['device']['hostname'] ?? null;
	$prefix = strtoupper($event['severity']) . ' ' . $event['transition'] . ' ' . ($host === null || $host === '' ? '' : $host . ': ');
	$suffix = ' | key=' . $event['alarm_key'] . ' episode=' . $event['episode_id'] . ' source=' . $event['source'] . ' event=' . $event['event_id'];

	// cactiEventDescription is a DisplayString of at most 1000 octets.
	return $prefix . mb_strcut(alarmmanager_one_line($message), 0, max(0, 1000 - strlen($prefix) - strlen($suffix)), 'UTF-8') . $suffix;
}
/**
 * Build snmptrap argv for CACTI-MIB cactiNotify plus the private snmp.conf body. Community and
 * passphrases are only ever written to that file (SNMPCONFPATH), never to argv where other local
 * users could read them.
 * @param  array<string, mixed>              $settings
 * @param  array<string, mixed>              $credential
 * @return array{0: list<string>, 1: string}
 */
function alarmmanager_snmptrap_command(string $binary, array $settings, #[\SensitiveParameter] array $credential, string $description, int $timeout): array {
	$secrets = $credential['snmp'] ?? [];
	$argv    = [$binary, '-v', $settings['version'], '-m', '', '-M', '/nonexistent'];
	$conf    = [];

	if ($settings['version'] === '2c') {
		$conf[] = 'defCommunity ' . ($secrets['community'] ?? throw new InvalidArgumentException('snmp_credentials_missing'));
	} else {
		$argv = [...$argv, '-u', $settings['security_name'], '-l', $settings['security_level']];

		if ($settings['security_level'] !== 'noAuthNoPriv') {
			$argv   = [...$argv, '-a', $settings['auth_protocol']];
			$conf[] = 'defAuthPassphrase ' . ($secrets['auth_passphrase'] ?? throw new InvalidArgumentException('snmp_credentials_missing'));
		}

		if ($settings['security_level'] === 'authPriv') {
			$argv   = [...$argv, '-x', $settings['priv_protocol']];
			$conf[] = 'defPrivPassphrase ' . ($secrets['priv_passphrase'] ?? throw new InvalidArgumentException('snmp_credentials_missing'));
		}

		if ($settings['engine_id'] !== null && $settings['notification'] === 'trap') {
			$argv = [...$argv, '-e', $settings['engine_id']];
		}
	}

	// The delivery queue owns retries, so an INFORM gets one bounded attempt.
	if ($settings['notification'] === 'inform') {
		$argv = [...$argv, '-Ci', '-r', '0', '-t', (string) max(1, $timeout)];
	}
	$host      = $settings['host'];
	$transport = $settings['transport'];

	if (str_contains($host, ':')) {
		$host      = '[' . $host . ']';
		$transport = str_ends_with($transport, '6') ? $transport : $transport . '6';
	}
	// Same OIDs as Cacti's own SNMP agent: cactiNotify carrying cactiEventDescription.0. An empty
	// uptime lets snmptrap supply sysUpTime; "--" keeps the description from parsing as an option.
	$argv = [...$argv, '--', $transport . ':' . $host . ':' . $settings['port'], '', '.1.3.6.1.4.1.23925.3.2.1', '.1.3.6.1.4.1.23925.3.1.1.0', 's', $description];

	return [$argv, $conf ? implode("\n", $conf) . "\n" : ''];
}
/**
 * @param  array<string, mixed> $event
 * @param  array<string, mixed> $endpoint
 * @return array<string, mixed>
 */
function alarmmanager_snmptrap_transport(array $event, array $endpoint, string $idempotency): array {
	$settings = alarmmanager_endpoint_transport($endpoint);
	$binary   = (string) read_config_option('path_snmptrap');

	if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary) || !function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
		return ['outcome' => 'failure', 'code' => 'snmptrap_unavailable'];
	}

	try {
		$credential = alarmmanager_load_credential((int) $endpoint['id']);
	} catch (RuntimeException $error) {
		// SNMPv3 noAuthNoPriv needs no secrets; missing ones are reported by the command builder.
		if ($error->getMessage() !== 'credential_missing') {
			throw $error;
		}
		$credential = [];
	}
	// Stay inside the worker's SIGALRM deadline so a slow INFORM reports its own result.
	$timeout       = max(1, (int) $endpoint['timeout_seconds'] - 1);
	$payload       = alarmmanager_notification_payload($settings, $event);

	if (!is_string($payload->message ?? null) || trim($payload->message) === '') {
		throw new InvalidArgumentException('snmp_message_required');
	}
	[$argv, $conf] = alarmmanager_snmptrap_command($binary, $settings, $credential, alarmmanager_snmptrap_description($event, $payload->message), max(1, $timeout - 1));
	$directory     = sys_get_temp_dir() . '/alarmmanager-snmp-' . bin2hex(random_bytes(12));
	$result        = ['code' => 'config_failed'];

	try {
		// This private directory holds the only on-disk copy of the secrets, for the life of one send.
		if (@mkdir($directory, 0700) && file_put_contents($directory . '/snmp.conf', $conf) === strlen($conf) && chmod($directory . '/snmp.conf', 0600)) {
			$result = alarmmanager_transport_process($argv, '', $timeout, ['SNMPCONFPATH' => $directory, 'SNMP_PERSISTENT_DIR' => $directory, 'MIBS' => '']);
		}
	} finally {
		if (is_dir($directory)) {
			// net-snmp may create cache directories under the persistent directory.
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
				$path->isDir() && !$path->isLink() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
			}
			@rmdir($directory);
		}
	}

	if ($result['code'] !== 'exited') {
		return ['outcome' => in_array($result['code'], ['timeout', 'config_failed'], true) ? 'retry' : 'failure', 'code' => 'snmptrap_' . $result['code']];
	}

	return $result['exit_code'] === 0 ? ['outcome' => 'success', 'code' => $settings['notification'] === 'inform' ? 'snmp_inform_acknowledged' : 'snmp_trap_sent'] : ['outcome' => 'retry', 'code' => 'snmptrap_failed'];
}
/**
 * One RFC 5424 or RFC 3164 line; machine-readable alarm identity travels in structured data.
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $event
 */
function alarmmanager_syslog_message(array $settings, array $event, string $text, string $hostname): string {
	$severity = ['critical' => 2, 'major' => 3, 'minor' => 4, 'warning' => 4, 'info' => 6, 'clear' => 5][$event['severity']];
	$priority = '<' . (alarmmanager_syslog_facilities()[$settings['facility']] * 8 + $severity) . '>';
	$text     = alarmmanager_one_line($text);
	$hostname = substr(preg_replace('/[^\x21-\x7e]/', '', $hostname), 0, 255);
	$hostname = $hostname === '' ? '-' : $hostname;

	if ($settings['format'] === 'rfc3164') {
		$time = new DateTimeImmutable($event['occurred_at']);
		$tag  = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $settings['app_name']), 0, 32);

		return $priority . $time->format('M') . ' ' . str_pad($time->format('j'), 2, ' ', STR_PAD_LEFT) . $time->format(' H:i:s') . ' ' . $hostname . ' ' . ($tag === '' ? 'alarmmanager' : $tag) . ': ' . $text;
	}
	$data = '[alarm@23925';

	foreach (['key' => $event['alarm_key'], 'episode' => $event['episode_id'], 'event' => $event['event_id'], 'severity' => $event['severity'], 'transition' => $event['transition'], 'source' => $event['source'], 'device' => $event['device']['hostname'] ?? null] as $name => $value) {
		if ($value !== null) {
			$data .= ' ' . $name . '="' . addcslashes((string) $value, '"\]') . '"';
		}
	}

	return $priority . '1 ' . $event['occurred_at'] . ' ' . $hostname . ' ' . $settings['app_name'] . ' - ' . $event['transition'] . ' ' . $data . '] ' . $text;
}
/**
 * @param  array<string, mixed> $settings
 * @return array<string, mixed>
 */
function alarmmanager_syslog_send(array $settings, string $message, int $timeout): array {
	// UDP receivers must accept 2048 octets; stream transports use RFC 6587 octet counting.
	$message = $settings['protocol'] === 'udp' ? mb_strcut($message, 0, 2048, 'UTF-8') : strlen($message) . ' ' . $message;
	$host    = str_contains($settings['host'], ':') ? '[' . $settings['host'] . ']' : $settings['host'];
	$context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $settings['host'], 'allow_self_signed' => false, 'SNI_enabled' => true,
		'crypto_method'                                          => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT] + ($settings['ca_bundle'] !== null ? ['cafile' => $settings['ca_bundle']] : [])]);
	// A refused or failed connection is an expected retry outcome, not a PHP warning for the Cacti log.
	set_error_handler(static fn (): bool => true);

	try {
		$socket = stream_socket_client($settings['protocol'] . '://' . $host . ':' . $settings['port'], $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
	} finally {
		restore_error_handler();
	}

	if ($socket === false) {
		return ['outcome' => 'retry', 'code' => 'syslog_connect_failed'];
	}

	try {
		stream_set_timeout($socket, $timeout);
		$offset = 0;

		// fwrite() returns false or 0 once the receiver stops accepting data.
		do {
			$written = (int) @fwrite($socket, substr($message, $offset));
			$offset += $written;
		} while ($written > 0 && $offset < strlen($message));
	} finally {
		fclose($socket);
	}

	return $offset === strlen($message) ? ['outcome' => 'success', 'code' => 'syslog_sent'] : ['outcome' => 'retry', 'code' => 'syslog_write_failed'];
}
/**
 * @param  array<string, mixed> $event
 * @param  array<string, mixed> $endpoint
 * @return array<string, mixed>
 */
function alarmmanager_syslog_transport(array $event, array $endpoint, string $idempotency): array {
	$settings = alarmmanager_endpoint_transport($endpoint);
	$payload  = alarmmanager_notification_payload($settings, $event);

	if (!is_string($payload->message ?? null) || trim($payload->message) === '') {
		throw new InvalidArgumentException('syslog_message_required');
	}

	return alarmmanager_syslog_send($settings, alarmmanager_syslog_message($settings, $event, $payload->message, (string) gethostname()), max(1, (int) $endpoint['timeout_seconds'] - 1));
}
/** Fresh child, no shell, clean environment, bounded pipes/runtime and group cleanup. */
function alarmmanager_transport_process(array $arguments, string $input, int $timeout, array $environment = []): array {
	if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
		throw new RuntimeException('process_transport_unavailable');
	}
	$process = proc_open(['/usr/bin/setsid', '--', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $environment + ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8']);

	if (!is_resource($process)) {
		throw new RuntimeException('process_start_failed');
	}
	$status   = proc_get_status($process);
	$pid      = $status['pid'];
	$offset   = 0;
	$bytes    = 0;
	$stdout   = '';
	$deadline = hrtime(true) + $timeout * 1000000000;

	foreach ($pipes as $pipe) {
		stream_set_blocking($pipe, false);
	}

	try {
		while (true) {
			foreach ([1, 2] as $descriptor) {
				$chunk = fread($pipes[$descriptor], 8192);
				$chunk = $chunk === false ? '' : $chunk;
				$bytes += strlen($chunk);

				if ($descriptor === 1) {
					$stdout .= $chunk;
				}
			}

			if ($bytes > 65536) {
				return ['code' => 'output_limit', 'exit_code' => -1, 'stdout' => ''];
			}
			$status = proc_get_status($process);

			if (!$status['running']) {
				// Drain the final buffered output before applying the same output bound.
				foreach ([1, 2] as $descriptor) {
					$chunk = stream_get_contents($pipes[$descriptor], 65537);
					$chunk = $chunk === false ? '' : $chunk;
					$bytes += strlen($chunk);

					if ($descriptor === 1) {
						$stdout .= $chunk;
					}
				}

				return $bytes > 65536 ? ['code' => 'output_limit', 'exit_code' => -1, 'stdout' => ''] : ['code' => 'exited', 'exit_code' => $status['exitcode'], 'stdout' => $stdout];
			}

			if (hrtime(true) >= $deadline) {
				return ['code' => 'timeout', 'exit_code' => -1, 'stdout' => ''];
			}

			if (isset($pipes[0])) {
				if ($offset < strlen($input)) {
					$written = @fwrite($pipes[0], substr($input, $offset, 8192));

					if ($written === false) {
						fclose($pipes[0]);
						unset($pipes[0]);
					} else {
						$offset += $written;
					}
				}

				if (isset($pipes[0]) && $offset >= strlen($input)) {
					fclose($pipes[0]);
					unset($pipes[0]);
				}
			}
			usleep(10000);
		}
	} finally {
		@posix_kill(-$pid, SIGKILL);
		@proc_terminate($process, SIGKILL);

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		proc_close($process);
	}
}
