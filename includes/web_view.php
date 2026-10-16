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

require_once(__DIR__ . '/reports.php');

const ALARMMANAGER_TABS = ['dashboard', 'conditions', 'events', 'deliveries', 'attempts', 'decisions', 'processing'];

function alarmmanager_web_assets(): void {
	global $config;

	print '<link rel="stylesheet" href="' . html_escape($config['url_path'] . 'plugins/alarmmanager/css/dashboard.css' . '?v=0.10.4-notification-queue') . '">';
	print '<script src="' . html_escape($config['url_path'] . 'plugins/alarmmanager/js/dashboard.js' . '?v=0.10.4-notification-queue') . '" defer></script>';
}
/**
 * Filters validated both by the Cacti request-var machinery (persistence in
 * the session) and by the report engine's own whitelists.
 *
 * @return array<string, mixed>
 */
function alarmmanager_view_filters(): array {
	$spec = [
		'range'        => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => 86400, 'options' => ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]],
		'severity'     => ['filter' => FILTER_CALLBACK, 'pageset' => true, 'default' => '', 'options' => ['options' => static fn ($value) => in_array($value, ['critical', 'major', 'minor', 'warning', 'info', 'clear'], true) ? $value : false]],
		'transition'   => ['filter' => FILTER_CALLBACK, 'pageset' => true, 'default' => '', 'options' => ['options' => static fn ($value) => in_array($value, ['raised', 'updated', 'reminder', 'recovered', 'occurrence'], true) ? $value : false]],
		'status'       => ['filter' => FILTER_CALLBACK, 'pageset' => true, 'default' => '', 'options' => ['options' => static fn ($value) => in_array($value, ['pending', 'leased', 'completed', 'failed', 'success', 'retry', 'failure', 'abandoned', 'in_progress'], true) ? $value : false]],
		'source'       => ['filter' => FILTER_VALIDATE_REGEXP, 'pageset' => true, 'default' => '', 'options' => ['options' => ['regexp' => '/\A[A-Za-z0-9_.:@\/-]*\z/D']]],
		'reason'       => ['filter' => FILTER_VALIDATE_REGEXP, 'pageset' => true, 'default' => '', 'options' => ['options' => ['regexp' => '/\A[A-Za-z0-9_.:@\/-]*\z/D']]],
		'device_id'    => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => 0, 'options' => ['options' => ['min_range' => 0, 'max_range' => 2147483647]]],
		'endpoint_id'  => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => 0, 'options' => ['options' => ['min_range' => 0, 'max_range' => 2147483647]]],
		'event_id'     => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => 0, 'options' => ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]],
		'current'      => ['filter' => FILTER_CALLBACK, 'pageset' => true, 'default' => '0', 'options' => ['options' => static fn ($value) => in_array($value, ['0', '1'], true) ? $value : false]],
		'acknowledged' => ['filter' => FILTER_CALLBACK, 'pageset' => true, 'default' => '', 'options' => ['options' => static fn ($value) => in_array($value, ['', '0', '1'], true) ? $value : false]],
	];

	validate_store_request_vars($spec, 'sess_am');

	$values = [];

	foreach (array_keys($spec) as $key) {
		$values[$key] = get_request_var($key, $spec[$key]['default']);
	}

	$values['until'] = get_filter_request_var('until', FILTER_VALIDATE_INT, ['options' => ['min_range' => 946684800, 'max_range' => time() + 60], 'default' => time()]);
	$values['page']  = get_filter_request_var('page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200], 'default' => 1]);
	$values['query'] = get_nfilter_request_var('query') ?? '';
	$values['tab']   = alarmmanager_view_tab();

	return alarmmanager_report_filters($values);
}
/**
 * The report tab, validated as an enum by Cacti's own filter machinery.
 */
function alarmmanager_view_tab(): string {
	return (string) get_filter_request_var('tab', FILTER_CALLBACK, ['options' => static fn ($value) => in_array($value, ALARMMANAGER_TABS, true) ? $value : false, 'default' => 'dashboard']);
}
/**
 * Handle an uncaught page error: JSON payload for the dashboard endpoint, a
 * bare translated notice otherwise. The Cacti chrome is deliberately skipped
 * so a reporting failure cannot cascade into the shell.
 *
 * @return never
 */
function alarmmanager_view_error(Throwable $error): void {
	http_response_code($error instanceof InvalidArgumentException ? 400 : ($error instanceof AlarmManager\StorageException ? 503 : 403));

	$message = __('Report unavailable or permission denied.', 'alarmmanager');

	if (get_nfilter_request_var('action') === 'dashboard_data') {
		header('Content-Type: application/json');
		print json_encode(['error' => $message]) . "\n";
		exit;
	}

	print html_escape($message);
	exit;
}
/**
 * Dashboard statistics as JSON; the JS client drives this with
 * action=dashboard_data so the page shell is never rendered.
 *
 * @param array<string, mixed> $scope
 */
function alarmmanager_dashboard_response(array $scope): void {
	header('Content-Type: application/json');

	try {
		$filters = alarmmanager_view_filters();
		print json_encode(alarmmanager_dashboard_data($scope, $filters), JSON_THROW_ON_ERROR);
		exit;
	} catch (JsonException $error) {
		http_response_code(503);
		print json_encode(['error' => __('Statistics could not be rendered.', 'alarmmanager')]) . "\n";
		exit;
	} catch (Throwable $error) {
		alarmmanager_view_error($error);
	}
}
function alarmmanager_web_open(array $scope, string $tab): void {
	print '<main id="alarmmanager_app" data-theme="' . html_escape((string) get_selected_theme()) . '"><h1>' . __esc('Alarm Manager', 'alarmmanager') . '</h1>';

	if ($tab === 'admin') {
		print '<p><a href="alarmmanager.php">' . __esc('View alarms', 'alarmmanager') . '</a></p>';

		return;
	}

	if ($scope['admin']) {
		print '<p><a href="alarmmanager_admin.php">' . __esc('Administration', 'alarmmanager') . '</a></p>';
	}
	print '<nav class="alarmmanagerTabs" aria-label="' . __esc('Alarm Manager pages', 'alarmmanager') . '">';

	foreach ([
		'dashboard'  => 'Dashboard',
		'conditions' => 'Active conditions',
		'events'     => 'Events',
		'deliveries' => 'Delivery jobs',
		'attempts'   => 'Attempts',
		'decisions'  => 'Routing decisions',
		'processing' => 'Processing'
	] as $key => $label) {
		print '<a ' . ($tab === $key ? 'aria-current="page" ' : '') . 'href="' . html_escape('alarmmanager.php?tab=' . $key) . '">' . __esc($label, 'alarmmanager') . '</a>';
	}

	print '</nav>';
}
function alarmmanager_web_filters(array $filters, string $tab, bool $dashboard = false): void {
	html_start_box($dashboard ? __esc('Alarm statistics', 'alarmmanager') : __esc('Filters', 'alarmmanager'), '100%', false, 3, 'center', '');
	print "<tr class='even noprint'><td class='noprint'><form id='alarmmanager_filters' method='get' action='alarmmanager.php' class='alarmmanagerDashboardBar'>";
	print '<input type="hidden" name="tab" value="' . html_escape($tab) . '">';
	print '<div class="alarmmanagerSearchHeader"><h2>' . __esc($dashboard ? 'Alarm statistics' : 'Filters', 'alarmmanager') . '</h2><div class="alarmmanagerDashboardTimespan"><label>' . __esc('Time range', 'alarmmanager') . ' <select name="range">';

	foreach ([3600 => 'Last hour', 21600 => 'Last 6 hours', 86400 => 'Last 24 hours', 604800 => 'Last 7 days', 2592000 => 'Last 30 days'] as $value => $label) {
		print '<option value="' . $value . '" ' . ($filters['range'] === $value ? 'selected' : '') . '>' . __esc($label, 'alarmmanager') . '</option>';
	}

	print '</select></label></div><label>' . __esc('Refresh', 'alarmmanager') . ' <select id="alarmmanager_refresh"><option value="0">' . __esc('Off', 'alarmmanager') . '</option><option value="30">30s</option><option value="60">60s</option><option value="300">5m</option></select></label></div><div class="alarmmanagerDashboardBarRow">';
	$fields = array_map(static fn (array $field): array => array_diff_key($field, ['column' => true]), alarmmanager_report_query_fields($tab));
	print '</div><fieldset class="alarmmanagerQueryBuilder" data-query-fields="' . html_escape(json_encode($fields, JSON_THROW_ON_ERROR)) . '"><legend>' . __esc('Search conditions', 'alarmmanager') . '</legend><div class="alarmmanagerQueryRows"></div><details class="alarmmanagerQueryRaw"><summary>' . __esc('Advanced filter JSON', 'alarmmanager') . '</summary><label>' . __esc('Filter document', 'alarmmanager') . '<textarea name="query" maxlength="8192">' . html_escape($filters['query'] ?? '') . '</textarea></label></details></fieldset><details class="alarmmanagerQuickFilters"><summary>' . __esc('Quick filters', 'alarmmanager') . '</summary><div class="alarmmanagerDashboardBarRow">';
	print '<label>' . __esc('Source', 'alarmmanager') . ' <input name="source" maxlength="32" value="' . html_escape((string) $filters['source']) . '"></label><label>' . __esc('Severity', 'alarmmanager') . ' <select name="severity"><option value="">' . __esc('All', 'alarmmanager') . '</option>';

	foreach (['critical', 'major', 'minor', 'warning', 'info', 'clear'] as $value) {
		print '<option ' . ($filters['severity'] === $value ? 'selected' : '') . '>' . html_escape($value) . '</option>';
	}

	print '</select></label>';
	print '<label>' . __esc('Device ID', 'alarmmanager') . ' <input name="device_id" type="number" min="0" max="2147483647" value="' . html_escape((string) $filters['device_id']) . '"></label>';

	if (!$dashboard) {
		foreach (['transition', 'status', 'reason', 'endpoint_id', 'event_id', 'acknowledged'] as $key) {
			print '<label>' . __esc($key === 'event_id' ? 'Event row ID' : str_replace('_', ' ', ucfirst($key)), 'alarmmanager') . ' <input name="' . html_escape($key) . '" maxlength="32" value="' . html_escape((string) $filters[$key]) . '"></label>';
		}
	}

	print '</div></details><div class="alarmmanagerDashboardBarRow">';
	print '<input type="hidden" name="current" value="' . ($filters['current'] ? '1' : '0') . '">';
	print '<button type="submit">' . __esc('Apply', 'alarmmanager') . '</button><a href="' . html_escape('alarmmanager.php?tab=' . $tab . '&clear=1') . '">' . __esc('Reset', 'alarmmanager') . '</a></div></form></td></tr>';
	html_end_box(false);
	print '<p class="alarmmanagerHint">' . __esc('Historical window uses UTC. Current conditions and queues ignore the time window.', 'alarmmanager') . '</p>';
}
function alarmmanager_report_url(array $filters, string $tab, array $extra = []): string {
	$values = array_intersect_key($filters, array_flip(['range', 'until', 'source', 'severity', 'transition', 'status', 'reason', 'device_id', 'endpoint_id', 'event_id', 'current', 'acknowledged', 'query']));

	if ($tab === 'events' && isset($extra['event_id'])) {
		unset($values['query']);
	}

	return 'alarmmanager.php?' . http_build_query(array_merge($values, ['tab' => $tab], $extra));
}
function alarmmanager_dashboard_markup(): void {
	print '<div id="alarmmanager_dashboard" aria-live="polite"><p id="alarmmanager_dashboard_status" role="status">' . __esc('Loading statistics…', 'alarmmanager') . '</p><div id="alarmmanager_current" class="alarmmanagerSummary"></div><div id="alarmmanager_historical" class="alarmmanagerSummary"></div><div class="alarmmanagerDashboardGrid">';

	foreach ([
		'transitions' => 'Transitions received',
		'severity'    => 'Severity breakdown',
		'source'      => 'Source breakdown (top 10)',
		'deliveries'  => 'Delivery jobs created',
		'attempts'    => 'Attempts started',
		'suppression' => 'Routing and suppression decisions',
		'endpoints'   => 'Endpoint performance (top 10)',
		'queues'      => 'Current queues',
		'workers'     => 'Current worker health'
	] as $id => $label) {
		print '<article class="alarmmanagerDashboardCard" id="alarmmanager_panel_' . html_escape($id) . '"><header class="alarmmanagerDashboardCardHeader"><h3 class="alarmmanagerDashboardCardTitle">' . __esc($label, 'alarmmanager') . '</h3><button type="button" class="alarmmanagerCardToggle" aria-expanded="true">' . __esc('Collapse', 'alarmmanager') . '</button></header><div class="alarmmanagerCardBody"><div class="alarmmanagerChart" id="alarmmanager_chart_' . html_escape($id) . '" ' . (in_array($id, ['endpoints', 'queues', 'workers'], true) ? 'hidden' : '') . '></div><div class="alarmmanagerLegend" id="alarmmanager_legend_' . html_escape($id) . '"></div></div></article>';
	}

	print '</div></div>';
}
/**
 * Report headers are derived from the row column aliases; the action column
 * is appended for administrative users on interactive kinds.
 */
function alarmmanager_report_table(string $kind, array $result, array $scope, array $filters): void {
	$action_kinds = ['conditions', 'deliveries', 'processing'];

	html_start_box(__esc($kind === 'conditions' ? 'Current active conditions' : ucfirst($kind), 'alarmmanager'), '100%', false, 3, 'center', '');

	if (!$result['rows']) {
		print "<tr class='odd'><td>" . __esc('No matching records.', 'alarmmanager') . '</td></tr>';
		html_end_box(true);

		return;
	}

	$headers          = array_keys($result['rows'][0]);
	$display_text     = [];

	foreach ($headers as $key) {
		$display_text[$key] = __esc($key === 'attempts' ? 'Attempts in current retry cycle' : ucfirst(str_replace('_', ' ', $key)), 'alarmmanager');
	}

	if ($scope['admin'] && in_array($kind, $action_kinds, true)) {
		$display_text['action'] = __esc('Action', 'alarmmanager');
	}

	html_header(array_values($display_text), 1);

	foreach ($result['rows'] as $row) {
		form_alternate_row();

		foreach ($headers as $key) {
			$value = html_escape((string) ($row[$key] ?? '—'));

			if ($key === 'event_row_id' || ($key === 'id' && in_array($kind, ['events', 'conditions'], true))) {
				$link = '<a class="linkOverDark" href="' . html_escape(alarmmanager_report_url($filters, 'events', ['event_id' => $row[$key]])) . '">' . html_escape((string) $row[$key]) . '</a>';
			} elseif ($key === 'external_url' && !empty($row['external_id']) && !empty($row[$key])) {
				require_once(__DIR__ . '/integrations.php');

				try {
					$reference = alarmmanager_external_reference_validate(['id' => $row['external_id'], 'url' => $row[$key]]);
					$link      = '<a class="linkOverDark" href="' . html_escape($reference['url']) . '" target="_blank" rel="noopener noreferrer">' . $value . '</a>';
				} catch (Throwable $error) {
					$link = $value;
				}
			} else {
				$link = $value;
			}

			form_selectable_cell($link, (int) ($row['id'] ?? 0), '', 'left');
		}

		if ($scope['admin'] && in_array($kind, $action_kinds, true)) {
			print '<td>';

			if ($kind === 'conditions') {
				alarmmanager_post_form('acknowledge', ['stream_key' => $row['stream_key'], 'acknowledged' => $row['acknowledged'] ? 'no' : 'yes'], $row['acknowledged'] ? 'Clear acknowledgment' : 'Acknowledge');
			} elseif ($row['status'] === 'failed') {
				alarmmanager_post_form('replay', ['queue' => $kind === 'processing' ? 'processing' : 'delivery', 'id' => $row['id']], 'Replay failed job', true);
			}

			print '</td>';
		}

		form_end_row();
	}

	html_end_box(true);
	print '<p class="alarmmanagerHint">' . __esc('Showing at most 50 records per page.', 'alarmmanager') . '</p>';
	print '<nav class="alarmmanagerPagination" aria-label="' . __esc('Pages', 'alarmmanager') . '">';

	if ($result['page'] > 1) {
		print '<a class="linkEdit" href="' . html_escape(alarmmanager_report_url($filters, $kind, ['page' => $result['page'] - 1])) . '">' . __esc('Previous', 'alarmmanager') . '</a>';
	}

	print '<span>' . __esc('Page %d', $result['page'], 'alarmmanager') . '</span>';

	if ($result['more'] && $result['page'] < 200) {
		print '<a class="linkEdit" href="' . html_escape(alarmmanager_report_url($filters, $kind, ['page' => $result['page'] + 1])) . '">' . __esc('Next', 'alarmmanager') . '</a>';
	}

	print '</nav>';
}
function alarmmanager_event_detail_markup(array $event): void {
	print '<details open class="alarmmanagerDashboardCard"><summary>' . __esc('Selected event details', 'alarmmanager') . '</summary><dl class="alarmmanagerEventDetail">';

	foreach (['event_id' => 'Source event ID', 'source' => 'Source', 'source_instance' => 'Source instance', 'resource_id' => 'Resource', 'alarm_key' => 'Alarm key', 'episode_id' => 'Episode', 'sequence' => 'Sequence', 'transition' => 'Transition', 'severity' => 'Severity', 'occurred_at' => 'Occurred at (UTC)', 'summary' => 'Summary', 'details' => 'Details'] as $key => $label) {
		print '<dt>' . __esc($label, 'alarmmanager') . '</dt><dd>' . html_escape((string) ($event[$key] ?? '—')) . '</dd>';
	}

	foreach (['device' => 'Device metadata', 'context' => 'Source context'] as $key => $label) {
		print '<dt>' . __esc($label, 'alarmmanager') . '</dt><dd><pre>' . html_escape(json_encode($event[$key], JSON_PRETTY_PRINT)) . '</pre></dd>';
	}

	print '</dl></details>';
}
function alarmmanager_csrf_field(): void {
	print '<input type="hidden" name="__csrf_magic" value="' . html_escape(csrf_get_tokens()) . '">';
}
function alarmmanager_post_form(string $action, array $hidden, string $label, bool $confirm = false): void {
	print '<form method="post" action="alarmmanager_admin.php" class="alarmmanagerInlineForm">';
	alarmmanager_csrf_field();
	print '<input type="hidden" name="action" value="' . html_escape($action) . '">';

	foreach ($hidden as $key => $value) {
		print '<input type="hidden" name="' . html_escape($key) . '" value="' . html_escape((string) $value) . '">';
	}

	if ($confirm) {
		print '<label><input type="checkbox" name="confirmed" value="yes" required> ' . __esc('Confirm replay may repeat an external effect', 'alarmmanager') . '</label>';
	}

	print '<button type="submit">' . __esc($label, 'alarmmanager') . '</button></form>';
}
