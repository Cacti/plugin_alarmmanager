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
require_once(__DIR__ . '/../setup.php');
require_once(__DIR__ . '/admin.php');
require_once(__DIR__ . '/remote.php');
require_once(__DIR__ . '/workers.php');
require_once(__DIR__ . '/integrations.php');

function alarmmanager_form_start(string $action, int $revision, array $hidden = []): void {
	print '<form method="post" action="alarmmanager_admin.php"' . ($action === 'configuration_save' ? ' enctype="multipart/form-data"' : '') . ' class="alarmmanagerEditor' . ($action === 'endpoint_save' ? ' alarmmanagerEndpointEditor' : ($action === 'endpoint_delete' ? ' alarmmanagerEndpointDelete' : '')) . ($action === 'settings_save' ? ' alarmmanagerSummaryEditor' : '') . '">';
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

	if (($endpoint['provider'] ?? '') === 'servicenow' || preg_match('~/api/now/table/(incident|em_event)(?:[/?]|$)~', $transport['url'] ?? '')) {
		$templates = alarmmanager_integration_templates();
		$presets   = ['incident' => $templates['servicenow'], 'em_event' => $templates['servicenow_events']];
		$events    = str_contains($transport['url'] ?? '', '/api/now/table/em_event');
		print '<label class="alarmmanagerFullWidth alarmmanagerServiceNowType" data-endpoint-types="restapi webhook">' . __esc('ServiceNow record type', 'alarmmanager') . ' <select name="servicenow_table" style="width:100%" data-servicenow-presets="' . html_escape(json_encode($presets, JSON_THROW_ON_ERROR)) . '"><option value="incident" ' . (!$events ? 'selected' : '') . '>' . __esc('Incident', 'alarmmanager') . '</option><option value="em_event" ' . ($events ? 'selected' : '') . '>' . __esc('Event', 'alarmmanager') . '</option></select></label>';
		print '<p class="alarmmanagerFullWidth">' . __esc('Changing the record type replaces the destination table, message fields and recovery settings with its defaults. Your instance and credentials are retained.', 'alarmmanager') . '</p>';
	}
	$mapping   = $transport['mapping'] ?? ($new ? alarmmanager_integration_templates()['webhook']['transport']['mapping'] : ['default' => []]);
	unset($transport['mapping']);

	if ($new && isset($endpoint['hint'])) {
		print '<p class="alarmmanagerFullWidth" data-integration-hint>' . __esc($endpoint['hint'], 'alarmmanager') . '</p>';
	}
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerDestination" data-endpoint-types="restapi webhook"><legend>' . __esc('Destination', 'alarmmanager') . '</legend>';
	alarmmanager_input('url', 'URL', $transport['url'] ?? '', 'url', 'maxlength="2048"');
	print '</fieldset>';
	print '<div class="alarmmanagerFullWidth" data-endpoint-types="restapi webhook">';
	alarmmanager_auth_editor(!$new ? (!empty($transport['credential']) ? 'keep' : 'none') : ($endpoint['auth'] ?? 'none'), false, $new);
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
	print '<p>' . __esc('JSON output is sent to the script on standard input.', 'alarmmanager') . '</p><p>' . __esc('The executable must exist and be executable by the worker on every delivering poller. Arguments are passed directly without a shell.', 'alarmmanager') . '</p></fieldset>';
	print '<p class="alarmmanagerFullWidth" data-endpoint-types="websocket">' . __esc('WebSocket delivery is not implemented.', 'alarmmanager') . '</p>';
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerMapping" data-message-template="' . html_escape(alarmmanager_message_template()) . '"><legend>' . __esc('Message fields', 'alarmmanager') . '</legend><p>' . __esc('Choose the alarm information to send to any service. Use Combined text to arrange device details, or Use device message to start with a complete message. Email uses Subject and Message; API destinations use the field names required by the receiving service.', 'alarmmanager') . '</p><div class="alarmmanagerMappingBuilder"></div>';
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
	print '</details></details><p class="alarmmanagerFullWidth alarmmanagerHint">' . __esc('Test destination saves the current settings and queues a sample alarm for this destination. Enable the destination before testing.', 'alarmmanager') . '</p><div class="alarmmanagerEndpointActions alarmmanagerFullWidth"><div>' . ($new ? '<a href="alarmmanager_admin.php?section=endpoints">' . __esc('Cancel', 'alarmmanager') . '</a>' : '');
	print '<button type="submit">' . __esc('Save endpoint', 'alarmmanager') . '</button><button type="submit" name="test_destination" value="yes">' . __esc('Test destination', 'alarmmanager') . '</button></div></div></form>';

	if (!$new) {
		alarmmanager_form_start('endpoint_delete', $revision, ['id' => $endpoint['id']]);
		print '<h3>' . __esc('Delete endpoint', 'alarmmanager') . '</h3><p>' . __esc('Deleting stops future delivery and removes the saved settings and credentials. Remove notification rule references and resolve queued or running deliveries first. Completed and failed delivery history is preserved.', 'alarmmanager') . '</p><label><input type="checkbox" name="confirmed" value="yes" required> ' . __esc('Delete this endpoint, its delivery settings and saved credentials', 'alarmmanager') . '</label><button type="submit">' . __esc('Delete endpoint', 'alarmmanager') . '</button></form>';
	}
	print '</details>';
}
/**
 * @param array<string, mixed>|null $schedule
 */
function alarmmanager_rule_schedule_editor(string $field, string $label, ?array $schedule): void {
	print '<section class="alarmmanagerRuleOption">';
	alarmmanager_checkbox($field . '_enabled', $label, $schedule !== null);
	print '<fieldset class="alarmmanagerRuleFields" data-rule-fields' . ($schedule === null ? ' disabled hidden' : '') . '>';
	print '<label>' . __esc('Days', 'alarmmanager') . '<select name="' . $field . '_days[]" multiple required>';

	foreach ([1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day => $name) {
		print '<option value="' . $day . '"' . (in_array($day, $schedule['days'] ?? [1, 2, 3, 4, 5], true) ? ' selected' : '') . '>' . __esc($name, 'alarmmanager') . '</option>';
	}
	print '</select></label><label>' . __esc('Timezone', 'alarmmanager') . '<select name="' . $field . '_timezone" required>';

	foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $timezone) {
		print '<option value="' . html_escape($timezone) . '"' . ($timezone === ($schedule['timezone'] ?? date_default_timezone_get()) ? ' selected' : '') . '>' . html_escape($timezone) . '</option>';
	}
	print '</select></label>';
	alarmmanager_input($field . '_start', 'Start time', $schedule['start'] ?? '09:00', 'time', 'required');
	alarmmanager_input($field . '_end', 'End time', $schedule['end'] ?? '17:00', 'time', 'required');
	print '<p class="alarmmanagerHint">' . __esc('Times use the selected timezone and the alarm event time. An end time before the start runs overnight. Equal times cover the entire selected day.', 'alarmmanager') . '</p></fieldset></section>';
}
/** Existing saved selectors are shown as equivalent condition groups.
 * @param array<string, mixed> $policy
 */
function alarmmanager_rule_alarm_query(array $policy): string {
	if ($policy['alarm_query'] !== '' || !$policy['match']) {
		return $policy['alarm_query'];
	}
	$conditions = [];

	foreach (['sources' => 'source', 'severities' => 'severity', 'transitions' => 'transition', 'device_ids' => 'device_id', 'collector_ids' => 'collector_id', 'tags' => 'tags'] as $key => $field) {
		if (!array_key_exists($key, $policy['match'])) {
			continue;
		}
		$rows = [];

		foreach ($policy['match'][$key] as $value) {
			$rows[] = ['join' => $key === 'tags' ? 'AND' : 'OR', 'field' => $field, 'operator' => $key === 'tags' ? 'has' : '=', 'value' => (string) $value];
		}

		if (!$rows && $key !== 'tags') {
			$rows = [['field' => 'source', 'operator' => '=', 'value' => 'none'], ['join' => 'AND', 'negative' => true, 'field' => 'source', 'operator' => '=', 'value' => 'none']];
		}

		if ($rows) {
			$conditions[] = ['join' => 'AND', 'rows' => $rows];
		}
	}

	return $conditions ? json_encode(['version' => 1, 'conditions' => $conditions], JSON_THROW_ON_ERROR) : '';
}

/**
 * @param array<string, mixed>                     $item
 * @param array<string, mixed>                     $configuration
 * @param array<string, array<int|string, string>> $choices
 */
function alarmmanager_rule_editor(array $item, int $revision, array $configuration, array $choices): void {
	$new      = (int) $item['id'] === 0;
	$policy   = alarmmanager_validate_rule_definition($item['policy'] ?? []);
	print '<details class="alarmmanagerDashboardCard alarmmanagerRule"' . ($new ? ' id="alarmmanager_new_rule" open' : '') . '><summary>' . html_escape($new ? __('Create a rule', 'alarmmanager') : $item['name']) . ($new ? '' : ' — ' . __esc($item['enabled'] ? 'Enabled' : 'Disabled', 'alarmmanager') . ' · ' . __esc('Priority', 'alarmmanager') . ' ' . (int) $item['priority']) . '</summary>';
	alarmmanager_form_start('rule_save', $revision, $new ? [] : ['id' => $item['id']]);
	print '<input type="hidden" name="rule_fields" value="yes"><fieldset class="alarmmanagerRuleSection"><legend>' . __esc('Rule and destination', 'alarmmanager') . '</legend>';
	alarmmanager_input('name', 'Rule name', $item['name'], 'text', 'maxlength="128" required placeholder="Critical network alarms"');
	alarmmanager_checkbox('enabled', 'Enable this rule', (bool) $item['enabled']);
	$selected = $item['endpoints'] ?? [];
	print '<fieldset class="alarmmanagerDestinations"><legend>' . __esc('Alarm Destination', 'alarmmanager') . '</legend><div class="alarmmanagerChoiceList">';

	foreach ($configuration['endpoints'] as $endpoint) {
		print '<label><input type="checkbox" name="endpoints[]" data-alarm-destination value="' . (int) $endpoint['id'] . '"' . (in_array($endpoint['id'], $selected, true) ? ' checked' : '') . '> <span>' . html_escape($endpoint['name']) . ($endpoint['enabled'] ? '' : ' <span class="alarmmanagerHint">(' . __esc('disabled', 'alarmmanager') . ')</span>') . '</span></label>';
	}
	print '</div><p class="alarmmanagerHint">' . __esc('Select one or more alarm destinations. Disabled destinations do not send alarms.', 'alarmmanager') . '</p></fieldset></fieldset>';

	print '<div class="alarmmanagerRuleSection">';
	alarmmanager_checkbox('all_devices', 'Applies to all devices', $policy['device_query'] === '');
	print '</div>';
	$device_fields = alarmmanager_rule_device_fields();

	foreach (['poller_id' => 'collector_ids', 'site_id' => 'site_ids', 'host_template_id' => 'template_ids'] as $field => $choice) {
		$device_fields[$field]['choices'] = (object) ([0 => __('None', 'alarmmanager')] + ($choices[$choice] ?? []));
	}
	print '<fieldset class="alarmmanagerRuleSection alarmmanagerQueryBuilder"' . ($policy['device_query'] === '' ? ' hidden disabled' : '') . ' data-query-fields="' . html_escape(json_encode($device_fields, JSON_THROW_ON_ERROR)) . '"><legend>' . __esc('Match devices', 'alarmmanager') . '</legend><p class="alarmmanagerHint">' . __esc('Build device conditions using AND, OR and groups. These conditions use the current Cacti device settings and must also satisfy any alarm filters below. With no device conditions, all devices can match.', 'alarmmanager') . '</p><div class="alarmmanagerQueryRows"></div><textarea name="device_query" maxlength="8192" hidden>' . html_escape($policy['device_query']) . '</textarea></fieldset>';
	$alarm_query = alarmmanager_rule_alarm_query($policy);
	print '<div class="alarmmanagerRuleSection">';
	alarmmanager_checkbox('all_alarm_types', 'Applies to all alarm types', $alarm_query === '');
	print '</div>';
	$alarm_fields = alarmmanager_rule_alarm_fields();

	foreach (['source' => 'sources', 'severity' => 'severities', 'transition' => 'transitions', 'device_id' => 'device_ids', 'collector_id' => 'collector_ids'] as $field => $choice) {
		$alarm_fields[$field]['choices'] = (object) ($choices[$choice] ?? []);
	}

	foreach (['cacti_device' => __('Device Status', 'alarmmanager'), 'syslog' => __('Syslog', 'alarmmanager'), 'thold' => __('Thold', 'alarmmanager')] as $source => $label) {
		if (isset($choices['sources'][$source])) {
			$alarm_fields['source']['choices']->$source = $label;
		}
	}
	print '<fieldset class="alarmmanagerRuleSection alarmmanagerQueryBuilder"' . ($alarm_query === '' ? ' hidden disabled' : '') . ' data-query-fields="' . html_escape(json_encode($alarm_fields, JSON_THROW_ON_ERROR)) . '"><legend>' . __esc('Match alarms', 'alarmmanager') . '</legend><p class="alarmmanagerHint">' . __esc('Build alarm conditions using AND, OR, NOT and groups. Alarms must also match the device conditions above.', 'alarmmanager') . '</p><div class="alarmmanagerQueryRows"></div><textarea name="alarm_query" maxlength="8192" hidden>' . html_escape($alarm_query) . '</textarea></fieldset>';

	if (!$choices['sources']) {
		print '<p class="alarmmanagerHint">' . __esc('No alarm sources detected yet. Sources appear after their first alarm.', 'alarmmanager') . '</p>';
	}
	print '<fieldset class="alarmmanagerRuleSection"><legend>' . __esc('Notification hours', 'alarmmanager') . '</legend><p class="alarmmanagerHint">' . __esc('By default, matching alarms can notify at any time. Turn on a schedule to limit when this rule matches.', 'alarmmanager') . '</p>';
	alarmmanager_rule_schedule_editor('schedule', 'Only notify during these hours', $policy['schedule']);
	print '</fieldset><fieldset class="alarmmanagerRuleSection"><legend>' . __esc('Suppress notifications', 'alarmmanager') . '</legend><p class="alarmmanagerHint">' . __esc('Suppressed alarms remain in history. Recovery notifications bypass acknowledgment, cooldown and duplicate suppression, but still follow notification hours and maintenance windows.', 'alarmmanager') . '</p>';
	alarmmanager_checkbox('suppress_acknowledged', 'Pause notifications for acknowledged alarms', $policy['suppress_acknowledged']);
	alarmmanager_input('cooldown_seconds', 'Minimum seconds between notifications for the same alarm episode', $policy['cooldown_seconds'], 'number', 'min="0" max="86400" required');
	alarmmanager_input('duplicate_seconds', 'Suppress identical notifications for this many seconds', $policy['duplicate_seconds'], 'number', 'min="0" max="86400" required');
	print '<p class="alarmmanagerHint">' . __esc('Set a duration to 0 to turn it off.', 'alarmmanager') . '</p>';
	alarmmanager_rule_schedule_editor('maintenance_schedule', 'Pause notifications during a maintenance window', $policy['maintenance_schedule']);
	print '</fieldset><fieldset class="alarmmanagerRuleSection"><legend>' . __esc('Rule order', 'alarmmanager') . '</legend>';
	alarmmanager_input('priority', 'Priority (lower numbers run first)', $item['priority'] ?? 100, 'number', 'min="0" max="10000" required');
	alarmmanager_checkbox('stop', 'Stop checking later rules when this rule matches', $item['stop'] ?? false);
	print '<p class="alarmmanagerHint">' . __esc('Rules with equal priority run in ID order. Stopping also applies when this rule suppresses notifications.', 'alarmmanager') . '</p></fieldset><div class="alarmmanagerRuleActions"><button type="submit"' . (!$configuration['endpoints'] ? ' disabled' : '') . '>' . __esc($new ? 'Create rule' : 'Save rule', 'alarmmanager') . '</button></div></form></details>';
}

function alarmmanager_admin_markup(array $state, string $section): void {
	$config   = $state['configuration'];
	$revision = $state['revision'];
	print '<h2>' . __esc(alarmmanager_admin_sections()[$section] ?? 'Configuration', 'alarmmanager') . '</h2><p>' . __esc('Local configuration revision', 'alarmmanager') . ': ' . $revision . '</p>';

	if ($section === 'queue') {
		$paused = alarmmanager_notifications_paused();
		$count  = alarmmanager_db_row("SELECT COUNT(*) AS waiting FROM plugin_alarmmanager_deliveries AS d
			INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
			WHERE d.status = 'pending' AND e.processing_poller_id = ?", [alarmmanager_local_poller()]);
		html_start_box(__esc('Alarm Queue', 'alarmmanager'), '100%', false, 3, 'center', '');
		print '<p>' . ($paused ? __esc('Notification delivery is paused.', 'alarmmanager') : __esc('Notification delivery is running.', 'alarmmanager')) . ' ' . __esc('Waiting messages', 'alarmmanager') . ': ' . (int) ($count['waiting'] ?? 0) . '</p>';
		print '<p>' . __esc('Controls apply to this poller. Alarms continue to be received and processed while delivery is paused. Messages already being sent may finish. Resume sends the waiting notifications.', 'alarmmanager') . '</p>';
		alarmmanager_form_start($paused ? 'queue_resume' : 'queue_pause', $revision);
		print '<button type="submit">' . ($paused ? __esc('Resume delivery', 'alarmmanager') : __esc('Pause delivery', 'alarmmanager')) . '</button></form>';
		print '<p><a href="alarmmanager.php?tab=events&amp;job=delivery&amp;status=pending&amp;current=1">' . __esc('View waiting messages', 'alarmmanager') . '</a> | <a href="alarmmanager.php?tab=events&amp;job=delivery&amp;status=leased&amp;current=1">' . __esc('View messages being sent', 'alarmmanager') . '</a></p>';
		alarmmanager_form_start('queue_clear', $revision);
		print '<p>' . __esc('Clear discards all waiting notifications on this poller, including retries. They remain in delivery history as failed with reason queue_cleared. Running messages and alarm state are preserved. Pause delivery before clearing during maintenance.', 'alarmmanager') . '</p><label><input type="checkbox" name="confirmed" value="yes" required> ' . __esc('Discard all waiting notifications', 'alarmmanager') . '</label><button type="submit">' . __esc('Clear queue', 'alarmmanager') . '</button></form>';
		html_end_box();
		$rows = alarmmanager_db_rows("SELECT d.id, ep.name AS endpoint, JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.summary')) AS summary, d.created_at, d.attempts, d.available_at
			FROM plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
			LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id
			WHERE d.status = 'pending' AND e.processing_poller_id = ? ORDER BY d.id LIMIT 100", [alarmmanager_local_poller()]);
		html_start_box(__esc('Waiting messages (first 100)', 'alarmmanager'), '100%', false, 3, 'center', '');
		html_header([__esc('ID', 'alarmmanager'), __esc('Endpoint', 'alarmmanager'), __esc('Message', 'alarmmanager'), __esc('Queued at (UTC)', 'alarmmanager'), __esc('Attempts', 'alarmmanager'), __esc('Available at (UTC)', 'alarmmanager')], 1);

		foreach ($rows as $row) {
			form_alternate_row();

			foreach ($row as $value) {
				form_selectable_cell(html_escape((string) $value), (int) $row['id'], '', 'left');
			}
			print '</tr>';
		}

		if (!$rows) {
			print '<tr><td colspan="6">' . __esc('No waiting messages.', 'alarmmanager') . '</td></tr>';
		}
		html_end_box();
	} elseif ($section === 'collectors') {
		alarmmanager_remote_markup();
	} elseif ($section === 'endpoints') {
		if (isset($_SESSION['alarmmanager_test_result'])) {
			print '<p role="status">' . html_escape($_SESSION['alarmmanager_test_result']) . '</p><p><a href="alarmmanager.php?tab=events&amp;job=delivery">' . __esc('View delivery results', 'alarmmanager') . '</a></p>';
			unset($_SESSION['alarmmanager_test_result']);
		}
		print '<p>' . __esc('Create an integration, configure its destination and credentials, test a sample alarm here, then select it directly in a rule.', 'alarmmanager') . '</p>';
		print '<form method="get" action="alarmmanager_admin.php" class="alarmmanagerTemplatePicker"><input type="hidden" name="section" value="endpoints"><input type="hidden" name="add" value="1"><label>' . __esc('Start a new integration from a template', 'alarmmanager') . '<select name="integration_template"><option value="">' . __esc('Blank endpoint', 'alarmmanager') . '</option>';
		$templates = alarmmanager_integration_templates();
		$selected  = (string) get_filter_request_var('integration_template', FILTER_CALLBACK, ['options' => static fn ($value) => is_string($value) && isset($templates[$value]) ? $value : false, 'default' => '']);
		$adding    = $selected !== '' || (string) get_filter_request_var('add', FILTER_CALLBACK, ['options' => static fn ($value) => $value === '1' ? '1' : false, 'default' => '']) === '1';

		foreach ($templates as $key => $template) {
			if ($key === 'servicenow_events') {
				continue;
			}
			print '<option value="' . $key . '" ' . ($selected === $key ? 'selected' : '') . '>' . __esc($key === 'servicenow' ? 'ServiceNow' : $template['name'], 'alarmmanager') . '</option>';
		}
		print '</select></label><button type="submit">' . __esc('Add Integration', 'alarmmanager') . '</button></form><p>' . __esc('Templates fill a new disabled draft only. Replace the example destination or executable before saving; existing endpoints and credentials are preserved.', 'alarmmanager') . '</p>';

		foreach ($config['endpoints'] as $item) {
			alarmmanager_endpoint_editor($item, $revision);
		}

		if ($adding) {
			alarmmanager_endpoint_editor(['id' => 0, 'enabled' => false] + ($templates[$selected] ?? ['name' => '', 'type' => 'restapi']), $revision, true);
		}
	} elseif ($section === 'rules') {
		print '<h2>' . __esc('Alarm Rules', 'alarmmanager') . '</h2><p>' . __esc('Choose which alarms should notify, where to send them and when to pause notifications. Rules run from lowest to highest priority.', 'alarmmanager') . '</p>';

		if (!$config['endpoints']) {
			print '<p class="alarmmanagerRuleNotice">' . __esc('Create an integration before saving your first rule.', 'alarmmanager') . ' <a href="alarmmanager_admin.php?section=endpoints">' . __esc('Create integration', 'alarmmanager') . '</a></p>';
		}
		$choices = ['severities' => ['critical' => __('Critical', 'alarmmanager'), 'major' => __('Major', 'alarmmanager'), 'minor' => __('Minor', 'alarmmanager'), 'warning' => __('Warning', 'alarmmanager'), 'info' => __('Information', 'alarmmanager'), 'clear' => __('Clear', 'alarmmanager')], 'transitions' => ['raised' => __('Raised', 'alarmmanager'), 'updated' => __('Updated', 'alarmmanager'), 'reminder' => __('Reminder', 'alarmmanager'), 'recovered' => __('Recovered', 'alarmmanager'), 'occurrence' => __('Occurrence', 'alarmmanager')], 'device_ids' => [], 'collector_ids' => []];

		$choices['sources'] = [];

		foreach (alarmmanager_report_rows("SELECT DISTINCT source FROM plugin_alarmmanager_events UNION SELECT DISTINCT source FROM plugin_alarmmanager_remote_history WHERE source IS NOT NULL AND source <> '' ORDER BY source LIMIT 256") as $row) {
			if ($row['source'] !== '') {
				$choices['sources'][$row['source']] = $row['source'];
			}
		}

		foreach (alarmmanager_db_rows('SELECT id, description, hostname FROM host ORDER BY description, id LIMIT 1000') as $device) {
			$choices['device_ids'][(int) $device['id']] = ($device['description'] ?: $device['hostname']) . ' (#' . $device['id'] . ')';
		}

		foreach (alarmmanager_db_rows('SELECT id, name FROM poller ORDER BY name, id LIMIT 256') as $poller) {
			$choices['collector_ids'][(int) $poller['id']] = $poller['name'] . ' (#' . $poller['id'] . ')';
		}

		foreach (['site_ids' => 'sites', 'template_ids' => 'host_template'] as $field => $table) {
			$choices[$field] = [];

			foreach (alarmmanager_db_rows("SELECT id, name FROM $table ORDER BY name, id LIMIT 1000") as $row) {
				$choices[$field][(int) $row['id']] = $row['name'] . ' (#' . $row['id'] . ')';
			}
		}
		print '<p class="alarmmanagerHint">' . __esc('Alarm notifications require an enabled matching Alarm Rule. Without one, alarms remain in history but no notifications are sent.', 'alarmmanager') . '</p>';
		print '<p><a href="#alarmmanager_new_rule">' . __esc('Create a rule', 'alarmmanager') . '</a></p>';

		if (!$config['rules']) {
			print '<p class="alarmmanagerHint">' . __esc('No rules yet. Create a rule below to start routing alarms to your notification destinations.', 'alarmmanager') . '</p>';
		}

		foreach ($config['rules'] as $rule) {
			alarmmanager_rule_editor($rule, $revision, $config, $choices);
		}
		alarmmanager_rule_editor(['id' => 0, 'name' => '', 'enabled' => true, 'policy' => []], $revision, $config, $choices);
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
	} elseif ($section === 'configuration') {
		$summaries = $config['device_summaries'] ?? ['offline' => 'Device Offline', 'recovering' => 'Device Recovering', 'online' => 'Device Online'];
		alarmmanager_form_start('settings_save', $revision);
		print '<h2>' . __esc('Device alarm summaries', 'alarmmanager') . '</h2>';
		alarmmanager_input('offline_summary', 'Offline summary', $summaries['offline'], 'text', 'maxlength="512" required');
		alarmmanager_input('recovering_summary', 'Recovering summary', $summaries['recovering'] ?? 'Device Recovering', 'text', 'maxlength="512" required');
		alarmmanager_input('online_summary', 'Online summary', $summaries['online'], 'text', 'maxlength="512" required');
		print '<p>' . __esc('Applies to new device alarms and recoveries. Existing history retains its original summary. These settings synchronize to collectors.', 'alarmmanager') . '</p>';
		$settings = alarmmanager_supervisor_options();
		$status   = alarmmanager_daemon_status();
		print '<h2>' . __esc('Daemon', 'alarmmanager') . '</h2><p>' . __esc('Supervisor', 'alarmmanager') . ': ' . __esc($status ? 'Running' : 'Not running', 'alarmmanager') . '</p>';

		if ($status) {
			print '<p>' . __esc('Active processing workers', 'alarmmanager') . ': ' . (int) $status['processing_workers'] . ' / ' . __esc('Active delivery workers', 'alarmmanager') . ': ' . (int) $status['delivery_workers'] . '</p>';
		} else {
			print '<p>' . __esc('Install and start the Alarm Manager service on this collector before enabling daemon workers.', 'alarmmanager') . '</p>';
		}
		alarmmanager_checkbox('daemon_enabled', 'Enable daemon', $settings['enabled']);
		alarmmanager_input('processing_workers', 'Processing workers', $settings['processing_workers'], 'number', 'min="1" max="64" required');
		alarmmanager_input('delivery_workers', 'Delivery workers', $settings['delivery_workers'], 'number', 'min="0" max="64" required');
		print '<p class="alarmmanagerHint alarmmanagerFullWidth">' . __esc('Changes apply automatically within a few seconds. Disabling the daemon lets current jobs finish and returns processing to the Cacti poller.', 'alarmmanager') . '</p><button type="submit">' . __esc('Save settings', 'alarmmanager') . '</button></form>';
		print '<h2>' . __esc('Export / import configuration', 'alarmmanager') . '</h2><p>' . __esc('Export endpoints, message mappings, alarm rules and device summaries as JSON. Stored credentials and daemon settings are excluded. Endpoint URLs may contain secrets; keep exported files private.', 'alarmmanager') . '</p>';
		print '<p><a class="button" href="alarmmanager_admin.php?section=configuration&amp;download=configuration">' . __esc('Export configuration', 'alarmmanager') . '</a></p>';
		alarmmanager_form_start('configuration_save', $revision);
		print '<input type="hidden" name="MAX_FILE_SIZE" value="1048576">';
		alarmmanager_input('configuration_file', 'Configuration JSON file (maximum 1 MiB)', '', 'file', 'accept=".json,application/json" required');
		print '<p>' . __esc('Import replaces the routing configuration using the IDs in the file. Missing endpoints and rules are disabled, preserving history. Stored credentials are unchanged. Concurrent edits reject stale revisions. Main-poller settings replicate through Cacti synchronization.', 'alarmmanager') . '</p><button type="submit">' . __esc('Import configuration', 'alarmmanager') . '</button></form>';
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
		print '<p><a href="alarmmanager.php?tab=events&amp;job=processing&amp;status=failed">' . __esc('Failed processing jobs and replay', 'alarmmanager') . '</a></p><p><a href="alarmmanager.php?tab=events&amp;job=delivery&amp;status=failed">' . __esc('Failed deliveries and replay', 'alarmmanager') . '</a></p>';
	}
}

function alarmmanager_remote_markup(): void {
	print '<h2>' . __esc('Collectors and remote history', 'alarmmanager') . '</h2><p>' . __esc('Each collector owns its local delivery. Imported history records the original events and outcomes. Collector history does not create central delivery jobs.', 'alarmmanager') . '</p>';
	print '<p>' . __esc("Configuration uses Cacti replication. Collectors automatically return history through Cacti's existing main database connection; no separate synchronization setup is required.", 'alarmmanager') . '</p>';
	$peers = alarmmanager_db_rows('SELECT q.id AS poller_id, q.name, p.last_seen,
		TIMESTAMPDIFF(SECOND, p.last_seen, UTC_TIMESTAMP(6)) AS age_seconds
		FROM poller AS q LEFT JOIN plugin_alarmmanager_sync_peers AS p ON p.poller_id = q.id
		WHERE q.id > 1 ORDER BY q.name, q.id LIMIT 256');

	html_start_box(__esc('Collectors', 'alarmmanager'), '100%', false, 3, 'center', '');
	html_header([__esc('Poller_id', 'alarmmanager'), __esc('Poller name', 'alarmmanager'), __esc('Status', 'alarmmanager'), __esc('Last history sync (UTC)', 'alarmmanager')], 1);

	foreach ($peers as $peer) {
		$status = $peer['last_seen'] === null ? 'Not yet seen' : ((int) $peer['age_seconds'] <= 2 * (int) (read_config_option('poller_interval') ?: 300) + 30 ? 'Online' : 'Offline');
		form_alternate_row();
		form_selectable_cell((string) (int) $peer['poller_id'], 0, '', 'left');
		form_selectable_cell(html_escape((string) $peer['name']), 0, '', 'left');
		form_selectable_cell(__esc($status, 'alarmmanager'), 0, '', 'left');
		form_selectable_cell(html_escape($peer['last_seen'] ?? '—'), 0, '', 'left');
		form_end_row();
	}

	if (!$peers) {
		print '<tr class="odd"><td colspan="4">' . __esc('No remote collectors configured.', 'alarmmanager') . '</td></tr>';
	}
	html_end_box(true);

	$poller = get_filter_request_var('collector', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647], 'default' => 0]);
	$entity = get_filter_request_var('entity', FILTER_CALLBACK, ['options' => static fn ($value) => is_string($value) && isset(alarmmanager_sync_entities()[$value]) ? $value : false, 'default' => 'event']);

	if (!is_string($entity) || !isset(alarmmanager_sync_entities()[$entity])) {
		throw new InvalidArgumentException('history_entity_invalid');
	}

	$peer_ids = array_map('intval', array_column($peers, 'poller_id'));

	if (!in_array($poller, $peer_ids, true)) {
		$poller = 0;
	}
	print '<form method="get"><input type="hidden" name="section" value="collectors"><label for="alarmmanager_history_poller">' . __esc('Poller', 'alarmmanager') . '</label> <select id="alarmmanager_history_poller" name="collector"><option value="0">' . __esc('All pollers', 'alarmmanager') . '</option>';

	foreach ($peers as $peer) {
		print '<option value="' . (int) $peer['poller_id'] . '"' . ($poller === (int) $peer['poller_id'] ? ' selected' : '') . '>' . html_escape($peer['poller_id'] . ' - ' . $peer['name']) . '</option>';
	}
	print '</select> <label>' . __esc('History record type', 'alarmmanager') . ' <select name="entity">';

	foreach (array_keys(alarmmanager_sync_entities()) as $type) {
		print '<option ' . ($type === $entity ? 'selected' : '') . '>' . $type . '</option>';
	}
	print '</select></label><button>' . __esc('Filter', 'alarmmanager') . '</button></form>';
	$rows = alarmmanager_db_rows('SELECT poller_id, instance_uuid, entity_id, revision, definition, received_at FROM plugin_alarmmanager_remote_history WHERE entity = ?' . ($poller ? ' AND poller_id = ?' : '') . ' ORDER BY received_at DESC, entity_id DESC LIMIT 50', $poller ? [$entity, $poller] : [$entity]);
	print '<p>' . __esc('Latest 50 matching remote records; original IDs and delivery ownership are preserved.', 'alarmmanager') . '</p>';
	$history_layout = [
		'event' => [
			'headers' => ['Collector', 'Record ID', 'Event ID', 'Source', 'Severity', 'Transition', 'Occurred at (UTC)', 'Summary', 'Received at (UTC)'],
			'fields'  => ['collector', 'entity_id', 'event_id', 'source', 'severity', 'transition', 'occurred_at', 'summary', 'received_at']
		],
		'processing' => [
			'headers' => ['Collector', 'Record ID', 'Event row ID', 'Status', 'Attempts', 'Last error', 'Created at (UTC)', 'Updated at (UTC)', 'Received at (UTC)'],
			'fields'  => ['collector', 'entity_id', 'event_row_id', 'status', 'attempts', 'last_error', 'created_at', 'updated_at', 'received_at']
		],
		'decision' => [
			'headers' => ['Collector', 'Record ID', 'Event row ID', 'Rule ID', 'Endpoint ID', 'Reason', 'Config revision', 'Created at (UTC)', 'Received at (UTC)'],
			'fields'  => ['collector', 'entity_id', 'event_row_id', 'rule_id', 'endpoint_id', 'reason', 'config_revision', 'created_at', 'received_at']
		],
		'delivery' => [
			'headers' => ['Collector', 'Record ID', 'Event row ID', 'Endpoint ID', 'Status', 'Attempts', 'Last error', 'Created at (UTC)', 'Updated at (UTC)', 'Received at (UTC)'],
			'fields'  => ['collector', 'entity_id', 'event_row_id', 'endpoint_id', 'status', 'attempts', 'last_error', 'created_at', 'updated_at', 'received_at']
		],
		'attempt' => [
			'headers' => ['Collector', 'Record ID', 'Delivery ID', 'Attempt number', 'Outcome', 'Code', 'Duration (ms)', 'Started at (UTC)', 'Ended at (UTC)', 'Received at (UTC)'],
			'fields'  => ['collector', 'entity_id', 'delivery_id', 'attempt_number', 'outcome', 'code', 'duration_ms', 'started_at', 'ended_at', 'received_at']
		]
	];
	$layout  = $history_layout[$entity] ?? throw new InvalidArgumentException('history_entity_invalid');
	$headers = $layout['headers'];
	html_start_box(__esc('Remote history', 'alarmmanager'), '100%', false, 3, 'center', '');
	html_header(array_map(static fn ($header) => __esc($header, 'alarmmanager'), $headers), 1);

	$poller_names = array_column($peers, 'name', 'poller_id');

	foreach ($rows as $row) {
		$definition             = json_decode($row['definition'], true, 32, JSON_THROW_ON_ERROR);
		$display                = $definition;
		$display['collector']   = $row['poller_id'] . ' - ' . ($poller_names[$row['poller_id']] ?? __('Unknown collector', 'alarmmanager'));
		$display['entity_id']   = $row['entity_id'];
		$display['received_at'] = $row['received_at'];

		if ($entity === 'event') {
			$event = json_decode($definition['payload'], true, 32, JSON_THROW_ON_ERROR);

			foreach (['severity', 'transition', 'occurred_at', 'summary'] as $field) {
				$display[$field] = $event[$field] ?? '—';
			}
		}

		$values = array_map(static fn ($field) => $display[$field] ?? '—', $layout['fields']);
		form_alternate_row();

		foreach ($values as $value) {
			form_selectable_cell(html_escape((string) $value), 0, '', 'left');
		}

		form_end_row();
	}

	if (!$rows) {
		print '<tr class="odd"><td colspan="' . count($headers) . '">' . __esc('No matching remote records.', 'alarmmanager') . '</td></tr>';
	}

	html_end_box(true);
}

function alarmmanager_auth_editor(string $selected, bool $replacement = false, bool $optional = false): void {
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerAuth"' . ($optional ? ' data-auth-optional="1"' : '') . '><legend>' . __esc('Authentication', 'alarmmanager') . '</legend><label>' . __esc('Authentication type', 'alarmmanager') . ' <select name="auth_type">';
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

/** Generic producer accounts replace the plugin-specific integration editor. */
