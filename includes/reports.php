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
	$result = ['range' => $range, 'until' => $until, 'from' => gmdate('Y-m-d H:i:s', $until - $range), 'to' => gmdate('Y-m-d H:i:s', $until), 'mode' => $input['mode'] ?? 'centralized', 'page' => 1, 'current' => (string) ($input['current'] ?? '0') === '1'];

	if (!in_array((string) ($input['current'] ?? '0'), ['0', '1'], true)) {
		throw new InvalidArgumentException('invalid_filter');
	}

	if (!in_array($result['mode'], ['centralized', 'observe'], true)) {
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

	foreach (['device_id', 'endpoint_id', 'event_id', 'page'] as $key) {
		$value        = $input[$key] ?? ($key === 'page' ? 1 : 0);
		$result[$key] = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $key === 'page' ? 1 : 0, 'max_range' => $key === 'page' ? 200 : ($key === 'event_id' ? PHP_INT_MAX : 2147483647)]]);

		if ($result[$key] === false) {
			throw new InvalidArgumentException('invalid_filter');
		}
	}

	return $result;
}
function alarmmanager_report_where(array $scope, array $filters, ?string $time_column = 'e.received_at', string $severity_column = 'e.severity'): array {
	$where  = ['(' . $scope['device_sql'] . ')', 'e.mode = ?'];
	$params = [$filters['mode']];

	if ($time_column !== null) {
		$where[]  = "$time_column >= ? AND $time_column < ?";
		$params[] = $filters['from'];
		$params[] = $filters['to'];
	}

	foreach (['source' => 'e.source', 'severity' => $severity_column, 'transition' => 'e.transition', 'device_id' => 'e.device_id', 'event_id' => 'e.id'] as $key => $column) {
		if ($filters[$key] !== '' && $filters[$key] !== 0) {
			$where[]  = "$column = ?";
			$params[] = $filters[$key];
		}
	}

	return [implode(' AND ', $where), $params];
}
/** Every report joins the event and applies the same visibility predicate before LIMIT. */
function alarmmanager_report_list(string $kind, array $scope, array $filters): array {
	$base     = 'plugin_alarmmanager_events AS e';
	$order    = 'e.id';
	$time     = 'e.received_at';
	$severity = 'e.severity';
	$columns  = 'e.id, e.received_at, e.source, e.transition, e.severity, e.device_id, e.event_id, JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.summary\')) AS summary, e.mode';

	switch ($kind) {
		case 'events': break;
		case 'conditions': $base = 'plugin_alarmmanager_states AS s INNER JOIN plugin_alarmmanager_events AS e ON e.id = s.last_event_id';
			$columns                = 's.stream_key, s.updated_at, s.active, s.acknowledged, s.severity, s.summary, e.id, e.source, e.device_id, e.mode';
			$order                  = 's.updated_at DESC, e.id';
			$time                   = null;
			$severity               = 's.severity';

			break;
		case 'deliveries': $base = 'plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id INNER JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id';
			$columns                = 'd.id, d.event_row_id, d.created_at, d.updated_at, d.endpoint_id, ep.name AS endpoint, d.status, d.attempts, d.last_error, JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.summary\')) AS summary, e.device_id, e.mode';
			$order                  = 'd.id';
			$time                   = 'd.created_at';

			break;
		case 'attempts': $base = 'plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id';
			$columns              = 'a.id, a.delivery_id, a.attempt_number, a.outcome, a.code, a.started_at, a.duration_ms, d.endpoint_id, e.id AS event_row_id, e.device_id';
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

	if ($kind === 'events' && $filters['event_id'] > 0) {
		$time = null;
	}
	[$where, $params] = alarmmanager_report_where($scope, $filters, $time, $severity);

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
	$offset = ($filters['page'] - 1) * 50;
	$rows   = alarmmanager_report_rows("SELECT $columns FROM $base WHERE $where ORDER BY $order DESC LIMIT 51 OFFSET $offset", $params);

	return ['rows' => array_slice($rows, 0, 50), 'more' => count($rows) > 50, 'page' => $filters['page']];
}
function alarmmanager_event_detail(array $scope, array $filters): ?array {
	if ($filters['event_id'] < 1) {
		return null;
	}
	[$where, $params] = alarmmanager_report_where($scope, $filters, null);
	$row              = alarmmanager_report_row("SELECT LEFT(e.payload, 32769) AS payload FROM plugin_alarmmanager_events AS e WHERE $where LIMIT 1", $params);

	if (!$row) {
		return null;
	}

	if (strlen($row['payload']) > 32768) {
		throw new RuntimeException('event_detail_unavailable');
	}

	return alarmmanager_validate_event(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR));
}
function alarmmanager_dashboard_data(array $scope, array $filters): array {
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
	$result['panels']['endpoints'] = alarmmanager_report_rows("SELECT ep.id AS endpoint_id, ep.name AS label, COUNT(*) AS attempts, COALESCE(SUM(a.outcome = 'success'), 0) AS successes, ROUND(AVG(a.duration_ms)) AS average_ms FROM plugin_alarmmanager_attempts AS a INNER JOIN plugin_alarmmanager_deliveries AS d ON d.id = a.delivery_id INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id INNER JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id WHERE $w GROUP BY ep.id, ep.name ORDER BY attempts DESC, ep.id LIMIT 10", $p);

	foreach (['processing' => ['plugin_alarmmanager_jobs AS j', 'j'], 'delivery' => ['plugin_alarmmanager_deliveries AS j', 'j']] as $queue => [$table, $alias]) {
		[$w, $p]                             = alarmmanager_report_where($scope, $filters, null);
		$result['current']['queues'][$queue] = alarmmanager_report_rows("SELECT j.status AS label, COUNT(*) AS count, COALESCE(MAX(TIMESTAMPDIFF(SECOND, j.created_at, UTC_TIMESTAMP())), 0) AS oldest_age_seconds FROM $table INNER JOIN plugin_alarmmanager_events AS e ON e.id = j.event_row_id WHERE $w AND j.status IN ('pending', 'leased', 'failed') GROUP BY j.status", $p);
	}

	if ($scope['admin']) {
		$result['workers'] = alarmmanager_report_rows('SELECT role, status, pid, poller_id, TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP()) AS age_seconds FROM plugin_alarmmanager_workers WHERE poller_id = ? ORDER BY heartbeat_at DESC LIMIT 32', [alarmmanager_local_poller()]);
	}

	return $result;
}
