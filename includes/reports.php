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
require_once(__DIR__ . '/web_auth.php');
require_once(__DIR__ . '/routing.php');
require_once(dirname(__DIR__) . '/lib/QueryBuilder.php');

/** Trusted columns and operators shared by the visual editor and SQL compiler.
 * @return array<string, array<string, mixed>>
 */
function alarmmanager_report_query_fields(string $kind = 'dashboard', string $severity = 'e.severity'): array {
	$text   = ['contains', 'begins', 'ends', '=', '!='];
	$number = ['=', '!=', '>', '>=', '<', '<='];
	$json   = static fn (string $path): string => "JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.$path'))";
	$fields = [
		'summary'         => ['label' => 'Summary', 'column' => $json('summary'), 'operators' => $text],
		'details'         => ['label' => 'Details', 'column' => $json('details'), 'operators' => $text],
		'hostname'        => ['label' => 'Hostname', 'column' => $json('device.hostname'), 'operators' => $text],
		'description'     => ['label' => 'Device description', 'column' => $json('device.description'), 'operators' => $text],
		'source'          => ['label' => 'Source', 'column' => 'e.source', 'operators' => $text],
		'source_instance' => ['label' => 'Source instance', 'column' => 'e.source_instance', 'operators' => $text],
		'severity'        => ['label' => 'Severity', 'column' => $severity, 'operators' => ['=', '!='], 'choices' => ['critical', 'major', 'minor', 'warning', 'info', 'clear']],
		'transition'      => ['label' => 'Alarm state', 'column' => 'e.transition', 'operators' => ['=', '!='], 'choices' => ['raised', 'updated', 'reminder', 'recovered', 'occurrence']],
		'device_id'       => ['label' => 'Device ID', 'column' => 'e.device_id', 'operators' => $number, 'type' => 'integer'],
		'collector_id'    => ['label' => 'Collector ID', 'column' => 'e.collector_id', 'operators' => $number, 'type' => 'integer'],
		'event_row_id'    => ['label' => 'Event row ID', 'column' => 'e.id', 'operators' => $number, 'type' => 'integer'],
		'event_id'        => ['label' => 'Event identifier', 'column' => 'e.event_id', 'operators' => $text],
		'alarm_key'       => ['label' => 'Alarm key', 'column' => 'e.alarm_key', 'operators' => $text],
		'episode_id'      => ['label' => 'Episode ID', 'column' => 'e.episode_id', 'operators' => $text]
	];
	$extra = match ($kind) {
		'dashboard', 'events' => [],
		'conditions'          => ['acknowledged' => ['Acknowledged', 's.acknowledged', 'integer']],
		'deliveries'          => ['status' => ['Delivery status', 'd.status', 'string'], 'endpoint_id' => ['Endpoint ID', 'd.endpoint_id', 'integer'], 'endpoint' => ['Endpoint name', 'ep.name', 'string'], 'attempts' => ['Attempts', 'd.attempts', 'integer'], 'error' => ['Last error', 'd.last_error', 'string']],
		'attempts'            => ['status' => ['Attempt outcome', 'a.outcome', 'string'], 'endpoint_id' => ['Endpoint ID', 'd.endpoint_id', 'integer'], 'attempts' => ['Attempt number', 'a.attempt_number', 'integer'], 'code' => ['Response code', 'a.code', 'string']],
		'decisions'           => ['reason' => ['Decision reason', 'r.reason', 'string'], 'endpoint_id' => ['Endpoint ID', 'r.endpoint_id', 'integer'], 'rule_id' => ['Rule ID', 'r.rule_id', 'integer']],
		'processing'          => ['status' => ['Processing status', 'j.status', 'string'], 'attempts' => ['Attempts', 'j.attempts', 'integer'], 'error' => ['Last error', 'j.last_error', 'string']],
		default               => throw new InvalidArgumentException('invalid_report')
	};

	foreach ($extra as $key => [$label, $column, $type]) {
		$fields[$key] = ['label' => $label, 'column' => $column, 'type' => $type, 'operators' => $type === 'integer' ? $number : $text];
	}

	if ($kind === 'events') {
		// Routing decisions are matched per event: any decision for the event may satisfy the condition.
		$decision = 'plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id';
		$fields += [
			'decision_reason' => ['label' => 'Routing decision', 'column' => 'r.reason', 'exists' => $decision, 'operators' => ['='], 'choices' => array_keys(alarmmanager_decision_labels())],
			'rule_id'         => ['label' => 'Rule ID', 'column' => 'r.rule_id', 'exists' => $decision, 'operators' => ['='], 'type' => 'integer'],
			'destination_id'  => ['label' => 'Destination ID', 'column' => 'r.endpoint_id', 'exists' => $decision, 'operators' => ['='], 'type' => 'integer']
		];
	}

	return $fields;
}

function alarmmanager_report_rows(string $sql, array $params = []): array {
	// MariaDB per-statement deadline does not alter the connection's worker settings.
	return alarmmanager_db_rows('SET STATEMENT max_statement_time=5 FOR ' . $sql, $params);
}
function alarmmanager_report_row(string $sql, array $params = []): array {
	return alarmmanager_report_rows($sql, $params)[0] ?? [];
}
function alarmmanager_report_filters(array $input): array {
	$range = filter_var($input['range'] ?? 86400, FILTER_VALIDATE_INT);

	if (!in_array($range, [3600, 21600, 86400, 604800, 2592000], true)) {
		throw new InvalidArgumentException('invalid_filter');
	}
	$until = filter_var($input['until'] ?? time(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 946684800, 'max_range' => time() + 60]]);

	if (!$until) {
		throw new InvalidArgumentException('invalid_filter');
	}
	$result = ['range' => $range, 'until' => $until, 'from' => gmdate('Y-m-d H:i:s', $until - $range), 'to' => gmdate('Y-m-d H:i:s', $until), 'page' => 1, 'current' => (string) ($input['current'] ?? '0') === '1'];

	if (!in_array((string) ($input['current'] ?? '0'), ['0', '1'], true)) {
		throw new InvalidArgumentException('invalid_filter');
	}

	$result['job'] = (string) ($input['job'] ?? 'delivery');

	if (!in_array($result['job'], ['delivery', 'processing', 'attempt'], true)) {
		throw new InvalidArgumentException('invalid_filter');
	}

	$result['acknowledged'] = (string) ($input['acknowledged'] ?? '');

	if (!in_array($result['acknowledged'], ['', '0', '1'], true)) {
		throw new InvalidArgumentException('invalid_filter');
	}

	foreach (['severity' => ['critical', 'major', 'minor', 'warning', 'info', 'clear'], 'transition' => ['raised', 'updated', 'reminder', 'recovered', 'occurrence'], 'status' => ['pending', 'leased', 'completed', 'failed', 'success', 'retry', 'failure', 'abandoned', 'in_progress']] as $key => $values) {
		$result[$key] = $input[$key] ?? '';

		if (!is_string($result[$key]) || ($result[$key] !== '' && !in_array($result[$key], $values, true))) {
			throw new InvalidArgumentException('invalid_filter');
		}
	}

	foreach (['source' => 32, 'reason' => 32] as $key => $limit) {
		$result[$key] = $input[$key] ?? '';

		if (!is_string($result[$key]) || strlen($result[$key]) > $limit || ($result[$key] !== '' && !preg_match('/\A[A-Za-z0-9_.:@\/-]+\z/D', $result[$key]))) {
			throw new InvalidArgumentException('invalid_filter');
		}
	}

	foreach (['device_id', 'endpoint_id', 'event_id', 'collector_id', 'page'] as $key) {
		$value        = $input[$key] ?? ($key === 'page' ? 1 : 0);
		$result[$key] = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $key === 'page' ? 1 : 0, 'max_range' => $key === 'page' ? 200 : ($key === 'event_id' ? PHP_INT_MAX : 2147483647)]]);

		if ($result[$key] === false) {
			throw new InvalidArgumentException('invalid_filter');
		}
	}

	$result['query'] = $input['query'] ?? '';

	if (!is_string($result['query'])) {
		throw new InvalidArgumentException('invalid_filter');
	}

	if ($result['query'] !== '') {
		AlarmManager\QueryBuilder::compile($result['query'], alarmmanager_report_query_fields($input['tab'] ?? 'dashboard'));
	}

	return $result;
}
function alarmmanager_report_where(array $scope, array $filters, ?string $time_column = 'e.received_at', string $severity_column = 'e.severity', string $kind = 'dashboard'): array {
	$where  = ['(' . $scope['device_sql'] . ')'];
	$params = [];

	if ($time_column !== null) {
		$where[]  = "$time_column >= ? AND $time_column < ?";
		$params[] = $filters['from'];
		$params[] = $filters['to'];
	}

	foreach (['source' => 'e.source', 'severity' => $severity_column, 'transition' => 'e.transition', 'device_id' => 'e.device_id', 'event_id' => 'e.id', 'collector_id' => 'e.collector_id'] as $key => $column) {
		if (($key !== 'event_id' || $kind === 'events') && $filters[$key] !== '' && $filters[$key] !== 0) {
			$where[]  = "$column = ?";
			$params[] = $filters[$key];
		}
	}

	if (($filters['query'] ?? '') !== '') {
		$query   = AlarmManager\QueryBuilder::compile($filters['query'], alarmmanager_report_query_fields($kind, $severity_column));
		$where[] = '(' . $query['sql'] . ')';
		$params  = array_merge($params, $query['params']);
	}

	return [implode(' AND ', $where), $params];
}
/** Every report joins the event and applies the same visibility predicate before LIMIT. */
function alarmmanager_report_list(string $kind, array $scope, array $filters): array {
	$collector = (int) ($filters['collector_id'] ?? 0);
	$base      = 'plugin_alarmmanager_events AS e';
	$order     = 'e.id';
	$time      = 'e.received_at';
	$severity  = 'e.severity';
	$columns   = 'e.id, e.received_at, e.source, e.transition, e.severity, e.device_id, COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.device.description\')), \'\'), JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.device.hostname\'))) AS device_name, e.event_id, JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.summary\')) AS summary, e.payload';

	switch ($kind) {
		case 'events': break;
		case 'conditions': $base = 'plugin_alarmmanager_states AS s INNER JOIN plugin_alarmmanager_events AS e ON e.id = s.last_event_id';
			$columns                = 's.stream_key, s.updated_at, s.active, s.acknowledged, s.severity, s.summary, e.id, e.source, e.device_id, e.payload';
			$order                  = 's.updated_at DESC, e.id';
			$time                   = null;
			$severity               = 's.severity';

			break;
		case 'deliveries': $base = 'plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id';
			$columns                = 'd.id, d.recipient, d.event_row_id, d.created_at, d.updated_at, d.endpoint_id, COALESCE(ep.name, CONCAT(\'Deleted endpoint #\', d.endpoint_id)) AS endpoint, d.status, d.attempts, d.last_error, JSON_UNQUOTE(JSON_EXTRACT(d.external_reference, \'$.id\')) AS external_id, JSON_UNQUOTE(JSON_EXTRACT(d.external_reference, \'$.url\')) AS external_url, JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.summary\')) AS summary, e.device_id';
			$order                  = 'd.id';
			$time                   = 'd.created_at';

			break;
		case 'attempts': $base = 'plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id';
			$columns              = 'a.id, a.delivery_id, d.recipient, a.attempt_number, a.outcome, a.code, a.started_at, a.duration_ms, d.endpoint_id, e.id AS event_row_id, e.device_id';
			$order                = 'a.id';
			$time                 = 'a.started_at';

			break;
		case 'decisions': $base = 'plugin_alarmmanager_decisions AS r INNER JOIN plugin_alarmmanager_events AS e ON e.id = r.event_row_id';
			$columns               = 'r.id, r.event_row_id, r.rule_id, r.endpoint_id, r.reason, r.config_revision, r.created_at, JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.summary\')) AS summary, e.device_id';
			$order                 = 'r.id';
			$time                  = 'r.created_at';

			break;
		case 'processing': $base = 'plugin_alarmmanager_jobs AS j INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id';
			$columns                = 'j.id, j.event_row_id, j.status, j.attempts, j.last_error, j.created_at, j.available_at, JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.summary\')) AS summary, e.device_id';
			$order                  = 'j.id';
			$time                   = null;

			break;
		default: throw new InvalidArgumentException('invalid_report');
	}

	if ($kind === 'deliveries' && $filters['current']) {
		$time = null;
	}

	if ($kind === 'events' && ($filters['event_id'] > 0 || $filters['current'])) {
		$time = null;
	}
	[$where, $params] = alarmmanager_report_where($scope, $filters, $time, $severity, $kind);

	// Both event and condition reports include mirrored collector history.
	$remote = ($collector === 0 || ($collector > 1 && $collector !== alarmmanager_local_poller()))
		&& in_array($kind, ['events', 'conditions'], true);

	if ($kind === 'events') {
		$job      = $filters['job'] ?? 'delivery';
		$relation = match ($job) {
			'processing' => 'plugin_alarmmanager_jobs AS job WHERE job.event_row_id = e.id',
			'attempt'    => 'plugin_alarmmanager_attempts AS job INNER JOIN plugin_alarmmanager_deliveries AS delivery ON delivery.id = job.delivery_id WHERE delivery.event_row_id = e.id',
			default      => 'plugin_alarmmanager_deliveries AS job WHERE job.event_row_id = e.id'
		};
		$checks = [];

		if ($filters['status'] !== '') {
			$checks[] = ($job === 'attempt' ? 'job.outcome' : 'job.status') . ' = ?';
			$params[] = $filters['status'];
		}

		if ($filters['endpoint_id'] && $job !== 'processing') {
			$checks[] = ($job === 'attempt' ? 'delivery.endpoint_id' : 'job.endpoint_id') . ' = ?';
			$params[] = $filters['endpoint_id'];
		}

		if ($checks) {
			$where .= ' AND EXISTS (SELECT 1 FROM ' . $relation . ' AND ' . implode(' AND ', $checks) . ')';
		}
	}

	if ($kind === 'conditions') {
		$where .= ' AND s.active = 1';

		if ($filters['acknowledged'] !== '') {
			$where .= ' AND s.acknowledged = ?';
			$params[] = (int) $filters['acknowledged'];
		}
	}

	if ($filters['status'] !== '' && in_array($kind, ['deliveries', 'attempts', 'processing'], true)) {
		$where .= ' AND ' . ($kind === 'attempts' ? 'a.outcome' : ($kind === 'processing' ? 'j.status' : 'd.status')) . ' = ?';
		$params[] = $filters['status'];
	}

	if ($filters['endpoint_id'] && in_array($kind, ['deliveries', 'attempts', 'decisions'], true)) {
		$where .= ' AND ' . ($kind === 'decisions' ? 'r.endpoint_id' : 'd.endpoint_id') . ' = ?';
		$params[] = $filters['endpoint_id'];
	}

	if ($filters['reason'] !== '' && $kind === 'decisions') {
		$where .= ' AND r.reason = ?';
		$params[] = $filters['reason'];
	}

	if ($filters['reason'] !== '' && $kind === 'events') {
		$where .= ' AND EXISTS (SELECT 1 FROM plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id AND r.reason = ?)';
		$params[] = $filters['reason'];
	}
	$base .= ' LEFT JOIN poller AS collector ON collector.id = e.collector_id';
	$columns .= ", e.collector_id, COALESCE(NULLIF(collector.name, ''), CONCAT('Poller #', e.collector_id)) AS collector_name";
	$offset = ($filters['page'] - 1) * 50;

	if ($remote) {
		// Derived table keeps every e.* predicate resolvable; mirrored rows get NULL id so no Record ID is ever rendered.
		$payload        = "JSON_UNQUOTE(JSON_EXTRACT(h.definition, '$.payload'))";
		$remote_gate    = $collector > 1 ? ' AND h.poller_id = ?' : '';

		if ($kind === 'events') {
			$remote_columns = 'NULL AS id, h.received_at, h.source, '
				. "COALESCE(JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.transition')), 'event') AS `transition`, "
				. "COALESCE(JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.severity')), 'info') AS severity, "
				. 'h.device_id, '
				. "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.device.description')), ''), JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.device.hostname'))) AS device_name, "
				. "JSON_UNQUOTE(JSON_EXTRACT(h.definition, '\$.event_id')) AS event_id, "
				. "JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.summary')) AS `summary`, "
				. "$payload AS payload, "
				. "h.poller_id AS collector_id, COALESCE(NULLIF(remote_poller.name, ''), CONCAT('Poller #', h.poller_id)) AS collector_name, "
				. 'h.entity_id AS remote_entity_id, h.instance_uuid AS remote_instance_uuid';
			$sql = "SELECT * FROM ((SELECT $columns, NULL AS remote_entity_id, NULL AS remote_instance_uuid FROM $base WHERE $where)"
				. " UNION ALL (SELECT $remote_columns FROM plugin_alarmmanager_remote_history AS h LEFT JOIN poller AS remote_poller ON remote_poller.id = h.poller_id WHERE h.entity = 'event'$remote_gate AND h.received_at >= ? AND h.received_at < ?)) AS report"
				. ' ORDER BY report.received_at DESC, report.collector_id DESC, report.id DESC LIMIT 51 OFFSET ' . $offset;
		} elseif ($kind === 'conditions') {
			$stream_expr   = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.alarm_key')), 'unknown')";
			$transition    = "JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.transition'))";
			$cond_local    = $columns; // includes payload from the case branch, then collector_id+collector_name appended below
			$cond_remote   = 't.stream_key, t.received_at AS updated_at, 1 AS active, 0 AS acknowledged, t.severity, t.`summary`, NULL AS id, t.source, t.device_id, t.payload, t.poller_id AS collector_id, t.collector_name';
			// Mirror arm: latest event per (poller, alarm_key); active when its transition is non-terminal.
			$sql = "SELECT * FROM ((SELECT $cond_local FROM $base WHERE $where)"
				. " UNION ALL (SELECT $cond_remote FROM (SELECT SHA2(CONCAT('remote:', h.poller_id, ':', {$stream_expr}), 64) AS stream_key, h.received_at, h.source, h.device_id, h.poller_id, COALESCE(JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.severity')), 'info') AS severity, COALESCE(JSON_UNQUOTE(JSON_EXTRACT($payload, '\$.summary')), 'Remote alert') AS `summary`, {$transition} AS transition, {$payload} AS payload, {$stream_expr} AS alarm_key, ROW_NUMBER() OVER (PARTITION BY h.poller_id, {$stream_expr} ORDER BY h.received_at DESC) AS seq, COALESCE(NULLIF(remote_poller.name, ''), CONCAT('Poller #', h.poller_id)) AS collector_name FROM plugin_alarmmanager_remote_history AS h LEFT JOIN poller AS remote_poller ON remote_poller.id = h.poller_id WHERE h.entity = 'event'$remote_gate AND h.received_at >= ? AND h.received_at < ?) AS t WHERE t.seq = 1 AND t.transition NOT IN ('recovered', 'clear') AND t.transition <> '' AND t.transition IS NOT NULL)) AS report"
				. ' ORDER BY report.updated_at DESC LIMIT 51 OFFSET ' . $offset;
		} else {
			$sql = "SELECT $columns FROM $base WHERE $where";
		}

		if ($collector > 1) {
			$params[] = $collector;
		}
		$params[] = $filters['from'];
		$params[] = $filters['to'];
	} else {
		$sql = "SELECT $columns FROM $base WHERE $where";
		$sql .= " ORDER BY $order DESC LIMIT 51 OFFSET $offset";
	}
	$rows = alarmmanager_report_rows($sql, $params);

	$more = count($rows) > 50;
	$rows = array_slice($rows, 0, 50);

	if ($kind === 'events' && $rows) {
		// PDO can return BIGINT identifiers as strings; NULL identifies remote history.
		$ids = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));

		$routing = alarmmanager_report_routing($rows, $ids);

		foreach ($rows as $index => &$row) {
			$row['routing']            = $routing[$index];
			$row['integration_status'] = $row['id'] === null ? __('Remote history', 'alarmmanager') : '';
			unset($row['remote_entity_id'], $row['remote_instance_uuid']);
		}
		unset($row);

		if ($ids) {
			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			$counts       = [];

			foreach (alarmmanager_report_rows("SELECT event_row_id, status, COUNT(*) AS total FROM plugin_alarmmanager_deliveries WHERE event_row_id IN ($placeholders) GROUP BY event_row_id, status", $ids) as $delivery) {
				$counts[$delivery['event_row_id']][$delivery['status']] = (int) $delivery['total'];
			}
			$processing = array_column(alarmmanager_report_rows("SELECT e.id, j.status, EXISTS(SELECT 1 FROM plugin_alarmmanager_decisions AS r WHERE r.event_row_id = e.id AND r.reason NOT IN ('queued', 'no_route')) AS suppressed FROM plugin_alarmmanager_events AS e LEFT JOIN plugin_alarmmanager_jobs AS j ON j.event_row_id = e.id WHERE e.id IN ($placeholders)", $ids), null, 'id');

			foreach ($rows as &$row) {
				if ($row['id'] !== null) {
					$row['integration_status'] = alarmmanager_integration_status($counts[$row['id']] ?? [], $processing[$row['id']]['status'] ?? '', (bool) ($processing[$row['id']]['suppressed'] ?? false));
				}
			}
			unset($row);
		}
	}

	return ['rows' => $rows, 'more' => $more, 'page' => $filters['page']];
}
/** @return array<string, string> Decision reason => short label for the Events Routing column. */
function alarmmanager_decision_labels(): array {
	return [
		'queued'             => __('Queued', 'alarmmanager'),
		'explicit_test_send' => __('Test send', 'alarmmanager'),
		'no_route'           => __('No matching rule', 'alarmmanager'),
		'endpoint_disabled'  => __('Destination disabled', 'alarmmanager'),
		'source_suppressed'  => __('Suppressed by source', 'alarmmanager'),
		'maintenance'        => __('Maintenance window', 'alarmmanager'),
		'acknowledged'       => __('Acknowledged', 'alarmmanager'),
		'cooldown'           => __('Cooldown', 'alarmmanager'),
		'duplicate'          => __('Duplicate', 'alarmmanager'),
		'duplicate_route'    => __('Already routed', 'alarmmanager'),
		'stale'              => __('Out of order', 'alarmmanager'),
		'ignored_episode'    => __('Ignored episode', 'alarmmanager'),
		'historical_only'    => __('History only', 'alarmmanager')
	];
}
/**
 * One routing summary per report row, from local decisions or a collector's mirrored decisions.
 * @param  list<array<string, mixed>> $rows
 * @param  list<int>                  $ids  Local event row IDs on this page.
 * @return list<string>
 */
function alarmmanager_report_routing(array $rows, array $ids): array {
	$decisions = [];

	if ($ids) {
		foreach (alarmmanager_report_rows('SELECT event_row_id, endpoint_id, reason FROM plugin_alarmmanager_decisions WHERE event_row_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids) as $decision) {
			$decisions['local:' . $decision['event_row_id']][] = $decision;
		}
	}
	$remote = array_filter($rows, static fn (array $row): bool => isset($row['remote_entity_id']));

	if ($remote) {
		// Mirrored decisions arrive in the same sync batches as their events; the time bound keeps the scan on remote_recent.
		$params = [min(array_column($remote, 'received_at')), ...array_values(array_unique(array_map('intval', array_column($remote, 'collector_id'))))];

		foreach (alarmmanager_report_rows("SELECT poller_id, instance_uuid, JSON_UNQUOTE(JSON_EXTRACT(definition, '$.event_row_id')) AS event_row_id, JSON_UNQUOTE(JSON_EXTRACT(definition, '$.endpoint_id')) AS endpoint_id, JSON_UNQUOTE(JSON_EXTRACT(definition, '$.reason')) AS reason FROM plugin_alarmmanager_remote_history WHERE entity = 'decision' AND received_at >= ? - INTERVAL 1 HOUR AND poller_id IN (" . implode(',', array_fill(0, count($params) - 1, '?')) . ') ORDER BY entity_id', $params) as $decision) {
			$decisions['remote:' . $decision['poller_id'] . ':' . $decision['instance_uuid'] . ':' . $decision['event_row_id']][] = $decision;
		}
	}
	$names  = array_column(alarmmanager_report_rows('SELECT id, name FROM plugin_alarmmanager_endpoints'), 'name', 'id');
	$labels = alarmmanager_decision_labels();
	$result = [];

	foreach ($rows as $row) {
		$key   = isset($row['remote_entity_id']) ? 'remote:' . $row['collector_id'] . ':' . $row['remote_instance_uuid'] . ':' . $row['remote_entity_id'] : 'local:' . $row['id'];
		$parts = [];

		// Group destinations by reason: "Queued → Slack" or "Cooldown → 2 destinations".
		foreach ($decisions[$key] ?? [] as $decision) {
			$parts[$decision['reason']][] = (int) $decision['endpoint_id'];
		}

		foreach ($parts as $reason => &$endpoints) {
			$endpoints = array_values(array_filter(array_unique($endpoints)));
			$target    = match (count($endpoints)) {
				0       => '',
				1       => ' → ' . ($names[$endpoints[0]] ?? __('Deleted destination #%d', $endpoints[0], 'alarmmanager')),
				default => ' → ' . __('%d destinations', count($endpoints), 'alarmmanager')
			};
			$endpoints = ($labels[$reason] ?? $reason) . $target;
		}
		unset($endpoints);
		$result[] = $parts ? implode(' · ', $parts) : '—';
	}

	return $result;
}
/** @param array<string, int> $counts */
function alarmmanager_integration_status(array $counts, string $processing, bool $suppressed): string {
	$labels = ['completed' => __('Delivered', 'alarmmanager'), 'failed' => __('Failed', 'alarmmanager'), 'leased' => __('Sending', 'alarmmanager'), 'pending' => __('Pending', 'alarmmanager')];
	$parts  = [];

	foreach ($labels as $status => $label) {
		if (!empty($counts[$status])) {
			$parts[] = $counts[$status] . ' ' . $label;
		}
	}

	return $parts ? implode(' · ', $parts) : match ($processing) {
		'failed'  => __('Processing failed', 'alarmmanager'),
		'pending' => __('Pending processing', 'alarmmanager'),
		'leased'  => __('Processing', 'alarmmanager'),
		default   => $suppressed ? __('Suppressed', 'alarmmanager') : __('No matching integration', 'alarmmanager')
	};
}
function alarmmanager_event_detail(array $scope, array $filters): ?array {
	if ($filters['event_id'] < 1 || (($filters['collector_id'] ?? 0) > 0 && $filters['collector_id'] !== alarmmanager_local_poller())) {
		return null;
	}
	[$where, $params] = alarmmanager_report_where($scope, $filters, null, 'e.severity', 'events');
	$row              = alarmmanager_report_row("SELECT LEFT(e.payload, 32769) AS payload FROM plugin_alarmmanager_events AS e WHERE $where LIMIT 1", $params);

	if (!$row) {
		return null;
	}

	if (strlen($row['payload']) > 32768) {
		throw new RuntimeException('event_detail_unavailable');
	}

	$event              = alarmmanager_validate_event(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR));
	$id                 = $filters['event_id'];
	$event['_row_id']   = $id;
	$event['_workflow'] = [
		'processing' => alarmmanager_report_rows('SELECT id, status, attempts, last_error, created_at, available_at FROM plugin_alarmmanager_jobs WHERE event_row_id = ? LIMIT 1', [$id]),
		'deliveries' => alarmmanager_report_rows("SELECT d.id, d.recipient, COALESCE(ep.name, CONCAT('Deleted endpoint #', d.endpoint_id)) AS integration, d.status, d.attempts, d.last_error, JSON_UNQUOTE(JSON_EXTRACT(d.external_reference, '$.id')) AS external_id, JSON_UNQUOTE(JSON_EXTRACT(d.external_reference, '$.url')) AS external_url, d.updated_at FROM plugin_alarmmanager_deliveries AS d LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id WHERE d.event_row_id = ? ORDER BY d.id DESC LIMIT 100", [$id]),
		'attempts'   => alarmmanager_report_rows("SELECT a.id, a.delivery_id, d.recipient, COALESCE(ep.name, CONCAT('Deleted endpoint #', d.endpoint_id)) AS integration, a.attempt_number, a.outcome, a.code, a.duration_ms, a.started_at FROM plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id WHERE d.event_row_id = ? ORDER BY a.id DESC LIMIT 100", [$id]),
		'decisions'  => alarmmanager_report_rows('SELECT rule_id, endpoint_id, reason, created_at FROM plugin_alarmmanager_decisions WHERE event_row_id = ? ORDER BY id DESC LIMIT 100', [$id])
	];

	return $event;
}
function alarmmanager_dashboard_data(array $scope, array $filters): array {
	require_once(__DIR__ . '/queue.php');
	[$where, $params]                = alarmmanager_report_where($scope, $filters);
	[$current, $current_params]      = alarmmanager_report_where($scope, $filters, null, 's.severity');
	$result                          = ['can_admin' => $scope['admin'], 'filters' => $filters, 'historical' => [], 'current' => [], 'panels' => [], 'workers' => []];
	$result['historical']['events']  = alarmmanager_report_row("SELECT COUNT(*) AS events, COALESCE(SUM(e.transition = 'raised'), 0) AS raised, COALESCE(SUM(e.transition = 'recovered'), 0) AS recovered, COALESCE(SUM(e.transition = 'occurrence'), 0) AS occurrences FROM plugin_alarmmanager_events AS e WHERE $where", $params);
	$result['current']['conditions'] = alarmmanager_report_row("SELECT COUNT(*) AS active, COALESCE(SUM(s.acknowledged = 1), 0) AS acknowledged FROM plugin_alarmmanager_states AS s INNER JOIN plugin_alarmmanager_events AS e ON e.id = s.last_event_id WHERE s.active = 1 AND $current", $current_params);
	$bucket                          = 60;

	foreach ([60, 600, 3600, 86400] as $step) {
		$bucket = $step;

		if ($filters['range'] / $bucket <= 240) {
			break;
		}
	}
	$result['bucket_seconds']        = $bucket;
	$result['panels']['transitions'] = alarmmanager_report_rows("SELECT FLOOR(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', e.received_at) / $bucket) * $bucket AS bucket, e.transition AS label, COUNT(*) AS count FROM plugin_alarmmanager_events AS e WHERE $where GROUP BY bucket, e.transition ORDER BY bucket LIMIT 1205", $params);

	foreach (['severity' => 'e.severity', 'source' => 'e.source'] as $panel => $column) {
		$result['panels'][$panel] = alarmmanager_report_rows("SELECT $column AS label, COUNT(*) AS count FROM plugin_alarmmanager_events AS e WHERE $where GROUP BY $column ORDER BY count DESC, label LIMIT 10", $params);
	}

	foreach (['deliveries' => ['d.status', 'plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id', 'd.created_at'], 'attempts' => ['a.outcome', 'plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id', 'a.started_at'], 'suppression' => ['r.reason', 'plugin_alarmmanager_decisions AS r INNER JOIN plugin_alarmmanager_events AS e ON e.id = r.event_row_id', 'r.created_at']] as $panel => [$column, $base, $time]) {
		[$w, $p]                  = alarmmanager_report_where($scope, $filters, $time);
		$result['panels'][$panel] = alarmmanager_report_rows("SELECT $column AS label, COUNT(*) AS count FROM $base WHERE $w GROUP BY $column ORDER BY count DESC, label LIMIT 20", $p);
	}
	[$w, $p]                       = alarmmanager_report_where($scope, $filters, 'a.started_at');
	$result['panels']['endpoints'] = alarmmanager_report_rows("SELECT d.endpoint_id, COALESCE(ep.name, CONCAT('Deleted endpoint #', d.endpoint_id)) AS label, COUNT(*) AS attempts, COALESCE(SUM(a.outcome = 'success'), 0) AS successes, ROUND(AVG(a.duration_ms)) AS average_ms FROM plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id WHERE $w GROUP BY d.endpoint_id, ep.name ORDER BY attempts DESC, d.endpoint_id LIMIT 10", $p);

	foreach (['processing' => ['plugin_alarmmanager_jobs AS j', 'j'], 'delivery' => ['plugin_alarmmanager_deliveries AS j', 'j']] as $queue => [$table, $alias]) {
		[$w, $p]                             = alarmmanager_report_where($scope, $filters, null);
		$result['current']['queues'][$queue] = alarmmanager_report_rows("SELECT j.status AS label, COUNT(*) AS count, COALESCE(MAX(TIMESTAMPDIFF(SECOND, j.created_at, UTC_TIMESTAMP())), 0) AS oldest_age_seconds FROM $table INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id WHERE $w AND j.status IN ('pending', 'leased', 'failed') GROUP BY j.status", $p);
	}

	$result['notifications_paused'] = alarmmanager_notifications_paused();

	if ($scope['admin']) {
		$result['workers'] = alarmmanager_report_rows('SELECT role, status, pid, poller_id, TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP()) AS age_seconds FROM plugin_alarmmanager_workers WHERE poller_id = ? ORDER BY heartbeat_at DESC LIMIT 32', [alarmmanager_local_poller()]);
	}

	return $result;
}
