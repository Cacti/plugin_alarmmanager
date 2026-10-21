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
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/runtime.php');
require_once(__DIR__ . '/transport_config.php');
require_once(dirname(__DIR__) . '/lib/QueryBuilder.php');

function alarmmanager_config_int($value, int $minimum, int $maximum): int {
	if (!is_int($value) || $value < $minimum || $value > $maximum) {
		throw new InvalidArgumentException('invalid_configuration');
	}

	return $value;
}
function alarmmanager_config_bool($value): bool {
	if (!is_bool($value)) {
		throw new InvalidArgumentException('invalid_configuration');
	}

	return $value;
}
function alarmmanager_config_list($value, int $maximum): array {
	if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
		throw new InvalidArgumentException('invalid_configuration');
	}

	return $value;
}
function alarmmanager_validate_schedule($schedule): ?array {
	if ($schedule === null) {
		return null;
	}

	if (!is_array($schedule)) {
		throw new InvalidArgumentException('invalid_schedule');
	}
	alarmmanager_event_keys($schedule, ['timezone', 'days', 'start', 'end']);
	$timezone = $schedule['timezone'] ?? 'UTC';

	if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
		throw new InvalidArgumentException('invalid_schedule');
	}
	$days = alarmmanager_config_list($schedule['days'] ?? [], 7);

	foreach ($days as $day) {
		alarmmanager_config_int($day, 1, 7);
	}

	foreach (['start', 'end'] as $field) {
		if (!is_string($schedule[$field] ?? null) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $schedule[$field])) {
			throw new InvalidArgumentException('invalid_schedule');
		}
	}

	return ['timezone' => $timezone, 'days' => array_values(array_unique($days)), 'start' => $schedule['start'], 'end' => $schedule['end']];
}
function alarmmanager_schedule_matches(?array $schedule, string $occurred_at): bool {
	if ($schedule === null) {
		return true;
	}
	$local = (new DateTimeImmutable($occurred_at))->setTimezone(new DateTimeZone($schedule['timezone']));
	$day   = (int) $local->format('N');
	$clock = $local->format('H:i');
	$start = $schedule['start'];
	$end   = $schedule['end'];

	if ($start === $end) {
		return in_array($day, $schedule['days'], true);
	}

	if ($start < $end) {
		return in_array($day, $schedule['days'], true) && $clock >= $start && $clock < $end;
	}

	return ($clock >= $start && in_array($day, $schedule['days'], true))
		|| ($clock < $end && in_array($day === 1 ? 7 : $day - 1, $schedule['days'], true));
}
/** Trusted Cacti host fields shared by the rule editor and parameterized matcher.
 * @return array<string, array<string, mixed>>
 */
function alarmmanager_rule_device_fields(): array {
	$text   = ['contains', '=', '!=', 'begins', 'ends'];
	$fields = [];

	foreach (['hostname' => __('Hostname', 'alarmmanager'), 'description' => __('Device description', 'alarmmanager'), 'location' => __('Location', 'alarmmanager'), 'snmp_sysLocation' => __('SNMP system location', 'alarmmanager')] as $column => $label) {
		$fields[$column] = ['label' => $label, 'column' => "COALESCE(h.$column, '')", 'operators' => $text];
	}

	foreach (['poller_id' => __('Collector', 'alarmmanager'), 'site_id' => __('Device Site Association', 'alarmmanager'), 'host_template_id' => __('Device Template', 'alarmmanager')] as $column => $label) {
		$fields[$column] = ['label' => $label, 'column' => 'h.' . $column, 'operators' => ['=', '!='], 'type' => 'integer'];
	}

	return $fields;
}

function alarmmanager_validate_rule_definition(array $rule): array {
	alarmmanager_event_keys($rule, ['match', 'device_query', 'schedule', 'maintenance_schedule', 'suppress_acknowledged', 'cooldown_seconds', 'duplicate_seconds']);
	$match = $rule['match'] ?? [];

	if (!is_array($match)) {
		throw new InvalidArgumentException('invalid_configuration');
	}
	alarmmanager_event_keys($match, ['sources', 'severities', 'transitions', 'device_ids', 'collector_ids', 'tags']);

	foreach ($match as $field => $values) {
		$match[$field] = alarmmanager_config_list($values, 32);

		foreach ($values as $value) {
			if (in_array($field, ['device_ids', 'collector_ids'], true)) {
				alarmmanager_config_int($value, 1, 2147483647);
			} else {
				alarmmanager_event_text($value, 128);
			}
		}

		if ($field === 'severities' && array_diff($values, ['critical', 'major', 'minor', 'warning', 'info', 'clear'])) {
			throw new InvalidArgumentException('invalid_configuration');
		}

		if ($field === 'transitions' && array_diff($values, ['raised', 'updated', 'reminder', 'recovered', 'occurrence'])) {
			throw new InvalidArgumentException('invalid_configuration');
		}
	}

	$device_query = $rule['device_query'] ?? '';

	if (!is_string($device_query)) {
		throw new InvalidArgumentException('invalid_device_filter');
	}

	if ($device_query !== '') {
		AlarmManager\QueryBuilder::compile($device_query, alarmmanager_rule_device_fields());
	}

	return [
		'device_query'          => $device_query,
		'match'                 => $match, 'schedule' => alarmmanager_validate_schedule($rule['schedule'] ?? null),
		'maintenance_schedule'  => alarmmanager_validate_schedule($rule['maintenance_schedule'] ?? null),
		'suppress_acknowledged' => alarmmanager_config_bool($rule['suppress_acknowledged'] ?? true),
		'cooldown_seconds'      => alarmmanager_config_int($rule['cooldown_seconds'] ?? 0, 0, 86400),
		'duplicate_seconds'     => alarmmanager_config_int($rule['duplicate_seconds'] ?? 0, 0, 86400)
	];
}
function alarmmanager_rule_matches(array $rule, array $event): bool {
	$fields = ['sources' => $event['source'], 'severities' => $event['severity'], 'transitions' => $event['transition'], 'device_ids' => $event['device']['id'], 'collector_ids' => $event['collector_id']];

	foreach ($fields as $key => $value) {
		if (isset($rule['match'][$key]) && !in_array($value, $rule['match'][$key], true)) {
			return false;
		}
	}

	if (isset($rule['match']['tags']) && array_diff($rule['match']['tags'], $event['device']['tags'])) {
		return false;
	}

	if (($rule['device_query'] ?? '') !== '') {
		$query = AlarmManager\QueryBuilder::compile($rule['device_query'], alarmmanager_rule_device_fields());

		// The host table is authoritative; absent devices cannot match even a negative filter.
		if (!$event['device']['id'] || !alarmmanager_db_row('SELECT h.id FROM host AS h WHERE h.id = ? AND (' . $query['sql'] . ') LIMIT 1', array_merge([$event['device']['id']], $query['params']))) {
			return false;
		}
	}

	return alarmmanager_schedule_matches($rule['schedule'], $event['occurred_at']);
}
/** @return array{offline: string, recovering: string, online: string} */
function alarmmanager_device_summaries(): array {
	$row           = alarmmanager_db_row('SELECT h.definition FROM plugin_alarmmanager_configuration_history AS h JOIN plugin_alarmmanager_schema AS s ON s.config_revision = h.revision WHERE s.id = 1');
	$configuration = $row ? json_decode($row['definition'], true, 24, JSON_THROW_ON_ERROR) : [];

	return ($configuration['device_summaries'] ?? []) + ['offline' => 'Device Offline', 'recovering' => 'Device Recovering', 'online' => 'Device Online'];
}

function alarmmanager_configuration(array $configuration): array {
	alarmmanager_event_keys($configuration, ['version', 'endpoints', 'rules', 'device_summaries']);

	if (($configuration['version'] ?? null) !== 1) {
		throw new InvalidArgumentException('invalid_configuration');
	}
	$result    = ['version' => 1, 'endpoints' => [], 'rules' => []];
	$summaries = $configuration['device_summaries'] ?? ['offline' => 'Device Offline', 'recovering' => 'Device Recovering', 'online' => 'Device Online'];

	if (!is_array($summaries)) {
		throw new InvalidArgumentException('invalid_device_summaries');
	}
	alarmmanager_event_keys($summaries, ['offline', 'recovering', 'online']);

	foreach (['offline', 'recovering', 'online'] as $state) {
		$text = $summaries[$state] ?? ($state === 'recovering' ? 'Device Recovering' : null);

		if (!is_string($text) || trim($text) === '' || strlen($text) > 512 || preg_match('/[\x00-\x1f\x7f]/', $text)) {
			throw new InvalidArgumentException('invalid_device_summaries');
		}
		$result['device_summaries'][$state] = trim($text);
	}

	foreach (['endpoints', 'rules'] as $section) {
		$items = alarmmanager_config_list($configuration[$section] ?? [], 200);

		foreach ($items as $item) {
			if (!is_array($item)) {
				throw new InvalidArgumentException('invalid_configuration');
			}
			$id = alarmmanager_config_int($item['id'] ?? null, 1, 2147483647);

			if (isset($result[$section][$id])) {
				throw new InvalidArgumentException('duplicate_config_id');
			}
			$name = alarmmanager_event_text($item['name'] ?? null, 128);

			if (trim($name) === '') {
				throw new InvalidArgumentException('invalid_configuration');
			}
			$enabled = alarmmanager_config_bool($item['enabled'] ?? true);

			if ($section === 'endpoints') {
				alarmmanager_event_keys($item, ['id', 'name', 'type', 'enabled', 'max_concurrency', 'min_interval_ms', 'timeout_seconds', 'max_attempts', 'transport']);

				if (!in_array($item['type'] ?? null, ['restapi', 'webhook', 'email', 'script', 'websocket'], true)) {
					throw new InvalidArgumentException('invalid_destination');
				}
				$result[$section][$id] = ['id' => $id, 'name' => $name, 'enabled' => $enabled, 'type' => $item['type'],
					'max_concurrency'             => alarmmanager_config_int($item['max_concurrency'] ?? 1, 1, 64),
					'min_interval_ms'             => alarmmanager_config_int($item['min_interval_ms'] ?? 0, 0, 3600000),
					'timeout_seconds'             => alarmmanager_config_int($item['timeout_seconds'] ?? 10, 1, 120),
					'max_attempts'                => alarmmanager_config_int($item['max_attempts'] ?? 5, 1, 20),
					'transport'                   => alarmmanager_transport_configuration($item['type'], $item['transport'] ?? [])];
			} else {
				alarmmanager_event_keys($item, ['id', 'name', 'enabled', 'priority', 'endpoints', 'stop', 'policy']);
				$policy = $item['policy'] ?? [];

				if (!is_array($policy)) {
					throw new InvalidArgumentException('invalid_configuration');
				}
				$result[$section][$id] = ['id' => $id, 'name' => $name, 'enabled' => $enabled,
					'priority'                    => alarmmanager_config_int($item['priority'] ?? 100, 0, 10000),
					'endpoints'                   => array_values(array_unique(alarmmanager_config_list($item['endpoints'] ?? [], 32))),
					'stop'                        => alarmmanager_config_bool($item['stop'] ?? false), 'policy' => alarmmanager_validate_rule_definition($policy)];
			}
		}
	}

	foreach ($result['rules'] as $rule) {
		if (!$rule['endpoints']) {
			throw new InvalidArgumentException('empty_rule_destinations');
		}

		foreach ($rule['endpoints'] as $id) {
			alarmmanager_config_int($id, 1, 2147483647);

			if (!isset($result['endpoints'][$id])) {
				throw new InvalidArgumentException('missing_endpoint');
			}
		}
	}

	if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 1048576) {
		throw new InvalidArgumentException('configuration_limit');
	}

	return $result;
}
function alarmmanager_apply_configuration(array $configuration, ?int $expected_revision = null): void {
	$settings = alarmmanager_configuration($configuration);
	alarmmanager_transaction(function () use ($settings, $expected_revision): void {
		alarmmanager_write_configuration($settings, $expected_revision);
	});
	unset($GLOBALS['alarmmanager_route_cache']);
}
function alarmmanager_routing_snapshot(): array {
	$revision = db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false);

	if ($revision === false) {
		throw new RuntimeException('configuration_unavailable');
	}
	$cache = $GLOBALS['alarmmanager_route_cache'] ?? null;

	if (is_array($cache) && $cache['revision'] === (int) $revision) {
		return $cache;
	}
	$rule_count = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_rules', [], '', false);

	if ($rule_count === false) {
		throw new RuntimeException('storage_failure');
	}
	$cache   = ['revision' => (int) $revision, 'rules' => [], 'endpoints' => [], 'default_route' => (int) $rule_count === 0];
	$columns = ['rules' => 'id, name, priority, enabled, endpoints_json, stop_processing, definition', 'endpoints' => 'id, name, type, enabled, max_concurrency, min_interval_ms, timeout_seconds, max_attempts'];

	foreach (['rules', 'endpoints'] as $section) {
		$order  = $section === 'rules' ? 'priority, id' : 'id';
		$where  = 'enabled = 1';
		$params = [];

		if ($section === 'endpoints') {
			foreach ($cache['rules'] as $rule) {
				foreach ($rule['endpoints'] as $id) {
					$params[] = alarmmanager_config_int($id, 1, 2147483647);
				}
			}
			$params = array_values(array_unique($params));

			if (count($params) > 200) {
				throw new RuntimeException('configuration_limit');
			}
			$where = $cache['default_route'] ? 'enabled = 1' : ($params ? 'id IN (' . implode(',', array_fill(0, count($params), '?')) . ')' : '1 = 0');

			if ($cache['default_route']) {
				$params = [];
			}
		}
		$rows = alarmmanager_db_rows("SELECT {$columns[$section]} FROM plugin_alarmmanager_$section WHERE $where ORDER BY $order LIMIT 201", $params);

		if (count($rows) > 200) {
			throw new RuntimeException('configuration_limit');
		}

		foreach ($rows as $row) {
			if ($section === 'rules') {
				$row['policy']    = alarmmanager_validate_rule_definition(json_decode($row['definition'], true, 16, JSON_THROW_ON_ERROR));
				$row['endpoints'] = alarmmanager_config_list(json_decode($row['endpoints_json'], true, 16, JSON_THROW_ON_ERROR), 32);
			}
			$cache[$section][(int) $row['id']] = $row;
		}
	}
	$GLOBALS['alarmmanager_route_cache'] = $cache;

	return $cache;
}

/** Internal configuration writer: caller must own a transaction. */
function alarmmanager_write_configuration(array $settings, ?int $expected_revision = null, ?PDO $connection = null): void {
	$locked_revision = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE', [], $connection);

	if ($expected_revision !== null && (int) ($locked_revision['config_revision'] ?? -1) !== $expected_revision) {
		throw new InvalidArgumentException('configuration_changed');
	}

	foreach (['endpoints', 'rules'] as $section) {
		alarmmanager_db_write('UPDATE plugin_alarmmanager_' . $section . ' SET enabled = 0', [], $connection);
	}

	foreach ($settings['endpoints'] as $endpoint) {
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_endpoints (id, name, type, enabled, max_concurrency, min_interval_ms, timeout_seconds, max_attempts)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), enabled = VALUES(enabled),
				max_concurrency = VALUES(max_concurrency), min_interval_ms = VALUES(min_interval_ms), timeout_seconds = VALUES(timeout_seconds), max_attempts = VALUES(max_attempts)',
			[$endpoint['id'], $endpoint['name'], $endpoint['type'], (int) $endpoint['enabled'], $endpoint['max_concurrency'], $endpoint['min_interval_ms'], $endpoint['timeout_seconds'], $endpoint['max_attempts']], $connection);
	}

	foreach ($settings['endpoints'] as $endpoint) {
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_transports (endpoint_id, definition) VALUES (?, ?) ON DUPLICATE KEY UPDATE definition = VALUES(definition)', [$endpoint['id'], json_encode($endpoint['transport'], JSON_THROW_ON_ERROR)], $connection);
	}

	foreach ($settings['rules'] as $rule) {
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_rules (id, name, priority, enabled, endpoints_json, stop_processing, definition) VALUES (?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE name = VALUES(name), priority = VALUES(priority), enabled = VALUES(enabled), endpoints_json = VALUES(endpoints_json), stop_processing = VALUES(stop_processing), definition = VALUES(definition)',
			[$rule['id'], $rule['name'], $rule['priority'], (int) $rule['enabled'], json_encode($rule['endpoints'], JSON_THROW_ON_ERROR), (int) $rule['stop'], json_encode($rule['policy'], JSON_THROW_ON_ERROR)], $connection);
	}
	alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET config_revision = config_revision + 1 WHERE id = 1', [], $connection);
	$revision = db_fetch_cell_prepared('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1', [], '', false, $connection);

	if ($revision === false) {
		throw new RuntimeException('storage_failure');
	}
	$history = $settings;

	foreach (['endpoints', 'rules'] as $section) {
		$history[$section] = array_values($history[$section]);
	}
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_configuration_history (revision, definition, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))', [(int) $revision, json_encode($history, JSON_THROW_ON_ERROR)], $connection);

	if ($connection === null) {
		alarmmanager_request_configuration_replication();
	}
}
