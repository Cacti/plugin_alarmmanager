<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/
require_once(__DIR__ . '/reports.php');

function alarmmanager_escape($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function alarmmanager_label(string $text): string {
	return alarmmanager_escape(__($text, 'alarmmanager'));
}
function alarmmanager_web_assets(): void {
	global $config;
	print '<link rel="stylesheet" href="' . alarmmanager_escape($config['url_path'] . 'plugins/alarmmanager/css/dashboard.css') . '?v=0.6.0">';
	print '<script src="' . alarmmanager_escape($config['url_path'] . 'plugins/alarmmanager/js/dashboard.js') . '?v=0.6.0" defer></script>';
}
function alarmmanager_web_open(array $scope, string $tab): void {
	$theme = function_exists('get_selected_theme') ? get_selected_theme() : 'modern';
	print '<main id="alarmmanager_app" data-theme="' . alarmmanager_escape($theme) . '"><h1>' . alarmmanager_label('Alarm Manager') . '</h1><nav class="alarmmanagerTabs" aria-label="' . alarmmanager_label('Alarm Manager pages') . '">';

	foreach (['dashboard' => 'Dashboard', 'conditions' => 'Active conditions', 'events' => 'Events', 'deliveries' => 'Delivery jobs', 'attempts' => 'Attempts', 'decisions' => 'Routing decisions'] as $key => $label) {
		print '<a ' . ($tab === $key ? 'aria-current="page" ' : '') . 'href="alarmmanager.php?tab=' . $key . '">' . alarmmanager_label($label) . '</a>';
	}

	if ($scope['admin']) {
		print '<a href="alarmmanager_admin.php" ' . ($tab === 'admin' ? 'aria-current="page"' : '') . '>' . alarmmanager_label('Administration') . '</a>';
	}
	print '</nav>';
}
function alarmmanager_web_filters(array $filters, string $tab, bool $dashboard = false): void {
	print '<form id="alarmmanager_filters" method="get" action="alarmmanager.php" class="alarmmanagerDashboardBar"><input type="hidden" name="tab" value="' . alarmmanager_escape($tab) . '"><div class="alarmmanagerSearchHeader"><h2>' . alarmmanager_label($dashboard ? 'Alarm statistics' : 'Filters') . '</h2><div class="alarmmanagerDashboardTimespan"><label>' . alarmmanager_label('Time range') . ' <select name="range">';

	foreach ([3600 => 'Last hour', 21600 => 'Last 6 hours', 86400 => 'Last 24 hours', 604800 => 'Last 7 days', 2592000 => 'Last 30 days'] as $value => $label) {
		print '<option value="' . $value . '" ' . ($filters['range'] === $value ? 'selected' : '') . '>' . alarmmanager_label($label) . '</option>';
	}
	print '</select></label></div><label>' . alarmmanager_label('Refresh') . ' <select id="alarmmanager_refresh"><option value="0">' . alarmmanager_label('Off') . '</option><option value="30">30s</option><option value="60">60s</option><option value="300">5m</option></select></label></div><div class="alarmmanagerDashboardBarRow">';
	print '<label>' . alarmmanager_label('Mode') . ' <select name="mode">';

	foreach (['centralized', 'observe'] as $mode) {
		print '<option ' . ($filters['mode'] === $mode ? 'selected' : '') . '>' . $mode . '</option>';
	} print '</select></label>';
	print '<label>' . alarmmanager_label('Source') . ' <input name="source" maxlength="32" value="' . alarmmanager_escape($filters['source']) . '"></label><label>' . alarmmanager_label('Severity') . ' <select name="severity"><option value="">' . alarmmanager_label('All') . '</option>';

	foreach (['critical', 'major', 'minor', 'warning', 'info', 'clear'] as $value) {
		print '<option ' . ($filters['severity'] === $value ? 'selected' : '') . '>' . $value . '</option>';
	} print '</select></label>';
	print '<label>' . alarmmanager_label('Device ID') . ' <input name="device_id" type="number" min="0" max="2147483647" value="' . $filters['device_id'] . '"></label>';

	if (!$dashboard) {
		foreach (['transition', 'status', 'reason', 'endpoint_id', 'event_id', 'acknowledged'] as $key) {
			print '<label>' . alarmmanager_label($key === 'event_id' ? 'Event row ID' : str_replace('_', ' ', ucfirst($key))) . ' <input name="' . $key . '" maxlength="32" value="' . alarmmanager_escape($filters[$key]) . '"></label>';
		}
	}
	print '<input type="hidden" name="current" value="' . ($filters['current'] ? '1' : '0') . '">';
	print '<button type="submit">' . alarmmanager_label('Apply') . '</button><a href="alarmmanager.php?tab=' . alarmmanager_escape($tab) . '">' . alarmmanager_label('Reset') . '</a></div></form><p class="alarmmanagerHint">' . alarmmanager_label('Historical window uses UTC. Current conditions and queues ignore the time window. Observe mode never dispatches delivery jobs.') . '</p>';
}
function alarmmanager_report_url(array $filters, string $tab, array $extra = []): string {
	$values = array_intersect_key($filters, array_flip(['range', 'until', 'mode', 'source', 'severity', 'transition', 'status', 'reason', 'device_id', 'endpoint_id', 'event_id', 'current', 'acknowledged']));

	return 'alarmmanager.php?' . http_build_query(array_merge($values, ['tab' => $tab], $extra));
}
function alarmmanager_dashboard_markup(): void {
	print '<div id="alarmmanager_dashboard" aria-live="polite"><p id="alarmmanager_dashboard_status" role="status">' . alarmmanager_label('Loading statistics…') . '</p><div id="alarmmanager_current" class="alarmmanagerSummary"></div><div id="alarmmanager_historical" class="alarmmanagerSummary"></div><div class="alarmmanagerDashboardGrid">';

	foreach (['transitions' => 'Transitions received', 'severity' => 'Severity breakdown', 'source' => 'Source breakdown (top 10)', 'deliveries' => 'Delivery jobs created', 'attempts' => 'Attempts started', 'suppression' => 'Routing and suppression decisions', 'endpoints' => 'Endpoint performance (top 10)', 'queues' => 'Current queues', 'workers' => 'Current worker health'] as $id => $label) {
		print '<article class="alarmmanagerDashboardCard" id="alarmmanager_panel_' . $id . '"><header class="alarmmanagerDashboardCardHeader"><h3 class="alarmmanagerDashboardCardTitle">' . alarmmanager_label($label) . '</h3><button type="button" class="alarmmanagerCardToggle" aria-expanded="true">' . alarmmanager_label('Collapse') . '</button></header><div class="alarmmanagerCardBody"><div class="alarmmanagerChart" id="alarmmanager_chart_' . $id . '" ' . (in_array($id, ['endpoints', 'queues', 'workers'], true) ? 'hidden' : '') . '></div><div class="alarmmanagerLegend" id="alarmmanager_legend_' . $id . '"></div></div></article>';
	}
	print '</div></div>';
}
function alarmmanager_report_table(string $kind, array $result, array $scope, array $filters): void {
	print '<h2>' . alarmmanager_label($kind === 'conditions' ? 'Current active conditions' : ucfirst($kind)) . '</h2><p>' . alarmmanager_label('Showing at most 50 records per page.') . '</p>';

	if (!$result['rows']) {
		print '<p class="alarmmanagerEmpty">' . alarmmanager_label('No matching records.') . '</p>';

		return;
	}
	$headers = array_keys($result['rows'][0]);
	print '<div class="alarmmanagerTableScroll" tabindex="0"><table class="cactiTable alarmmanagerTable"><thead><tr>';

	foreach ($headers as $key) {
		print '<th scope="col">' . alarmmanager_label($key === 'attempts' ? 'Attempts in current retry cycle' : ucfirst(str_replace('_', ' ', $key))) . '</th>';
	}

if ($scope['admin'] && in_array($kind, ['conditions', 'deliveries', 'processing'], true)) {
		print '<th scope="col">' . alarmmanager_label('Action') . '</th>';
	} print '</tr></thead><tbody>';

	foreach ($result['rows'] as $row) {
		print '<tr>';

		foreach ($headers as $key) {
			print '<td>';

			if (($key === 'event_row_id' || ($key === 'id' && in_array($kind, ['events', 'conditions'], true)))) {
				print '<a href="' . alarmmanager_escape(alarmmanager_report_url($filters, 'events', ['event_id' => $row[$key]])) . '">' . alarmmanager_escape($row[$key]) . '</a>';
			} else {
				print alarmmanager_escape($row[$key] ?? '—');
			} print '</td>';
		}

		if ($scope['admin'] && in_array($kind, ['conditions', 'deliveries', 'processing'], true)) {
			print '<td>';

			if ($kind === 'conditions') {
				alarmmanager_post_form('acknowledge', ['stream_key' => $row['stream_key'], 'acknowledged' => $row['acknowledged'] ? 'no' : 'yes'], $row['acknowledged'] ? 'Clear acknowledgment' : 'Acknowledge');
			} elseif ($row['status'] === 'failed') {
				alarmmanager_post_form('replay', ['queue' => $kind === 'processing' ? 'processing' : 'delivery', 'id' => $row['id']], 'Replay failed job', true);
			} print '</td>';
		} print '</tr>';
	}
	print '</tbody></table></div><nav class="alarmmanagerPagination" aria-label="' . alarmmanager_label('Pages') . '">';

	if ($result['page'] > 1) {
		print '<a href="' . alarmmanager_escape(alarmmanager_report_url($filters, $kind, ['page' => $result['page'] - 1])) . '">' . alarmmanager_label('Previous') . '</a>';
	}
	print '<span>' . alarmmanager_label('Page') . ' ' . $result['page'] . '</span>';

	if ($result['more'] && $result['page'] < 200) {
		print '<a href="' . alarmmanager_escape(alarmmanager_report_url($filters, $kind, ['page' => $result['page'] + 1])) . '">' . alarmmanager_label('Next') . '</a>';
	} print '</nav>';
}
function alarmmanager_event_detail_markup(array $event): void {
	print '<details open class="alarmmanagerDashboardCard"><summary>' . alarmmanager_label('Selected event details') . '</summary><dl class="alarmmanagerEventDetail">';

	foreach (['event_id' => 'Source event ID', 'source' => 'Source', 'source_instance' => 'Source instance', 'resource_id' => 'Resource', 'alarm_key' => 'Alarm key', 'episode_id' => 'Episode', 'sequence' => 'Sequence', 'transition' => 'Transition', 'severity' => 'Severity', 'occurred_at' => 'Occurred at (UTC)', 'summary' => 'Summary', 'details' => 'Details'] as $key => $label) {
		print '<dt>' . alarmmanager_label($label) . '</dt><dd>' . alarmmanager_escape($event[$key]) . '</dd>';
	}

	foreach (['device' => 'Device metadata', 'context' => 'Source context'] as $key => $label) {
		print '<dt>' . alarmmanager_label($label) . '</dt><dd><pre>' . alarmmanager_escape(json_encode($event[$key], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre></dd>';
	}
	print '</dl></details>';
}
function alarmmanager_csrf_field(): void {
	if (!function_exists('csrf_get_tokens')) {
		throw new RuntimeException('csrf_unavailable');
	}
	print '<input type="hidden" name="__csrf_magic" value="' . alarmmanager_escape(csrf_get_tokens()) . '">';
}
function alarmmanager_post_form(string $action, array $hidden, string $label, bool $confirm = false): void {
	print '<form method="post" action="alarmmanager_admin.php" class="alarmmanagerInlineForm">';
	alarmmanager_csrf_field();
	print '<input type="hidden" name="action" value="' . alarmmanager_escape($action) . '">';

	foreach ($hidden as $key => $value) {
		print '<input type="hidden" name="' . alarmmanager_escape($key) . '" value="' . alarmmanager_escape($value) . '">';
	}

	if ($confirm) {
		print '<label><input type="checkbox" name="confirmed" value="yes" required> ' . alarmmanager_label('Confirm replay may repeat an external effect') . '</label>';
	}
	print '<button type="submit">' . alarmmanager_label($label) . '</button></form>';
}
