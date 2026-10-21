<?php
// SPDX-License-Identifier: GPL-2.0-or-later

use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DeviceRuleTest extends AlarmManagerTestCase {
	public function testHostConditionsAndMultipleDestinations(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Disposable database creation must be enabled.');
		}
		self::alarmmanager_test_load('includes/schema.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/processor.php');
		self::alarmmanager_test_load('includes/queue.php');
		$db                              = alarmmanager_test_connect();
		$GLOBALS['alarmmanager_test_db'] = $db;
		$before                          = $GLOBALS['config'];
		$GLOBALS['config']['poller_id']  = 2;
		$name                            = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$db->exec("CREATE DATABASE `$name`");
		$db->exec("USE `$name`");

		try {
			$this->assertTrue(alarmmanager_schema_install());
			$db->exec('CREATE TABLE plugin_config (directory VARCHAR(64), status INT)');
			$db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', 1)");
			$db->exec('CREATE TABLE host (id INT PRIMARY KEY, location VARCHAR(40), poller_id INT, site_id INT, host_template_id INT, hostname VARCHAR(100), description VARCHAR(150), snmp_sysLocation VARCHAR(300))');
			$db->exec("INSERT INTO host VALUES (42, 'hello', 1, 3, 2, 'router.test', 'A test router', 'SNMP room')");
			$event                          = alarmmanager_validate_event(json_decode(file_get_contents(dirname(__DIR__, 2) . '/examples/event.json'), true));
			$event['device']['id']          = 42;
			$event['collector_id']          = 2;
			$event['device']['description'] = 'Stale payload';
			$make                           = static fn (array $conditions): array => alarmmanager_validate_rule_definition(['device_query' => json_encode(['version' => 1, 'conditions' => $conditions], JSON_THROW_ON_ERROR)]);
			$conditions                     = [['field' => 'location', 'operator' => '=', 'value' => 'hello'], ['join' => 'AND', 'field' => 'poller_id', 'operator' => '=', 'value' => '1'], ['join' => 'AND', 'field' => 'host_template_id', 'operator' => '=', 'value' => '2']];
			$policy                         = $make($conditions);
			$this->assertTrue(alarmmanager_rule_matches($policy, $event), 'Use host.poller_id, not the event collector.');

			foreach (['description' => 'test', 'hostname' => 'router', 'snmp_sysLocation' => 'SNMP', 'site_id' => '3'] as $field => $value) {
				$this->assertTrue(alarmmanager_rule_matches($make([['field' => $field, 'operator' => $field === 'site_id' ? '=' : 'contains', 'value' => $value]]), $event));
			}
			$or = $make([['field' => 'location', 'operator' => '=', 'value' => 'wrong'], ['join' => 'OR', 'rows' => array_slice($conditions, 1)]]);
			$this->assertTrue(alarmmanager_rule_matches($or, $event));
			$db->exec("UPDATE host SET location='changed' WHERE id=42");
			$this->assertFalse(alarmmanager_rule_matches($policy, $event), 'Current host values override stale event metadata.');
			$db->exec("UPDATE host SET location='hello' WHERE id=42");
			$this->assertFalse(alarmmanager_rule_matches($make([['field' => 'description', 'operator' => 'contains', 'value' => "%_!' OR 1=1"]]), $event), 'SQL and wildcard characters are literal values.');
			$missing                 = $event;
			$missing['device']['id'] = 99;
			$this->assertFalse(alarmmanager_rule_matches($make([['field' => 'hostname', 'operator' => '!=', 'value' => 'anything']]), $missing));
			alarmmanager_apply_configuration(['version' => 1, 'endpoints' => [
				['id' => 1, 'name' => 'Slack', 'type' => 'webhook', 'transport' => ['url' => 'https://slack.example.test']],
				['id' => 2, 'name' => 'Second integration', 'type' => 'webhook', 'transport' => ['url' => 'https://other.example.test']]
			], 'rules' => [['id' => 1, 'name' => 'test', 'endpoints' => [1, 2], 'policy' => $policy]]]);
			alarmmanager_transaction(static fn () => alarmmanager_persist_event($event, 'centralized', 2));
			$this->assertTrue(alarmmanager_process_job(alarmmanager_claim_processing()));
			$this->assertSame([1, 2], array_map('intval', $db->query('SELECT endpoint_id FROM plugin_alarmmanager_deliveries ORDER BY endpoint_id')->fetchAll(PDO::FETCH_COLUMN)), 'Matching devices route to both selected integrations.');
			$db->exec('UPDATE host SET host_template_id=9 WHERE id=42');
			$event['event_id']  = 'nonmatching-device';
			$event['alarm_key'] = 'nonmatching-device';
			alarmmanager_transaction(static fn () => alarmmanager_persist_event($event, 'centralized', 2));
			$this->assertTrue(alarmmanager_process_job(alarmmanager_claim_processing()));
			$this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries')->fetchColumn(), 'A different template must not enqueue integrations.');
			$this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM plugin_alarmmanager_decisions WHERE reason='no_route'")->fetchColumn());
		} finally {
			$db->exec("DROP DATABASE `$name`");
			$GLOBALS['config'] = $before;
		}
	}
}
