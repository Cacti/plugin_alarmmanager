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

function alarmmanager_event_token($value, int $limit): string {
	if (!is_string($value) || strlen($value) > $limit || !preg_match('/\A[A-Za-z0-9_.:@\/-]+\z/D', $value)) {
		throw new InvalidArgumentException('Invalid event identifier.');
	}

	return $value;
}

function alarmmanager_event_text($value, int $limit): string {
	if (!is_string($value) || strlen($value) > $limit) {
		throw new InvalidArgumentException('Invalid event text.');
	}

	return $value;
}

function alarmmanager_event_time($value): string {
	if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})\z/D', $value, $parts)) {
		throw new InvalidArgumentException('Event time requires an ISO 8601 offset.');
	}
	$offset = $parts[3] === 'Z' ? '+00:00' : $parts[3];

	if ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59
		|| ((int) substr($offset, 1, 2) === 14 && substr($offset, 4, 2) !== '00')) {
		throw new InvalidArgumentException('Invalid time offset.');
	}
	$normalized = $parts[1] . '.' . str_pad($parts[2] ?? '', 6, '0') . $offset;
	$time       = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
	$errors     = DateTimeImmutable::getLastErrors();

	if ($time === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
		throw new InvalidArgumentException('Invalid event date.');
	}
	$time = $time->setTimezone(new DateTimeZone('UTC'));

	if ((int) $time->format('Y') < 1000 || (int) $time->format('Y') > 9999) {
		throw new InvalidArgumentException('Event date is outside the database range.');
	}

	return $time->format('Y-m-d\TH:i:s.u\Z');
}

function alarmmanager_event_keys(array $data, array $allowed): void {
	if (array_diff(array_keys($data), $allowed)) {
		throw new InvalidArgumentException('Unknown event fields.');
	}
}

function alarmmanager_event_tags($tags): array {
	if (!is_array($tags) || !array_is_list($tags) || count($tags) > 32) {
		throw new InvalidArgumentException('Invalid tag list.');
	}

	foreach ($tags as $tag) {
		alarmmanager_event_text($tag, 128);
	}
	$tags = array_values(array_unique($tags));
	sort($tags, SORT_STRING);

	return $tags;
}

/** Deterministic bounded payload; reject raw host records and unknown context. */
function alarmmanager_validate_event(array $event): array {
	alarmmanager_event_keys($event, ['event_id', 'source', 'source_instance', 'resource_id', 'alarm_key', 'episode_id', 'sequence', 'transition', 'severity', 'occurred_at', 'collector_id', 'device', 'summary', 'details', 'context']);
	$transition = $event['transition'] ?? null;

	if (!in_array($transition, ['raised', 'updated', 'reminder', 'recovered', 'occurrence'], true)
		|| !in_array($event['severity'] ?? null, ['critical', 'major', 'minor', 'warning', 'info', 'clear'], true)) {
		throw new InvalidArgumentException('Invalid transition or severity.');
	}

	if (!is_int($event['sequence'] ?? null) || $event['sequence'] < 1
		|| !is_int($event['collector_id'] ?? null) || $event['collector_id'] < 1 || $event['collector_id'] > 2147483647) {
		throw new InvalidArgumentException('Invalid sequence or collector.');
	}
	$device  = $event['device'] ?? [];
	$context = $event['context'] ?? [];

	if (!is_array($device) || !is_array($context)) {
		throw new InvalidArgumentException('Invalid device or context.');
	}
	alarmmanager_event_keys($device, ['id', 'hostname', 'description', 'location', 'contact', 'sysname', 'tags', 'cacti_location', 'collector_name', 'external_id', 'snmp_sysUpTimeInstance', 'uptime', 'status_fail_date', 'status_rec_date', 'status_last_error']);

	if (isset($device['id']) && (!is_int($device['id']) || $device['id'] < 1)) {
		throw new InvalidArgumentException('Invalid device ID.');
	}
	$safe_device = [
		'id'          => $device['id'] ?? null,
		'hostname'    => alarmmanager_event_text($device['hostname'] ?? '', 255),
		'description' => alarmmanager_event_text($device['description'] ?? '', 512),
		'location'    => alarmmanager_event_text($device['location'] ?? '', 512),
		'contact'     => alarmmanager_event_text($device['contact'] ?? '', 512),
		'sysname'     => alarmmanager_event_text($device['sysname'] ?? '', 255),
		'tags'        => alarmmanager_event_tags($device['tags'] ?? [])
	];

	foreach (['cacti_location', 'collector_name', 'external_id', 'snmp_sysUpTimeInstance', 'uptime', 'status_fail_date', 'status_rec_date', 'status_last_error'] as $field) {
		if (!array_key_exists($field, $device)) {
			continue;
		}
		$value               = alarmmanager_event_text($device[$field], $field === 'status_last_error' ? 2048 : 512);
		$safe_device[$field] = in_array($field, ['status_fail_date', 'status_rec_date'], true) && $value !== '' ? alarmmanager_event_time($value) : $value;
	}
	alarmmanager_event_keys($context, ['rule_name', 'rule_type', 'metric', 'metric_value', 'threshold', 'source_suppressed', 'suppression_reason', 'source_notification_channels', 'ownership_epoch']);
	$safe_context = [];

	foreach (['rule_name', 'rule_type', 'metric', 'metric_value', 'threshold', 'suppression_reason'] as $field) {
		if (array_key_exists($field, $context)) {
			$safe_context[$field] = alarmmanager_event_text($context[$field], 512);
		}
	}

	if (array_key_exists('source_suppressed', $context)) {
		if (!is_bool($context['source_suppressed'])) {
			throw new InvalidArgumentException('Invalid suppression flag.');
		}
		$safe_context['source_suppressed'] = $context['source_suppressed'];
	}

	if (array_key_exists('source_notification_channels', $context)) {
		$safe_context['source_notification_channels'] = alarmmanager_event_tags($context['source_notification_channels']);
	}

	if (isset($context['ownership_epoch'])) {
		if (!is_int($context['ownership_epoch']) || $context['ownership_epoch'] < 1 || $context['ownership_epoch'] > 1000000) {
			throw new InvalidArgumentException('Invalid ownership epoch.');
		}
		$safe_context['ownership_epoch'] = $context['ownership_epoch'];
	}

	return [
		'event_id'        => alarmmanager_event_token($event['event_id'] ?? null, 128),
		'source'          => alarmmanager_event_token($event['source'] ?? null, 32),
		'source_instance' => alarmmanager_event_token($event['source_instance'] ?? null, 128),
		'resource_id'     => alarmmanager_event_token($event['resource_id'] ?? null, 128),
		'alarm_key'       => alarmmanager_event_token($event['alarm_key'] ?? null, 191),
		'episode_id'      => alarmmanager_event_token($event['episode_id'] ?? null, 128),
		'sequence'        => $event['sequence'],
		'transition'      => $transition,
		'severity'        => $event['severity'],
		'occurred_at'     => alarmmanager_event_time($event['occurred_at'] ?? null),
		'collector_id'    => $event['collector_id'],
		'device'          => $safe_device,
		'summary'         => alarmmanager_event_text($event['summary'] ?? null, 512),
		'details'         => alarmmanager_event_text($event['details'] ?? '', 16384),
		'context'         => $safe_context
	];
}
