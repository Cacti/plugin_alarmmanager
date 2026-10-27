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
require_once(__DIR__ . '/routing.php');

function alarmmanager_message_template(): string {
	return "{{alarm.summary}}\nHostname: {{device.hostname}}\nDescription: {{device.description}}\nLocation: {{device.location}}\nContact: {{device.contact}}\nSystem name: {{device.sysname}}\nSeverity: {{alarm.severity}}\nState: {{alarm.transition}}\nTime: {{event.occurred_at}}";
}

/**
 * Templates create disabled drafts only; no endpoint IDs or credentials are copied.
 * The 'auth' and 'hint' keys steer the editor preselect and guidance only and never persist.
 */
/** @return array<string, array<string, mixed>> */
function alarmmanager_integration_templates(): array {
	$mapping = ['default' => [
		['to' => 'event_id', 'source' => 'event.id', 'required' => true],
		['to' => 'episode_id', 'source' => 'alarm.episode_id', 'required' => true],
		['to' => 'hostname', 'source' => 'device.hostname'],
		['to' => 'description', 'source' => 'device.description'],
		['to' => 'location', 'source' => 'device.location'],
		['to' => 'contact', 'source' => 'device.contact'],
		['to' => 'sysname', 'source' => 'device.sysname'],
		['to' => 'occurred_at', 'source' => 'event.occurred_at'],
		['to' => 'message', 'template' => alarmmanager_message_template(), 'type' => 'string'],
		['to' => 'summary', 'source' => 'alarm.summary'],
		['to' => 'details', 'source' => 'alarm.details'],
		['to' => 'severity', 'source' => 'alarm.severity'],
		['to' => 'state', 'source' => 'alarm.transition'],
		['to' => 'external_id', 'source' => 'integration.id']
	]];
	$http            = ['url' => 'https://receiver.example.invalid/alarms', 'method' => 'POST', 'credential' => false, 'mapping' => $mapping];
	$pd_severity     = ['critical' => 'critical', 'major' => 'error', 'minor' => 'warning', 'warning' => 'warning', 'info' => 'info', 'clear' => 'info'];
	$sn_urgency      = ['critical' => 1, 'major' => 2, 'minor' => 3, 'warning' => 3, 'info' => 3, 'clear' => 3];
	$sn_target       = ['url' => 'https://REPLACE-INSTANCE.service-now.com/api/now/table/incident/{{integration.id}}', 'method' => 'PATCH'];
	$sn_event_fields = [
		['to' => 'source', 'constant' => 'Cacti Alarm Manager', 'type' => 'string', 'required' => true],
		['to' => 'node', 'source' => 'device.hostname', 'type' => 'string'],
		['to' => 'type', 'source' => 'event.source', 'type' => 'string'],
		['to' => 'resource', 'source' => 'alarm.resource_id', 'type' => 'string'],
		['to' => 'metric_name', 'source' => 'alarm.key', 'type' => 'string'],
		['to' => 'message_key', 'source' => 'alarm.episode_id', 'type' => 'string', 'required' => true],
		['to' => 'description', 'source' => 'alarm.summary', 'type' => 'string', 'required' => true]
	];

	return [
		'webhook'  => ['name' => 'Generic webhook', 'type' => 'webhook', 'transport' => $http],
		'restapi'  => ['name' => 'Generic REST API', 'type' => 'restapi', 'transport' => $http],
		'script'   => ['name' => 'JSON script', 'type' => 'script', 'transport' => ['executable' => '/usr/local/bin/process_alarm.py', 'arguments' => [], 'compatibility' => 'json', 'retry_exit_codes' => [75], 'mapping' => $mapping]],
		'snmptrap' => ['name' => 'SNMP trap', 'type' => 'snmptrap',
			'hint'               => 'Replace the receiver host and enter the community or SNMPv3 passphrases. Traps use Cacti\'s CACTI-MIB cactiNotify notification; its cactiEventDescription starts with the severity and state and ends with the alarm key, so the receiver can pair a raise with its recovery. Add one destination per receiver. A trap is confirmed only as sent; choose INFORM for receiver acknowledgement.',
			'transport'          => ['host' => 'trap-receiver.example.invalid', 'version' => '2c', 'mapping' => ['default' => [
				['to' => 'message', 'source' => 'alarm.summary', 'type' => 'string', 'required' => true]
			]]]],
		'syslog' => ['name' => 'Syslog', 'type' => 'syslog',
			'hint'             => 'Replace the receiver host. RFC 5424 adds structured data [alarm@23925 key=… severity=…] so the receiver can correlate raise and recovery; RFC 3164 sends the message text only. Use TLS on port 6514 where the receiver supports it. UDP messages are confirmed only as sent.',
			'transport'        => ['host' => 'syslog-receiver.example.invalid', 'protocol' => 'udp', 'format' => 'rfc5424', 'mapping' => ['default' => [
				['to' => 'message', 'template' => alarmmanager_message_template(), 'type' => 'string', 'required' => true]
			]]]],
		'slack'   => ['name' => 'Slack message', 'type' => 'webhook', 'auth' => 'bearer',
			'hint'              => 'Enter the bot token under Authentication and replace the channel constant before enabling the endpoint.',
			'transport'         => ['url' => 'https://slack.com/api/chat.postMessage', 'method' => 'POST', 'credential' => true, 'response_match' => ['path' => 'ok', 'equals' => true], 'mapping' => ['default' => [
				['to' => 'channel', 'constant' => 'REPLACE-WITH-CHANNEL', 'type' => 'string', 'required' => true],
				['to' => 'text', 'template' => alarmmanager_message_template(), 'type' => 'string', 'required' => true]
			]]]],
		'teams' => ['name' => 'Microsoft Teams (Workflows)', 'type' => 'webhook', 'auth' => 'teams',
			'hint'            => 'In Teams Workflows, create Send webhook alerts to a channel with Anyone authentication. Sign in, select the channel, and add a co-owner. Paste the generated URL under Authentication. Save the disabled draft, review Preview, then enable and Test destination. Check workflow run history and the channel: HTTP acceptance does not confirm a Teams post. Tenant-authenticated triggers are not supported.',
			'transport'       => ['url' => 'https://teams.workflow.invalid', 'method' => 'POST', 'credential' => true, 'teams_workflow' => true, 'mapping' => ['default' => [
				['to' => 'text', 'template' => alarmmanager_message_template() . "\nSource: {{event.source}}\nCollector: {{event.collector_id}} {{device.collector_name}}\nEpisode: {{alarm.episode_id}}\nAlarm key: {{alarm.key}}", 'type' => 'string', 'required' => true]
			]]]],
		'twilio' => ['name' => 'Twilio SMS', 'type' => 'restapi', 'auth' => 'basic',
			'hint'             => 'Replace REPLACE-WITH-ACCOUNT-SID in the URL with your AC Account SID. Under Basic authentication, use an API Key SID and secret (recommended), or the Account SID and Auth Token. Set From to your Twilio sender and To to the recipient in E.164 format. Keep Body within 1,600 characters; long SMS messages incur multiple segment charges. HTTP 201 means Twilio accepted the message, not handset delivery. Check Twilio Messaging logs. Retries after an uncertain response can send duplicates.',
			'transport'        => ['url' => 'https://api.twilio.com/2010-04-01/Accounts/REPLACE-WITH-ACCOUNT-SID/Messages.json', 'method' => 'POST', 'body_encoding' => 'form', 'credential' => true, 'success_codes' => [201], 'response_reference' => ['id' => 'sid'], 'mapping' => ['default' => [
				['to' => 'From', 'constant' => 'REPLACE-WITH-TWILIO-NUMBER', 'type' => 'string', 'required' => true],
				['to' => 'To', 'constant' => 'REPLACE-WITH-RECIPIENT-NUMBER', 'type' => 'string', 'required' => true],
				['to' => 'Body', 'template' => '{{device.hostname}}: {{alarm.summary}} ({{alarm.severity}}, {{alarm.transition}})', 'type' => 'string', 'required' => true]
			]]]],
		'twilio_whatsapp' => ['name' => 'Twilio WhatsApp', 'type' => 'restapi', 'auth' => 'basic',
			'hint'                      => 'Use your Account SID in the URL and API Key SID/secret under Basic authentication. Set From and To to whatsapp:+E.164 addresses, MessagingServiceSid to your MG service, and ContentSid to an approved HX template such as Cacti alarm: {{1}}. The Combined text field supplies template variable 1. Recipients must opt in. HTTP 201 confirms acceptance only; check Twilio Messaging logs. Retries can send duplicates.',
			'transport'                 => ['url' => 'https://api.twilio.com/2010-04-01/Accounts/REPLACE-WITH-ACCOUNT-SID/Messages.json', 'method' => 'POST', 'body_encoding' => 'form', 'twilio_whatsapp' => true, 'credential' => true, 'success_codes' => [201], 'response_reference' => ['id' => 'sid'], 'mapping' => ['default' => [
				['to' => 'From', 'constant' => 'whatsapp:REPLACE-WITH-TWILIO-NUMBER', 'type' => 'string', 'required' => true],
				['to' => 'To', 'constant' => 'whatsapp:REPLACE-WITH-RECIPIENT-NUMBER', 'type' => 'string', 'required' => true],
				['to' => 'MessagingServiceSid', 'constant' => 'REPLACE-WITH-MESSAGING-SERVICE-SID', 'type' => 'string', 'required' => true],
				['to' => 'ContentSid', 'constant' => 'REPLACE-WITH-CONTENT-SID', 'type' => 'string', 'required' => true],
				['to' => 'text', 'template' => '{{device.hostname}}: {{alarm.summary}} ({{alarm.severity}}, {{alarm.transition}})', 'type' => 'string', 'required' => true]
			]]]],
		'telegram' => ['name' => 'Telegram bot', 'type' => 'webhook',
			'hint'               => 'Replace the bot token in the URL and the chat ID constant before enabling; no credential record is used.',
			'transport'          => ['url' => 'https://api.telegram.org/botREPLACE-WITH-TOKEN/sendMessage', 'method' => 'POST', 'credential' => false, 'response_match' => ['path' => 'ok', 'equals' => true], 'mapping' => ['default' => [
				['to' => 'chat_id', 'constant' => 'REPLACE-WITH-CHAT-ID', 'type' => 'string', 'required' => true],
				['to' => 'text', 'template' => alarmmanager_message_template(), 'type' => 'string', 'required' => true]
			]]]],
		'pagerduty' => ['name' => 'PagerDuty Events v2', 'type' => 'webhook',
			'hint'                => 'Replace the integration key constant before enabling; no credential record is used.',
			'transport'           => ['url' => 'https://events.pagerduty.com/v2/enqueue', 'method' => 'POST', 'credential' => false, 'response_match' => ['path' => 'status', 'equals' => 'ok'], 'mapping' => [
				'default' => [
					['to' => 'routing_key', 'constant' => 'REPLACE-WITH-INTEGRATION-KEY', 'type' => 'string', 'required' => true],
					['to' => 'event_action', 'constant' => 'trigger', 'type' => 'string', 'required' => true],
					['to' => 'dedup_key', 'source' => 'alarm.episode_id', 'required' => true],
					['to' => 'payload.summary', 'source' => 'alarm.summary', 'type' => 'string', 'required' => true],
					['to' => 'payload.source', 'source' => 'device.hostname', 'type' => 'string', 'required' => true],
					['to' => 'payload.severity', 'source' => 'alarm.severity', 'type' => 'string', 'values' => $pd_severity, 'required' => true],
					['to' => 'payload.timestamp', 'source' => 'event.occurred_at', 'type' => 'string']
				],
				'recovered' => [
					['to' => 'routing_key', 'constant' => 'REPLACE-WITH-INTEGRATION-KEY', 'type' => 'string', 'required' => true],
					['to' => 'event_action', 'constant' => 'resolve', 'type' => 'string', 'required' => true],
					['to' => 'dedup_key', 'source' => 'alarm.episode_id', 'required' => true]
				]]]],
		'servicenow' => ['name' => 'ServiceNow incidents', 'provider' => 'servicenow', 'type' => 'webhook', 'auth' => 'basic',
			'hint'                 => 'Replace the instance in the URL, add the Basic credentials after saving, and review the incident constants; recovery and update events resolve the created incident.',
			'transport'            => ['url' => 'https://REPLACE-INSTANCE.service-now.com/api/now/table/incident', 'method' => 'POST', 'credential' => true, 'response_reference' => ['id' => 'result.sys_id'], 'transition_overrides' => [
				'updated'    => $sn_target,
				'reminder'   => $sn_target,
				'occurrence' => $sn_target,
				'recovered'  => $sn_target
			], 'mapping' => [
				'raised' => [
					['to' => 'short_description', 'source' => 'alarm.summary', 'type' => 'string', 'required' => true],
					['to' => 'description', 'source' => 'alarm.details', 'type' => 'string'],
					['to' => 'urgency', 'source' => 'alarm.severity', 'type' => 'integer', 'values' => $sn_urgency, 'required' => true]
				],
				'recovered' => [
					['to' => 'state', 'constant' => 6, 'type' => 'integer', 'required' => true],
					['to' => 'close_code', 'constant' => 'Solved (Permanently)', 'type' => 'string'],
					['to' => 'work_notes', 'source' => 'alarm.summary', 'type' => 'string', 'required' => true],
					['to' => 'urgency', 'source' => 'alarm.severity', 'type' => 'integer', 'values' => $sn_urgency]
				],
				'default' => [
					['to' => 'work_notes', 'source' => 'alarm.summary', 'type' => 'string', 'required' => true],
					['to' => 'urgency', 'source' => 'alarm.severity', 'type' => 'integer', 'values' => $sn_urgency]
				]]]],
		'servicenow_events' => ['name' => 'ServiceNow events', 'provider' => 'servicenow', 'type' => 'webhook', 'auth' => 'basic',
			'hint'                        => 'Writes Event Management records to em_event. Replace the instance URL and enter credentials with permission to create events. Recovery posts severity 0 with the same message key; review the event fields for your instance.',
			'transport'                   => ['url' => 'https://REPLACE-INSTANCE.service-now.com/api/now/table/em_event', 'method' => 'POST', 'credential' => true, 'mapping' => [
				'default' => [...$sn_event_fields,
					['to' => 'severity', 'source' => 'alarm.severity', 'type' => 'integer', 'values' => ['critical' => 1, 'major' => 2, 'minor' => 3, 'warning' => 4, 'info' => 5, 'clear' => 0], 'required' => true]
				],
				'recovered' => [...$sn_event_fields,
					['to' => 'severity', 'constant' => 0, 'type' => 'integer', 'required' => true]
				]
			]]]
	];
}

/**
 * Resolve the per-transition HTTP target. Overrides key the transition vocabulary; {{integration.id}}
 * substitutes the last completed delivery's external reference. NULL when the reference has not been
 * recorded yet, which the retryable workers resolve once the creating delivery completes.
 * @param  array<string, mixed>                                    $settings
 * @param  array<string, mixed>                                    $event
 * @param  array<string, mixed>                                    $reference
 * @return ?array{url: non-empty-string, method: non-empty-string}
 */
function alarmmanager_transition_target(array $settings, array $event, array $reference): ?array {
	$target   = ['url' => $settings['url'], 'method' => $settings['method'] ?? 'POST'];
	$override = $settings['transition_overrides'][$event['transition']] ?? null;

	if ($override !== null) {
		$target['url']    = $override['url'] ?? $target['url'];
		$target['method'] = $override['method'] ?? $target['method'];
	}

	if (!is_string($target['url']) || $target['url'] === '' || !is_string($target['method']) || $target['method'] === '') {
		throw new InvalidArgumentException('invalid_transport');
	}

	if (str_contains($target['url'], '{{integration.id}}')) {
		if (!is_string($reference['id'] ?? null) || $reference['id'] === '') {
			return null;
		}
		$target['url'] = str_replace('{{integration.id}}', $reference['id'], $target['url']);
		alarmmanager_transport_url_validate($target['url'], alarmmanager_config_bool($settings['allow_http'] ?? false));
	}

	return $target;
}

/** Only explicitly selected response fields enter history; raw response bodies are discarded. */
/** @return array{id: string, url: ?string} */
function alarmmanager_external_reference_validate(mixed $reference): array {
	if (!is_array($reference)) {
		throw new InvalidArgumentException('invalid_external_reference');
	}
	alarmmanager_event_keys($reference, ['id', 'url']);
	$id = $reference['id'] ?? null;

	if (is_int($id)) {
		$id = (string) $id;
	}

	if (!is_string($id) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $id)) {
		throw new InvalidArgumentException('invalid_external_reference');
	}
	$url = $reference['url'] ?? null;

	if ($url !== null) {
		$parts = is_string($url) ? parse_url($url) : false;

		if (!is_string($url) || strlen($url) > 2048 || !$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
			throw new InvalidArgumentException('invalid_external_reference');
		}
	}

	return ['id' => $id, 'url' => $url];
}

/**
 * @param  array<string, string>           $paths
 * @return array{id: string, url: ?string}
 */
function alarmmanager_response_reference(string $body, array $paths): array {
	$data      = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
	$reference = [];

	foreach ($paths as $key => $path) {
		$value = $data;

		foreach (explode('.', $path) as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) {
				throw new InvalidArgumentException('invalid_external_reference');
			}
			$value = $value[$part];
		}
		$reference[$key] = $value;
	}

	return alarmmanager_external_reference_validate($reference);
}

/** Endpoint, stream and episode scope prevents sharing references across alarms or destinations. */
/**
 * @param  array<string, mixed>                                 $event
 * @param  array<string, array<int, array<string, mixed>>>|null $mapping
 * @param  array<string, mixed>|null                            $overrides
 * @return array<string, mixed>
 */
function alarmmanager_delivery_reference(array $event, int $endpoint_id, ?array $mapping = null, ?array $overrides = null): array {
	$items   = $mapping[$event['transition']] ?? $mapping['default'] ?? [];
	$sources = array_column($items, 'source');

	foreach ($items as $item) {
		if (isset($item['template'])) {
			$sources = array_merge($sources, alarmmanager_mapping_template_fields($item['template']));
		}
	}

	if ($mapping !== null && !array_intersect($sources, ['integration.id', 'integration.url']) && !str_contains($overrides[$event['transition']]['url'] ?? '', '{{integration.id}}')) {
		return [];
	}

	$row = alarmmanager_db_row("SELECT d.external_reference FROM plugin_alarmmanager_deliveries AS d INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id WHERE d.endpoint_id = ? AND d.stream_key = ? AND e.episode_id = ? AND e.sequence < ? AND d.status = 'completed' AND d.external_reference IS NOT NULL ORDER BY e.sequence DESC, d.id DESC LIMIT 1", [$endpoint_id, alarmmanager_stream_key($event, 'centralized'), $event['episode_id'], $event['sequence']]);

	return $row ? alarmmanager_external_reference_validate(json_decode($row['external_reference'], true, 8, JSON_THROW_ON_ERROR)) : [];
}

/**
 * Keep the editable Combined text field while emitting the Workflows Adaptive Card envelope.
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $event
 * @param array<string, mixed> $reference
 */
function alarmmanager_notification_payload(array $settings, array $event, array $reference = []): stdClass {
	$payload = alarmmanager_map_payload($settings['mapping'], $event, $reference);

	if (($settings['provider'] ?? '') === 'pagerduty') {
		$payload->routing_key = '[REDACTED]';
	}

	if (!empty($settings['twilio_whatsapp'])) {
		if (!is_string($payload->text ?? null) || trim($payload->text) === '' || isset($payload->Body) || isset($payload->ContentVariables)) {
			throw new InvalidArgumentException('invalid_twilio_message');
		}
		// shortcut: WhatsApp uses variable 1; extend when multi-variable templates are needed.
		// WhatsApp template variables cannot contain line breaks or tabs.
		$payload->ContentVariables = json_encode(['1' => preg_replace('/\s+/u', ' ', trim($payload->text))], JSON_THROW_ON_ERROR);
		unset($payload->text);
	}

	if (!empty($settings['teams_workflow'])) {
		if (!is_string($payload->text ?? null) || $payload->text === '') {
			throw new InvalidArgumentException('teams_message_required');
		}
		$payload = (object) ['type' => 'message', 'attachments' => [
			['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null,
				'content'     => ['type' => 'AdaptiveCard', 'version' => '1.2',
					'body'                  => [['type' => 'TextBlock', 'text' => $payload->text, 'wrap' => true]]]]
		]];
	}

	if (!empty($settings['teams_workflow']) && strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 28000) {
		throw new InvalidArgumentException('teams_message_too_large');
	}

	return $payload;
}
