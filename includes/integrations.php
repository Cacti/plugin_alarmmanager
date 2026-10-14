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

/** Templates create disabled drafts only; no endpoint IDs or credentials are copied. */
/** @return array<string, array<string, mixed>> */
function alarmmanager_integration_templates(): array {
	$mapping = ['default' => [
		['to' => 'event_id', 'source' => 'event.id', 'required' => true],
		['to' => 'episode_id', 'source' => 'alarm.episode_id', 'required' => true],
		['to' => 'hostname', 'source' => 'device.hostname'],
		['to' => 'summary', 'source' => 'alarm.summary'],
		['to' => 'details', 'source' => 'alarm.details'],
		['to' => 'severity', 'source' => 'alarm.severity'],
		['to' => 'state', 'source' => 'alarm.transition'],
		['to' => 'external_id', 'source' => 'integration.id']
	]];
	$http = ['url' => 'https://receiver.example.invalid/alarms', 'method' => 'POST', 'credential' => false, 'mapping' => $mapping];

	return [
		'webhook' => ['name' => 'Generic webhook', 'type' => 'webhook', 'transport' => $http],
		'restapi' => ['name' => 'Generic REST API', 'type' => 'restapi', 'transport' => $http],
		'script'  => ['name' => 'JSON script', 'type' => 'script', 'transport' => ['executable' => '/usr/local/bin/process_alarm.py', 'arguments' => [], 'compatibility' => 'json', 'retry_exit_codes' => [75], 'mapping' => $mapping]]
	];
}

/** Only explicitly selected response fields enter history; raw response bodies are discarded. */
/** @return array{id: string, url: ?string} */
function alarmmanager_external_reference_validate(mixed $reference): array {
	if (!is_array($reference)) {
		throw new InvalidArgumentException('invalid_external_reference');
	}
	alarmmanager_event_keys($reference, ['id', 'url']);
	$id = $reference['id'] ?? null;

	if (is_int($id)) {
		$id = (string) $id;
	}

	if (!is_string($id) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $id)) {
		throw new InvalidArgumentException('invalid_external_reference');
	}
	$url = $reference['url'] ?? null;

	if ($url !== null) {
		$parts = is_string($url) ? parse_url($url) : false;

		if (!is_string($url) || strlen($url) > 2048 || !$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
			throw new InvalidArgumentException('invalid_external_reference');
		}
	}

	return ['id' => $id, 'url' => $url];
}

/**
 * @param  array<string, string>           $paths
 * @return array{id: string, url: ?string}
 */
function alarmmanager_response_reference(string $body, array $paths): array {
	$data      = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
	$reference = [];

	foreach ($paths as $key => $path) {
		$value = $data;

		foreach (explode('.', $path) as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) {
				throw new InvalidArgumentException('invalid_external_reference');
			}
			$value = $value[$part];
		}
		$reference[$key] = $value;
	}

	return alarmmanager_external_reference_validate($reference);
}

/** Endpoint, stream and episode scope prevents sharing references across alarms or destinations. */
/**
 * @param  array<string, mixed>                                 $event
 * @param  array<string, array<int, array<string, mixed>>>|null $mapping
 * @return array<string, mixed>
 */
function alarmmanager_delivery_reference(array $event, int $endpoint_id, ?array $mapping = null): array {
	if ($mapping !== null && !array_intersect(array_column($mapping[$event['transition']] ?? $mapping['default'] ?? [], 'source'), ['integration.id', 'integration.url'])) {
		return [];
	}

	$row = alarmmanager_db_row("SELECT d.external_reference FROM plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id WHERE d.endpoint_id = ? AND d.stream_key = ? AND e.episode_id = ? AND e.sequence < ? AND d.status = 'completed' AND d.external_reference IS NOT NULL ORDER BY e.sequence DESC, d.id DESC LIMIT 1", [$endpoint_id, alarmmanager_stream_key($event, 'centralized'), $event['episode_id'], $event['sequence']]);

	return $row ? alarmmanager_external_reference_validate(json_decode($row['external_reference'], true, 8, JSON_THROW_ON_ERROR)) : [];
}
