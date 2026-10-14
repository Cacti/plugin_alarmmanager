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
require_once(__DIR__ . '/mapping.php');

function alarmmanager_transport_text($value, int $limit): string {
	$value = alarmmanager_event_text($value, $limit);

	if (str_contains($value, "\0")) {
		throw new InvalidArgumentException('invalid_transport');
	}

	return $value;
}
function alarmmanager_recipient_validate($value): void {
	if (is_array($value)) {
		foreach ($value as $key => $item) {
			if (is_string($key) && preg_match('/[\r\n\x00]/', $key)) {
				throw new InvalidArgumentException('invalid_recipients');
			} alarmmanager_recipient_validate($item);
		}
	} elseif (!is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
		throw new InvalidArgumentException('invalid_recipients');
	}
}
function alarmmanager_transport_configuration(string $type, $settings): array {
	if (!is_array($settings)) {
		throw new InvalidArgumentException('invalid_transport');
	}

	if (!$settings) {
		return [];
	} // Old endpoint rows remain configurable but cannot send.
	$keys = match ($type) {
		'restapi', 'webhook' => ['mapping', 'url', 'method', 'credential', 'success_codes', 'response_match', 'connect_timeout_seconds', 'allow_http', 'ca_bundle', 'response_reference'],
		'email'              => ['mapping', 'to', 'from', 'html', 'attachments'],
		'script'             => ['mapping', 'executable', 'arguments', 'retry_exit_codes', 'compatibility', 'response_reference'],
		default              => throw new InvalidArgumentException('unsupported_transport')
	};
	alarmmanager_event_keys($settings, $keys);
	$result = ['mapping' => alarmmanager_mapping_validate($settings['mapping'] ?? [])];

	if (in_array($type, ['restapi', 'webhook'], true)) {
		$url        = alarmmanager_transport_text($settings['url'] ?? null, 2048);
		$parts      = parse_url($url);
		$allow_http = alarmmanager_config_bool($settings['allow_http'] ?? false);

		if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query']) || !in_array($parts['scheme'] ?? '', $allow_http ? ['https', 'http'] : ['https'], true) || preg_match('/[\x00-\x20\x7f]/', $url)) {
			throw new InvalidArgumentException('invalid_transport_url');
		}
		$method = $settings['method'] ?? 'POST';

		if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
			throw new InvalidArgumentException('invalid_transport_method');
		}
		$codes = alarmmanager_config_list($settings['success_codes'] ?? range(200, 299), 100);

		if (!$codes) {
			throw new InvalidArgumentException('invalid_success_codes');
		}

		foreach ($codes as $code) {
			alarmmanager_config_int($code, 200, 299);
		}
		$match = $settings['response_match'] ?? null;

		if ($match !== null) {
			if (!is_array($match)) {
				throw new InvalidArgumentException('invalid_response_match');
			}
			alarmmanager_event_keys($match, ['path', 'equals']);

			if (!is_string($match['path'] ?? null) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,7}\z/D', $match['path']) || !array_key_exists('equals', $match) || (!is_scalar($match['equals']) && $match['equals'] !== null)) {
				throw new InvalidArgumentException('invalid_response_match');
			}
		}
		$result += ['url' => $url, 'method' => $method, 'allow_http' => $allow_http, 'credential' => alarmmanager_config_bool($settings['credential'] ?? false), 'success_codes' => array_values(array_unique($codes)), 'response_match' => $match, 'connect_timeout_seconds' => alarmmanager_config_int($settings['connect_timeout_seconds'] ?? 5, 1, 30)];
		$ca = $settings['ca_bundle'] ?? null;

		if ($ca !== null && (!is_string($ca) || !str_starts_with($ca, '/') || strlen($ca) > 1024 || preg_match('/[\x00-\x1f]/', $ca))) {
			throw new InvalidArgumentException('invalid_ca_bundle');
		}
		$result['ca_bundle'] = $ca;

		if ($allow_http && $result['credential']) {
			throw new InvalidArgumentException('credentials_require_tls');
		}
	} elseif ($type === 'email') {
		foreach (['to', 'from'] as $key) {
			$value = $settings[$key] ?? ($key === 'from' ? '' : null);

			if (!is_string($value) && !is_array($value)) {
				throw new InvalidArgumentException('invalid_recipients');
			}

			if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 8192 || preg_match('/[\r\n\x00]/', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
				throw new InvalidArgumentException('invalid_recipients');
			}
			alarmmanager_recipient_validate($value);
			$result[$key] = $value;
		}

		if (empty($result['to'])) {
			throw new InvalidArgumentException('invalid_recipients');
		}
		$result['html']        = alarmmanager_config_bool($settings['html'] ?? false);
		$result['attachments'] = alarmmanager_config_list($settings['attachments'] ?? [], 8);

		foreach ($result['attachments'] as &$attachment) {
			if (is_string($attachment)) {
				$attachment = ['attachment' => $attachment];
			}

			if (!is_array($attachment)) {
				throw new InvalidArgumentException('invalid_attachment');
			}
			alarmmanager_event_keys($attachment, ['attachment', 'filename', 'mime_type', 'inline', 'encoding', 'local_graph_id', 'timespan']);

			foreach (['attachment', 'filename', 'mime_type', 'inline', 'encoding'] as $key) {
				if (isset($attachment[$key])) {
					alarmmanager_transport_text($attachment[$key], $key === 'attachment' ? 65536 : 255);
				}
			}

			foreach (['local_graph_id', 'timespan'] as $key) {
				if (isset($attachment[$key])) {
					alarmmanager_config_int($attachment[$key], 1, 2147483647);
				}
			}

			if (!is_string($attachment['attachment'] ?? null) || !in_array($attachment['inline'] ?? 'attachment', ['inline', 'attachment'], true) || !in_array($attachment['encoding'] ?? 'base64', ['base64', 'quoted-printable', '8bit', '7bit', 'binary'], true)) {
				throw new InvalidArgumentException('invalid_attachment');
			}
		} unset($attachment);
	} elseif ($type === 'script') {
		$executable = alarmmanager_transport_text($settings['executable'] ?? null, 1024);

		if (!str_starts_with($executable, '/') || preg_match('/[\r\n]/', $executable)) {
			throw new InvalidArgumentException('invalid_executable');
		}
		$result['executable'] = $executable;
		$result['arguments']  = alarmmanager_config_list($settings['arguments'] ?? [], 32);

		foreach ($result['arguments'] as $arg) {
			alarmmanager_transport_text($arg, 1024);
		}
		$result['retry_exit_codes'] = alarmmanager_config_list($settings['retry_exit_codes'] ?? [75], 32);

		foreach ($result['retry_exit_codes'] as $code) {
			alarmmanager_config_int($code, 1, 255);
		}
		$result['compatibility'] = $settings['compatibility'] ?? 'json';

		if (!in_array($result['compatibility'], ['json', 'syslog_ticket'], true)) {
			throw new InvalidArgumentException('invalid_script_compatibility');
		}
	}

	if (in_array($type, ['restapi', 'webhook', 'script'], true)) {
		$paths = $settings['response_reference'] ?? null;

		if ($paths !== null) {
			if (!is_array($paths) || !isset($paths['id'])) {
				throw new InvalidArgumentException('invalid_reference_paths');
			}
			alarmmanager_event_keys($paths, ['id', 'url']);

			foreach ($paths as $path) {
				if (!is_string($path) || strlen($path) > 191 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,7}\z/D', $path)) {
					throw new InvalidArgumentException('invalid_reference_paths');
				}
			}
		}
		$result['response_reference'] = $paths;
	}

	if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 131072) {
		throw new InvalidArgumentException('transport_limit');
	}

	return $result;
}
