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
	print '<form method="post" action="alarmmanager_admin.php"' . ($action === 'configuration_save' ? ' enctype="multipart/form-data"' : '') . ' class="alarmmanagerEditor' . (['endpoint_save' => ' alarmmanagerEndpointEditor', 'endpoint_delete' => ' alarmmanagerEndpointDelete', 'queue_clear' => ' alarmmanagerConfirm'][$action] ?? '') . '">';
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
/** @param array<int|string, string> $options value => untranslated label */
function alarmmanager_select(string $key, string $label, array $options, string $selected): void {
	print '<label>' . __esc($label, 'alarmmanager') . ' <select name="' . $key . '">';

	foreach ($options as $value => $text) {
		// Numeric-string keys such as SNMP version "3" arrive as integers.
		print '<option value="' . html_escape((string) $value) . '"' . ($selected === (string) $value ? ' selected' : '') . '>' . __esc($text, 'alarmmanager') . '</option>';
	}
	print '</select></label>';
}
function alarmmanager_textarea(string $key, string $label, string $value, int $limit = 131072, string $extra = ''): void {
	print '<label class="alarmmanagerFullWidth">' . __esc($label, 'alarmmanager') . '<textarea name="' . $key . '" rows="8" maxlength="' . $limit . '" spellcheck="false"' . ($extra === '' ? '' : ' ' . $extra) . '>' . html_escape($value) . '</textarea></label>';
}
function alarmmanager_json_text($value): string {
	return json_encode($value ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
/**
 * @param array<string, mixed>  $mapping
 * @param array<string, string> $summaries
 */
function alarmmanager_mapping_editor(array $mapping, array $summaries = [], string $name = 'mapping_json', string $type = '', string $title = 'Default message template'): void {
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerMapping" data-mapping-type="' . html_escape($type) . '" data-alarm-summary="' . html_escape($summaries['offline'] ?? 'Device Offline') . '" data-message-template="' . html_escape(alarmmanager_message_template()) . '"><legend>' . __esc($title, 'alarmmanager') . '</legend><p>' . __esc('Choose the alarm information to send to any service. Use Combined text to arrange device details, or Use device message to start with a complete message. Email uses Subject and Message; API destinations use the field names required by the receiving service.', 'alarmmanager') . '</p><div class="alarmmanagerMappingBuilder"></div>';
	print '<details class="alarmmanagerRawMapping"><summary>' . __esc('Advanced message configuration', 'alarmmanager') . '</summary>';
	alarmmanager_textarea($name, 'Message configuration JSON', alarmmanager_json_text($mapping));

	print '</details>';
	print '</fieldset>';
}
/**
 * @param array<string, mixed>  $endpoint
 * @param array<string, string> $summaries
 */
function alarmmanager_endpoint_editor(array $endpoint, int $revision, bool $new = false, array $summaries = []): void {
	print '<details class="alarmmanagerDashboardCard" ' . ($new ? 'open' : '') . '><summary>' . html_escape($new ? __('New endpoint', 'alarmmanager') : $endpoint['name']) . '</summary>';
	alarmmanager_form_start('endpoint_save', $revision, $new ? [] : ['id' => $endpoint['id']]);
	alarmmanager_input('name', 'Name', $endpoint['name'], 'text', 'maxlength="128" required');
	alarmmanager_checkbox('enabled', 'Enabled', $endpoint['enabled'] ?? true);
	print '<input type="hidden" name="endpoint_fields" value="yes">';
	$transport = $endpoint['transport'] ?? [];
	$provider  = $transport['provider'] ?? ($endpoint['integration_provider'] ?? '');
	$providers = alarmmanager_provider_definitions();
	// SNMP trap and syslog destinations have one fixed type and no HTTP provider presets.
	$fixed = in_array($endpoint['type'], ['snmptrap', 'syslog'], true);
	print '<label' . ($fixed ? ' hidden' : '') . '>' . __esc('Integration provider', 'alarmmanager') . ' <select name="provider" data-provider-definitions="' . html_escape(json_encode($providers, JSON_THROW_ON_ERROR)) . '" data-provider-templates="' . html_escape(json_encode(alarmmanager_integration_templates(), JSON_THROW_ON_ERROR)) . '"><option value="">' . __esc('Custom integration', 'alarmmanager') . '</option>';

	foreach ($providers as $key => $definition) {
		print '<option value="' . $key . '" ' . ($provider === $key ? 'selected' : '') . '>' . __esc($definition['name'], 'alarmmanager') . '</option>';
	}
	print '</select></label><input type="hidden" name="provider_reset" value=""><div class="alarmmanagerFullWidth alarmmanagerProviderSettings">';

	foreach (['account_sid' => ['Account SID (AC…)', 'twilio twilio_whatsapp'], 'instance_url' => ['ServiceNow instance URL', 'servicenow servicenow_events']] as $key => [$label, $choices]) {
		print '<div data-providers="' . $choices . '">';
		alarmmanager_input('provider_' . $key, $label, alarmmanager_provider_value($provider, $key, $transport), 'text', 'maxlength="2048"');
		print '</div>';
	}
	print '<div data-providers="twilio twilio_whatsapp"><label>' . __esc('Twilio credentials', 'alarmmanager') . ' <select name="twilio_auth_mode"><option value="api_key">' . __esc('API Key SID and Secret (recommended)', 'alarmmanager') . '</option><option value="account">' . __esc('Account SID and Auth Token', 'alarmmanager') . '</option></select></label></div></div>';
	print '<label' . ($fixed ? ' hidden data-fixed-type' : '') . '>' . __esc('Send using', 'alarmmanager') . ' <select name="type">';

	foreach ([($endpoint['type'] === 'webhook' ? 'webhook' : 'restapi') => 'Webhook / REST API', 'email' => 'Email', 'script' => 'Script', 'snmptrap' => 'SNMP trap', 'syslog' => 'Syslog'] + ($endpoint['type'] === 'websocket' ? ['websocket' => 'WebSocket (unsupported, saved)'] : []) as $type => $label) {
		print '<option value="' . $type . '" ' . ($endpoint['type'] === $type ? 'selected' : '') . '>' . __esc($label, 'alarmmanager') . '</option>';
	} print '</select></label>';

	$transport = $endpoint['transport'] ?? [];

	if ($provider === '' && (($endpoint['provider'] ?? '') === 'servicenow' || preg_match('~/api/now/table/(incident|em_event)(?:[/?]|$)~', $transport['url'] ?? ''))) {
		$templates = alarmmanager_integration_templates();
		$presets   = ['incident' => $templates['servicenow'], 'em_event' => $templates['servicenow_events']];
		$events    = str_contains($transport['url'] ?? '', '/api/now/table/em_event');
		print '<label class="alarmmanagerFullWidth alarmmanagerServiceNowType" data-endpoint-types="restapi webhook">' . __esc('ServiceNow record type', 'alarmmanager') . ' <select name="servicenow_table" style="width:100%" data-servicenow-presets="' . html_escape(json_encode($presets, JSON_THROW_ON_ERROR)) . '"><option value="incident" ' . (!$events ? 'selected' : '') . '>' . __esc('Incident', 'alarmmanager') . '</option><option value="em_event" ' . ($events ? 'selected' : '') . '>' . __esc('Event', 'alarmmanager') . '</option></select></label>';
		print '<p class="alarmmanagerFullWidth">' . __esc('Changing the record type replaces the destination table, message fields and recovery settings with its defaults. Your instance and credentials are retained.', 'alarmmanager') . '</p>';
	}
	$mapping   = $transport['mapping'] ?? ($new ? alarmmanager_integration_templates()['webhook']['transport']['mapping'] : ['default' => []]);

	if ($provider === 'pagerduty') {
		foreach ($mapping as &$items) {
			$items = array_values(array_filter($items, static fn ($item) => ($item['to'] ?? '') !== 'routing_key'));
		}
		unset($items);
	}
	unset($transport['mapping']);

	if ($provider !== '') {
		$endpoint['hint'] = 'Enter the provider settings and credentials below. Message fields use the provider’s standard payload. Save a disabled draft first, then enable and Test destination when ready.';
	}

	if (!empty($transport['teams_workflow'])) {
		$endpoint['hint'] = alarmmanager_integration_templates()['teams']['hint'];
	}

	if (isset($endpoint['hint'])) {
		print '<p class="alarmmanagerFullWidth" data-integration-hint>' . __esc($endpoint['hint'], 'alarmmanager') . '</p>';
	}
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerDestination" data-endpoint-types="restapi webhook"><legend>' . __esc('Destination', 'alarmmanager') . '</legend>';
	alarmmanager_input('url', 'URL', $transport['url'] ?? '', 'url', 'maxlength="2048"' . (!empty($transport['teams_workflow']) ? ' readonly' : ''));
	print '</fieldset>';
	print '<div class="alarmmanagerFullWidth" data-endpoint-types="restapi webhook">';
	alarmmanager_auth_editor(!$new ? (!empty($transport['credential']) ? 'keep' : 'none') : ($providers[$provider]['auth'] ?? ($endpoint['auth'] ?? 'none')), false, $new);
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
	alarmmanager_trap_syslog_editor($transport);
	print '<p class="alarmmanagerFullWidth" data-endpoint-types="websocket">' . __esc('WebSocket delivery is not implemented.', 'alarmmanager') . '</p>';
	alarmmanager_mapping_editor($mapping, $summaries);
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
 * Integration templates as a searchable card gallery. Cards are plain links so Cacti's
 * jQuery UI select and button widgets never restyle them, and they work without JavaScript.
 * @param array<string, array<string, mixed>> $templates
 */
function alarmmanager_template_gallery(array $templates, bool $open): void {
	$groups = [
		'messaging' => [__('Chat and messaging', 'alarmmanager'), [
			'slack'           => __('Post alarms to a Slack channel.', 'alarmmanager'),
			'teams'           => __('Post alarm cards through a Teams Workflow.', 'alarmmanager'),
			'telegram'        => __('Send alarms from a Telegram bot.', 'alarmmanager'),
			'twilio'          => __('Text alarms to phone numbers.', 'alarmmanager'),
			'twilio_whatsapp' => __('Send approved WhatsApp templates.', 'alarmmanager')]],
		'incident' => [__('Incident management', 'alarmmanager'), [
			'pagerduty'  => __('Trigger and resolve PagerDuty incidents.', 'alarmmanager'),
			'servicenow' => __('Open and resolve ServiceNow incidents or events.', 'alarmmanager')]],
		'network' => [__('Network management', 'alarmmanager'), [
			'snmptrap' => __('Send SNMPv2c or SNMPv3 traps and INFORMs.', 'alarmmanager'),
			'syslog'   => __('Forward RFC 5424 or RFC 3164 messages over UDP, TCP or TLS.', 'alarmmanager')]],
		'custom' => [__('Custom', 'alarmmanager'), [
			''        => __('Start from an empty endpoint.', 'alarmmanager'),
			'webhook' => __('POST alarm JSON to any HTTPS endpoint.', 'alarmmanager'),
			'restapi' => __('Call an API with your own method, authentication and response checks.', 'alarmmanager'),
			'script'  => __('Run a local executable with the alarm as JSON input.', 'alarmmanager')]]
	];
	// Inline 24px stroke icons per category; currentColor follows the Cacti theme.
	$svg   = static fn (string $path): string => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
	$icons = ['messaging' => $svg('<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'), 'incident' => $svg('<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>'),
		'network'            => $svg('<rect x="9" y="3" width="6" height="5" rx="1"/><rect x="3" y="16" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M6 16v-4h12v4"/>'), 'custom' => $svg('<path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/>'), 'blank' => $svg('<path d="M12 5v14M5 12h14"/>')];
	print '<details class="alarmmanagerTemplateGallery"' . ($open ? ' open' : '') . '><summary>' . __esc('Add integration', 'alarmmanager') . '</summary>';
	print '<div class="alarmmanagerGalleryHead"><p>' . __esc('Choose a template. It opens as a disabled draft; nothing is sent until you enable it.', 'alarmmanager') . '</p><input type="search" data-template-search hidden placeholder="' . __esc('Search integrations', 'alarmmanager') . '" aria-label="' . __esc('Search integrations', 'alarmmanager') . '"></div>';

	foreach ($groups as $category => [$heading, $cards]) {
		print '<section class="alarmmanagerTemplateGroup"><h4>' . html_escape($heading) . '</h4><ul>';

		foreach ($cards as $key => $description) {
			$name = $key === '' ? __('Blank endpoint', 'alarmmanager') : ($key === 'servicenow' ? 'ServiceNow' : __($templates[$key]['name'], 'alarmmanager'));
			print '<li><a class="alarmmanagerTemplateCard" data-category="' . $category . '" href="alarmmanager_admin.php?section=endpoints&amp;add=1' . ($key === '' ? '' : '&amp;integration_template=' . $key) . '"><span class="alarmmanagerTemplateIcon" aria-hidden="true">' . $icons[$key === '' ? 'blank' : $category] . '</span><span><strong>' . html_escape($name) . '</strong><span>' . html_escape($description) . '</span></span></a></li>';
		}
		print '</ul></section>';
	}
	print '<p class="alarmmanagerTemplateEmpty" hidden>' . __esc('No integrations match your search.', 'alarmmanager') . '</p></details>';
}
/** @param array<string, mixed> $transport */
function alarmmanager_trap_syslog_editor(array $transport): void {
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerSnmp" data-endpoint-types="snmptrap"><legend>' . __esc('SNMP trap settings', 'alarmmanager') . '</legend>';
	alarmmanager_input('snmp_host', 'Receiver hostname or IP address', $transport['host'] ?? '', 'text', 'maxlength="253"');
	alarmmanager_input('snmp_port', 'Port', $transport['port'] ?? 162, 'number', 'min="1" max="65535"');
	alarmmanager_select('snmp_transport', 'Transport', ['udp' => 'UDP', 'tcp' => 'TCP', 'udp6' => 'UDP over IPv6', 'tcp6' => 'TCP over IPv6'], $transport['transport'] ?? 'udp');
	alarmmanager_select('snmp_notification', 'Notification', ['trap' => 'Trap (unacknowledged)', 'inform' => 'INFORM (acknowledged by the receiver)'], $transport['notification'] ?? 'trap');
	alarmmanager_select('snmp_version', 'SNMP version', ['2c' => 'SNMPv2c', '3' => 'SNMPv3'], $transport['version'] ?? '2c');
	print '<div data-snmp-versions="2c">';
	alarmmanager_input('snmp_community', 'Community (secret)', '', 'password', 'autocomplete="new-password" maxlength="256"');
	print '</div><div class="alarmmanagerFullWidth" data-snmp-versions="3">';
	alarmmanager_input('snmp_security_name', 'Security name', $transport['security_name'] ?? '', 'text', 'maxlength="32" autocomplete="off"');
	alarmmanager_select('snmp_security_level', 'Security level', ['authPriv' => 'Authentication and privacy', 'authNoPriv' => 'Authentication only', 'noAuthNoPriv' => 'No authentication'], $transport['security_level'] ?? 'authPriv');
	alarmmanager_select('snmp_auth_protocol', 'Authentication protocol', ['SHA' => 'SHA-1', 'SHA-224' => 'SHA-224', 'SHA-256' => 'SHA-256', 'SHA-384' => 'SHA-384', 'SHA-512' => 'SHA-512'], $transport['auth_protocol'] ?? 'SHA');
	alarmmanager_input('snmp_auth_passphrase', 'Authentication passphrase', '', 'password', 'autocomplete="new-password" maxlength="256"');
	alarmmanager_select('snmp_priv_protocol', 'Privacy protocol', ['AES' => 'AES-128', 'AES-192' => 'AES-192', 'AES-256' => 'AES-256'], $transport['priv_protocol'] ?? 'AES');
	alarmmanager_input('snmp_priv_passphrase', 'Privacy passphrase', '', 'password', 'autocomplete="new-password" maxlength="256"');
	alarmmanager_input('snmp_engine_id', 'Engine ID for traps (blank uses the local engine)', $transport['engine_id'] ?? '', 'text', 'maxlength="66" placeholder="0x80001f8880…"');
	print '</div><p>' . __esc('Saved community and passphrases are encrypted and never displayed. Leave them blank to keep the saved values; entering any value replaces all saved SNMP secrets.', 'alarmmanager') . '</p>';
	print '<p>' . __esc('Traps are sent as Cacti\'s CACTI-MIB cactiNotify with the snmptrap binary from Cacti Settings > Paths on every delivering poller. Add one destination per receiver.', 'alarmmanager') . '</p></fieldset>';
	print '<fieldset class="alarmmanagerFullWidth" data-endpoint-types="syslog"><legend>' . __esc('Syslog settings', 'alarmmanager') . '</legend>';
	alarmmanager_input('syslog_host', 'Receiver hostname or IP address', $transport['host'] ?? '', 'text', 'maxlength="253"');
	alarmmanager_input('syslog_port', 'Port', $transport['port'] ?? 514, 'number', 'min="1" max="65535"');
	alarmmanager_select('syslog_protocol', 'Protocol', ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], $transport['protocol'] ?? 'udp');
	alarmmanager_select('syslog_format', 'Format', ['rfc5424' => 'RFC 5424 with structured data', 'rfc3164' => 'RFC 3164 (legacy BSD)'], $transport['format'] ?? 'rfc5424');
	alarmmanager_select('syslog_facility', 'Facility', array_combine(array_keys(alarmmanager_syslog_facilities()), array_keys(alarmmanager_syslog_facilities())), $transport['facility'] ?? 'local0');
	alarmmanager_input('syslog_app_name', 'Application name', $transport['app_name'] ?? 'cacti-alarmmanager', 'text', 'maxlength="48"');
	alarmmanager_input('syslog_ca_bundle', 'Custom certificate authority for TLS (PEM file path)', $transport['ca_bundle'] ?? '', 'text', 'maxlength="1024" placeholder="/etc/cacti/alarmmanager/syslog-ca.pem"');
	print '<p>' . __esc('TLS verifies the receiver certificate and hostname. Use port 6514 for TLS. UDP and TCP are unencrypted, and a UDP message is confirmed only as sent.', 'alarmmanager') . '</p></fieldset>';
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

	print '<fieldset class="alarmmanagerRuleSection"><legend>' . __esc('Message template', 'alarmmanager') . '</legend><p>' . __esc('Use each destination default, or customize its message for this rule. Customization starts with a copy of the destination template and does not change other rules. If several rules match the same destination, the first matching rule in priority and ID order supplies its template.', 'alarmmanager') . '</p>';

	foreach ($configuration['endpoints'] as $endpoint) {
		$id         = (int) $endpoint['id'];
		$custom     = isset($policy['template_overrides'][$id]);
		$twilio     = in_array($endpoint['transport']['provider'] ?? '', ['twilio', 'twilio_whatsapp'], true);
		$recipients = $policy['recipient_overrides'][$id] ?? [];
		print '<details data-rule-template="' . $id . '"' . (in_array($id, $selected, true) ? '' : ' hidden') . '><summary><span>' . html_escape($endpoint['name']) . '</span><span class="alarmmanagerBadge" data-template-state="' . ($custom ? 'custom' : 'default') . '">' . __esc($custom ? 'Custom template' : 'Default template', 'alarmmanager') . '</span>';

		if ($twilio) {
			print '<span class="alarmmanagerBadge" data-recipient-count>' . ($recipients ? __esc('Recipients: %d', count($recipients), 'alarmmanager') : __esc('Destination recipient', 'alarmmanager')) . '</span>';
		}
		print '</summary><label><input type="checkbox" name="template_enabled[]" value="' . $id . '"' . ($custom ? ' checked' : '') . '> ' . __esc('Customize message for this rule', 'alarmmanager') . '</label><p>' . __esc('Unchecked: Use destination default.', 'alarmmanager') . '</p>';

		if ($twilio) {
			alarmmanager_textarea('recipient_numbers[' . $id . ']', 'Twilio recipients for this rule', implode("\n", $recipients), 2048, 'data-phone-list');
			print '<p class="alarmmanagerHint">' . __esc('Optional: add each phone number in international format, such as +15551234567. These numbers replace the destination recipient for this rule. Leave the list empty to use the destination default. Each number has its own delivery status and retries.', 'alarmmanager') . '</p>';
		}
		alarmmanager_mapping_editor($policy['template_overrides'][$id] ?? $endpoint['transport']['mapping'] ?? [], alarmmanager_device_summaries(), 'template_mapping[' . $id . ']', $endpoint['type'], 'Rule message template');
		print '</details>';
	}
	print '</fieldset>';

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
	alarmmanager_checkbox('all_alarm_types', 'Applies to all alarm sources', $alarm_query === '');
	print '</div>';
	$alarm_fields = alarmmanager_rule_alarm_fields();

	foreach (['source' => 'sources', 'severity' => 'severities', 'transition' => 'transitions', 'device_id' => 'device_ids', 'collector_id' => 'collector_ids'] as $field => $choice) {
		$alarm_fields[$field]['choices'] = (object) ($choices[$choice] ?? []);
	}

	foreach (['cacti_device' => __('Device Status', 'alarmmanager'), 'syslog' => __('Syslog', 'alarmmanager'), 'thold' => __('Thold', 'alarmmanager'), 'cacti' => __('Cacti_Admin_Notifications', 'alarmmanager')] as $source => $label) {
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

	// Rules print their own heading and introduction.
	if ($section !== 'rules') {
		print '<h2>' . __esc(alarmmanager_admin_sections()[$section] ?? 'Configuration Backup/Restore', 'alarmmanager') . '</h2>';
	}

	if ($section === 'queue') {
		$paused = alarmmanager_notifications_paused();
		$count  = alarmmanager_db_row("SELECT COUNT(*) AS waiting, MIN(d.created_at) AS oldest FROM plugin_alarmmanager_deliveries AS d
			INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
			WHERE d.status = 'pending' AND e.processing_poller_id = ?", [alarmmanager_local_poller()]);
		$waiting = (int) ($count['waiting'] ?? 0);
		print '<section class="alarmmanagerSettingsCard alarmmanagerQueueBar"><header>';
		print '<span class="alarmmanagerBadge" data-state="' . ($paused ? 'paused' : 'running') . '">' . ($paused ? __esc('Paused', 'alarmmanager') : __esc('Running', 'alarmmanager')) . '</span></header>';
		print '<div class="alarmmanagerQueueCounter"><strong>' . $waiting . '</strong><div><span>' . __esc('waiting messages', 'alarmmanager') . '</span><small>' . ($waiting ? __esc('oldest queued %s UTC', substr((string) $count['oldest'], 0, 16), 'alarmmanager') : __esc('everything has been delivered', 'alarmmanager')) . '</small></div>';

		if ($waiting) {
			print '<button type="button" class="alarmmanagerQueueToggle" aria-expanded="false" aria-controls="alarmmanager_queue_messages" data-show="' . __esc('View messages', 'alarmmanager') . '" data-hide="' . __esc('Hide messages', 'alarmmanager') . '">' . __esc('View messages', 'alarmmanager') . '</button>';
		}
		print '</div>';

		if ($waiting) {
			$rows = alarmmanager_db_rows("SELECT d.id, ep.name AS endpoint, JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.summary')) AS summary, d.created_at, d.attempts, d.available_at
				FROM plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
				LEFT JOIN plugin_alarmmanager_endpoints AS ep ON ep.id = d.endpoint_id
				WHERE d.status = 'pending' AND e.processing_poller_id = ? ORDER BY d.id LIMIT 100", [alarmmanager_local_poller()]);
			print '<div id="alarmmanager_queue_messages" class="alarmmanagerQueueMessages" hidden><table class="cactiTable">';
			html_header([__esc('ID', 'alarmmanager'), __esc('Endpoint', 'alarmmanager'), __esc('Message', 'alarmmanager'), __esc('Queued', 'alarmmanager'), __esc('Tries', 'alarmmanager'), __esc('Next try', 'alarmmanager')], 1);

			foreach ($rows as $row) {
				form_alternate_row();

				foreach ($row as $value) {
					form_selectable_cell(html_escape((string) $value), (int) $row['id'], '', 'left');
				}
				print '</tr>';
			}
			print '</table><p class="alarmmanagerHint">' . __esc('Times in UTC · first 100 shown', 'alarmmanager') . '</p></div>';
		}
		print '<div class="alarmmanagerQueueActions">';
		alarmmanager_form_start($paused ? 'queue_resume' : 'queue_pause', $revision);
		print '<button type="submit"' . ($paused ? ' class="alarmmanagerPrimaryAction">' . __esc('Resume delivery', 'alarmmanager') : '>' . __esc('Pause delivery', 'alarmmanager')) . '</button></form>';
		alarmmanager_form_start('queue_clear', $revision);
		// The dialog adds confirmed=yes; without it the server rejects the clear.
		print '<div class="alarmmanagerConfirmDialog" hidden title="' . __esc('Clear queue', 'alarmmanager') . '" data-confirm="' . __esc('Clear queue', 'alarmmanager') . '" data-cancel="' . __esc('Cancel', 'alarmmanager') . '">';
		print '<p><strong>' . __esc('Discard all %d waiting notifications on this poller?', $waiting, 'alarmmanager') . '</strong></p><ul>';
		print '<li>' . __esc('Includes retries. They stay in delivery history as failed, reason queue_cleared.', 'alarmmanager') . '</li>';
		print '<li>' . __esc('Messages already sending and alarm state are kept.', 'alarmmanager') . '</li>';
		print '<li>' . __esc('Tip: pause delivery first during maintenance.', 'alarmmanager') . '</li></ul></div>';
		print '<button type="submit" class="alarmmanagerDangerAction"' . ($waiting ? '' : ' disabled') . '>' . __esc('Clear queue…', 'alarmmanager') . '</button></form>';
		print '</div><p class="alarmmanagerHint">' . __esc('Controls apply to this poller. While paused, alarms are still received and queued; Resume sends them.', 'alarmmanager') . '</p></section>';
	} elseif ($section === 'endpoints') {
		if (isset($_SESSION['alarmmanager_test_result'])) {
			print '<p role="status">' . html_escape($_SESSION['alarmmanager_test_result']) . '</p><p><a href="alarmmanager.php?tab=events&amp;job=delivery">' . __esc('View delivery results', 'alarmmanager') . '</a></p>';
			unset($_SESSION['alarmmanager_test_result']);
		}
		print '<p>' . __esc('Create an integration, configure its destination and credentials, test a sample alarm here, then select it directly in a rule.', 'alarmmanager') . '</p>';
		$templates = alarmmanager_integration_templates();
		$providers = alarmmanager_provider_definitions();
		$selected  = (string) get_filter_request_var('integration_template', FILTER_CALLBACK, ['options' => static fn ($value) => is_string($value) && isset($templates[$value]) ? $value : false, 'default' => '']);
		$adding    = $selected !== '' || (string) get_filter_request_var('add', FILTER_CALLBACK, ['options' => static fn ($value) => $value === '1' ? '1' : false, 'default' => '']) === '1';
		alarmmanager_template_gallery($templates, !$adding && !$config['endpoints']);
		print '<h3 class="alarmmanagerSectionDivider">' . __esc('Configured integrations', 'alarmmanager') . ' <span class="alarmmanagerBadge">' . count($config['endpoints']) . '</span></h3>';

		if (!$config['endpoints'] && !$adding) {
			print '<p class="alarmmanagerHint">' . __esc('No integrations yet. Choose a template above to create one.', 'alarmmanager') . '</p>';
		}

		foreach ($config['endpoints'] as $item) {
			alarmmanager_endpoint_editor($item, $revision, false, alarmmanager_device_summaries());
		}

		if ($adding) {
			alarmmanager_endpoint_editor(['id' => 0, 'enabled' => false, 'integration_provider' => isset($providers[$selected]) ? $selected : ''] + ($templates[$selected] ?? ['name' => '', 'type' => 'restapi']), $revision, true, alarmmanager_device_summaries());
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
		print '<p><a class="alarmmanagerPrimaryAction" data-icon="add" href="#alarmmanager_new_rule">' . __esc('Create a rule', 'alarmmanager') . '</a></p>';

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

		print '<section class="alarmmanagerSettingsCard"><header><h3>' . __esc('Backup and restore', 'alarmmanager') . '</h3></header><div class="alarmmanagerSettingsSplit"><div><h4>' . __esc('Export', 'alarmmanager') . '</h4>';
		print '<p class="alarmmanagerHint">' . __esc('Endpoints, message templates, and rules as JSON. Credentials and settings are not included. Endpoint URLs can contain secrets, so keep the file private.', 'alarmmanager') . '</p>';
		print '<a class="alarmmanagerPrimaryAction" href="alarmmanager_admin.php?section=configuration&amp;download=configuration">' . __esc('Export configuration', 'alarmmanager') . '</a></div><div><h4>' . __esc('Import', 'alarmmanager') . '</h4>';
		alarmmanager_form_start('configuration_save', $revision);
		print '<input type="hidden" name="MAX_FILE_SIZE" value="1048576">';
		alarmmanager_input('configuration_file', 'Configuration file (JSON, up to 1 MiB)', '', 'file', 'accept=".json,application/json" required');
		print '<p class="alarmmanagerHint">' . __esc('Replaces the routing configuration using the IDs in the file. Endpoints and rules missing from the file are disabled, not deleted. Saved credentials are kept.', 'alarmmanager') . '</p>';
		print '<button type="submit" class="alarmmanagerPrimaryAction">' . __esc('Import configuration', 'alarmmanager') . '</button></form></div></div></section>';
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

function alarmmanager_auth_editor(string $selected, bool $replacement = false, bool $optional = false): void {
	print '<fieldset class="alarmmanagerFullWidth alarmmanagerAuth"' . ($optional ? ' data-auth-optional="1"' : '') . '><legend>' . __esc('Authentication', 'alarmmanager') . '</legend><label>' . __esc('Authentication type', 'alarmmanager') . ' <select name="auth_type">';
	$options = $replacement ? [] : ['none' => 'None'];

	if ($selected === 'keep') {
		$options['keep'] = 'Keep saved authentication';
	}
	$options += ['bearer' => 'Bearer token', 'basic' => 'Basic authentication', 'headers' => 'Custom authentication headers', 'teams' => 'Teams Workflow URL', 'provider_token' => 'Provider token'];

	foreach ($options as $value => $label) {
		print '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . __esc($label, 'alarmmanager') . '</option>';
	}
	print '</select></label><div class="alarmmanagerAuthFields" data-auth-type="provider_token">';
	alarmmanager_input('provider_token', 'Provider token', '', 'password', 'autocomplete="new-password" maxlength="4096"');
	print '</div><div class="alarmmanagerAuthFields" data-auth-type="teams">';
	alarmmanager_input('teams_webhook_url', 'Teams Workflow URL (secret)', '', 'password', 'autocomplete="new-password" maxlength="4096"');
	print '</div><div class="alarmmanagerAuthFields" data-auth-type="bearer">';
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
