<?php
// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Provider identity travels with the existing transport JSON and collector configuration.
 * @return array<string, array<string, mixed>>
 */
function alarmmanager_provider_definitions(): array {
	return [
		'twilio'            => ['name' => 'Twilio SMS', 'auth' => 'basic', 'fields' => ['account_sid' => 'Account SID (AC…)'], 'labels' => ['From' => 'Sender phone number', 'To' => 'Recipient phone number', 'Body' => 'Message']],
		'twilio_whatsapp'   => ['name' => 'Twilio WhatsApp', 'auth' => 'basic', 'fields' => ['account_sid' => 'Account SID (AC…)'], 'labels' => ['From' => 'WhatsApp sender (whatsapp:+…)', 'To' => 'WhatsApp recipient (whatsapp:+…)', 'MessagingServiceSid' => 'Messaging Service SID (MG…)', 'ContentSid' => 'Approved Template SID (HX…)', 'text' => 'Template message (variable 1)']],
		'slack'             => ['name' => 'Slack', 'auth' => 'bearer', 'fields' => [], 'labels' => ['channel' => 'Slack channel ID', 'text' => 'Message']],
		'teams'             => ['name' => 'Microsoft Teams (Workflows)', 'auth' => 'teams', 'fields' => [], 'labels' => ['text' => 'Message']],
		'telegram'          => ['name' => 'Telegram', 'auth' => 'provider_token', 'token_label' => 'Telegram bot token', 'fields' => [], 'labels' => ['chat_id' => 'Telegram chat ID', 'text' => 'Message']],
		'pagerduty'         => ['name' => 'PagerDuty Events v2', 'auth' => 'provider_token', 'token_label' => 'PagerDuty integration key', 'fields' => [], 'labels' => ['event_action' => 'Event action', 'dedup_key' => 'Alarm correlation key', 'payload.summary' => 'Summary', 'payload.source' => 'Host', 'payload.severity' => 'Severity', 'payload.timestamp' => 'Event time']],
		'servicenow'        => ['name' => 'ServiceNow incidents', 'auth' => 'basic', 'fields' => ['instance_url' => 'ServiceNow instance URL'], 'labels' => ['short_description' => 'Short description', 'description' => 'Description', 'urgency' => 'Urgency', 'state' => 'Incident state', 'close_code' => 'Close code', 'work_notes' => 'Work notes']],
		'servicenow_events' => ['name' => 'ServiceNow events', 'auth' => 'basic', 'fields' => ['instance_url' => 'ServiceNow instance URL'], 'labels' => ['source' => 'Source', 'node' => 'Host', 'type' => 'Event type', 'resource' => 'Resource', 'metric_name' => 'Metric', 'message_key' => 'Alarm correlation key', 'description' => 'Description', 'severity' => 'Severity']]
	];
}

/** @param array<string, mixed> $transport */
function alarmmanager_provider_value(string $provider, string $field, array $transport): string {
	$url = $transport['url'] ?? '';

	if ($field === 'account_sid') {
		preg_match('~/Accounts/(AC[0-9a-fA-F]{32})/Messages\.json$~', $url, $match);

		return $match[1] ?? '';
	}
	$parts = parse_url($url);

	return isset($parts['host']) ? 'https://' . $parts['host'] : '';
}

/**
 * Generate protocol options from provider fields; retain the user's message mappings.
 * @param  array<string, mixed> $transport
 * @param  array<string, mixed> $input
 * @return array<string, mixed>
 */
function alarmmanager_provider_transport(string $provider, array $transport, array $input, bool $enabled): array {
	$definition = alarmmanager_provider_definitions()[$provider] ?? null;

	if ($definition === null) {
		throw new InvalidArgumentException('invalid_provider');
	}
	$preset = alarmmanager_integration_templates()[$provider]['transport'];

	foreach (['url', 'method', 'body_encoding', 'teams_workflow', 'twilio_whatsapp', 'success_codes', 'response_match', 'response_reference', 'transition_overrides'] as $key) {
		unset($transport[$key]);

		if (array_key_exists($key, $preset)) {
			$transport[$key] = $preset[$key];
		}
	}
	$transport['provider']   = $provider;
	$transport['credential'] = true;
	$transport['allow_http'] = false;

	if (str_starts_with($provider, 'twilio')) {
		$sid = $input['provider_account_sid'] ?? '';

		if (!is_string($sid) || ($sid !== '' && !preg_match('/\AAC[0-9a-fA-F]{32}\z/D', $sid)) || ($enabled && $sid === '')) {
			throw new InvalidArgumentException('invalid_twilio_account');
		}
		$transport['url'] = str_replace('REPLACE-WITH-ACCOUNT-SID', $sid ?: 'REPLACE-WITH-ACCOUNT-SID', $preset['url']);
	}

	if (str_starts_with($provider, 'servicenow')) {
		$instance = $input['provider_instance_url'] ?? '';

		if (!is_string($instance)) {
			throw new InvalidArgumentException('invalid_provider_instance');
		}
		$instance = rtrim($instance, '/');

		if (!$enabled && $instance === '') {
			$instance = 'https://REPLACE-INSTANCE.service-now.com';
		}
		$parts = parse_url(alarmmanager_transport_url_validate($instance, false));

		if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['path'] ?? '', ['', '/'], true)) {
			throw new InvalidArgumentException('invalid_provider_instance');
		}
		$transport['url'] = $instance . parse_url($preset['url'], PHP_URL_PATH);

		foreach ($transport['transition_overrides'] ?? [] as $state => $target) {
			$transport['transition_overrides'][$state]['url'] = $transport['url'] . '/{{integration.id}}';
		}
	}

	if ($provider === 'pagerduty') {
		foreach ($transport['mapping'] as &$items) {
			$items = array_values(array_filter($items, static fn ($item) => ($item['to'] ?? '') !== 'routing_key'));
		}
		unset($items);
	}

	return $transport;
}

/**
 * Provider changes cannot reuse a previous destination's credentials.
 * @param array<string, mixed>|null $credential
 */
function alarmmanager_provider_credentials(string $provider, ?array $credential): void {
	$auth  = alarmmanager_provider_definitions()[$provider]['auth'];
	$valid = match ($auth) {
		'basic'          => !empty($credential['basic']['username']) && !empty($credential['basic']['password']),
		'bearer'         => str_starts_with($credential['headers']['Authorization'] ?? '', 'Bearer ') && strlen($credential['headers']['Authorization']) > 7,
		'teams'          => !empty($credential['teams_webhook_url']),
		'provider_token' => !empty($credential['provider_token']),
	};

	if (!$valid) {
		throw new InvalidArgumentException('provider_credentials_required');
	}

	if (str_starts_with($provider, 'twilio') && !preg_match('/\A(?:AC|SK)[0-9a-fA-F]{32}\z/D', $credential['basic']['username'])) {
		throw new InvalidArgumentException('invalid_twilio_credentials');
	}
}

/**
 * Attach provider secrets only after preview generation, immediately before HTTP delivery.
 * @param  array<string, mixed>                  $settings
 * @param  array<string, mixed>                  $target
 * @param  array<string, mixed>|null             $credential
 * @return array{array<string, mixed>, stdClass}
 */
function alarmmanager_provider_request(array $settings, array $target, stdClass $payload, ?array $credential): array {
	$provider = $settings['provider'] ?? '';

	if (in_array($provider, ['telegram', 'pagerduty'], true)) {
		alarmmanager_provider_credentials($provider, $credential);

		if ($provider === 'telegram') {
			$target['url'] = 'https://api.telegram.org/bot' . str_replace('%3A', ':', rawurlencode($credential['provider_token'])) . '/sendMessage';
		} else {
			$payload->routing_key = $credential['provider_token'];
		}
	}

	return [$target, $payload];
}
