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

/** @param array<string, mixed> $reference */
function alarmmanager_mapping_fields(array $event, array $reference = []): array {
	$fields = [];

	foreach (['event_id' => 'event.id', 'source' => 'event.source', 'source_instance' => 'event.source_instance', 'collector_id' => 'event.collector_id', 'occurred_at' => 'event.occurred_at', 'resource_id' => 'alarm.resource_id', 'alarm_key' => 'alarm.key', 'episode_id' => 'alarm.episode_id', 'sequence' => 'alarm.sequence', 'transition' => 'alarm.transition', 'severity' => 'alarm.severity', 'summary' => 'alarm.summary', 'details' => 'alarm.details'] as $key => $name) {
		$fields[$name] = $event[$key] ?? null;
	}

	foreach (['id', 'hostname', 'description', 'tags'] as $key) {
		$fields['device.' . $key] = $event['device'][$key] ?? null;
	}

	foreach (['rule_name', 'rule_type', 'metric', 'metric_value', 'threshold', 'suppression_reason', 'source_suppressed', 'source_notification_channels', 'ownership_epoch'] as $key) {
		$fields['context.' . $key] = $event['context'][$key] ?? null;
	}

	$fields['integration.id']  = $reference['id'] ?? null;
	$fields['integration.url'] = $reference['url'] ?? null;

	return $fields;
}
function alarmmanager_mapping_validate($mapping): array {
	if (!is_array($mapping)) {
		throw new InvalidArgumentException('invalid_mapping');
	}
	alarmmanager_event_keys($mapping, ['default', 'raised', 'updated', 'reminder', 'recovered', 'occurrence']);
	$result  = [];
	$allowed = array_keys(alarmmanager_mapping_fields([]));

	foreach ($mapping as $transition => $items) {
		if (!is_array($items) || !array_is_list($items) || count($items) > 64) {
			throw new InvalidArgumentException('invalid_mapping');
		}
		$paths = [];

		foreach ($items as $item) {
			if (!is_array($item)) {
				throw new InvalidArgumentException('invalid_mapping');
			}
			alarmmanager_event_keys($item, ['to', 'source', 'constant', 'template', 'default', 'type', 'values', 'required']);
			$path = $item['to'] ?? '';

			if (!is_string($path) || strlen($path) > 191 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,7}\z/D', $path)) {
				throw new InvalidArgumentException('invalid_mapping_path');
			}

			foreach ($paths as $prior) {
				if ($path === $prior || str_starts_with($path, $prior . '.') || str_starts_with($prior, $path . '.')) {
					throw new InvalidArgumentException('conflicting_mapping');
				}
			}
			$paths[] = $path;

			if (count(array_intersect(['source', 'constant', 'template'], array_keys($item))) !== 1) {
				throw new InvalidArgumentException('ambiguous_mapping');
			}

			if (array_key_exists('source', $item) && (!is_string($item['source']) || !in_array($item['source'], $allowed, true))) {
				throw new InvalidArgumentException('unknown_mapping_field');
			}
			if (array_key_exists('template', $item)) {
				if (!is_string($item['template'])) {
					throw new InvalidArgumentException('invalid_mapping_template');
				}
				alarmmanager_mapping_template_fields($item['template']);
			}
			$type = $item['type'] ?? 'identity';

			if (!in_array($type, ['identity', 'string', 'integer', 'number', 'boolean', 'html'], true) || !is_bool($item['required'] ?? false)) {
				throw new InvalidArgumentException('invalid_mapping');
			}

			if (isset($item['values']) && (!is_array($item['values']) || count($item['values']) > 32)) {
				throw new InvalidArgumentException('invalid_mapping');
			}

			foreach (['constant', 'template', 'default', 'values'] as $key) {
				if (array_key_exists($key, $item) && strlen(json_encode($item[$key], JSON_THROW_ON_ERROR)) > 16384) {
					throw new InvalidArgumentException('mapping_limit');
				}
			}
			$result[$transition][] = $item + ['type' => 'identity', 'required' => false];
		}
		$result[$transition] ??= [];
	}

	return $result;
}
/** @return list<string> */
function alarmmanager_mapping_template_fields(string $template): array {
	preg_match_all('/{{([^{}]*)}}/', $template, $matches);
	$remaining = preg_replace('/{{[^{}]*}}/', '', $template);
	if (str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
		throw new InvalidArgumentException('invalid_mapping_template');
	}
	$fields = array_map('trim', $matches[1]);
	if (array_diff($fields, array_keys(alarmmanager_mapping_fields([])))) {
		throw new InvalidArgumentException('unknown_mapping_field');
	}
	return $fields;
}

/** @param array<string, mixed> $fields */
function alarmmanager_mapping_template(string $template, array $fields): ?string {
	$missing = false;
	$text = preg_replace_callback('/{{([^{}]*)}}/', static function (array $match) use ($fields, &$missing): string {
		$value = $fields[trim($match[1])];
		if ($value === null) {
			$missing = true;
			return '';
		}
		return alarmmanager_mapping_convert($value, 'string');
	}, $template);
	return $missing ? null : $text;
}

function alarmmanager_mapping_convert($value, string $type) {
	if ($value === null || $type === 'identity') {
		return $value;
	}

	if ($type === 'string' || $type === 'html') {
		if (!is_scalar($value)) {
			throw new InvalidArgumentException('mapping_type');
		}
		$text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

		return $type === 'html' ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
	}

	if ($type === 'integer') {
		if (is_bool($value) || (!is_int($value) && !is_string($value))) {
			throw new InvalidArgumentException('mapping_type');
		}
		$result = filter_var($value, FILTER_VALIDATE_INT);

		if ($result === false) {
			throw new InvalidArgumentException('mapping_type');
		}

		return $result;
	}

	if ($type === 'number') {
		if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
			throw new InvalidArgumentException('mapping_type');
		}

		return (float) $value;
	}

	if ($type === 'boolean') {
		if (is_bool($value)) {
			return $value;
		}

		return match ($value) {
			1, '1', 'true' => true, 0, '0', 'false' => false, default => throw new InvalidArgumentException('mapping_type')
		};
	}

	throw new InvalidArgumentException('mapping_type');
}
/** @param array<string, mixed> $reference */
function alarmmanager_map_payload(array $mapping, array $event, array $reference = []): stdClass {
	$mapping = alarmmanager_mapping_validate($mapping);
	$items   = $mapping[$event['transition']] ?? $mapping['default'] ?? [];
	$fields  = alarmmanager_mapping_fields($event, $reference);
	$payload = new stdClass();

	foreach ($items as $item) {
		$value = array_key_exists('template', $item) ? alarmmanager_mapping_template($item['template'], $fields) : (array_key_exists('constant', $item) ? $item['constant'] : $fields[$item['source']]);

		if ($value === null && array_key_exists('default', $item)) {
			$value = $item['default'];
		}

		if (isset($item['values']) && $value !== null) {
			if (!is_string($value) && !is_int($value)) {
				throw new InvalidArgumentException('mapping_type');
			}

			if (!array_key_exists($value, $item['values'])) {
				throw new InvalidArgumentException('mapping_value');
			}
			$value = $item['values'][$value];
		}

		if ($value === null && $item['required']) {
			throw new InvalidArgumentException('mapping_required');
		}
		$value = alarmmanager_mapping_convert($value, $item['type']);
		$parts = explode('.', $item['to']);
		$last  = array_pop($parts);
		$node  = $payload;

		foreach ($parts as $part) {
			$node->$part ??= new stdClass();
			$node = $node->$part;
		}
		$node->$last = $value;
	}

	if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 65536) {
		throw new InvalidArgumentException('mapping_limit');
	}

	return $payload;
}
