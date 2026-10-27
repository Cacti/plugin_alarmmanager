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
require_once(__DIR__ . '/providers.php');

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
/** Shared strict URL predicate: https unless explicit allow_http, no query/fragment/user info or control characters. */
function alarmmanager_transport_url_validate(string $url, bool $allow_http): string {
	$parts = parse_url($url);

	if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query']) || !in_array($parts['scheme'] ?? '', $allow_http ? ['https', 'http'] : ['https'], true) || preg_match('/[\x00-\x20\x7f]/', $url)) {
		throw new InvalidArgumentException('invalid_transport_url');
	}

	return $url;
}
/**
 * CACTI-ALARMMANAGER-MIB notification objects: name => [column under amObjects, snmptrap value type].
 * @return array<string, array{0: int, 1: string}>
 */
function alarmmanager_snmp_objects(): array {
	return ['amAlarmKey' => [1, 's'], 'amEpisodeId' => [2, 's'], 'amEventId' => [3, 's'], 'amTransition' => [4, 'i'], 'amSeverity' => [5, 'i'], 'amSource' => [6, 's'], 'amDeviceHostname' => [7, 's'], 'amDeviceDescription' => [8, 's'], 'amDeviceId' => [9, 'u'], 'amSummary' => [10, 's'], 'amDetails' => [11, 's'], 'amOccurredAt' => [12, 's'], 'amRuleName' => [13, 's'], 'amMetric' => [14, 's'], 'amMetricValue' => [15, 's'], 'amThreshold' => [16, 's']];
}
/** @return array<string, int> RFC 5424 facility codes offered for syslog destinations. */
function alarmmanager_syslog_facilities(): array {
	return ['user' => 1, 'daemon' => 3, 'local0' => 16, 'local1' => 17, 'local2' => 18, 'local3' => 19, 'local4' => 20, 'local5' => 21, 'local6' => 22, 'local7' => 23];
}
/** Hostname or IP literal for an SNMP or syslog receiver; never a URL or port. */
function alarmmanager_transport_host(mixed $value): string {
	$host = alarmmanager_transport_text($value ?? null, 253);

	if (filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\z/D', $host)) {
		throw new InvalidArgumentException('invalid_transport_host');
	}

	return $host;
}
/** @param list<string> $choices */
function alarmmanager_transport_choice(mixed $value, array $choices, string $code): string {
	if (!is_string($value) || !in_array($value, $choices, true)) {
		throw new InvalidArgumentException($code);
	}

	return $value;
}
function alarmmanager_transport_configuration(string $type, $settings): array {
	if (!is_array($settings)) {
		throw new InvalidArgumentException('invalid_transport');
	}

	if (!$settings) {
		return [];
	} // Old endpoint rows remain configurable but cannot send.
	$keys = match ($type) {
		'restapi', 'webhook' => ['provider', 'mapping', 'twilio_whatsapp', 'body_encoding', 'teams_workflow', 'url', 'method', 'credential', 'success_codes', 'response_match', 'connect_timeout_seconds', 'allow_http', 'ca_bundle', 'response_reference', 'transition_overrides'],
		'email'              => ['mapping', 'to', 'from', 'html', 'attachments'],
		'script'             => ['mapping', 'executable', 'arguments', 'retry_exit_codes', 'compatibility', 'response_reference'],
		'snmptrap'           => ['mapping', 'credential', 'version', 'host', 'port', 'transport', 'notification', 'security_name', 'security_level', 'auth_protocol', 'priv_protocol', 'engine_id', 'base_oid'],
		'syslog'             => ['mapping', 'host', 'port', 'protocol', 'format', 'facility', 'app_name', 'ca_bundle'],
		default              => throw new InvalidArgumentException('unsupported_transport')
	};
	alarmmanager_event_keys($settings, $keys);
	$result = ['mapping' => alarmmanager_mapping_validate($settings['mapping'] ?? [])];

	if (array_key_exists('provider', $settings)) {
		if (!is_string($settings['provider']) || !isset(alarmmanager_provider_definitions()[$settings['provider']])) {
			throw new InvalidArgumentException('invalid_provider');
		}
		$result['provider'] = $settings['provider'];
	}

	if (in_array($type, ['restapi', 'webhook'], true)) {
		$allow_http = alarmmanager_config_bool($settings['allow_http'] ?? false);
		$url        = alarmmanager_transport_url_validate(alarmmanager_transport_text($settings['url'] ?? null, 2048), $allow_http);

		if (str_contains($url, '{{')) {
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

		if (isset($settings['body_encoding'])) {
			if (!in_array($settings['body_encoding'], ['json', 'form'], true) || (!empty($settings['teams_workflow']) && $settings['body_encoding'] !== 'json')) {
				throw new InvalidArgumentException('invalid_body_encoding');
			}
			$result['body_encoding'] = $settings['body_encoding'];
		}

		if (alarmmanager_config_bool($settings['teams_workflow'] ?? false)) {
			if ($type !== 'webhook' || $url !== 'https://teams.workflow.invalid' || $method !== 'POST' || $allow_http || !$result['credential'] || !empty($settings['transition_overrides']) || $match !== null || !empty($settings['response_reference'])) {
				throw new InvalidArgumentException('invalid_teams_workflow');
			}
			$result['teams_workflow'] = true;
		}

		if (alarmmanager_config_bool($settings['twilio_whatsapp'] ?? false)) {
			if (($result['body_encoding'] ?? 'json') !== 'form' || $method !== 'POST' || $allow_http || !$result['credential'] || !empty($settings['teams_workflow'])) {
				throw new InvalidArgumentException('invalid_twilio_transport');
			}
			$result['twilio_whatsapp'] = true;
		}
		$ca = $settings['ca_bundle'] ?? null;

		if ($ca !== null && (!is_string($ca) || !str_starts_with($ca, '/') || strlen($ca) > 1024 || preg_match('/[\x00-\x1f]/', $ca))) {
			throw new InvalidArgumentException('invalid_ca_bundle');
		}
		$result['ca_bundle'] = $ca;

		if ($allow_http && $result['credential']) {
			throw new InvalidArgumentException('credentials_require_tls');
		}
		$overrides = $settings['transition_overrides'] ?? null;

		if ($overrides !== null) {
			if (!is_array($overrides)) {
				throw new InvalidArgumentException('invalid_transport');
			}
			alarmmanager_event_keys($overrides, ['raised', 'updated', 'reminder', 'recovered', 'occurrence']);
			$result['transition_overrides'] = [];

			foreach ($overrides as $transition => $item) {
				if (!is_array($item)) {
					throw new InvalidArgumentException('invalid_transport');
				}
				alarmmanager_event_keys($item, ['url', 'method']);

				if (!isset($item['url']) && !isset($item['method'])) {
					throw new InvalidArgumentException('invalid_transport');
				}
				$entry = [];

				if (isset($item['url'])) {
					$target = alarmmanager_transport_text($item['url'], 2048);

					if (str_contains(str_replace('{{integration.id}}', '', $target), '{{')) {
						throw new InvalidArgumentException('invalid_transport_url');
					}
					$entry['url'] = alarmmanager_transport_url_validate($target, $allow_http);
				}

				if (isset($item['method'])) {
					if (!in_array($item['method'], ['POST', 'PUT', 'PATCH'], true)) {
						throw new InvalidArgumentException('invalid_transport_method');
					}
					$entry['method'] = $item['method'];
				}
				$result['transition_overrides'][$transition] = $entry;
			}
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
	} elseif ($type === 'snmptrap') {
		// Community and passphrases live only in the encrypted credential record.
		$result += ['credential' => true, 'version' => alarmmanager_transport_choice($settings['version'] ?? '2c', ['2c', '3'], 'invalid_snmp_settings'),
			'host'                  => alarmmanager_transport_host($settings['host'] ?? null),
			'port'                  => alarmmanager_config_int($settings['port'] ?? 162, 1, 65535),
			'transport'             => alarmmanager_transport_choice($settings['transport'] ?? 'udp', ['udp', 'tcp', 'udp6', 'tcp6'], 'invalid_snmp_settings'),
			'notification'          => alarmmanager_transport_choice($settings['notification'] ?? 'trap', ['trap', 'inform'], 'invalid_snmp_settings'),
			'base_oid'              => $settings['base_oid'] ?? '1.3.6.1.4.1.23925.6'];

		if (!is_string($result['base_oid']) || !preg_match('/\A[0-2](?:\.[0-9]{1,10}){1,100}\z/D', $result['base_oid'])) {
			throw new InvalidArgumentException('invalid_snmp_settings');
		}

		if ($result['version'] === '3') {
			$result += ['security_name' => $settings['security_name'] ?? null,
				'security_level'           => alarmmanager_transport_choice($settings['security_level'] ?? 'authPriv', ['noAuthNoPriv', 'authNoPriv', 'authPriv'], 'invalid_snmp_settings'),
				'auth_protocol'            => alarmmanager_transport_choice($settings['auth_protocol'] ?? 'SHA', ['SHA', 'SHA-224', 'SHA-256', 'SHA-384', 'SHA-512'], 'invalid_snmp_settings'),
				'priv_protocol'            => alarmmanager_transport_choice($settings['priv_protocol'] ?? 'AES', ['AES', 'AES-192', 'AES-256'], 'invalid_snmp_settings'),
				'engine_id'                => $settings['engine_id'] ?? null];

			if (!is_string($result['security_name']) || !preg_match('/\A[\x21-\x7e]{1,32}\z/D', $result['security_name']) || ($result['engine_id'] !== null && (!is_string($result['engine_id']) || !preg_match('/\A0x[0-9A-Fa-f]{10,64}\z/D', $result['engine_id'])))) {
				throw new InvalidArgumentException('invalid_snmp_settings');
			}
		}
		$objects = alarmmanager_snmp_objects();

		foreach ($result['mapping'] as $items) {
			foreach ($items as $item) {
				if (!isset($objects[$item['to']])) {
					throw new InvalidArgumentException('invalid_snmp_mapping');
				}
			}
		}
	} elseif ($type === 'syslog') {
		$result += ['host' => alarmmanager_transport_host($settings['host'] ?? null),
			'protocol'        => alarmmanager_transport_choice($settings['protocol'] ?? 'udp', ['udp', 'tcp', 'tls'], 'invalid_syslog_settings'),
			'format'          => alarmmanager_transport_choice($settings['format'] ?? 'rfc5424', ['rfc5424', 'rfc3164'], 'invalid_syslog_settings'),
			'facility'        => alarmmanager_transport_choice($settings['facility'] ?? 'local0', array_keys(alarmmanager_syslog_facilities()), 'invalid_syslog_settings'),
			'app_name'        => $settings['app_name'] ?? 'cacti-alarmmanager',
			'ca_bundle'       => $settings['ca_bundle'] ?? null];
		$result['port'] = alarmmanager_config_int($settings['port'] ?? ($result['protocol'] === 'tls' ? 6514 : 514), 1, 65535);

		if (!is_string($result['app_name']) || !preg_match('/\A[\x21-\x7e]{1,48}\z/D', $result['app_name'])) {
			throw new InvalidArgumentException('invalid_syslog_settings');
		}
		$ca = $result['ca_bundle'];

		if ($ca !== null && (!is_string($ca) || !str_starts_with($ca, '/') || strlen($ca) > 1024 || preg_match('/[\x00-\x1f]/', $ca) || $result['protocol'] !== 'tls')) {
			throw new InvalidArgumentException('invalid_ca_bundle');
		}

		foreach ($result['mapping'] as $items) {
			if (array_diff(array_column($items, 'to'), ['message'])) {
				throw new InvalidArgumentException('invalid_syslog_mapping');
			}
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
