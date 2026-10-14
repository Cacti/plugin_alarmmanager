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
require_once(__DIR__ . '/web_view.php');
require_once(__DIR__ . '/admin.php');
require_once(__DIR__ . '/remote_view.php');
require_once(__DIR__ . '/workers.php');

function alarmmanager_form_start(string $action, int $revision, array $hidden = []): void {
	print '<form method="post" action="alarmmanager_admin.php" class="alarmmanagerEditor">';
	alarmmanager_csrf_field();
	print '<input type="hidden" name="action" value="' . html_escape($action) . '"><input type="hidden" name="revision" value="' . $revision . '">';

	foreach ($hidden as $key => $value) {
		print '<input type="hidden" name="' . html_escape($key) . '" value="' . html_escape($value) . '">';
	}
}
function alarmmanager_input(string $key, string $label, $value = '', string $type = 'text', string $extra = ''): void {
	print '<label>' . __esc($label, 'alarmmanager') . ' <input name="' . $key . '" type="' . $type . '" value="' . html_escape($value) . '" ' . $extra . '></label>';
}
function alarmmanager_checkbox(string $key, string $label, bool $checked = false): void {
	print '<label><input type="checkbox" name="' . $key . '" value="yes" ' . ($checked ? 'checked' : '') . '> ' . __esc($label, 'alarmmanager') . '</label>';
}
function alarmmanager_textarea(string $key, string $label, string $value, int $limit = 131072): void {
	print '<label class="alarmmanagerFullWidth">' . __esc($label, 'alarmmanager') . '<textarea name="' . $key . '" rows="8" maxlength="' . $limit . '" spellcheck="false">' . html_escape($value) . '</textarea></label>';
}
function alarmmanager_json_text($value): string {
	return json_encode($value ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function alarmmanager_endpoint_editor(array $endpoint, int $revision, bool $new = false): void {
	print '<details class="alarmmanagerDashboardCard" ' . ($new ? 'open' : '') . '><summary>' . html_escape(($new ? __('New endpoint', 'alarmmanager') : $endpoint['name']) . ' (#' . $endpoint['id'] . ')') . '</summary>';
	alarmmanager_form_start('endpoint_save', $revision);
	alarmmanager_input('id', 'Endpoint ID', $endpoint['id'], 'number', 'min="1" max="2147483647" required' . ($new ? '' : ' readonly'));
	alarmmanager_input('name', 'Name', $endpoint['name'], 'text', 'maxlength="128" required');
	alarmmanager_checkbox('enabled', 'Enabled', $endpoint['enabled'] ?? true);
	print '<label>' . __esc('Type', 'alarmmanager') . ' <select name="type">';

	foreach (['restapi', 'webhook', 'email', 'script', 'websocket'] as $type) {
		print '<option ' . ($endpoint['type'] === $type ? 'selected' : '') . '>' . $type . '</option>';
	} print '</select></label>';

	foreach (['max_concurrency' => ['Concurrency', 1, 64, 1], 'timeout_seconds' => ['Timeout seconds', 1, 120, 10], 'max_attempts' => ['Attempt budget', 1, 20, 5], 'min_interval_ms' => ['Minimum interval ms', 0, 3600000, 0]] as $key => [$label, $min, $max, $default]) {
		alarmmanager_input($key, $label, $endpoint[$key] ?? $default, 'number', "min=\"$min\" max=\"$max\" required");
	}
	$transport = $endpoint['transport'] ?? [];
	$mapping   = $transport['mapping'] ?? ['default' => []];
	unset($transport['mapping']);
	print '<fieldset class="alarmmanagerFullWidth"><legend>' . __esc('HTTP settings (REST API or webhook)', 'alarmmanager') . '</legend>';
	alarmmanager_input('url', 'URL', $transport['url'] ?? '', 'url', 'maxlength="2048"');
	// The control must not be named "method": a named form control called
	// "method" shadows HTMLFormElement.method and crashes csrf-magic.js.
	print '<label>' . __esc('Method', 'alarmmanager') . ' <select name="http_method">';

	foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
		print '<option ' . (($transport['method'] ?? 'POST') === $method ? 'selected' : '') . '>' . $method . '</option>';
	} print '</select></label>';
	alarmmanager_input('ca_bundle', 'Self-signed certificate / private CA PEM path', $transport['ca_bundle'] ?? '', 'text', 'maxlength="1024" placeholder="/etc/cacti/alarmmanager/internal-ca.pem"');
	alarmmanager_checkbox('credential', 'Use encrypted endpoint credential', $transport['credential'] ?? false);
	alarmmanager_checkbox('allow_http', 'Allow anonymous HTTP', $transport['allow_http'] ?? false);
	print '<p>' . __esc('Certificate and hostname verification remain enabled. Provision the PEM file on every poller. Save secrets on the Credentials page.', 'alarmmanager') . '</p></fieldset>';
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerMapping"><legend>' . __esc('Payload mappings', 'alarmmanager') . '</legend><p>' . __esc('Add destination fields using normalized sources or constants. Each transition can replace the default payload. No PHP or string substitution is evaluated.', 'alarmmanager') . '</p><div class="alarmmanagerMappingBuilder"></div>';
	alarmmanager_textarea('mapping_json', 'Mapping JSON (advanced / no-JavaScript editor)', alarmmanager_json_text($mapping));
	print '</fieldset>';
	alarmmanager_textarea('transport_json', 'Transport options (email, script, HTTP success criteria and timeouts)', alarmmanager_json_text($transport));
	print '<p class="alarmmanagerHint alarmmanagerFullWidth">' . __esc('Email requires to/from, html and attachments. Script requires an absolute executable, argument list and compatibility mode. WebSocket delivery is not implemented. Use the delivery documentation for option shapes.', 'alarmmanager') . '</p><button type="submit">' . __esc('Save endpoint', 'alarmmanager') . '</button></form></details>';
}
function alarmmanager_admin_markup(array $state, string $section): void {
	$config   = $state['configuration'];
	$revision = $state['revision'];
	print '<nav class="alarmmanagerTabs" aria-label="' . __esc('Administration sections', 'alarmmanager') . '">';

	foreach (['endpoints' => 'Endpoints and mappings', 'profiles' => 'Routing profiles', 'producers' => 'Producer integrations', 'collectors' => 'Collectors and history', 'rules' => 'Rules and suppression', 'credentials' => 'Credentials', 'test' => 'Preview and test', 'configuration' => 'Configuration', 'health' => 'Workers and failed processing', 'audit' => 'Audit'] as $key => $label) {
		print '<a href="alarmmanager_admin.php?section=' . $key . '" ' . ($section === $key ? 'aria-current="page"' : '') . '>' . __esc($label, 'alarmmanager') . '</a>';
	} print '</nav><p>' . __esc('Local configuration revision', 'alarmmanager') . ': ' . $revision . '</p>';

	if ($section === 'collectors') {
		alarmmanager_remote_markup();
	} elseif ($section === 'producers') {
		alarmmanager_producer_markup($revision);
	} elseif ($section === 'endpoints') {
		foreach ($config['endpoints'] as $item) {
			alarmmanager_endpoint_editor($item, $revision);
		}
		$ids = array_column($config['endpoints'], 'id');
		$id  = $ids ? max($ids) + 1 : 1;
		alarmmanager_endpoint_editor(['id' => $id, 'name' => '', 'enabled' => false, 'type' => 'restapi'], $revision, true);
	} elseif ($section === 'profiles' || $section === 'rules') {
		$items   = $config[$section];
		$ids     = array_column($items, 'id');
		$items[] = ['id' => $ids ? max($ids) + 1 : 1, 'name' => '', 'enabled' => false, 'endpoints' => [], 'policy' => [], 'profile_id' => 1];

		foreach ($items as $item) {
			print '<details class="alarmmanagerDashboardCard"><summary>' . html_escape($item['name'] ?: __('New item', 'alarmmanager')) . ' (#' . $item['id'] . ')</summary>';
			alarmmanager_form_start($section === 'profiles' ? 'profile_save' : 'rule_save', $revision);
			alarmmanager_input('id', 'ID', $item['id'], 'number', 'min="1" max="2147483647" required');
			alarmmanager_input('name', 'Name', $item['name'], 'text', 'maxlength="128" required');
			alarmmanager_checkbox('enabled', 'Enabled', (bool) $item['enabled']);

			if ($section === 'profiles') {
				print '<label>' . __esc('Destinations (select multiple)', 'alarmmanager') . '<select name="endpoints[]" multiple required>';

				foreach ($config['endpoints'] as $ep) {
					print '<option value="' . $ep['id'] . '" ' . (in_array($ep['id'], $item['endpoints'], true) ? 'selected' : '') . '>' . html_escape($ep['name'] . ' #' . $ep['id']) . '</option>';
				} print '</select></label>';
			} else {
				alarmmanager_input('priority', 'Priority (lower first)', $item['priority'] ?? 100, 'number', 'min="0" max="10000" required');
				print '<label>' . __esc('Profile', 'alarmmanager') . '<select name="profile_id" required>';

				foreach ($config['profiles'] as $profile) {
					print '<option value="' . $profile['id'] . '" ' . ($profile['id'] === $item['profile_id'] ? 'selected' : '') . '>' . html_escape($profile['name']) . '</option>';
				} print '</select></label>';
				alarmmanager_checkbox('stop', 'Stop after this rule', $item['stop'] ?? false);
				alarmmanager_textarea('policy_json', 'Selectors, schedules and suppression policy JSON', alarmmanager_json_text($item['policy']));
			}
			print '<button type="submit">' . __esc('Save', 'alarmmanager') . '</button></form></details>';
		}
	} elseif ($section === 'credentials') {
		$status = alarmmanager_db_rows('SELECT ep.id, ep.name, CASE WHEN c.endpoint_id IS NULL THEN 0 ELSE 1 END AS configured FROM plugin_alarmmanager_endpoints AS ep LEFT JOIN plugin_alarmmanager_credentials AS c ON c.endpoint_id = ep.id WHERE ep.type IN (?, ?) ORDER BY ep.id LIMIT 200', ['restapi', 'webhook']);
		print '<p>' . __esc('Credential values are never loaded into this page. Saving replaces the endpoint record; blank values do not preserve an old password. Use the CLI for protected backup/restore and poller provisioning.', 'alarmmanager') . '</p>';

		foreach ($status as $row) {
			print '<details class="alarmmanagerDashboardCard"><summary>' . html_escape($row['name']) . ' #' . $row['id'] . ' — ' . __esc($row['configured'] ? 'Configured' : 'Not configured', 'alarmmanager') . '</summary>';
			alarmmanager_form_start('credential_save', $revision, ['id' => $row['id']]);
			alarmmanager_textarea('headers', 'Authentication headers JSON (new values)', '{}', 65536);
			alarmmanager_input('basic_username', 'Basic username (optional)', '', 'text', 'autocomplete="off" maxlength="4096"');
			alarmmanager_input('basic_password', 'Basic password (optional)', '', 'password', 'autocomplete="new-password" maxlength="4096"');
			print '<button type="submit">' . __esc('Replace credential', 'alarmmanager') . '</button></form>';
			alarmmanager_post_form('credential_remove', ['id' => $row['id']], 'Remove credential');
			print '</details>';
		}
		alarmmanager_form_start('key_rotate', $revision);
		alarmmanager_checkbox('confirmed', 'Confirm local key rotation; create a protected backup before and after');
		print '<button type="submit">' . __esc('Rotate local key', 'alarmmanager') . '</button></form>';
	} elseif ($section === 'test') {
		foreach (['preview' => 'Preview (no traffic)', 'test_send' => 'Queue explicit test send'] as $action => $label) {
			alarmmanager_form_start($action, $revision);
			print '<h2>' . __esc($label, 'alarmmanager') . '</h2><label>' . __esc('Endpoint', 'alarmmanager') . '<select name="id">';

			foreach ($config['endpoints'] as $ep) {
				print '<option value="' . $ep['id'] . '">' . html_escape($ep['name']) . '</option>';
			} print '</select></label>';
			alarmmanager_textarea('event_json', 'Normalized sample event JSON', file_get_contents(__DIR__ . '/../examples/event.json'), 65536);

			if ($action === 'test_send') {
				alarmmanager_checkbox('confirmed', 'Confirm this will notify the selected endpoint through workers');
			} print '<button type="submit">' . __esc($label, 'alarmmanager') . '</button></form>';
		}
	} elseif ($section === 'configuration') {
		alarmmanager_form_start('configuration_save', $revision);
		alarmmanager_textarea('configuration_json', 'Complete local routing configuration (no secrets)', alarmmanager_json_text($config), 1048576);
		print '<button type="submit">' . __esc('Apply configuration', 'alarmmanager') . '</button></form><p>' . __esc('Removed IDs are disabled, preserving history. Concurrent edits reject stale revisions. Provision each remote poller explicitly.', 'alarmmanager') . '</p>';
	} elseif ($section === 'health') {
		// Same shared metrics used by cli/status.php; rendered as Cacti tables.
		$metrics = alarmmanager_metrics();
		html_start_box(__esc('Local queue health', 'alarmmanager'), '100%', false, 3, 'center', '');
		html_header([__esc('Queue', 'alarmmanager'), __esc('Status', 'alarmmanager'), __esc('Jobs', 'alarmmanager'), __esc('Oldest job age (seconds)', 'alarmmanager'), __esc('Retries', 'alarmmanager')], 1);

		if ($metrics['queues'] !== []) {
			foreach ($metrics['queues'] as $queue => $statuses) {
				foreach ($statuses as $row) {
					form_alternate_row();
					form_selectable_cell(html_escape($queue), 0, '', 'left');
					form_selectable_cell(html_escape((string) $row['status']), 0, '', 'left');
					form_selectable_cell(html_escape((string) $row['jobs']), 0, '', 'right');
					form_selectable_cell(html_escape((string) $row['oldest_age_seconds']), 0, '', 'right');
					form_selectable_cell(html_escape((string) $row['retries']), 0, '', 'right');
					form_end_row();
				}
			}
		} else {
			print "<tr class='odd'><td>" . __esc('No queue activity recorded.', 'alarmmanager') . '</td></tr>';
		}
		html_end_box(true);
		html_start_box(__esc('Local worker health', 'alarmmanager'), '100%', false, 3, 'center', '');
		html_header([__esc('Worker', 'alarmmanager'), __esc('Role', 'alarmmanager'), __esc('Poller', 'alarmmanager'), __esc('PID', 'alarmmanager'), __esc('Status', 'alarmmanager'), __esc('Current job', 'alarmmanager'), __esc('Heartbeat age (seconds)', 'alarmmanager')], 1);

		if ($metrics['workers'] !== []) {
			foreach ($metrics['workers'] as $row) {
				form_alternate_row();
				form_selectable_cell(html_escape((string) $row['worker_id']), 0, '', 'left');
				form_selectable_cell(html_escape((string) $row['role']), 0, '', 'left');
				form_selectable_cell(html_escape((string) $row['poller_id']), 0, '', 'right');
				form_selectable_cell(html_escape((string) $row['pid']), 0, '', 'right');
				form_selectable_cell(html_escape((string) $row['status']), 0, '', 'left');
				form_selectable_cell(html_escape((string) ($row['current_job_id'] ?? '—')), 0, '', 'right');
				form_selectable_cell(html_escape((string) $row['heartbeat_age_seconds']), 0, '', 'right');
				form_end_row();
			}
		} else {
			print "<tr class='odd'><td>" . __esc('No local worker heartbeats recorded.', 'alarmmanager') . '</td></tr>';
		}
		html_end_box(true);
		print '<p class="alarmmanagerHint">' . __esc('Completed processing jobs in the last 300 seconds', 'alarmmanager') . ': ' . $metrics['completed_last_300_seconds']['processing'] . ' — ' . __esc('Completed delivery jobs in the last 300 seconds', 'alarmmanager') . ': ' . $metrics['completed_last_300_seconds']['delivery'] . ' — ' . __esc('Processing streams blocked behind a failed predecessor', 'alarmmanager') . ': ' . $metrics['processing_blocked_by_failed_predecessor'] . '</p>';

		if ($metrics['blocked_destination_types'] !== []) {
			print '<p class="alarmmanagerHint">' . __esc('Pending jobs for destination types without a qualified transport', 'alarmmanager') . ': ';

			foreach ($metrics['blocked_destination_types'] as $index => $row) {
				print ($index > 0 ? ', ' : '') . html_escape((string) $row['type']) . ' (' . (int) $row['pending_jobs'] . ')';
			}
			print '</p>';
		}
		print '<p><a href="alarmmanager.php?tab=processing&status=failed">' . __esc('Failed processing jobs and replay', 'alarmmanager') . '</a></p><p><a href="alarmmanager.php?tab=deliveries&status=failed">' . __esc('Failed deliveries and replay', 'alarmmanager') . '</a></p>';
	} else {
		$rows = alarmmanager_db_rows('SELECT id, user_id, action, resource_id, created_at FROM plugin_alarmmanager_audit ORDER BY id DESC LIMIT 50');
		html_start_box(__esc('Latest 50 administration actions', 'alarmmanager'), '100%', false, 3, 'center', '');
		html_header([__esc('ID', 'alarmmanager'), __esc('User ID', 'alarmmanager'), __esc('Action', 'alarmmanager'), __esc('Resource ID', 'alarmmanager'), __esc('Recorded at', 'alarmmanager')], 1);

		if ($rows !== []) {
			foreach ($rows as $row) {
				form_alternate_row();
				form_selectable_cell(html_escape((string) $row['id']), 0, '', 'right');
				form_selectable_cell(html_escape((string) $row['user_id']), 0, '', 'right');
				form_selectable_cell(html_escape((string) $row['action']), 0, '', 'left');
				form_selectable_cell(html_escape((string) $row['resource_id']), 0, '', 'right');
				form_selectable_cell(html_escape((string) $row['created_at']), 0, '', 'left');
				form_end_row();
			}
		} else {
			print "<tr class='odd'><td>" . __esc('No administration actions recorded.', 'alarmmanager') . '</td></tr>';
		}
		html_end_box(true);
	}
}
