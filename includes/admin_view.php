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
require_once(__DIR__ . '/remote.php');
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
	print '<details class="alarmmanagerDashboardCard" ' . ($new ? 'open' : '') . '><summary>' . html_escape($new ? __('New endpoint', 'alarmmanager') : $endpoint['name']) . '</summary>';
	alarmmanager_form_start('endpoint_save', $revision, $new ? [] : ['id' => $endpoint['id']]);
	alarmmanager_input('name', 'Name', $endpoint['name'], 'text', 'maxlength="128" required');
	alarmmanager_checkbox('enabled', 'Enabled', $endpoint['enabled'] ?? true);
	print '<input type="hidden" name="endpoint_fields" value="yes">';
	print '<label>' . __esc('Send using', 'alarmmanager') . ' <select name="type">';

	foreach ([($endpoint['type'] === 'webhook' ? 'webhook' : 'restapi') => 'Webhook / REST API', 'email' => 'Email', 'script' => 'Script'] + ($endpoint['type'] === 'websocket' ? ['websocket' => 'WebSocket (unsupported, saved)'] : []) as $type => $label) {
		print '<option value="' . $type . '" ' . ($endpoint['type'] === $type ? 'selected' : '') . '>' . __esc($label, 'alarmmanager') . '</option>';
	} print '</select></label>';

	$transport = $endpoint['transport'] ?? [];
	$mapping   = $transport['mapping'] ?? ($new ? ['default' => [['to' => 'hostname', 'source' => 'device.hostname'], ['to' => 'summary', 'source' => 'alarm.summary'], ['to' => 'severity', 'source' => 'alarm.severity'], ['to' => 'state', 'source' => 'alarm.transition']]] : ['default' => []]);
	unset($transport['mapping']);
	print '<fieldset class="alarmmanagerFullWidth" data-endpoint-types="restapi webhook"><legend>' . __esc('Destination', 'alarmmanager') . '</legend>';
	alarmmanager_input('url', 'URL', $transport['url'] ?? '', 'url', 'maxlength="2048"');
	print '</fieldset>';
	print '<div class="alarmmanagerFullWidth" data-endpoint-types="restapi webhook">';
	alarmmanager_auth_editor(!empty($transport['credential']) ? 'keep' : 'none');
	print '</div><fieldset class="alarmmanagerFullWidth" data-endpoint-types="email"><legend>' . __esc('Email settings', 'alarmmanager') . '</legend>';
	foreach (['to' => 'Recipients', 'from' => 'Sender email (blank uses Cacti default)'] as $key => $label) {
		$value = $transport[$key] ?? '';
		alarmmanager_input('email_' . $key, $label, is_array($value) ? alarmmanager_json_text($value) : $value, 'text', 'maxlength="8192"' . ($key === 'to' ? ' data-list-editor="recipients"' : ''));
	}
	alarmmanager_checkbox('email_html', 'HTML email', $transport['html'] ?? false);
	print '<p>' . __esc('Delivery uses the Cacti mail settings. Add the people who should receive these alarms.', 'alarmmanager') . '</p></fieldset>';
	print '<fieldset class="alarmmanagerFullWidth" data-endpoint-types="script"><legend>' . __esc('Script settings', 'alarmmanager') . '</legend>';
	alarmmanager_input('script_executable', 'Absolute script / executable path', $transport['executable'] ?? '', 'text', 'maxlength="1024" placeholder="/usr/local/bin/notify-alarm"');
	print '<div data-list-editor="arguments">';
	alarmmanager_textarea('script_arguments', 'Arguments', json_encode($transport['arguments'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	print '</div>';
	print '<label>' . __esc('Script input mode', 'alarmmanager') . ' <select name="script_compatibility">';
	foreach (['json' => 'JSON on standard input', 'syslog_ticket' => 'Syslog ticket arguments and JSON input'] as $value => $label) {
		print '<option value="' . $value . '"' . (($transport['compatibility'] ?? 'json') === $value ? ' selected' : '') . '>' . __esc($label, 'alarmmanager') . '</option>';
	}
	print '</select></label><p>' . __esc('The executable must exist and be executable by the worker on every delivering poller. Arguments are passed directly without a shell.', 'alarmmanager') . '</p></fieldset>';
	print '<p class="alarmmanagerFullWidth" data-endpoint-types="websocket">' . __esc('WebSocket delivery is not implemented.', 'alarmmanager') . '</p>';
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerMapping"><legend>' . __esc('Message fields', 'alarmmanager') . '</legend><p>' . __esc('Choose the alarm information to send. Email uses Subject and Message; API destinations use the field names required by the receiving service.', 'alarmmanager') . '</p><div class="alarmmanagerMappingBuilder"></div>';
	print '<details class="alarmmanagerRawMapping"><summary>' . __esc('Advanced message configuration', 'alarmmanager') . '</summary>';
	alarmmanager_textarea('mapping_json', 'Message configuration JSON', alarmmanager_json_text($mapping));
	print '</details>';
	print '</fieldset>';
	print '<details class="alarmmanagerFullWidth alarmmanagerAdvanced"><summary>' . __esc('Advanced delivery settings', 'alarmmanager') . '</summary>';
	foreach (['timeout_seconds' => ['Delivery timeout (seconds)', 1, 120, 10], 'max_attempts' => ['Maximum delivery attempts', 1, 20, 5], 'min_interval_ms' => ['Minimum time between deliveries (milliseconds)', 0, 3600000, 0]] as $key => [$label, $min, $max, $default]) {
		alarmmanager_input($key, $label, $endpoint[$key] ?? $default, 'number', "min=\"$min\" max=\"$max\" required");
	}
	print '<fieldset data-endpoint-types="restapi webhook"><legend>' . __esc('Connection options', 'alarmmanager') . '</legend>';
	// The control must not be named "method": a named form control called
	// "method" shadows HTMLFormElement.method and crashes csrf-magic.js.
	print '<label>' . __esc('Method', 'alarmmanager') . ' <select name="http_method">';

	foreach (['POST', 'PUT', 'PATCH'] as $method) {
		print '<option ' . (($transport['method'] ?? 'POST') === $method ? 'selected' : '') . '>' . $method . '</option>';
	} print '</select></label>';
	alarmmanager_input('ca_bundle', 'Custom certificate authority (PEM file path)', $transport['ca_bundle'] ?? '', 'text', 'maxlength="1024" placeholder="/etc/cacti/alarmmanager/internal-ca.pem"');
	alarmmanager_checkbox('allow_http', 'Allow unencrypted HTTP', $transport['allow_http'] ?? false);
	print '<p>' . __esc('Certificate and hostname verification remain enabled. Provision the PEM file on every poller. Saved credentials are never displayed.', 'alarmmanager') . '</p>';
	print '</fieldset><details><summary>' . __esc('Other delivery options', 'alarmmanager') . '</summary>';
	alarmmanager_textarea('transport_json', 'Delivery options JSON', alarmmanager_json_text($transport));
	print '</details></details><a href="alarmmanager_admin.php?section=test">' . __esc('Preview or test a saved destination', 'alarmmanager') . '</a>';
	print '<button type="submit">' . __esc('Save endpoint', 'alarmmanager') . '</button></form></details>';
}
function alarmmanager_admin_markup(array $state, string $section): void {
	$config   = $state['configuration'];
	$revision = $state['revision'];
	print '<nav class="alarmmanagerTabs" aria-label="' . __esc('Administration sections', 'alarmmanager') . '">';

	foreach (['endpoints' => 'Endpoints and mappings', 'profiles' => 'Routing profiles', 'producers' => 'Producer integrations', 'collectors' => 'Collectors and history', 'rules' => 'Rules and suppression', 'credentials' => 'Credentials', 'test' => 'Preview and test', 'configuration' => 'Configuration', 'daemon' => 'Daemon', 'health' => 'Workers and failed processing'] as $key => $label) {
		print '<a href="alarmmanager_admin.php?section=' . $key . '" ' . ($section === $key ? 'aria-current="page"' : '') . '>' . __esc($label, 'alarmmanager') . '</a>';
	} print '</nav><p>' . __esc('Local configuration revision', 'alarmmanager') . ': ' . $revision . '</p>';

	if ($section === 'collectors') {
		alarmmanager_remote_markup();
	} elseif ($section === 'producers') {
		alarmmanager_producer_markup($revision, $config['profiles']);
	} elseif ($section === 'endpoints') {
		foreach ($config['endpoints'] as $item) {
			alarmmanager_endpoint_editor($item, $revision);
		}
		alarmmanager_endpoint_editor(['id' => 0, 'name' => '', 'enabled' => false, 'type' => 'restapi'], $revision, true);
	} elseif ($section === 'profiles' || $section === 'rules') {
		$items   = $config[$section];
		$items[] = ['id' => 0, 'name' => '', 'enabled' => false, 'endpoints' => [], 'policy' => [], 'profile_id' => 1];

		foreach ($items as $item) {
			$new = (int) $item['id'] === 0;
			print '<details class="alarmmanagerDashboardCard"' . ($new ? ' open' : '') . '><summary>' . html_escape($new ? __($section === 'profiles' ? 'New profile' : 'New rule', 'alarmmanager') : $item['name'] . ' (#' . $item['id'] . ')') . '</summary>';
			alarmmanager_form_start($section === 'profiles' ? 'profile_save' : 'rule_save', $revision, $new ? [] : ['id' => $item['id']]);
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

		if (!$status) {
			print '<p>' . __esc('No REST API or webhook endpoints have been saved. Create an endpoint to enter its bearer token, Basic authentication username and password, or API-key headers.', 'alarmmanager') . '</p><p><a href="alarmmanager_admin.php?section=endpoints">' . __esc('Create endpoint', 'alarmmanager') . '</a></p>';
		}

		foreach ($status as $row) {
			print '<details class="alarmmanagerDashboardCard"><summary>' . html_escape($row['name']) . ' #' . $row['id'] . ' — ' . __esc($row['configured'] ? 'Configured' : 'Not configured', 'alarmmanager') . '</summary>';
			alarmmanager_form_start('credential_save', $revision, ['id' => $row['id']]);
			alarmmanager_auth_editor('bearer', true);
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
	} elseif ($section === 'daemon') {
		$settings = alarmmanager_supervisor_options();
		$status   = alarmmanager_daemon_status();
		print '<h2>' . __esc('Daemon', 'alarmmanager') . '</h2><p>' . __esc('Supervisor', 'alarmmanager') . ': ' . __esc($status ? 'Running' : 'Not running', 'alarmmanager') . '</p>';

		if ($status) {
			print '<p>' . __esc('Active processing workers', 'alarmmanager') . ': ' . (int) $status['processing_workers'] . ' / ' . __esc('Active delivery workers', 'alarmmanager') . ': ' . (int) $status['delivery_workers'] . '</p>';
		} else {
			print '<p>' . __esc('Install and start the Alarm Manager service on this collector before enabling daemon workers.', 'alarmmanager') . '</p>';
		}
		alarmmanager_form_start('daemon_save', $revision);
		alarmmanager_checkbox('daemon_enabled', 'Enable daemon', $settings['enabled']);
		alarmmanager_input('processing_workers', 'Processing workers', $settings['processing_workers'], 'number', 'min="1" max="64" required');
		alarmmanager_input('delivery_workers', 'Delivery workers', $settings['delivery_workers'], 'number', 'min="0" max="64" required');
		print '<p class="alarmmanagerHint alarmmanagerFullWidth">' . __esc('Changes apply automatically within a few seconds. Disabling the daemon lets current jobs finish and returns processing to the Cacti poller.', 'alarmmanager') . '</p><button type="submit">' . __esc('Save daemon settings', 'alarmmanager') . '</button></form>';
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
	}
}

/** @param array<int, array<string, mixed>> $profiles */
function alarmmanager_producer_markup(int $revision, array $profiles): void {
	print '<p>' . __esc('Pending producer receipts', 'alarmmanager') . ': ' . (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_producer_receipts WHERE status = 'pending'", [], '', false) . '. ' . __esc('Use the producer CLI to retry pending receipts after database recovery.', 'alarmmanager') . '</p>';
	$rows = alarmmanager_db_rows('SELECT source, scope, resource_id, revision, definition FROM plugin_alarmmanager_producer_config ORDER BY source, scope, resource_id LIMIT 257');
	print '<p>' . __esc('Legacy is the default. Resource overrides template overrides plugin default. Saved policy remains intact if Alarm Manager is disabled; new notifications then use legacy fallback. Accepted notifications retain centralized ownership.', 'alarmmanager') . '</p>';
	print '<p>' . __esc('Centralized channel handoff replaces the selected source delivery with the routing profile endpoints. Configure recipients, templates, attachments and reviewed script wrappers on those endpoints before enabling it. Unselected source channels retain their existing behavior.', 'alarmmanager') . '</p>';

	if (count($rows) > 256) {
		throw new RuntimeException('producer_configuration_limit');
	}
	$rows[] = ['source' => 'thold', 'scope' => 'default', 'resource_id' => 0, 'revision' => 0, 'definition' => '{}'];

	foreach ($rows as $row) {
		$definition = AlarmManagerProducerBridge::validate(json_decode($row['definition'], true, 8, JSON_THROW_ON_ERROR));
		$new        = (int) $row['revision'] === 0;
		print '<details class="alarmmanagerDashboardCard" ' . ($new ? 'open' : '') . '><summary>' . __esc($new ? 'New producer policy' : $row['source'] . ' ' . $row['scope'] . ' #' . $row['resource_id'], 'alarmmanager') . '</summary>';
		alarmmanager_form_start('producer_save', $revision, ['producer_revision' => $row['revision']]);

		foreach (['source' => ['thold', 'syslog'], 'scope' => ['default', 'template', 'resource'], 'mode' => ['legacy', 'observe', 'centralized']] as $field => $options) {
			$value = $field === 'mode' ? $definition['mode'] : $row[$field];
			print '<label>' . __esc(ucfirst($field), 'alarmmanager') . ' <select name="' . $field . '" ' . (!$new && $field !== 'mode' ? 'disabled' : '') . '>';

			foreach ($options as $option) {
				print '<option ' . ($value === $option ? 'selected' : '') . '>' . $option . '</option>';
			} print '</select></label>';

			if (!$new && $field !== 'mode') {
				print '<input type="hidden" name="' . $field . '" value="' . html_escape($value) . '">';
			}
		}
		alarmmanager_input('resource_id', 'Resource or template ID (default uses 0)', $row['resource_id'], 'number', 'min="0" required' . ($new ? '' : ' readonly'));
		print '<label>' . __esc('Routing profile', 'alarmmanager') . ' <select name="profile_id"><option value="0">' . __esc('Ordinary matching rules (observe mode)', 'alarmmanager') . '</option>';

		foreach ($profiles as $profile) {
			print '<option value="' . $profile['id'] . '"' . ((int) $definition['profile_id'] === (int) $profile['id'] ? ' selected' : '') . '>' . html_escape($profile['name']) . '</option>';
		}
		print '</select></label>';

		foreach (['email', 'script', 'ticket'] as $channel) {
			print '<label><input type="checkbox" name="channels[]" value="' . $channel . '" ' . (in_array($channel, $definition['channels'], true) ? 'checked' : '') . '> ' . __esc('Hand off ' . $channel, 'alarmmanager') . '</label>';
		}
		alarmmanager_checkbox('complete_history', 'Include source-logged suppressed notification events', $definition['complete_history']);
		alarmmanager_checkbox('migration_confirmed', 'I have configured the replacement endpoints and reviewed recipient, template, attachment and script behavior');

		if (!$new) {
			alarmmanager_checkbox('inherit', 'Remove this override and inherit the parent policy');
		}
		print '<button type="submit">' . __esc('Save producer policy', 'alarmmanager') . '</button></form></details>';
	}
}

function alarmmanager_remote_markup(): void {
	print '<h2>' . __esc('Collectors and remote history', 'alarmmanager') . '</h2><p>' . __esc('Each collector owns its local delivery. Imported history records the original events and outcomes. Collector history does not create central delivery jobs.', 'alarmmanager') . '</p>';
	$peers = alarmmanager_db_rows('SELECT poller_id, instance_uuid, enabled, last_seen, TIMESTAMPDIFF(SECOND, last_seen, UTC_TIMESTAMP()) AS age_seconds FROM plugin_alarmmanager_sync_peers ORDER BY poller_id LIMIT 256');

	foreach ($peers as $peer) {
		$status = !$peer['enabled'] ? 'Disabled' : ($peer['last_seen'] === null ? 'Not yet seen' : ((int) $peer['age_seconds'] <= 90 ? 'Online' : 'Offline'));
		print '<p>' . __esc('Collector', 'alarmmanager') . ' ' . (int) $peer['poller_id'] . ': ' . __esc($status, 'alarmmanager') . ' — ' . html_escape($peer['last_seen'] ?? '') . '</p>';
	}
	$grants = alarmmanager_db_rows('SELECT device_id, poller_id, epoch, owner, mode, valid_from, valid_until FROM plugin_alarmmanager_device_owners ORDER BY device_id LIMIT 100');
	print '<details><summary>' . __esc('Device ownership grants (first 100)', 'alarmmanager') . '</summary><pre>' . html_escape(json_encode($grants, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre></details>';
	$poller = get_filter_request_var('collector', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647], 'default' => 0]);
	$entity = get_filter_request_var('entity', FILTER_CALLBACK, ['options' => static fn ($value) => is_string($value) && isset(alarmmanager_sync_entities()[$value]) ? $value : false, 'default' => 'event']);
	print '<form method="get"><input type="hidden" name="section" value="collectors"><label>' . __esc('Collector (0 selects all)', 'alarmmanager') . ' <input name="collector" type="number" min="0" value="' . $poller . '"></label><label>' . __esc('Record type', 'alarmmanager') . ' <select name="entity">';

	foreach (array_keys(alarmmanager_sync_entities()) as $type) {
		print '<option ' . ($type === $entity ? 'selected' : '') . '>' . $type . '</option>';
	} print '</select></label><button>' . __esc('Filter', 'alarmmanager') . '</button></form>';
	$rows = alarmmanager_db_rows('SELECT poller_id, instance_uuid, entity_id, revision, definition, received_at FROM plugin_alarmmanager_remote_history WHERE entity = ?' . ($poller ? ' AND poller_id = ?' : '') . ' ORDER BY received_at DESC, entity_id DESC LIMIT 50', $poller ? [$entity, $poller] : [$entity]);
	print '<p>' . __esc('Latest 50 matching remote records; original IDs and delivery ownership are preserved.', 'alarmmanager') . '</p>';

	foreach ($rows as $row) {
		print '<details class="alarmmanagerDashboardCard"><summary>' . __esc('Collector', 'alarmmanager') . ' ' . (int) $row['poller_id'] . ' / ' . html_escape($entity) . ' #' . (int) $row['entity_id'] . ' — ' . html_escape($row['received_at']) . '</summary><pre>' . html_escape(json_encode(json_decode($row['definition'], true, 32, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre></details>';
	}
}

function alarmmanager_auth_editor(string $selected, bool $replacement = false): void {
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerAuth"><legend>' . __esc('Authentication', 'alarmmanager') . '</legend><label>' . __esc('Authentication type', 'alarmmanager') . ' <select name="auth_type">';
	$options = $replacement ? [] : ['none' => 'None'];

	if ($selected === 'keep') {
		$options['keep'] = 'Keep saved authentication';
	}
	$options += ['bearer' => 'Bearer token', 'basic' => 'Basic authentication', 'headers' => 'Custom authentication headers'];

	foreach ($options as $value => $label) {
		print '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . __esc($label, 'alarmmanager') . '</option>';
	}
	print '</select></label><div class="alarmmanagerAuthFields" data-auth-type="bearer">';
	alarmmanager_input('bearer_token', 'Bearer token', '', 'password', 'autocomplete="new-password" maxlength="4089"');
	print '</div><div class="alarmmanagerAuthFields" data-auth-type="basic">';
	alarmmanager_input('basic_username', 'Username', '', 'text', 'autocomplete="off" maxlength="4096"');
	alarmmanager_input('basic_password', 'Password', '', 'password', 'autocomplete="new-password" maxlength="4096"');
	print '</div><div class="alarmmanagerAuthFields" data-auth-type="headers">';
	print '<div data-list-editor="headers">';
	alarmmanager_textarea('headers', 'Authentication headers', '{}', 65536);
	print '</div>';
	print '</div><p class="alarmmanagerHint">' . __esc('Saved credentials are encrypted and never displayed. New values replace the saved credential record.', 'alarmmanager') . '</p></fieldset>';
}
