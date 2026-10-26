<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class TwilioRecipientsTest extends AlarmManagerTestCase {
	public function testRecipientMigrationFanoutAndIndependentRetries(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1' || !function_exists('pcntl_alarm')) {
			$this->markTestSkipped('Disposable database creation and pcntl required.');
		}
		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/delivery.php');
		self::alarmmanager_test_load('includes/processor.php');
		$GLOBALS['config']               = ['poller_id' => 2];
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$db->exec("USE `$database`");

		try {
			$db->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
			$db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id, name)) ENGINE=InnoDB');
			$db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
			$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.12.0', 1)");
			$this->assertTrue(plugin_alarmmanager_install());
			// Recreate the v12 uniqueness contract with an existing completed row.
			$db->exec("INSERT INTO plugin_alarmmanager_deliveries (event_row_id,endpoint_id,stream_key,sequence,priority,status,available_at,created_at,updated_at,idempotency_key) VALUES (99999,99999,REPEAT('a',64),1,0,'completed',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),REPEAT('a',64))");
			$old_id = $db->lastInsertId();
			$db->exec('ALTER TABLE plugin_alarmmanager_deliveries DROP INDEX event_endpoint_recipient, DROP COLUMN recipient, ADD UNIQUE KEY event_endpoint (event_row_id,endpoint_id)');
			$db->exec('UPDATE plugin_alarmmanager_schema SET version=12');
			$this->assertTrue(alarmmanager_schema_recipients());
			$this->assertTrue(alarmmanager_schema_recipients(), 'Migration repeats safely');
			$this->assertSame('', db_fetch_cell_prepared('SELECT recipient FROM plugin_alarmmanager_deliveries WHERE id=?', [$old_id]));
			$this->assertTrue(alarmmanager_schema_ready());
			$numbers   = ['+15551234567', '+15557654321'];
			$transport = alarmmanager_integration_templates()['twilio']['transport'] + ['provider' => 'twilio'];
			$config    = ['version' => 1, 'endpoints' => [['id' => 1, 'name' => 'SMS', 'type' => 'restapi', 'transport' => $transport]], 'rules' => [['id' => 1, 'name' => 'Recipients', 'endpoints' => [1], 'policy' => ['recipient_overrides' => [1 => [$numbers[0], $numbers[1], $numbers[0]]]]]]];
			alarmmanager_apply_configuration($config);
			$sample                                     = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true);
			$sample['collector_id']                     = 2;
			$seen                                       = [];
			$GLOBALS['alarmmanager_transport_handlers'] = ['restapi' => static function ($event, $endpoint, $key) use (&$seen, $numbers): array {
				$recipient = $endpoint['recipient'] ?? '';
				$seen[]    = [$recipient, $key];

				return $recipient === $numbers[0] && count($seen) === 1 ? ['outcome' => 'retry', 'code' => 'fixture_retry', 'retry_after' => 30] : ['outcome' => 'success', 'code' => 'fixture_ok'];
			}];
			$enqueue = function (array $event): int {
				$response = alarmmanager_transaction(fn () => alarmmanager_persist_event(alarmmanager_validate_event($event), 'centralized', 2));
				$claim    = alarmmanager_claim_processing();
				$this->assertNotNull($claim);
				$this->assertTrue(alarmmanager_process_job($claim));

				return $response['event_row_id'];
			};
			$id = $enqueue($sample);
			$this->assertSame(2, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE event_row_id=?', [$id]));
			$this->assertSame($numbers, array_column(db_fetch_assoc_prepared('SELECT recipient FROM plugin_alarmmanager_deliveries WHERE event_row_id=? ORDER BY id', [$id]), 'recipient'));
			$this->assertSame('already_accepted', alarmmanager_transaction(fn () => alarmmanager_persist_event(alarmmanager_validate_event($sample), 'centralized', 2))['status']);

			for ($i = 0; $i < 2; $i++) {
				$claim = alarmmanager_claim_delivery(['restapi']);
				$this->assertNotNull($claim, 'One recipient retry cannot block its sibling');
				$this->assertTrue(alarmmanager_run_delivery($claim));
			}
			$this->assertSame($numbers, array_column($seen, 0));
			$this->assertNotSame($seen[0][1], $seen[1][1]);
			$db->exec("UPDATE plugin_alarmmanager_deliveries SET available_at=UTC_TIMESTAMP(6) WHERE status='pending'");
			$claim = alarmmanager_claim_delivery(['restapi']);
			$this->assertTrue(alarmmanager_run_delivery($claim));
			$this->assertSame([$numbers[0], $numbers[1], $numbers[0]], array_column($seen, 0));
			$this->assertSame($seen[0][1], $seen[2][1], 'Retry retains recipient idempotency');
			$this->assertNull(alarmmanager_claim_delivery(['restapi']));
			$sample['event_id']                                     = 'recipient-update';
			$sample['sequence']                                     = 2;
			$id2                                                    = $enqueue($sample);
			$config['rules'][0]['policy']['recipient_overrides'][1] = [$numbers[0]];
			alarmmanager_apply_configuration($config);

			for ($i = 0; $i < 2; $i++) {
				$this->assertTrue(alarmmanager_run_delivery(alarmmanager_claim_delivery(['restapi'])));
			}
			$this->assertCount(4, $seen, 'Removed recipient is canceled before an external effect');
			$this->assertSame('no_matching_rule', db_fetch_cell_prepared('SELECT last_error FROM plugin_alarmmanager_deliveries WHERE event_row_id=? AND recipient=?', [$id2, $numbers[1]]));
			unset($config['rules'][0]['policy']['recipient_overrides']);
			alarmmanager_apply_configuration($config);
			$sample['event_id'] = 'recipient-default';
			$sample['sequence'] = 3;
			$id3                = $enqueue($sample);
			$this->assertSame('', db_fetch_cell_prepared('SELECT recipient FROM plugin_alarmmanager_deliveries WHERE event_row_id=?', [$id3]));
			$this->assertTrue(alarmmanager_run_delivery(alarmmanager_claim_delivery(['restapi'])));
			$this->assertSame('', $seen[4][0]);
			$this->assertSame($transport['mapping'], alarmmanager_endpoint_transport(['id' => 1, 'type' => 'restapi'])['mapping'], 'Rule overrides never modify destination defaults');
		} finally {
			unset($GLOBALS['alarmmanager_transport_handlers'], $GLOBALS['alarmmanager_route_cache']);
			$db->exec("DROP DATABASE `$database`");
		}
	}
}
