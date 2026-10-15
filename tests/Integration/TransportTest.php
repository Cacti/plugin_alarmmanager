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
final class TransportTest extends AlarmManagerTestCase {
	/**
	 * HTTP/TLS/script/email transports, credentials rotation, worker dispatch and boundaries.
	 */
	public function testTransportDeliveryCredentialsAndBoundaries(): void {
		self::requireAlarmManagerTestDb();

		if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
			$this->markTestSkipped('Dedicated DB create opt-in and curl required.');
		}

		if (!extension_loaded('curl')) {
			$this->markTestSkipped('The curl extension is required for the transport fixtures.');
		}

		foreach (['openssl', 'python3'] as $tool) {
			if (trim((string) @shell_exec('command -v ' . $tool)) === '') {
				$this->markTestSkipped("$tool is required for the transport fixtures.");
			}
		}

		if (!function_exists('posix_kill') || !defined('SIGKILL') || !is_executable('/usr/bin/setsid')) {
			$this->markTestSkipped('posix, SIGKILL and setsid are required for the script transport fixtures.');
		}

		// email_boundary.php probes the Cacti mailer signature with function_exists().
		if (!function_exists('send_mail')) {
			function send_mail($to, $from, $subject, $body, $attachments = '', $headers = '', $html = false) {
				$GLOBALS['mail_fixture_args'] = func_get_args();

				return $GLOBALS['mail_fixture_error'] ?? '';
			}
		}

		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/ingestion.php');
		self::alarmmanager_test_load('includes/workers.php');
		self::alarmmanager_test_load('includes/delivery.php');
		self::alarmmanager_test_load('includes/email_boundary.php');
		$GLOBALS['config']               = ['poller_id' => 2];
		$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
		$database                        = 'alarmmanager_test_' . bin2hex(random_bytes(6));
		$GLOBALS['alarmmanager_test_db']->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
		$GLOBALS['alarmmanager_test_db']->exec("USE `$database`");
		$tmp = sys_get_temp_dir() . '/alarmmanager-transport-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		$server = null;
		$pipes  = [];

		try {
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id, name)) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
			$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.4.0', 1)");
			$this->assertTrue(plugin_alarmmanager_install() && alarmmanager_schema_ready(), 'Schema v3 install');
			$key = alarmmanager_secret_key();
			$this->assertSame(32, strlen($key), 'Setup key');
			$this->assertTrue(alarmmanager_schema_install() && alarmmanager_secret_key() === $key, 'Repeat schema setup key persistence');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = 2');
			$GLOBALS['alarmmanager_test_db']->exec('DROP TABLE plugin_alarmmanager_credentials, plugin_alarmmanager_transports');
			$this->assertTrue(plugin_alarmmanager_upgrade() && alarmmanager_schema_ready() && alarmmanager_secret_key() === $key, 'Version 2 migration preserved key');
			$openssl = proc_open(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $tmp . '/key.pem', '-out', $tmp . '/cert.pem', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $opipes);
			$this->assertTrue(is_resource($openssl) && proc_close($openssl) === 0, 'TLS fixture certificate');
			$server = proc_open(['python3', __DIR__ . '/../fixtures/transport_server.py', $tmp . '/cert.pem', $tmp . '/key.pem'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
			stream_set_timeout($pipes[1], 5);
			$ports = json_decode(fgets($pipes[1]), true, 4, JSON_THROW_ON_ERROR);
			$http  = 'http://127.0.0.1:' . $ports['http'];
			$https = 'https://localhost:' . $ports['https'];
			$event = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true));
			$map   = ['default' => [['to' => 'hostname', 'source' => 'device.id'], ['to' => 'message', 'source' => 'alarm.summary']]];
			$base  = ['url' => $https . '/echo', 'ca_bundle' => $tmp . '/cert.pem', 'mapping' => $map, 'credential' => true, 'response_match' => ['path' => 'authenticated', 'equals' => true]];
			$this->tdefinition(1, 'restapi', $base);
			$endpoint   = ['id' => 1, 'type' => 'restapi', 'timeout_seconds' => 1];
			$credential = ['headers' => ['Authorization' => 'Bearer fixture-token']];
			alarmmanager_save_credential(1, $credential);
			$this->assertTrue(alarmmanager_load_credential(1) === $credential, 'Stored credential roundtrip');
			$this->assertFalse(str_contains(db_fetch_cell_prepared('SELECT ciphertext FROM plugin_alarmmanager_credentials'), 'fixture-token'), 'Ciphertext excludes plaintext');
			$before  = $this->tstats($http);
			$preview = alarmmanager_delivery_preview($event, $endpoint);
			$after   = $this->tstats($http);
			$this->assertTrue(($before['counts']['/echo'] ?? 0) === ($after['counts']['/echo'] ?? 0), 'Preview sent no traffic');
			$this->assertTrue($preview['credentials'] === '[REDACTED]' && !str_contains(json_encode($preview), 'fixture-token'), 'Preview redaction');
			$this->assertTrue(alarmmanager_http_transport($event, $endpoint, str_repeat('a', 64))['outcome'] === 'success', 'TLS authenticated HTTP');
			$base['response_match'] = ['path' => 'request.hostname', 'equals' => 42];
			$this->tdefinition(1, 'restapi', $base);
			$this->assertTrue(alarmmanager_http_transport($event, $endpoint, str_repeat('b', 64))['outcome'] === 'success', 'Mapped device id to hostname');
			$bad_host        = $base;
			$bad_host['url'] = 'https://127.0.0.1:' . $ports['https'] . '/echo';
			$this->tdefinition(1, 'restapi', $bad_host);
			$this->assertTrue(alarmmanager_http_transport($event, $endpoint, str_repeat('8', 64))['outcome'] === 'failure', 'Trusted certificate wrong hostname rejected');

			foreach (['POST', 'PUT', 'PATCH'] as $method) {
				$base['method']         = $method;
				$base['response_match'] = ['path' => 'method', 'equals' => $method];
				$this->tdefinition(1, 'restapi', $base);
				$this->assertTrue(alarmmanager_http_transport($event, $endpoint, str_repeat('c', 64))['outcome'] === 'success', 'HTTP method');
			}
			$untrusted = $base;
			unset($untrusted['ca_bundle']);
			$this->tdefinition(1, 'restapi', $untrusted);
			$this->assertTrue(alarmmanager_http_transport($event, $endpoint, str_repeat('d', 64))['outcome'] === 'failure', 'Untrusted TLS rejected');

			foreach ([['url' => $http . '/echo', 'credential' => true, 'allow_http' => true], ['url' => 'https://user:pass@localhost/'], ['url' => 'https://localhost/?token=x'], ['url' => 'file:///tmp/x']] as $invalid) {
				$this->treject(fn () => alarmmanager_transport_configuration('restapi', $invalid), 'Unsafe HTTP configuration');
			}
			$plain = ['url' => $http . '/ok', 'allow_http' => true, 'mapping' => $map];
			$this->tdefinition(1, 'restapi', $plain);
			$this->assertTrue(alarmmanager_http_transport($event, $endpoint, str_repeat('e', 64))['outcome'] === 'success', 'Explicit private HTTP');

			foreach (['/bad' => ['failure', 'http_400'], '/redirect' => ['failure', 'http_302'], '/large' => ['failure', 'http_response_limit'], '/slow' => ['retry', 'http_timeout']] as $path => $expected) {
				$this->tdefinition(1, 'restapi', $plain + []);
				$settings        = $plain;
				$settings['url'] = $http . $path;
				$this->tdefinition(1, 'restapi', $settings);
				$result = alarmmanager_http_transport($event, $endpoint, str_repeat('f', 64));
				$this->assertTrue([$result['outcome'], $result['code']] === $expected && !str_contains(json_encode($result), 'fake-secret'), 'HTTP failure classification/redaction');
			}
			$this->assertSame(0, (int) ($this->tstats($http)['counts']['/redirect-target'] ?? 0), 'Redirect did not forward');

			foreach (['/invalid' => 'http_response_invalid', '/mismatch' => 'http_response_mismatch'] as $path => $code) {
				$settings                   = $plain;
				$settings['url']            = $http . $path;
				$settings['response_match'] = ['path' => 'accepted', 'equals' => true];
				$this->tdefinition(1, 'restapi', $settings);
				$this->assertSame($code, alarmmanager_http_transport($event, $endpoint, str_repeat('0', 64))['code'], 'HTTP response criterion');
			}
			$this->assertTrue(alarmmanager_retry_after('9999999999') === 3600 && alarmmanager_retry_after('bad') === 0 && alarmmanager_retry_after(gmdate('D, d M Y H:i:s', 1005) . ' GMT', 1000) === 5, 'Retry-After date/clamp');
			$this->assertSame(9, alarmmanager_http_result(429, '', alarmmanager_transport_configuration('webhook', $plain), '9')['retry_after'], '429 retry classification');
			// Snapshot/rotation/restore are atomic and preserve usability across authorized pollers.
			$backup = alarmmanager_credential_backup();
			$cipher = db_fetch_cell_prepared('SELECT ciphertext FROM plugin_alarmmanager_credentials');
			alarmmanager_rotate_key();
			$this->assertTrue(alarmmanager_secret_key() !== $key && alarmmanager_load_credential(1) === $credential, 'Rotation usability');
			$rotated                                                                                                        = alarmmanager_secret_key();
			$GLOBALS['alarmmanager_test_failure_plan']['UPDATE settings_user SET value = ? WHERE user_id = ? AND name = ?'] = [9999];
			$this->treject(fn () => alarmmanager_rotate_key(), 'Rotation write failure');
			$this->assertTrue(alarmmanager_secret_key() === $rotated && alarmmanager_load_credential(1) === $credential, 'Rotation rollback preserved data');
			alarmmanager_credential_restore($backup['bundle'], $backup['key']);
			$this->assertTrue(alarmmanager_secret_key() === $key && db_fetch_cell_prepared('SELECT ciphertext FROM plugin_alarmmanager_credentials') === $cipher, 'Backup restore');
			alarmmanager_db_write('DELETE FROM settings_user WHERE name = ?', ['alarmmanager_key']);
			$this->treject(fn () => alarmmanager_setup_key(), 'Missing key over records refuses setup');
			$this->treject(fn () => alarmmanager_load_credential(1), 'Missing key fails closed');
			alarmmanager_credential_restore($backup['bundle'], $backup['key']);
			$this->assertTrue(alarmmanager_load_credential(1) === $credential, 'Lost key explicit restore');
			alarmmanager_db_write('UPDATE settings_user SET value = ?', [base64_encode(random_bytes(32))]);
			$this->treject(fn () => alarmmanager_load_credential(1), 'Wrong stored key');
			alarmmanager_credential_restore($backup['bundle'], $backup['key']);
			$bad = $backup['bundle'];
			$bad['credentials'][0]['ciphertext'] .= ' ';
			$this->treject(fn () => alarmmanager_credential_restore($bad, $backup['key']), 'Tampered bundle');
			$this->assertTrue(alarmmanager_load_credential(1) === $credential, 'Failed restore left data');
			$GLOBALS['config']['DEBUG_SQL_FLOW'] = true;
			$this->treject(fn () => alarmmanager_load_credential(1), 'Secret tracing fails closed');
			$GLOBALS['config']['DEBUG_SQL_FLOW'] = false;
			// Provision a separate poller-local database and prove ciphertext alone is unusable.
			$primary_db      = $GLOBALS['alarmmanager_test_db'];
			$remote_database = 'alarmmanager_test_' . bin2hex(random_bytes(6));
			$primary_db->exec("CREATE DATABASE `$remote_database` CHARACTER SET utf8mb4");

			try {
				$GLOBALS['alarmmanager_test_db'] = alarmmanager_test_connect();
				$GLOBALS['alarmmanager_test_db']->exec("USE `$remote_database`");
				$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
				$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id, name)) ENGINE=InnoDB');
				$GLOBALS['alarmmanager_test_db']->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
				$GLOBALS['alarmmanager_test_db']->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.5.0', 1)");
				$GLOBALS['config']['poller_id'] = 3;
				$this->assertTrue(plugin_alarmmanager_install(), 'Independent poller setup');
				$this->tdefinition(1, 'restapi', $base);
				alarmmanager_db_write('INSERT INTO plugin_alarmmanager_credentials (endpoint_id, ciphertext, updated_at) VALUES (1, ?, UTC_TIMESTAMP(6))', [$cipher]);
				$this->treject(fn () => alarmmanager_load_credential(1), 'Ciphertext-only provisioning rejected');
				alarmmanager_credential_restore($backup['bundle'], $backup['key']);
				$this->assertTrue(alarmmanager_load_credential(1) === $credential && alarmmanager_http_transport($event, $endpoint, str_repeat('5', 64))['outcome'] === 'success', 'Provisioned independent local credentials usable');
			} finally {
				$GLOBALS['alarmmanager_test_db'] = $primary_db;
				$GLOBALS['config']['poller_id']  = 2;
				$primary_db->exec("DROP DATABASE `$remote_database`");
			}
			// Worker-only test sends and simultaneous destinations with one transient failure.
			$routes                                     = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true);
			$routes['endpoints'][0]['transport']        = $plain;
			$routes['endpoints'][0]['transport']['url'] = $http . '/retry';
			$routes['endpoints'][1]['transport']        = ['url' => $http . '/echo', 'allow_http' => true, 'mapping' => ['default' => [['to' => 'host', 'source' => 'device.id']]], 'response_match' => ['path' => 'request.host', 'equals' => 42]];
			alarmmanager_apply_configuration($routes);
			$this->assertFalse(str_contains(db_fetch_cell_prepared('SELECT definition FROM plugin_alarmmanager_configuration_history ORDER BY revision DESC LIMIT 1'), 'fixture-token'), 'Configuration history excludes credentials');
			$response = alarmmanager_submit(['contract_version' => 1, 'mode' => 'centralized', 'status' => 'unhandled', 'event' => $event]);
			$this->assertSame('accepted', $response['status'], 'Fan-out ingestion');
			$claim = alarmmanager_claim_processing();
			$this->assertTrue($claim && alarmmanager_process_job($claim), 'Fan-out processing');
			$this->assertSame(2, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries'), 'Two destinations');

			for ($i = 0; $i < 2; $i++) {
				$claim = alarmmanager_claim_delivery(['restapi', 'webhook']);
				$this->assertTrue($claim && alarmmanager_run_delivery($claim), 'Real adapter worker dispatch');
			}
			$this->assertSame(1, (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'"), 'Sibling succeeded independently');
			$this->assertGreaterThanOrEqual(1, (int) db_fetch_cell_prepared("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) FROM plugin_alarmmanager_deliveries WHERE status = 'pending'"), 'Retry-After scheduled');
			$stats      = $this->tstats($http);
			$echo_count = $stats['counts']['/echo'];
			alarmmanager_db_write("UPDATE plugin_alarmmanager_deliveries SET available_at = UTC_TIMESTAMP(6) WHERE status = 'pending'");
			$claim = alarmmanager_claim_delivery(['restapi', 'webhook']);
			$this->assertTrue($claim && alarmmanager_run_delivery($claim), 'Retry completed');
			$stats = $this->tstats($http);
			$this->assertTrue($stats['counts']['/echo'] === $echo_count && count($stats['keys']['/retry']) === 2 && $stats['keys']['/retry'][0] === $stats['keys']['/retry'][1], 'No sibling resend/stable idempotency');
			$before  = $stats['counts']['/echo'];
			$test_id = alarmmanager_enqueue_test($event, 2);
			$this->assertTrue($this->tstats($http)['counts']['/echo'] === $before, 'Test action only queued');
			$claim = alarmmanager_claim_delivery(['webhook']);
			$this->assertTrue($claim && $claim['event_row_id'] === $test_id && alarmmanager_run_delivery($claim), 'Explicit test dispatched by worker');
			// Expired claims cannot start an external effect.
			$stale_id    = alarmmanager_enqueue_test($event, 2);
			$stale_claim = alarmmanager_claim_delivery(['webhook']);
			$echo_count  = $this->tstats($http)['counts']['/echo'];
			alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$stale_claim['id']]);
			$this->assertTrue(!alarmmanager_run_delivery($stale_claim) && $this->tstats($http)['counts']['/echo'] === $echo_count, 'Expired worker did not send traffic');
			// Credential corruption fails closed before traffic and is sanitized in attempt records.
			$this->tdefinition(1, 'restapi', $base);
			$bad_credential_id = alarmmanager_enqueue_test($event, 1);
			$claim             = alarmmanager_claim_delivery(['restapi']);
			$echo_count        = $this->tstats($http)['counts']['/echo'];
			alarmmanager_db_write('UPDATE plugin_alarmmanager_credentials SET ciphertext = ?', ['am1:invalid']);
			$this->assertTrue(alarmmanager_run_delivery($claim) && db_fetch_cell_prepared('SELECT last_error FROM plugin_alarmmanager_deliveries WHERE event_row_id = ?', [$bad_credential_id]) === 'credential_unavailable' && $this->tstats($http)['counts']['/echo'] === $echo_count, 'Bad credential worker failure redacted/no traffic');
			alarmmanager_credential_restore($backup['bundle'], $backup['key']);
			// Controlled script argv/stdin/env, failures and bounds.
			$script = ['executable' => PHP_BINARY, 'arguments' => ['-n', __DIR__ . '/../fixtures/transport_script.php', 'ok', $tmp . '/script.json', 'a;$(touch never)'], 'mapping' => $map];
			$this->tdefinition(3, 'script', $script);
			$ep3 = ['id' => 3, 'type' => 'script', 'timeout_seconds' => 1];
			putenv('ALARMMANAGER_FIXTURE_PRIVATE=private');
			$this->assertTrue(alarmmanager_script_transport($event, $ep3, str_repeat('1', 64))['outcome'] === 'success', 'Structured script');
			$observed = json_decode(file_get_contents($tmp . '/script.json'), true);
			$this->assertTrue($observed['payload']['hostname'] === 42 && $observed['arguments'] === ['a;$(touch never)'] && $observed['environment_filtered'] && $observed['idempotency'] === str_repeat('1', 64), 'Script escaping/input/env');
			$script['compatibility'] = 'syslog_ticket';
			$this->tdefinition(3, 'script', $script);
			alarmmanager_script_transport($event, $ep3, str_repeat('2', 64));
			$observed = json_decode(file_get_contents($tmp . '/script.json'), true);
			$this->assertTrue(in_array('--severity=major', $observed['arguments'], true) && in_array('--hostlist=router.example.test', $observed['arguments'], true), 'Syslog ticket named argv');
			unset($script['compatibility']);

			foreach (['retry' => ['retry', 'script_exit_failed'], 'failure' => ['failure', 'script_exit_failed'], 'timeout' => ['retry', 'script_timeout'], 'large' => ['failure', 'script_output_limit']] as $mode => $expected) {
				$script['arguments'] = ['-n', __DIR__ . '/../fixtures/transport_script.php', $mode];
				$this->tdefinition(3, 'script', $script);
				$result = alarmmanager_script_transport($event, $ep3, str_repeat('3', 64));
				$this->assertTrue([$result['outcome'], $result['code']] === $expected, 'Script exit/runtime/output classification');
			}
			$script['arguments'] = ['-n', __DIR__ . '/../fixtures/transport_script.php', 'descendants', $tmp . '/child.pid'];
			$this->tdefinition(3, 'script', $script);
			$result = alarmmanager_script_transport($event, $ep3, str_repeat('9', 64));
			$child  = (int) file_get_contents($tmp . '/child.pid');
			$state  = @file_get_contents('/proc/' . $child . '/stat');
			$this->assertTrue($result['code'] === 'script_timeout' && ($state === false || preg_match('/\) Z /', $state)), 'Timeout killed ordinary script descendants');
			$this->treject(fn () => alarmmanager_transport_configuration('script', ['executable' => 'php']), 'Relative script rejected');
			// Cacti boundary preserves supported formatting/recipient/attachment signatures.
			$message = ['to' => [['email' => 'one@example.test', 'name' => 'One'], ['email' => 'two@example.test', 'name' => 'Two']], 'from' => 'Alarm <alarm@example.test>', 'subject' => 'Alarm subject', 'body' => '<b>body</b>', 'attachments' => [['attachment' => 'fixture content', 'filename' => 'test.txt', 'mime_type' => 'text/plain', 'inline' => 'attachment']], 'html' => true, 'idempotency' => str_repeat('4', 64)];
			$this->assertTrue(alarmmanager_email_send($message)['outcome'] === 'success', 'Cacti mailer success boundary');
			$this->assertSame([$message['to'], $message['from'], $message['subject'], $message['body'], $message['attachments'], ['X-AlarmManager-Idempotency-Key' => $message['idempotency']], true], $GLOBALS['mail_fixture_args'], 'Cacti email arguments preserved');
			$GLOBALS['mail_fixture_error'] = 'fake-secret';
			$this->assertTrue(alarmmanager_email_send($message) === ['outcome' => 'retry', 'code' => 'email_failed'], 'Mailer error redaction');
			$this->treject(fn () => alarmmanager_transport_configuration('email', ['to' => "x\r\nBcc: y"]), 'Email header injection');
			$script_reference                       = $script;
			$script_reference['arguments']          = ['-n', __DIR__ . '/../fixtures/transport_script.php', 'reference'];
			$script_reference['response_reference'] = ['id' => 'ticket.id', 'url' => 'ticket.url'];
			$this->tdefinition(3, 'script', $script_reference);
			$this->assertSame(['id' => 'SCRIPT-42', 'url' => 'https://tickets.example.invalid/script'], alarmmanager_script_transport($event, $ep3, str_repeat('8', 64))['external_reference'], 'Script stdout captures only selected reference fields');
			// Real HTTP references, atomic completion/fencing and episode/destination isolation.
			alarmmanager_db_write('UPDATE plugin_alarmmanager_endpoints SET enabled = 0');
			$reference_map = ['default' => [['to' => 'external_id', 'source' => 'integration.id']]];

			foreach ([10 => '/ticket', 11 => '/ticket2'] as $id => $path) {
				$this->tdefinition($id, 'webhook', ['url' => $http . $path, 'allow_http' => true, 'mapping' => $reference_map, 'response_reference' => ['id' => 'ticket.id', 'url' => 'ticket.url']]);
			}
			$reference_event               = $event;
			$reference_event['alarm_key']  = 'reference-test';
			$reference_event['episode_id'] = 'reference-episode';
			$reference_event['sequence']   = 1;
			$reference_event['event_id']   = 'reference-raise';
			$reference_event['transition'] = 'raised';
			$stream                        = alarmmanager_stream_key($reference_event, 'centralized');
			$insert                        = function (array $sample) use ($stream): int {
				$result = alarmmanager_transaction(fn () => alarmmanager_persist_event(alarmmanager_validate_event($sample), 'centralized', 2));

				foreach ([10, 11] as $id) {
					alarmmanager_db_write('INSERT INTO plugin_alarmmanager_deliveries (event_row_id, endpoint_id, stream_key, sequence, priority, available_at, created_at, updated_at, idempotency_key) VALUES (?, ?, ?, ?, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)', [$result['event_row_id'], $id, $stream, $sample['sequence'], hash('sha256', $sample['event_id'] . ':' . $id)]);
				}

				return $result['event_row_id'];
			};
			$raise_id    = $insert($reference_event);
			$lost_claim  = alarmmanager_claim_delivery(['webhook']);
			$lost_result = alarmmanager_http_transport($reference_event, $lost_claim['endpoint'], $lost_claim['idempotency_key']);
			$this->assertSame('success', $lost_result['outcome']);
			$this->assertNull(db_fetch_cell_prepared('SELECT external_reference FROM plugin_alarmmanager_deliveries WHERE id = ?', [$lost_claim['id']]), 'Uncommitted response does not enter reference history');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$lost_claim['id']]);
			$this->assertFalse(alarmmanager_finish_delivery($lost_claim, $lost_result), 'Expired completion cannot store a reference');
			alarmmanager_reclaim('delivery');
			alarmmanager_db_write("UPDATE plugin_alarmmanager_deliveries SET available_at = UTC_TIMESTAMP(6) WHERE endpoint_id IN (10, 11) AND status = 'pending'");

			for ($i = 0; $i < 2; $i++) {
				$claim = alarmmanager_claim_delivery(['webhook']);
				$this->assertTrue($claim && alarmmanager_run_delivery($claim));
				$this->assertFalse(alarmmanager_finish_delivery($claim, ['outcome' => 'success', 'code' => 'http_200', 'external_reference' => ['id' => 'STALE']]), 'Finished lease cannot overwrite reference');
			}
			$reference_event['sequence']   = 2;
			$reference_event['event_id']   = 'reference-recovery';
			$reference_event['transition'] = 'recovered';
			$this->assertSame('CASE-1', alarmmanager_delivery_reference($reference_event, 10)['id']);
			$this->assertSame('CASE-2', alarmmanager_delivery_reference($reference_event, 11)['id']);
			$preview_count = $this->tstats($http)['counts']['/ticket'];
			$this->assertSame('CASE-1', alarmmanager_delivery_preview($reference_event, ['id' => 10, 'type' => 'webhook'])['payload']->external_id);
			$this->assertSame($preview_count, $this->tstats($http)['counts']['/ticket'], 'Preview sends no request');
			$recovery_id = $insert($reference_event);
			$this->assertSame('already_accepted', alarmmanager_transaction(fn () => alarmmanager_persist_event(alarmmanager_validate_event($reference_event), 'centralized', 2))['status']);

			for ($i = 0; $i < 2; $i++) {
				$claim = alarmmanager_claim_delivery(['webhook']);
				$this->assertTrue($claim && alarmmanager_run_delivery($claim));
			}
			$this->assertSame('CASE-1', json_decode(db_fetch_cell_prepared('SELECT external_reference FROM plugin_alarmmanager_deliveries WHERE event_row_id = ? AND endpoint_id = 10', [$recovery_id]), true)['id'], 'Recovery reused original external ID');
			$this->assertSame(2, (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE event_row_id = ?', [$recovery_id]), 'Duplicate recovery did not duplicate delivery');
			$reference_event['episode_id'] = 'new-reference-episode';
			$this->assertSame([], alarmmanager_delivery_reference($reference_event, 10), 'New episode cannot inherit old ticket');
			$this->assertSame([], alarmmanager_delivery_reference($reference_event, 12), 'Unrelated destination cannot inherit ticket');
			alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = 8');
			$this->assertTrue(alarmmanager_schema_install() && alarmmanager_schema_ready(), 'v8 upgrade and repeat schema installation succeed');
			$this->assertSame('CASE-1', json_decode(db_fetch_cell_prepared('SELECT external_reference FROM plugin_alarmmanager_deliveries WHERE event_row_id = ? AND endpoint_id = 10', [$raise_id]), true)['id'], 'Upgrade preserves reference history');
			$this->assertTrue(alarmmanager_delivery_handlers()['restapi'] === 'alarmmanager_http_transport' && alarmmanager_delivery_handlers()['webhook'] === 'alarmmanager_http_transport', 'Production handlers registered');
		} finally {
			if (is_resource($server)) {
				proc_terminate($server, SIGKILL);

				foreach ($pipes as $pipe) {
					fclose($pipe);
				} proc_close($server);
			}

			if ($GLOBALS['alarmmanager_test_db']->inTransaction()) {
				$GLOBALS['alarmmanager_test_db']->rollBack();
			}
			$GLOBALS['alarmmanager_test_db']->exec("DROP DATABASE `$database`");

			foreach (glob($tmp . '/*') as $file) {
				unlink($file);
			} rmdir($tmp);
		}
	}

	/**
	 * Throw the label unless the action throws, mirroring the original rejection fixture.
	 */
	private function treject(callable $action, string $label): void {
		try {
			$action();
		} catch (Throwable $error) {
			$this->assertTrue(true, $label);

			return;
		}

		$this->fail($label);
	}

	/**
	 * Install one fixture endpoint transport definition.
	 */
	private function tdefinition(int $id, string $type, array $settings): void {
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_endpoints (id, name, type, enabled, timeout_seconds) VALUES (?, ?, ?, 1, 1) ON DUPLICATE KEY UPDATE type = VALUES(type)', [$id, 'fixture', $type]);
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_transports (endpoint_id, definition) VALUES (?, ?) ON DUPLICATE KEY UPDATE definition = VALUES(definition)', [$id, json_encode($settings, JSON_THROW_ON_ERROR)]);
	}

	/**
	 * Query the transport server's loopback statistics endpoint.
	 */
	private function tstats(string $url): array {
		$c = curl_init($url . '/stats');
		curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
		$data = curl_exec($c);
		curl_close($c);

		return json_decode($data, true, 16, JSON_THROW_ON_ERROR);
	}
}
