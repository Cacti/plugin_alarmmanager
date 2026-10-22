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

final class IntegrationTemplateTest extends AlarmManagerTestCase {
	/**
	 * @return array<string, mixed>
	 */
	private function sampleEvent(): array {
		return json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
	}

	private function assertRejects(callable $action, string $message, string $label): void {
		$caught = null;

		try {
			$action();
		} catch (InvalidArgumentException $error) {
			$caught = $error->getMessage();
		}
		$this->assertSame($message, $caught, $label);
	}

	public function testTemplatesAreDisabledDraftInputsWithoutSecrets(): void {
		self::alarmmanager_test_load('includes/integrations.php');

		foreach (alarmmanager_integration_templates() as $key => $template) {
			$this->assertArrayNotHasKey('id', $template);
			$this->assertArrayNotHasKey('enabled', $template);
			$transport = alarmmanager_transport_configuration($template['type'], $template['transport']);
			$this->assertSame(in_array($key, ['slack', 'teams', 'servicenow', 'servicenow_events'], true), (bool) ($transport['credential'] ?? false));
			$this->assertArrayNotHasKey('headers', $template['transport']);
			$this->assertArrayNotHasKey('basic', $template['transport']);

			if (isset($template['auth'])) {
				$this->assertSame(['teams' => 'teams', 'slack' => 'bearer', 'servicenow' => 'basic', 'servicenow_events' => 'basic'][$key], $template['auth']);
			} else {
				$this->assertArrayNotHasKey('auth', $template);
			}
		}
		$this->assertArrayNotHasKey('transition_overrides', alarmmanager_transport_configuration('webhook', ['url' => 'https://x.example.invalid/a']));
	}

	public function testGenericTemplatesMapExternalReferences(): void {
		self::alarmmanager_test_load('includes/integrations.php');
		$event = $this->sampleEvent();

		foreach (['webhook', 'restapi', 'script'] as $key) {
			$template  = alarmmanager_integration_templates()[$key];
			$transport = alarmmanager_transport_configuration($template['type'], $template['transport']);
			$payload   = alarmmanager_map_payload($transport['mapping'], alarmmanager_validate_event($event));
			$this->assertStringContainsString('Hostname: router.example.test', $payload->message);
			$this->assertSame('', $payload->location);
			$withLocation                       = $event;
			$withLocation['device']['location'] = 'Toronto DC';
			$payload                            = alarmmanager_map_payload($transport['mapping'], alarmmanager_validate_event($withLocation));
			$this->assertSame('Toronto DC', $payload->location);
			$this->assertStringContainsString('Location: Toronto DC', $payload->message);
			$this->assertSame('CASE-42', alarmmanager_map_payload($transport['mapping'], $event, ['id' => 'CASE-42'])->external_id);
			$event['transition'] = 'recovered';
			$this->assertSame('recovered', alarmmanager_map_payload($transport['mapping'], $event)->state);
			$this->assertNull(alarmmanager_map_payload($transport['mapping'], $event)->external_id);
			$event['transition'] = 'raised';
		}
	}

	public function testProviderPresetsMapProviderPayloads(): void {
		self::alarmmanager_test_load('includes/integrations.php');
		$event     = $this->sampleEvent();
		$templates = alarmmanager_integration_templates();

		$slack = alarmmanager_transport_configuration('webhook', $templates['slack']['transport']);
		$this->assertSame(['path' => 'ok', 'equals' => true], $slack['response_match']);
		$this->assertSame('REPLACE-WITH-CHANNEL', alarmmanager_map_payload($slack['mapping'], $event)->channel);
		$event['device'] += ['location' => 'Toronto DC', 'contact' => 'Network team', 'sysname' => 'edge-01'];
		$event = alarmmanager_validate_event($event);
		$text  = alarmmanager_map_payload($slack['mapping'], $event)->text;

		foreach (['Sample device down', 'Hostname: router.example.test', 'Location: Toronto DC', 'Contact: Network team', 'System name: edge-01', 'State: raised'] as $value) {
			$this->assertStringContainsString($value, $text);
		}
		$legacy = $this->sampleEvent();
		$this->assertStringContainsString("Location: \nContact: ", alarmmanager_map_payload($slack['mapping'], $legacy)->text);
		$event['transition'] = 'recovered';
		$this->assertStringContainsString('State: recovered', alarmmanager_map_payload($slack['mapping'], $event)->text);
		$event['transition'] = 'raised';

		$event    = $this->sampleEvent();
		$telegram = alarmmanager_transport_configuration('webhook', $templates['telegram']['transport']);
		$this->assertSame(['path' => 'ok', 'equals' => true], $telegram['response_match']);
		$this->assertSame('REPLACE-WITH-CHAT-ID', alarmmanager_map_payload($telegram['mapping'], $event)->chat_id);
		$this->assertStringContainsString('Hostname: router.example.test', alarmmanager_map_payload($telegram['mapping'], $event)->text);

		$pagerduty = alarmmanager_transport_configuration('webhook', $templates['pagerduty']['transport']);
		$this->assertSame(['path' => 'status', 'equals' => 'ok'], $pagerduty['response_match']);
		$raised = alarmmanager_map_payload($pagerduty['mapping'], $event);
		$this->assertSame('REPLACE-WITH-INTEGRATION-KEY', $raised->routing_key);
		$this->assertSame('trigger', $raised->event_action);
		$this->assertSame('sample-outage-0001', $raised->dedup_key);
		$this->assertSame('Sample device down', $raised->payload->summary);
		$this->assertSame('router.example.test', $raised->payload->source);
		$this->assertSame('error', $raised->payload->severity);
		$this->assertSame('2026-10-13T03:00:00Z', $raised->payload->timestamp);
		$event['transition'] = 'recovered';
		$resolved            = alarmmanager_map_payload($pagerduty['mapping'], $event);
		$this->assertSame('resolve', $resolved->event_action);
		$this->assertObjectNotHasProperty('payload', $resolved);
		$event['transition'] = 'updated';
		$this->assertSame('trigger', alarmmanager_map_payload($pagerduty['mapping'], $event)->event_action);

		$servicenow = alarmmanager_transport_configuration('webhook', $templates['servicenow']['transport']);
		$this->assertSame(['id' => 'result.sys_id'], $servicenow['response_reference']);
		$event['transition'] = 'raised';
		$raised              = alarmmanager_map_payload($servicenow['mapping'], $event);
		$this->assertSame('Sample device down', $raised->short_description);
		$this->assertSame('Synthetic event for local ingestion checks.', $raised->description);
		$this->assertSame(2, $raised->urgency);
		$event['transition'] = 'recovered';
		$resolved            = alarmmanager_map_payload($servicenow['mapping'], $event);
		$this->assertSame(6, $resolved->state);
		$this->assertSame('Solved (Permanently)', $resolved->close_code);
		$this->assertSame('Sample device down', $resolved->work_notes);
		$this->assertSame(2, $resolved->urgency);
		$event['transition'] = 'updated';
		$updated             = alarmmanager_map_payload($servicenow['mapping'], $event);
		$this->assertSame('Sample device down', $updated->work_notes);
		$this->assertSame(2, $updated->urgency);
		$this->assertObjectNotHasProperty('short_description', $updated);

		foreach ($servicenow['transition_overrides'] as $transition => $target) {
			$this->assertSame('PATCH', $target['method']);
			$this->assertStringContainsString('{{integration.id}}', $target['url']);
		}
		$this->assertArrayNotHasKey('raised', $servicenow['transition_overrides']);

		foreach (['critical' => ['critical', 1], 'major' => ['error', 2], 'minor' => ['warning', 3], 'warning' => ['warning', 3], 'info' => ['info', 3], 'clear' => ['info', 3]] as $severity => [$pagerduty_severity, $urgency]) {
			$event['transition'] = 'raised';
			$event['severity']   = $severity;
			$this->assertSame($pagerduty_severity, alarmmanager_map_payload($pagerduty['mapping'], $event)->payload->severity);
			$event['transition'] = 'updated';
			$this->assertSame($urgency, alarmmanager_map_payload($servicenow['mapping'], $event)->urgency);
		}
	}

	public function testServiceNowEventsUseEventFieldsAndPostRecoveryWithTheSameKey(): void {
		self::alarmmanager_test_load('includes/integrations.php');
		$template  = alarmmanager_integration_templates()['servicenow_events'];
		$transport = alarmmanager_transport_configuration('webhook', $template['transport']);
		$this->assertStringEndsWith('/api/now/table/em_event', $transport['url']);
		$this->assertArrayNotHasKey('transition_overrides', $transport);
		$this->assertNull($transport['response_reference']);
		$event = $this->sampleEvent();

		foreach (['critical' => 1, 'major' => 2, 'minor' => 3, 'warning' => 4, 'info' => 5, 'clear' => 0] as $severity => $expected) {
			$event['severity'] = $severity;
			$this->assertSame($expected, alarmmanager_map_payload($transport['mapping'], $event)->severity);
		}
		$event['severity'] = 'major';

		foreach (['raised', 'updated', 'reminder', 'occurrence', 'recovered'] as $transition) {
			$event['transition'] = $transition;
			$payload             = alarmmanager_map_payload($transport['mapping'], $event);
			$this->assertSame('Cacti Alarm Manager', $payload->source);
			$this->assertSame('router.example.test', $payload->node);
			$this->assertSame('sample', $payload->type);
			$this->assertSame('device:42', $payload->resource);
			$this->assertSame('device:42:reachability', $payload->metric_name);
			$this->assertSame('sample-outage-0001', $payload->message_key);
			$this->assertSame('Sample device down', $payload->description);
			$this->assertSame($transition === 'recovered' ? 0 : 2, $payload->severity);
			$this->assertObjectNotHasProperty('short_description', $payload);
			$this->assertObjectNotHasProperty('urgency', $payload);
			$this->assertObjectNotHasProperty('state', $payload);
			$this->assertSame(['url' => $transport['url'], 'method' => 'POST'], alarmmanager_transition_target($transport, $event, []));
		}
	}

	public function testTeamsWorkflowPayloadAndResponseClassification(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$settings = alarmmanager_transport_configuration('webhook', alarmmanager_integration_templates()['teams']['transport']);
		$event    = $this->sampleEvent();
		unset($event['device']);
		$event = alarmmanager_validate_event($event);

		foreach (['raised', 'updated', 'reminder', 'occurrence', 'recovered'] as $transition) {
			$event['transition'] = $transition;
			$card                = alarmmanager_notification_payload($settings, $event);
			$this->assertSame('message', $card->type);
			$this->assertSame('application/vnd.microsoft.card.adaptive', $card->attachments[0]['contentType']);
			$text = $card->attachments[0]['content']['body'][0]['text'];

			foreach (['State: ' . $transition, 'Episode: sample-outage-0001', 'Alarm key: device:42:reachability', 'Source: sample', 'Collector: 1', 'Location: ', 'Description: '] as $value) {
				$this->assertStringContainsString($value, $text);
			}
			$this->assertStringNotContainsString('duration', $text);
			$this->assertArrayNotHasKey('transition_overrides', $settings);
		}

		foreach ([200, 202, 204] as $status) {
			$this->assertSame(['outcome' => 'success', 'code' => 'teams_workflow_accepted'], alarmmanager_http_result($status, '', $settings));
		}
		$this->assertSame('failure', alarmmanager_http_result(403, 'secret response', $settings)['outcome']);
		$this->assertSame(['outcome' => 'retry', 'code' => 'http_429', 'retry_after' => 30], alarmmanager_http_result(429, '', $settings, '30'));
	}

	public function testTeamsSecretsUseExistingEncryptedCredentialEnvelope(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$url    = 'https://prod-01.westus.logic.azure.com/workflows/fixture/triggers/manual/paths/invoke?api-version=2016-06-01&sig=fixture-secret';
		$record = alarmmanager_admin_credential(['auth_type' => 'teams', 'teams_webhook_url' => $url]);
		$key    = random_bytes(32);
		$cipher = alarmmanager_encrypt_credential($record, 42, $key);
		$this->assertStringNotContainsString('fixture-secret', $cipher);
		$this->assertSame($record, alarmmanager_decrypt_credential($cipher, 42, $key));
		$this->assertNull(alarmmanager_admin_credential(['auth_type' => 'teams'], true));
		$this->assertSame($url, alarmmanager_teams_webhook_url($record));

		foreach ([null, ['headers' => []]] as $missing) {
			$this->assertRejects(fn () => alarmmanager_teams_webhook_url($missing), 'teams_webhook_missing', 'Enabling or sending requires the protected URL');
		}

		foreach (['', 'http://prod.logic.azure.com/workflows/f?sig=x', 'https://evil.test/workflows/f?sig=x', 'https://prod.logic.azure.com/workflows/f', $url . '#fragment', $url . "\n"] as $bad) {
			$this->assertRejects(fn () => alarmmanager_credential_validate(['teams_webhook_url' => $bad]), 'invalid_teams_webhook_url', 'Invalid URL rejected without echoing it');
		}
		$settings = alarmmanager_integration_templates()['teams']['transport'];
		$this->assertStringNotContainsString('fixture-secret', json_encode($settings));
		$this->assertRejects(fn () => alarmmanager_transport_configuration('webhook', array_replace($settings, ['allow_http' => true])), 'invalid_teams_workflow', 'TLS required');
		$modern = str_replace('prod-01.westus.logic.azure.com', 'default-fixture.environment.api.powerplatform.com', $url);
		$this->assertSame($modern, alarmmanager_credential_validate(['teams_webhook_url' => $modern])['teams_webhook_url']);
		$this->assertRejects(fn () => alarmmanager_credential_validate(['teams_webhook_url' => $url, 'headers' => ['Authorization' => 'Bearer fixture']]), 'invalid_teams_webhook_url', 'Anyone workflow must not receive Authorization');
		$this->assertStringNotContainsString('fixture-secret', alarmmanager_admin_error_message(new InvalidArgumentException('invalid_teams_webhook_url')));
	}

	public function testTransitionTargetResolvesAndValidatesOverrides(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$settings = ['url' => 'https://base.example.invalid/api', 'method' => 'POST', 'transition_overrides' => ['recovered' => ['url' => 'https://base.example.invalid/api/{{integration.id}}', 'method' => 'PATCH']]];

		$raised = alarmmanager_transition_target($settings, ['transition' => 'raised'], []);
		$this->assertSame('https://base.example.invalid/api', $raised['url']);
		$this->assertSame('POST', $raised['method']);

		$this->assertNull(alarmmanager_transition_target($settings, ['transition' => 'recovered'], []));
		$this->assertNull(alarmmanager_transition_target($settings, ['transition' => 'recovered'], ['id' => '']));
		$this->assertNull(alarmmanager_transition_target($settings, ['transition' => 'recovered'], ['id' => 6]));

		$this->assertSame('POST', alarmmanager_transition_target(['url' => 'https://base.example.invalid/api'], ['transition' => 'raised'], [])['method']);

		$url_only = alarmmanager_transition_target(['url' => 'https://base.example.invalid/api', 'method' => 'POST', 'transition_overrides' => ['recovered' => ['url' => 'https://base.example.invalid/api/{{integration.id}}']]], ['transition' => 'recovered'], ['id' => 'SYSID-01']);
		$this->assertSame('https://base.example.invalid/api/SYSID-01', $url_only['url']);
		$this->assertSame('POST', $url_only['method']);

		foreach (['x?y', 'x#y'] as $id) {
			$this->assertRejects(static fn () => alarmmanager_transition_target($settings, ['transition' => 'recovered'], ['id' => $id]), 'invalid_transport_url', 'reference id "' . $id . '" must not alter the URL structure');
		}
	}

	public function testMalformedTransitionOverridesAreRejected(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$base = ['url' => 'https://x.example.invalid/api/incident'];

		foreach ([
			[['transition_overrides' => 'nope'], 'invalid_transport'],
			[['transition_overrides' => ['recovered_not_a_transition' => ['url' => 'https://x.example.invalid/a']]], 'Unknown event fields.'],
			[['transition_overrides' => ['recovered' => []]], 'invalid_transport'],
			[['transition_overrides' => ['recovered' => ['url' => 'https://x.example.invalid/a', 'extra' => 1]]], 'Unknown event fields.'],
			[['transition_overrides' => ['recovered' => ['method' => 'DELETE']]], 'invalid_transport_method'],
			[['transition_overrides' => ['recovered' => ['url' => 'https://x.example.invalid/a', 'method' => 'DELETE']]], 'invalid_transport_method'],
			[['transition_overrides' => ['recovered' => ['url' => 'http://x.example.invalid/a']]], 'invalid_transport_url'],
			[['transition_overrides' => ['recovered' => ['url' => 'https://x.example.invalid/a?b=1']]], 'invalid_transport_url'],
			[['transition_overrides' => ['recovered' => ['url' => 'https://x.example.invalid/a/{{instance}}']]], 'invalid_transport_url'],
			[['url' => 'https://x.example.invalid/a/{{integration.id}}'], 'invalid_transport_url'],
		] as [$settings, $message]) {
			$this->assertRejects(static function () use ($base, $settings) {
				alarmmanager_transport_configuration('webhook', $settings + $base);
			}, $message, json_encode($settings));
		}

		$accepted = alarmmanager_transport_configuration('webhook', $base + ['transition_overrides' => ['recovered' => ['method' => 'PATCH', 'url' => 'https://x.example.invalid/api/incident/{{integration.id}}']]]);
		$this->assertSame(['recovered' => ['url' => 'https://x.example.invalid/api/incident/{{integration.id}}', 'method' => 'PATCH']], $accepted['transition_overrides']);
	}
}
