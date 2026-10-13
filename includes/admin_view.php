<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/web_view.php');
require_once(__DIR__ . '/admin.php');
require_once(__DIR__ . '/remote_view.php');

function alarmmanager_form_start(string $action, int $revision, array $hidden = []): void {
	print '<form method="post" action="alarmmanager_admin.php" class="alarmmanagerEditor">'; alarmmanager_csrf_field();
	print '<input type="hidden" name="action" value="' . alarmmanager_escape($action) . '"><input type="hidden" name="revision" value="' . $revision . '">';
	foreach ($hidden as $key => $value) { print '<input type="hidden" name="' . alarmmanager_escape($key) . '" value="' . alarmmanager_escape($value) . '">'; }
}
function alarmmanager_input(string $key, string $label, $value = '', string $type = 'text', string $extra = ''): void {
	print '<label>' . alarmmanager_label($label) . ' <input name="' . $key . '" type="' . $type . '" value="' . alarmmanager_escape($value) . '" ' . $extra . '></label>';
}
function alarmmanager_checkbox(string $key, string $label, bool $checked = false): void {
	print '<label><input type="checkbox" name="' . $key . '" value="yes" ' . ($checked ? 'checked' : '') . '> ' . alarmmanager_label($label) . '</label>';
}
function alarmmanager_textarea(string $key, string $label, string $value, int $limit = 131072): void {
	print '<label class="alarmmanagerFullWidth">' . alarmmanager_label($label) . '<textarea name="' . $key . '" rows="8" maxlength="' . $limit . '" spellcheck="false">' . alarmmanager_escape($value) . '</textarea></label>';
}
function alarmmanager_json_text($value): string { return json_encode($value ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function alarmmanager_endpoint_editor(array $endpoint, int $revision, bool $new = false): void {
	print '<details class="alarmmanagerDashboardCard" ' . ($new ? 'open' : '') . '><summary>' . alarmmanager_escape(($new ? __('New endpoint', 'alarmmanager') : $endpoint['name']) . ' (#' . $endpoint['id'] . ')') . '</summary>';
	alarmmanager_form_start('endpoint_save', $revision);
	alarmmanager_input('id', 'Endpoint ID', $endpoint['id'], 'number', 'min="1" max="2147483647" required' . ($new ? '' : ' readonly'));
	alarmmanager_input('name', 'Name', $endpoint['name'], 'text', 'maxlength="128" required'); alarmmanager_checkbox('enabled', 'Enabled', $endpoint['enabled'] ?? true);
	print '<label>' . alarmmanager_label('Type') . ' <select name="type">'; foreach (['restapi', 'webhook', 'email', 'script', 'websocket'] as $type) { print '<option ' . ($endpoint['type'] === $type ? 'selected' : '') . '>' . $type . '</option>'; } print '</select></label>';
	foreach (['max_concurrency' => ['Concurrency', 1, 64, 1], 'timeout_seconds' => ['Timeout seconds', 1, 120, 10], 'max_attempts' => ['Attempt budget', 1, 20, 5], 'min_interval_ms' => ['Minimum interval ms', 0, 3600000, 0]] as $key => [$label, $min, $max, $default]) { alarmmanager_input($key, $label, $endpoint[$key] ?? $default, 'number', "min=\"$min\" max=\"$max\" required"); }
	$transport = $endpoint['transport'] ?? []; $mapping = $transport['mapping'] ?? ['default' => []]; unset($transport['mapping']);
	print '<fieldset class="alarmmanagerFullWidth"><legend>' . alarmmanager_label('HTTP settings (REST API or webhook)') . '</legend>';
	alarmmanager_input('url', 'URL', $transport['url'] ?? '', 'url', 'maxlength="2048"');
	print '<label>' . alarmmanager_label('Method') . ' <select name="method">'; foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) { print '<option ' . (($transport['method'] ?? 'POST') === $method ? 'selected' : '') . '>' . $method . '</option>'; } print '</select></label>';
	alarmmanager_input('ca_bundle', 'Self-signed certificate / private CA PEM path', $transport['ca_bundle'] ?? '', 'text', 'maxlength="1024" placeholder="/etc/cacti/alarmmanager/internal-ca.pem"');
	alarmmanager_checkbox('credential', 'Use encrypted endpoint credential', $transport['credential'] ?? false); alarmmanager_checkbox('allow_http', 'Allow anonymous HTTP', $transport['allow_http'] ?? false);
	print '<p>' . alarmmanager_label('Certificate and hostname verification remain enabled. Provision the PEM file on every poller. Save secrets on the Credentials page.') . '</p></fieldset>';
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerMapping"><legend>' . alarmmanager_label('Payload mappings') . '</legend><p>' . alarmmanager_label('Add destination fields using normalized sources or constants. Each transition can replace the default payload. No PHP or string substitution is evaluated.') . '</p><div class="alarmmanagerMappingBuilder"></div>';
	alarmmanager_textarea('mapping_json', 'Mapping JSON (advanced / no-JavaScript editor)', alarmmanager_json_text($mapping)); print '</fieldset>';
	alarmmanager_textarea('transport_json', 'Transport options (email, script, HTTP success criteria and timeouts)', alarmmanager_json_text($transport));
	print '<p class="alarmmanagerHint alarmmanagerFullWidth">' . alarmmanager_label('Email requires to/from, html and attachments. Script requires an absolute executable, argument list and compatibility mode. WebSocket delivery is not implemented. Use the delivery documentation for option shapes.') . '</p><button type="submit">' . alarmmanager_label('Save endpoint') . '</button></form></details>';
}
function alarmmanager_admin_markup(array $state, string $section): void {
	$config = $state['configuration']; $revision = $state['revision'];
	print '<nav class="alarmmanagerTabs" aria-label="' . alarmmanager_label('Administration sections') . '">'; foreach (['endpoints' => 'Endpoints and mappings', 'profiles' => 'Routing profiles', 'producers' => 'Producer integrations', 'collectors' => 'Collectors and history', 'rules' => 'Rules and suppression', 'credentials' => 'Credentials', 'test' => 'Preview and test', 'configuration' => 'Configuration', 'health' => 'Workers and failed processing', 'audit' => 'Audit'] as $key => $label) { print '<a href="alarmmanager_admin.php?section=' . $key . '" ' . ($section === $key ? 'aria-current="page"' : '') . '>' . alarmmanager_label($label) . '</a>'; } print '</nav><p>' . alarmmanager_label('Local configuration revision') . ': ' . $revision . '</p>';
	if ($section === 'collectors') { alarmmanager_remote_markup(); }
	elseif ($section === 'producers') { alarmmanager_producer_markup($revision); }
	elseif ($section === 'endpoints') {
		foreach ($config['endpoints'] as $item) { alarmmanager_endpoint_editor($item, $revision); }
		$ids = array_column($config['endpoints'], 'id'); $id = $ids ? max($ids) + 1 : 1;
		alarmmanager_endpoint_editor(['id' => $id, 'name' => '', 'enabled' => false, 'type' => 'restapi'], $revision, true);
	} elseif ($section === 'profiles' || $section === 'rules') {
		$items = $config[$section]; $ids = array_column($items, 'id'); $items[] = ['id' => $ids ? max($ids) + 1 : 1, 'name' => '', 'enabled' => false, 'endpoints' => [], 'policy' => [], 'profile_id' => 1];
		foreach ($items as $item) {
			print '<details class="alarmmanagerDashboardCard"><summary>' . alarmmanager_escape($item['name'] ?: __('New item', 'alarmmanager')) . ' (#' . $item['id'] . ')</summary>';
			alarmmanager_form_start($section === 'profiles' ? 'profile_save' : 'rule_save', $revision); alarmmanager_input('id', 'ID', $item['id'], 'number', 'min="1" max="2147483647" required'); alarmmanager_input('name', 'Name', $item['name'], 'text', 'maxlength="128" required'); alarmmanager_checkbox('enabled', 'Enabled', (bool) $item['enabled']);
			if ($section === 'profiles') { print '<label>' . alarmmanager_label('Destinations (select multiple)') . '<select name="endpoints[]" multiple required>'; foreach ($config['endpoints'] as $ep) { print '<option value="' . $ep['id'] . '" ' . (in_array($ep['id'], $item['endpoints'], true) ? 'selected' : '') . '>' . alarmmanager_escape($ep['name'] . ' #' . $ep['id']) . '</option>'; } print '</select></label>'; }
			else {
				alarmmanager_input('priority', 'Priority (lower first)', $item['priority'] ?? 100, 'number', 'min="0" max="10000" required');
				print '<label>' . alarmmanager_label('Profile') . '<select name="profile_id" required>'; foreach ($config['profiles'] as $profile) { print '<option value="' . $profile['id'] . '" ' . ($profile['id'] === $item['profile_id'] ? 'selected' : '') . '>' . alarmmanager_escape($profile['name']) . '</option>'; } print '</select></label>';
				alarmmanager_checkbox('stop', 'Stop after this rule', $item['stop'] ?? false); alarmmanager_textarea('policy_json', 'Selectors, schedules and suppression policy JSON', alarmmanager_json_text($item['policy']));
			}
			print '<button type="submit">' . alarmmanager_label('Save') . '</button></form></details>';
		}
	} elseif ($section === 'credentials') {
		$status = alarmmanager_db_rows('SELECT ep.id, ep.name, CASE WHEN c.endpoint_id IS NULL THEN 0 ELSE 1 END AS configured FROM plugin_alarmmanager_endpoints AS ep LEFT JOIN plugin_alarmmanager_credentials AS c ON c.endpoint_id = ep.id WHERE ep.type IN (?, ?) ORDER BY ep.id LIMIT 200', ['restapi', 'webhook']);
		print '<p>' . alarmmanager_label('Credential values are never loaded into this page. Saving replaces the endpoint record; blank values do not preserve an old password. Use the CLI for protected backup/restore and poller provisioning.') . '</p>';
		foreach ($status as $row) {
			print '<details class="alarmmanagerDashboardCard"><summary>' . alarmmanager_escape($row['name']) . ' #' . $row['id'] . ' — ' . alarmmanager_label($row['configured'] ? 'Configured' : 'Not configured') . '</summary>';
			alarmmanager_form_start('credential_save', $revision, ['id' => $row['id']]); alarmmanager_textarea('headers', 'Authentication headers JSON (new values)', '{}', 65536); alarmmanager_input('basic_username', 'Basic username (optional)', '', 'text', 'autocomplete="off" maxlength="4096"'); alarmmanager_input('basic_password', 'Basic password (optional)', '', 'password', 'autocomplete="new-password" maxlength="4096"'); print '<button type="submit">' . alarmmanager_label('Replace credential') . '</button></form>'; alarmmanager_post_form('credential_remove', ['id' => $row['id']], 'Remove credential'); print '</details>';
		}
		alarmmanager_form_start('key_rotate', $revision); alarmmanager_checkbox('confirmed', 'Confirm local key rotation; create a protected backup before and after'); print '<button type="submit">' . alarmmanager_label('Rotate local key') . '</button></form>';
	} elseif ($section === 'test') {
		foreach (['preview' => 'Preview (no traffic)', 'test_send' => 'Queue explicit test send'] as $action => $label) {
			alarmmanager_form_start($action, $revision); print '<h2>' . alarmmanager_label($label) . '</h2><label>' . alarmmanager_label('Endpoint') . '<select name="id">'; foreach ($config['endpoints'] as $ep) { print '<option value="' . $ep['id'] . '">' . alarmmanager_escape($ep['name']) . '</option>'; } print '</select></label>'; alarmmanager_textarea('event_json', 'Normalized sample event JSON', file_get_contents(__DIR__ . '/../examples/event.json'), 65536);
			if ($action === 'test_send') { alarmmanager_checkbox('confirmed', 'Confirm this will notify the selected endpoint through workers'); } print '<button type="submit">' . alarmmanager_label($label) . '</button></form>';
		}
	} elseif ($section === 'configuration') {
		alarmmanager_form_start('configuration_save', $revision); alarmmanager_textarea('configuration_json', 'Complete local routing configuration (no secrets)', alarmmanager_json_text($config), 1048576); print '<button type="submit">' . alarmmanager_label('Apply configuration') . '</button></form><p>' . alarmmanager_label('Removed IDs are disabled, preserving history. Concurrent edits reject stale revisions. Provision each remote poller explicitly.') . '</p>';
	} elseif ($section === 'health') {
		print '<h2>' . alarmmanager_label('Local worker health') . '</h2><pre>' . alarmmanager_escape(json_encode(alarmmanager_db_rows('SELECT role, poller_id, pid, status, current_job_id, heartbeat_at FROM plugin_alarmmanager_workers WHERE poller_id = ? ORDER BY heartbeat_at DESC LIMIT 32', [alarmmanager_local_poller()]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre><p><a href="alarmmanager.php?tab=processing&status=failed">' . alarmmanager_label('Failed processing jobs and replay') . '</a></p><p><a href="alarmmanager.php?tab=deliveries&status=failed">' . alarmmanager_label('Failed deliveries and replay') . '</a></p>';
	} else {
		$rows = alarmmanager_db_rows('SELECT id, user_id, action, resource_id, created_at FROM plugin_alarmmanager_audit ORDER BY id DESC LIMIT 50'); print '<h2>' . alarmmanager_label('Latest 50 administration actions') . '</h2><pre>' . alarmmanager_escape(json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre>';
	}
}
