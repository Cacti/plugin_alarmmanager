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

use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class WebTest extends AlarmManagerTestCase {
	/**
	 * Web scoping, admin actions, replay, Cacti policy unions and producer administration.
	 */
	public function testWebScopingAdminActionsAndProducerAdmin(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Set ALARMMANAGER_TEST_ALLOW_CREATE=1 and a dedicated test-server DSN to run the web lane.');
		}

		// web_auth.php and web_view.php probe these Cacti CSRF fixtures with function_exists().
		if (!function_exists('csrf_check')) {
			function csrf_check($fatal = true) {
				return ($_POST['__csrf_magic'] ?? '') === 'fixture-valid';
			}
		}

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'fixture-valid';
			}
		}

		self::alarmmanager_test_load('tests/fixtures/cacti_web_functions.php');
		self::alarmmanager_test_load('tests/fixtures/web_schema.php');
		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/processor.php');
		self::alarmmanager_test_load('includes/queue.php');
		self::alarmmanager_test_load('includes/admin_view.php');
		$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$GLOBALS['alarmmanager_test_db']->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");

		try {
			alarmmanager_test_web_schema($GLOBALS['alarmmanager_test_db']);
			$this->assertTrue(plugin_alarmmanager_install(), 'Schema/install registration');
			alarmmanager_test_web_grants($GLOBALS['alarmmanager_test_db']);
			$routes                         = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true);
			$routes['endpoints'][0]['name'] = '<img src=x onerror=window.ALARM_BAD=1>';
			alarmmanager_apply_configuration($routes);
			$event = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true);

			for ($i = 1; $i <= 400; $i++) {
				$sample                 = $event;
				$sample['event_id']     = 'web:' . $i;
				$sample['alarm_key']    = 'web:' . $i;
				$sample['source']       = 'fixture';
				$sample['device']['id'] = $i <= 250 ? 42 : 43;
				$sample['summary']      = $i === 1 ? 'Visible <img src=x onerror=window.ALARM_BAD=1>' : ($i <= 250 ? 'Visible' : 'HIDDEN_FORBIDDEN_MARKER');
				$response               = alarmmanager_submit(['contract_version'=>1, 'status'=>'unhandled', 'mode'=>'centralized', 'event'=>$sample]);

				if ($response['status'] !== 'accepted') {
					throw new RuntimeException('Seed ingestion failed');
				}
			}

			while ($claim = alarmmanager_claim_processing()) {
				alarmmanager_process_job($claim);
			}
			$sample              = $event;
			$sample['event_id']  = 'web:global';
			$sample['alarm_key'] = 'web:global';
			$sample['source']    = 'fixture';
			unset($sample['device']);
			alarmmanager_submit(['contract_version'=>1, 'status'=>'unhandled', 'mode'=>'centralized', 'event'=>$sample]);
			alarmmanager_process_job(alarmmanager_claim_processing());
			$sample              = $event;
			$sample['event_id']  = 'web:observe';
			$sample['alarm_key'] = 'web:observe';
			$sample['source']    = 'fixture';
			alarmmanager_submit(['contract_version'=>1, 'status'=>'unhandled', 'mode'=>'observe', 'event'=>$sample]);
			alarmmanager_process_job(alarmmanager_claim_processing());
			$filters = alarmmanager_report_filters(['until'=>time() + 1]);
			$this->wuser(2);
			$scope = alarmmanager_web_scope();
			$this->assertFalse($scope['admin'], 'Read-only scope');
			$started     = microtime(true);
			$queries     = $GLOBALS['alarmmanager_test_queries'];
			$dashboard   = alarmmanager_dashboard_data($scope,$filters);
			$duration    = (microtime(true) - $started) * 1000;
			$query_count = $GLOBALS['alarmmanager_test_queries'] - $queries;
			$this->assertTrue((int)$dashboard['historical']['events']['events'] === 250 && (int)$dashboard['current']['conditions']['active'] === 250, 'Scoped aggregates exclude hidden/global');
			$buckets = $dashboard['panels']['transitions'];
			$GLOBALS['alarmmanager_test_db']->exec("SET time_zone='+04:00'");
			$this->assertTrue(alarmmanager_dashboard_data($scope,$filters)['panels']['transitions'] === $buckets, 'UTC buckets independent of DB session timezone');
			$GLOBALS['alarmmanager_test_db']->exec("SET time_zone='+00:00'");
			$this->assertTrue(!$dashboard['can_admin'] && $dashboard['workers'] === [], 'Read-only no worker details');
			$this->assertSame(500, array_sum(array_column($dashboard['panels']['deliveries'],'count')), 'Scoped delivery totals');
			$events = alarmmanager_report_list('events',$scope,$filters);
			$this->assertTrue(count($events['rows']) === 50 && $events['more'], 'Bounded page');
			$this->assertTrue(count(array_unique(array_column($events['rows'],'device_id'))) === 1 && (int)$events['rows'][0]['device_id'] === 42, 'Permissions before pagination');
			$next         = $filters;
			$next['page'] = 2;
			$page2        = alarmmanager_report_list('events',$scope,$next);
			$this->assertSame([], array_intersect(array_column($events['rows'],'id'), array_column($page2['rows'],'id')), 'Stable pagination');

			foreach (['conditions', 'deliveries', 'attempts', 'decisions', 'processing'] as $kind) {
				$report = alarmmanager_report_list($kind,$scope,$filters);
				$this->assertTrue(count($report['rows']) <= 50 && !str_contains(json_encode($report),'HIDDEN_FORBIDDEN_MARKER'), 'Scoped detail report');
			}
			$selected             = $filters;
			$selected['event_id'] = (int)$events['rows'][0]['id'];
			$this->assertTrue(alarmmanager_event_detail($scope,$selected)['device']['id'] === 42, 'Scoped selected event details');
			$hidden               = alarmmanager_db_row('SELECT id FROM plugin_alarmmanager_events WHERE device_id=43 LIMIT 1');
			$selected['event_id'] = (int)$hidden['id'];
			$this->assertNull(alarmmanager_event_detail($scope,$selected), 'Hidden selected event detail denied');
			$specific             = $filters;
			$specific['severity'] = 'major';
			$this->assertSame(250, (int)alarmmanager_dashboard_data($scope,$specific)['historical']['events']['events'], 'Filter aggregate');
			$specific['device_id'] = 43;
			$this->assertSame([], alarmmanager_report_list('events',$scope,$specific)['rows'], 'Hidden explicit ID');
			$obs         = $filters;
			$obs['mode'] = 'observe';
			$this->assertSame(1, (int)alarmmanager_dashboard_data($scope,$obs)['historical']['events']['events'], 'Observation separate counts');
			$this->wreject(fn () =>$this->waction(['action'=>'credential_remove', 'id'=>'1']), 'Read-only mutation denied');
			$this->wuser(3);
			$this->wreject(fn () =>alarmmanager_web_scope(), 'Unassigned realm denied');
			$this->wuser(1);
			$admin = alarmmanager_web_scope();
			$this->assertTrue($admin['admin'] && (int)alarmmanager_dashboard_data($admin,$filters)['historical']['events']['events'] === 401, 'Administrator scope includes global');
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = [];
			$this->wreject(fn () =>alarmmanager_admin_action(['action'=>'credential_remove', 'id'=>'1']), 'GET mutation denied');
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$this->wreject(fn () =>alarmmanager_admin_action(['action'=>'credential_remove', 'id'=>'1']), 'Missing CSRF denied');
			$_POST = ['__csrf_magic'=>'bad'];
			$this->wreject(fn () =>alarmmanager_admin_action(['action'=>'credential_remove', 'id'=>'1']), 'Bad CSRF denied');

			foreach ([['range'=>'999999'], ['mode'=>'all'], ['page'=>'201'], ['until'=>'999999999999'], ['source'=>"' OR 1=1"], ['device_id'=>['42']]] as $bad) {
				$this->wreject(fn () =>alarmmanager_report_filters($bad), 'Invalid filter rejected');
			}
			$state   = alarmmanager_admin_configuration();
			$version = $state['revision'];
			$this->waction(['action'=>'configuration_save', 'revision'=>$version, 'configuration_json'=>json_encode($state['configuration'])]);
			$this->wreject(fn () =>$this->waction(['action'=>'configuration_save', 'revision'=>$version, 'configuration_json'=>json_encode($state['configuration'])]), 'Stale configuration rejected');
			$this->assertSame(1, (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_audit'), 'Configuration audit');
			$record = ['headers'=>['Authorization'=>'Bearer WEB_SECRET_MARKER']];
			$this->waction(['action'=>'credential_save', 'id'=>'1', 'headers'=>json_encode($record['headers'])]);
			$this->assertTrue(alarmmanager_load_credential(1) === $record, 'Admin credential save');
			ob_start();
			alarmmanager_admin_markup(alarmmanager_admin_configuration(),'credentials');
			$html = ob_get_clean();
			$this->assertTrue(!str_contains($html,'WEB_SECRET_MARKER') && !str_contains($html,'am1:'), 'Credential page never returns ciphertext/plaintext');
			ob_start();
			alarmmanager_endpoint_editor($routes['endpoints'][0],$version);
			$html = ob_get_clean();
			$this->assertTrue(str_contains($html,'&lt;img') && !str_contains($html,'<img src=x'), 'Endpoint editor escaping');
			$routes['endpoints'][0]['transport'] = ['url'=>'https://example.test/alarms', 'credential'=>true, 'mapping'=>['default'=>[['to'=>'hostname', 'source'=>'device.id']]]];
			alarmmanager_apply_configuration($routes);
			$native = ['action'=>'endpoint_save', 'revision'=>alarmmanager_admin_configuration()['revision'], 'id'=>'1', 'name'=>'Native REST', 'enabled'=>'yes', 'type'=>'restapi', 'url'=>'https://example.test/alarms', 'method'=>'POST', 'credential'=>'yes', 'mapping_json'=>json_encode(['default'=>[['to'=>'host', 'source'=>'device.id']]]), 'transport_json'=>'{}'];
			$this->waction($native);
			$this->assertSame('host', alarmmanager_admin_configuration()['configuration']['endpoints'][0]['transport']['mapping']['default'][0]['to'], 'Native endpoint form mapping save');
			$before  = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries');
			$preview = $this->waction(['action'=>'preview', 'id'=>'1', 'event_json'=>json_encode($event)]);
			$this->assertTrue(str_contains($preview,'REDACTED') && !str_contains($preview,'WEB_SECRET_MARKER') && (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries') === $before, 'Admin preview no enqueue/secrets');
			$this->waction(['action'=>'test_send', 'id'=>'1', 'event_json'=>json_encode($event), 'confirmed'=>'yes']);
			$this->assertSame($before + 1, (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries'), 'Test queues single destination');
			$this->wreject(fn () =>$this->waction(['action'=>'test_send', 'id'=>'1', 'event_json'=>json_encode($event)]), 'Test explicit confirmation');
			$claim = alarmmanager_claim_delivery(['restapi']);
			$id    = $claim['id'];
			$key   = $claim['idempotency_key'];
			alarmmanager_finish_delivery($claim,['outcome'=>'failure', 'code'=>'fixture_failure']);
			$this->waction(['action'=>'replay', 'queue'=>'delivery', 'id'=>$id, 'confirmed'=>'yes']);
			alarmmanager_db_write("UPDATE plugin_alarmmanager_endpoints SET last_claim_at = CASE id WHEN 1 THEN '1970-01-01' ELSE UTC_TIMESTAMP(6) END");
			$reclaimed = alarmmanager_claim_delivery(['restapi']);
			$this->assertTrue($reclaimed && $reclaimed['id'] === $id && $reclaimed['idempotency_key'] === $key, 'Replay preserves identity/idempotency');
			alarmmanager_finish_delivery($reclaimed,['outcome'=>'success', 'code'=>'fixture_ok']);
			$this->assertSame(2, (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_attempts WHERE delivery_id=?', [$id]), 'Replay keeps prior attempts and appends distinct number');
			$this->wreject(fn () =>$this->waction(['action'=>'replay', 'queue'=>'delivery', 'id'=>$id, 'confirmed'=>'yes']), 'Completed job cannot replay');
			$job = alarmmanager_db_row('SELECT id FROM plugin_alarmmanager_jobs LIMIT 1');
			alarmmanager_db_write("UPDATE plugin_alarmmanager_jobs SET status='failed', attempts=5 WHERE id=?", [$job['id']]);
			$this->waction(['action'=>'replay', 'queue'=>'processing', 'id'=>$job['id'], 'confirmed'=>'yes']);
			$this->assertSame(0, (int)db_fetch_cell_prepared('SELECT attempts FROM plugin_alarmmanager_jobs WHERE id=?', [$job['id']]), 'Processing retry budget reset');
			$condition = alarmmanager_db_row('SELECT stream_key FROM plugin_alarmmanager_states WHERE active=1 LIMIT 1');
			$this->waction(['action'=>'acknowledge', 'stream_key'=>$condition['stream_key'], 'acknowledged'=>'yes']);
			$this->assertSame(1, (int)db_fetch_cell_prepared('SELECT acknowledged FROM plugin_alarmmanager_states WHERE stream_key=?', [$condition['stream_key']]), 'Acknowledgment audited');
			$old = alarmmanager_secret_key();
			$this->waction(['action'=>'key_rotate', 'confirmed'=>'yes']);
			$this->assertTrue(alarmmanager_secret_key() !== $old && alarmmanager_load_credential(1) === $record, 'Browser local key rotation');
			$this->waction(['action'=>'credential_remove', 'id'=>'1']);
			$this->assertSame(0, (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_credentials'), 'Credential remove');
			// Real Cacti policy generator: default-deny + group grants, deleted/disabled hosts and default-allow exclusions.
			$policy_filters           = $filters;
			$policy_filters['source'] = 'fixture';
			$this->wuser(2);
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO user_auth_group (id,name,enabled) VALUES (1,'Readers','on')");
			$GLOBALS['alarmmanager_test_db']->exec('INSERT INTO user_auth_group_members VALUES (2,1)');
			$GLOBALS['alarmmanager_test_db']->exec('INSERT INTO user_auth_group_perms VALUES (1,3,43)');
			$this->assertSame(400, (int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events'], 'Group device grant union');
			$GLOBALS['alarmmanager_test_db']->exec("UPDATE host SET deleted='on' WHERE id=43");
			$this->assertSame(250, (int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events'], 'Deleted host hidden');
			$GLOBALS['alarmmanager_test_db']->exec("UPDATE host SET deleted='' WHERE id=43");
			$GLOBALS['alarmmanager_test_db']->exec("UPDATE user_auth_group SET enabled='' WHERE id=1");
			$GLOBALS['alarmmanager_test_db']->exec('UPDATE user_auth SET policy_hosts=1 WHERE id=2');
			$GLOBALS['alarmmanager_test_db']->exec('DELETE FROM user_auth_perms WHERE user_id=2');
			$GLOBALS['alarmmanager_test_db']->exec('INSERT INTO user_auth_perms VALUES (2,3,43)');
			$this->assertSame(250, (int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events'], 'Default allow respects explicit deny');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO settings_user VALUES (2,'hide_disabled','on')");
			$GLOBALS['alarmmanager_test_db']->exec("UPDATE host SET disabled='on' WHERE id=42");
			$this->assertSame(0, (int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events'], 'Disabled host preference');
			[$where,$params] = alarmmanager_report_where($scope,$filters);
			$plan            = alarmmanager_db_rows("EXPLAIN SELECT e.id FROM plugin_alarmmanager_events AS e WHERE $where ORDER BY e.id DESC LIMIT 51",$params);
			$measure         = ['events'=>401, 'reader_visible_events'=>250, 'dashboard_queries'=>$query_count, 'dashboard_ms'=>round($duration,3), 'php_peak_bytes'=>memory_get_peak_usage(true), 'list_explain'=>$plan, 'database'=>db_fetch_cell_prepared('SELECT VERSION()')];

			if (getenv('ALARMMANAGER_WEB_MEASUREMENT')) {
				file_put_contents(getenv('ALARMMANAGER_WEB_MEASUREMENT'),json_encode($measure,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			}
			$deadline_start = hrtime(true);
			$this->wreject(fn () =>alarmmanager_report_rows('SELECT SLEEP(10)'),'Per-statement report timeout');
			$this->assertTrue((hrtime(true) - $deadline_start) / 1e9 < 9 && str_contains($GLOBALS['database_last_error'] ?? '', '1969'), 'Report timeout bounded');
			$this->wuser(2);
			$this->wreject(fn () =>$this->waction(['action'=>'producer_save', 'source'=>'thold', 'scope'=>'default', 'resource_id'=>0, 'mode'=>'legacy']), 'reader producer save denied');
			$this->wuser(1);
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = ['__csrf_magic'=>'invalid'];
			$this->wreject(fn () =>alarmmanager_admin_action(['action'=>'producer_save', 'source'=>'thold', 'scope'=>'default', 'resource_id'=>0, 'mode'=>'legacy']), 'producer invalid CSRF denied');
			$producer = ['action'=>'producer_save', 'source'=>'thold', 'scope'=>'default', 'resource_id'=>0, 'producer_revision'=>0, 'mode'=>'centralized', 'profile_id'=>1, 'channels'=>['email']];
			$this->wreject(fn () =>$this->waction($producer), 'central producer migration confirmation required');
			$this->waction($producer + ['migration_confirmed'=>'yes']);
			$this->assertSame('centralized', AlarmManagerProducerBridge::configuration('thold',42)['mode'], 'admin producer default saved');
			$this->wreject(fn () =>$this->waction($producer + ['migration_confirmed'=>'yes']), 'producer stale editor denied');
			$override = ['action'=>'producer_save', 'source'=>'thold', 'scope'=>'template', 'resource_id'=>10, 'producer_revision'=>0, 'mode'=>'observe', 'profile_id'=>0];
			$this->waction($override);
			$this->assertSame('observe', AlarmManagerProducerBridge::configuration('thold',42,10)['mode'], 'template override saved');
			$this->waction(['action'=>'producer_save', 'source'=>'thold', 'scope'=>'resource', 'resource_id'=>42, 'producer_revision'=>0, 'mode'=>'legacy', 'profile_id'=>0]);
			$this->assertSame('legacy', AlarmManagerProducerBridge::configuration('thold',42,10)['mode'], 'threshold override saved');
			$this->waction(['action'=>'producer_save', 'source'=>'thold', 'scope'=>'resource', 'resource_id'=>42, 'producer_revision'=>1, 'mode'=>'legacy', 'profile_id'=>0, 'inherit'=>'yes']);
			$this->assertSame('observe', AlarmManagerProducerBridge::configuration('thold',42,10)['mode'], 'override removal restores inheritance');
			$this->wreject(fn () =>$this->waction(['action'=>'producer_save', 'source'=>'syslog', 'scope'=>'template', 'resource_id'=>1, 'mode'=>'observe']), 'syslog template rejected');
			ob_start();
			alarmmanager_admin_markup(alarmmanager_admin_configuration(),'producers');
			$html = ob_get_clean();
			$this->assertTrue(str_contains($html,'Producer integrations') && str_contains($html,'migration_confirmed') && str_contains($html,'__csrf_magic'), 'producer admin controls and CSRF rendered');
			$this->assertSame(4, (int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_audit WHERE action LIKE 'producer_%'"), 'producer changes audited');
			ob_start();
			alarmmanager_admin_markup(alarmmanager_admin_configuration(),'collectors');
			$html = ob_get_clean();
			$this->assertTrue(str_contains($html,'Collectors and remote history') && str_contains($html,'Latest 50'), 'bounded collector history controls rendered');
		} finally {
			if ($GLOBALS['alarmmanager_test_db']->inTransaction()) {
				$GLOBALS['alarmmanager_test_db']->rollBack();
			}
			$GLOBALS['alarmmanager_test_db']->exec("DROP DATABASE `$database`");
		}
	}

	/**
	 * Throw the label unless the action throws, mirroring the original rejection fixture.
	 */
	private function wreject(callable $action, string $label): void {
		try {
			$action();
		} catch (Throwable $error) {
			$this->assertTrue(true, $label);

			return;
		}

		$this->fail($label);
	}

	/**
	 * Switch the acting web user.
	 */
	private function wuser(int $user): void {
		$_SESSION = ['sess_user_id' => $user];
	}

	/**
	 * Issue one admin action through the POST/CSRF web fixture surface.
	 */
	private function waction(array $input): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = ['__csrf_magic' => 'fixture-valid'];

		return alarmmanager_admin_action($input);
	}
}
