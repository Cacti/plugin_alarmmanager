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
	public function testTemplatesAreDisabledDraftInputsWithoutSecrets(): void {
		self::alarmmanager_test_load('includes/integrations.php');
		$event = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);

		foreach (alarmmanager_integration_templates() as $template) {
			$this->assertArrayNotHasKey('id', $template);
			$this->assertArrayNotHasKey('enabled', $template);
			$transport = alarmmanager_transport_configuration($template['type'], $template['transport']);
			$this->assertFalse($transport['credential'] ?? false);
			$payload = alarmmanager_map_payload($transport['mapping'], $event, ['id' => 'CASE-42']);
			$this->assertSame('CASE-42', $payload->external_id);
			$event['transition'] = 'recovered';
			$this->assertSame('recovered', alarmmanager_map_payload($transport['mapping'], $event)->state);
			$this->assertNull(alarmmanager_map_payload($transport['mapping'], $event)->external_id);
		}
	}

	public function testReferencesAreExplicitBoundedFieldsAndDoNotTrustEventContext(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$settings = ['response_reference' => ['id' => 'ticket.id', 'url' => 'ticket.url']];
		$result   = alarmmanager_transport_reference_result(['outcome' => 'success', 'code' => 'http_200'], '{"ticket":{"id":42,"url":"https://tickets.example.invalid/42"},"secret":"never-store"}', $settings);
		$this->assertSame(['id' => '42', 'url' => 'https://tickets.example.invalid/42'], $result['external_reference']);

		foreach (['{}', 'not-json', json_encode(['ticket' => ['id' => str_repeat('x', 129), 'url' => null]]), '{"ticket":{"id":[],"url":null}}', '{"ticket":{"id":"x","url":"javascript:alert(1)"}}', '{"ticket":{"id":"x","url":"https://tickets.example.invalid/42?token=secret"}}'] as $body) {
			$this->assertSame('external_reference_invalid', alarmmanager_transport_reference_result(['outcome' => 'success', 'code' => 'http_200'], $body, $settings)['code']);
		}
		$this->assertArrayNotHasKey('external_reference', alarmmanager_transport_reference_result(['outcome' => 'retry', 'code' => 'http_503'], '{}', $settings));
		$this->assertNull(alarmmanager_mapping_fields(['integration' => ['id' => 'forged'], 'context' => ['integration.id' => 'forged']])['integration.id']);
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_transport_configuration('script', ['executable' => '/opt/notify', 'response_reference' => ['id' => '../secret']]);
	}
}
