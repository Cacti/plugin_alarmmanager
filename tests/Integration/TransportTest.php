<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

if (getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1' || !getenv('ALARMMANAGER_TEST_DSN') || !extension_loaded('curl')) {
	fwrite(STDERR, "Dedicated DB create opt-in and curl required.\n");
	exit(2);
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/workers.php');
require_once(__DIR__ . '/../../includes/test_delivery.php');
require_once(__DIR__ . '/../../includes/email_boundary.php');
$checks = 0;
$config = ['poller_id' => 2];
function tcheck(bool $condition, string $label): void {
	global $checks;

	if (!$condition) {
		throw new RuntimeException($label);
	} $checks++;
}
function treject(callable $action, string $label): void {
	try {
		$action();
	} catch (Throwable $error) {
		tcheck(true, $label);

		return;
	}

throw new RuntimeException($label);
}
function tdefinition(int $id, string $type, array $settings): void {
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_endpoints (id, name, type, enabled, timeout_seconds) VALUES (?, ?, ?, 1, 1) ON DUPLICATE KEY UPDATE type = VALUES(type)', [$id, 'fixture', $type]);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_transports (endpoint_id, definition) VALUES (?, ?) ON DUPLICATE KEY UPDATE definition = VALUES(definition)', [$id, json_encode($settings, JSON_THROW_ON_ERROR)]);
}
function tstats(string $url): array {
	$c = curl_init($url . '/stats');
	curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
	$data = curl_exec($c);
	curl_close($c);

	return json_decode($data, true, 16, JSON_THROW_ON_ERROR);
}
function send_mail($to, $from, $subject, $body, $attachments = '', $headers = '', $html = false) {
	$GLOBALS['mail_fixture_args'] = func_get_args();

	return $GLOBALS['mail_fixture_error'] ?? '';
}
$alarmmanager_test_db = alarmmanager_test_connect();
$database             = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$alarmmanager_test_db->exec("USE `$database`");
$tmp = sys_get_temp_dir() . '/alarmmanager-transport-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$server = null;
$pipes  = [];

try {
	$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id, name)) ENGINE=InnoDB');
	$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.4.0', 1)");
	tcheck(plugin_alarmmanager_install() && alarmmanager_schema_ready(), 'Schema v3 install');
	$key = alarmmanager_secret_key();
	tcheck(strlen($key) === 32, 'Setup key');
	tcheck(alarmmanager_schema_install() && alarmmanager_secret_key() === $key, 'Repeat schema setup key persistence');
	alarmmanager_db_write('UPDATE plugin_alarmmanager_schema SET version = 2');
	$alarmmanager_test_db->exec('DROP TABLE plugin_alarmmanager_credentials, plugin_alarmmanager_transports');
	tcheck(plugin_alarmmanager_upgrade() && alarmmanager_schema_ready() && alarmmanager_secret_key() === $key, 'Version 2 migration preserved key');
	$openssl = proc_open(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $tmp . '/key.pem', '-out', $tmp . '/cert.pem', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $opipes);
	tcheck(is_resource($openssl) && proc_close($openssl) === 0, 'TLS fixture certificate');
	$server = proc_open(['python3', __DIR__ . '/../fixtures/transport_server.py', $tmp . '/cert.pem', $tmp . '/key.pem'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
	stream_set_timeout($pipes[1], 5);
	$ports = json_decode(fgets($pipes[1]), true, 4, JSON_THROW_ON_ERROR);
	$http  = 'http://127.0.0.1:' . $ports['http'];
	$https = 'https://localhost:' . $ports['https'];
	$event = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true));
	$map   = ['default' => [['to' => 'hostname', 'source' => 'device.id'], ['to' => 'message', 'source' => 'alarm.summary']]];
	$base  = ['url' => $https . '/echo', 'ca_bundle' => $tmp . '/cert.pem', 'mapping' => $map, 'credential' => true, 'response_match' => ['path' => 'authenticated', 'equals' => true]];
	tdefinition(1, 'restapi', $base);
	$endpoint   = ['id' => 1, 'type' => 'restapi', 'timeout_seconds' => 1];
	$credential = ['headers' => ['Authorization' => 'Bearer fixture-token']];
	alarmmanager_save_credential(1, $credential);
	tcheck(alarmmanager_load_credential(1) === $credential, 'Stored credential roundtrip');
	tcheck(!str_contains(db_fetch_cell_prepared('SELECT ciphertext FROM plugin_alarmmanager_credentials'), 'fixture-token'), 'Ciphertext excludes plaintext');
	$before  = tstats($http);
	$preview = alarmmanager_delivery_preview($event, $endpoint);
	$after   = tstats($http);
	tcheck(($before['counts']['/echo'] ?? 0) === ($after['counts']['/echo'] ?? 0), 'Preview sent no traffic');
	tcheck($preview['credentials'] === '[REDACTED]' && !str_contains(json_encode($preview), 'fixture-token'), 'Preview redaction');
	tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('a', 64))['outcome'] === 'success', 'TLS authenticated HTTP');
	$base['response_match'] = ['path' => 'request.hostname', 'equals' => 42];
	tdefinition(1, 'restapi', $base);
	tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('b', 64))['outcome'] === 'success', 'Mapped device id to hostname');
	$bad_host        = $base;
	$bad_host['url'] = 'https://127.0.0.1:' . $ports['https'] . '/echo';
	tdefinition(1, 'restapi', $bad_host);
	tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('8', 64))['outcome'] === 'failure', 'Trusted certificate wrong hostname rejected');

	foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
		$base['method']         = $method;
		$base['response_match'] = ['path' => 'method', 'equals' => $method];
		tdefinition(1, 'restapi', $base);
		tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('c', 64))['outcome'] === 'success', 'HTTP method');
	}
	$untrusted = $base;
	unset($untrusted['ca_bundle']);
	tdefinition(1, 'restapi', $untrusted);
	tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('d', 64))['outcome'] === 'failure', 'Untrusted TLS rejected');

	foreach ([['url' => $http . '/echo', 'credential' => true, 'allow_http' => true], ['url' => 'https://user:pass@localhost/'], ['url' => 'https://localhost/?token=x'], ['url' => 'file:///tmp/x']] as $invalid) {
		treject(fn () => alarmmanager_transport_configuration('restapi', $invalid), 'Unsafe HTTP configuration');
	}
	$plain = ['url' => $http . '/ok', 'allow_http' => true, 'mapping' => $map];
	tdefinition(1, 'restapi', $plain);
	tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('e', 64))['outcome'] === 'success', 'Explicit private HTTP');

	foreach (['/bad' => ['failure', 'http_400'], '/redirect' => ['failure', 'http_302'], '/large' => ['failure', 'http_response_limit'], '/slow' => ['retry', 'http_timeout']] as $path => $expected) {
		tdefinition(1, 'restapi', $plain + []);
		$settings        = $plain;
		$settings['url'] = $http . $path;
		tdefinition(1, 'restapi', $settings);
		$result = alarmmanager_http_transport($event, $endpoint, str_repeat('f', 64));
		tcheck([$result['outcome'], $result['code']] === $expected && !str_contains(json_encode($result), 'fake-secret'), 'HTTP failure classification/redaction');
	}
	tcheck((tstats($http)['counts']['/redirect-target'] ?? 0) === 0, 'Redirect did not forward');

	foreach (['/invalid' => 'http_response_invalid', '/mismatch' => 'http_response_mismatch'] as $path => $code) {
		$settings                   = $plain;
		$settings['url']            = $http . $path;
		$settings['response_match'] = ['path' => 'accepted', 'equals' => true];
		tdefinition(1, 'restapi', $settings);
		tcheck(alarmmanager_http_transport($event, $endpoint, str_repeat('0', 64))['code'] === $code, 'HTTP response criterion');
	}
	tcheck(alarmmanager_retry_after('9999999999') === 3600 && alarmmanager_retry_after('bad') === 0 && alarmmanager_retry_after(gmdate('D, d M Y H:i:s', 1005) . ' GMT', 1000) === 5, 'Retry-After date/clamp');
	tcheck(alarmmanager_http_result(429, '', alarmmanager_transport_configuration('webhook', $plain), '9')['retry_after'] === 9, '429 retry classification');
	// Snapshot/rotation/restore are atomic and preserve usability across authorized pollers.
	$backup = alarmmanager_credential_backup();
	$cipher = db_fetch_cell_prepared('SELECT ciphertext FROM plugin_alarmmanager_credentials');
	alarmmanager_rotate_key();
	tcheck(alarmmanager_secret_key() !== $key && alarmmanager_load_credential(1) === $credential, 'Rotation usability');
	$rotated                                                                                                        = alarmmanager_secret_key();
	$GLOBALS['alarmmanager_test_failure_plan']['UPDATE settings_user SET value = ? WHERE user_id = ? AND name = ?'] = [9999];
	treject(fn () => alarmmanager_rotate_key(), 'Rotation write failure');
	tcheck(alarmmanager_secret_key() === $rotated && alarmmanager_load_credential(1) === $credential, 'Rotation rollback preserved data');
	alarmmanager_credential_restore($backup['bundle'], $backup['key']);
	tcheck(alarmmanager_secret_key() === $key && db_fetch_cell_prepared('SELECT ciphertext FROM plugin_alarmmanager_credentials') === $cipher, 'Backup restore');
	alarmmanager_db_write('DELETE FROM settings_user WHERE name = ?', ['alarmmanager_key']);
	treject(fn () => alarmmanager_setup_key(), 'Missing key over records refuses setup');
	treject(fn () => alarmmanager_load_credential(1), 'Missing key fails closed');
	alarmmanager_credential_restore($backup['bundle'], $backup['key']);
	tcheck(alarmmanager_load_credential(1) === $credential, 'Lost key explicit restore');
	alarmmanager_db_write('UPDATE settings_user SET value = ?', [base64_encode(random_bytes(32))]);
	treject(fn () => alarmmanager_load_credential(1), 'Wrong stored key');
	alarmmanager_credential_restore($backup['bundle'], $backup['key']);
	$bad = $backup['bundle'];
	$bad['credentials'][0]['ciphertext'] .= ' ';
	treject(fn () => alarmmanager_credential_restore($bad, $backup['key']), 'Tampered bundle');
	tcheck(alarmmanager_load_credential(1) === $credential, 'Failed restore left data');
	$config['DEBUG_SQL_FLOW'] = true;
	treject(fn () => alarmmanager_load_credential(1), 'Secret tracing fails closed');
	$config['DEBUG_SQL_FLOW'] = false;
	// Provision a separate poller-local database and prove ciphertext alone is unusable.
	$primary_db      = $alarmmanager_test_db;
	$remote_database = 'alarmmanager_test_' . bin2hex(random_bytes(6));
	$primary_db->exec("CREATE DATABASE `$remote_database` CHARACTER SET utf8mb4");

	try {
		$alarmmanager_test_db = alarmmanager_test_connect();
		$alarmmanager_test_db->exec("USE `$remote_database`");
		$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id, name)) ENGINE=InnoDB');
		$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
		$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.5.0', 1)");
		$config['poller_id'] = 3;
		tcheck(plugin_alarmmanager_install(), 'Independent poller setup');
		tdefinition(1, 'restapi', $base);
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_credentials (endpoint_id, ciphertext, updated_at) VALUES (1, ?, UTC_TIMESTAMP(6))', [$cipher]);
		treject(fn () => alarmmanager_load_credential(1), 'Ciphertext-only provisioning rejected');
		alarmmanager_credential_restore($backup['bundle'], $backup['key']);
		tcheck(alarmmanager_load_credential(1) === $credential && alarmmanager_http_transport($event, $endpoint, str_repeat('5', 64))['outcome'] === 'success', 'Provisioned independent local credentials usable');
	} finally {
		$alarmmanager_test_db = $primary_db;
		$config['poller_id']  = 2;
		$primary_db->exec("DROP DATABASE `$remote_database`");
	}
	// Worker-only test sends and simultaneous destinations with one transient failure.
	$routes                                     = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true);
	$routes['endpoints'][0]['transport']        = $plain;
	$routes['endpoints'][0]['transport']['url'] = $http . '/retry';
	$routes['endpoints'][1]['transport']        = ['url' => $http . '/echo', 'allow_http' => true, 'mapping' => ['default' => [['to' => 'host', 'source' => 'device.id']]], 'response_match' => ['path' => 'request.host', 'equals' => 42]];
	alarmmanager_apply_configuration($routes);
	tcheck(!str_contains(db_fetch_cell_prepared('SELECT definition FROM plugin_alarmmanager_configuration_history ORDER BY revision DESC LIMIT 1'), 'fixture-token'), 'Configuration history excludes credentials');
	$response = alarmmanager_submit(['contract_version' => 1, 'mode' => 'centralized', 'status' => 'unhandled', 'event' => $event]);
	tcheck($response['status'] === 'accepted', 'Fan-out ingestion');
	$claim = alarmmanager_claim_processing();
	tcheck($claim && alarmmanager_process_job($claim), 'Fan-out processing');
	tcheck((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries') === 2, 'Two destinations');

	for ($i = 0; $i < 2; $i++) {
		$claim = alarmmanager_claim_delivery(['restapi', 'webhook']);
		tcheck($claim && alarmmanager_run_delivery($claim), 'Real adapter worker dispatch');
	}
	tcheck((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries WHERE status = 'completed'") === 1, 'Sibling succeeded independently');
	tcheck((int) db_fetch_cell_prepared("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) FROM plugin_alarmmanager_deliveries WHERE status = 'pending'") >= 1, 'Retry-After scheduled');
	$stats      = tstats($http);
	$echo_count = $stats['counts']['/echo'];
	alarmmanager_db_write("UPDATE plugin_alarmmanager_deliveries SET available_at = UTC_TIMESTAMP(6) WHERE status = 'pending'");
	$claim = alarmmanager_claim_delivery(['restapi', 'webhook']);
	tcheck($claim && alarmmanager_run_delivery($claim), 'Retry completed');
	$stats = tstats($http);
	tcheck($stats['counts']['/echo'] === $echo_count && count($stats['keys']['/retry']) === 2 && $stats['keys']['/retry'][0] === $stats['keys']['/retry'][1], 'No sibling resend/stable idempotency');
	$before  = $stats['counts']['/echo'];
	$test_id = alarmmanager_enqueue_test($event, 2);
	tcheck(tstats($http)['counts']['/echo'] === $before, 'Test action only queued');
	$claim = alarmmanager_claim_delivery(['webhook']);
	tcheck($claim && $claim['event_row_id'] === $test_id && alarmmanager_run_delivery($claim), 'Explicit test dispatched by worker');
	// Expired claims cannot start an external effect.
	$stale_id    = alarmmanager_enqueue_test($event, 2);
	$stale_claim = alarmmanager_claim_delivery(['webhook']);
	$echo_count  = tstats($http)['counts']['/echo'];
	alarmmanager_db_write('UPDATE plugin_alarmmanager_deliveries SET lease_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?', [$stale_claim['id']]);
	tcheck(!alarmmanager_run_delivery($stale_claim) && tstats($http)['counts']['/echo'] === $echo_count, 'Expired worker did not send traffic');
	// Credential corruption fails closed before traffic and is sanitized in attempt records.
	tdefinition(1, 'restapi', $base);
	$bad_credential_id = alarmmanager_enqueue_test($event, 1);
	$claim             = alarmmanager_claim_delivery(['restapi']);
	$echo_count        = tstats($http)['counts']['/echo'];
	alarmmanager_db_write('UPDATE plugin_alarmmanager_credentials SET ciphertext = ?', ['am1:invalid']);
	tcheck(alarmmanager_run_delivery($claim) && db_fetch_cell_prepared('SELECT last_error FROM plugin_alarmmanager_deliveries WHERE event_row_id = ?', [$bad_credential_id]) === 'credential_unavailable' && tstats($http)['counts']['/echo'] === $echo_count, 'Bad credential worker failure redacted/no traffic');
	alarmmanager_credential_restore($backup['bundle'], $backup['key']);
	// Controlled script argv/stdin/env, failures and bounds.
	$script = ['executable' => PHP_BINARY, 'arguments' => ['-n', __DIR__ . '/../fixtures/transport_script.php', 'ok', $tmp . '/script.json', 'a;$(touch never)'], 'mapping' => $map];
	tdefinition(3, 'script', $script);
	$ep3 = ['id' => 3, 'type' => 'script', 'timeout_seconds' => 1];
	putenv('ALARMMANAGER_FIXTURE_PRIVATE=private');
	tcheck(alarmmanager_script_transport($event, $ep3, str_repeat('1', 64))['outcome'] === 'success', 'Structured script');
	$observed = json_decode(file_get_contents($tmp . '/script.json'), true);
	tcheck($observed['payload']['hostname'] === 42 && $observed['arguments'] === ['a;$(touch never)'] && $observed['environment_filtered'] && $observed['idempotency'] === str_repeat('1', 64), 'Script escaping/input/env');
	$script['compatibility'] = 'syslog_ticket';
	tdefinition(3, 'script', $script);
	alarmmanager_script_transport($event, $ep3, str_repeat('2', 64));
	$observed = json_decode(file_get_contents($tmp . '/script.json'), true);
	tcheck(in_array('--severity=major', $observed['arguments'], true) && in_array('--hostlist=router.example.test', $observed['arguments'], true), 'Syslog ticket named argv');
	unset($script['compatibility']);

	foreach (['retry' => ['retry', 'script_exit_failed'], 'failure' => ['failure', 'script_exit_failed'], 'timeout' => ['retry', 'script_timeout'], 'large' => ['failure', 'script_output_limit']] as $mode => $expected) {
		$script['arguments'] = ['-n', __DIR__ . '/../fixtures/transport_script.php', $mode];
		tdefinition(3, 'script', $script);
		$result = alarmmanager_script_transport($event, $ep3, str_repeat('3', 64));
		tcheck([$result['outcome'], $result['code']] === $expected, 'Script exit/runtime/output classification');
	}
	$script['arguments'] = ['-n', __DIR__ . '/../fixtures/transport_script.php', 'descendants', $tmp . '/child.pid'];
	tdefinition(3, 'script', $script);
	$result = alarmmanager_script_transport($event, $ep3, str_repeat('9', 64));
	$child  = (int) file_get_contents($tmp . '/child.pid');
	$state  = @file_get_contents('/proc/' . $child . '/stat');
	tcheck($result['code'] === 'script_timeout' && ($state === false || preg_match('/\) Z /', $state)), 'Timeout killed ordinary script descendants');
	treject(fn () => alarmmanager_transport_configuration('script', ['executable' => 'php']), 'Relative script rejected');
	// Cacti boundary preserves supported formatting/recipient/attachment signatures.
	$message = ['to' => [['email' => 'one@example.test', 'name' => 'One'], ['email' => 'two@example.test', 'name' => 'Two']], 'from' => 'Alarm <alarm@example.test>', 'subject' => 'Alarm subject', 'body' => '<b>body</b>', 'attachments' => [['attachment' => 'fixture content', 'filename' => 'test.txt', 'mime_type' => 'text/plain', 'inline' => 'attachment']], 'html' => true, 'idempotency' => str_repeat('4', 64)];
	tcheck(alarmmanager_email_send($message)['outcome'] === 'success', 'Cacti mailer success boundary');
	tcheck($GLOBALS['mail_fixture_args'] === [$message['to'], $message['from'], $message['subject'], $message['body'], $message['attachments'], ['X-AlarmManager-Idempotency-Key' => $message['idempotency']], true], 'Cacti email arguments preserved');
	$GLOBALS['mail_fixture_error'] = 'fake-secret';
	tcheck(alarmmanager_email_send($message) === ['outcome' => 'retry', 'code' => 'email_failed'], 'Mailer error redaction');
	treject(fn () => alarmmanager_transport_configuration('email', ['to' => "x\r\nBcc: y"]), 'Email header injection');
	tcheck(alarmmanager_delivery_handlers()['restapi'] === 'alarmmanager_http_transport' && alarmmanager_delivery_handlers()['webhook'] === 'alarmmanager_http_transport', 'Production handlers registered');
	print "PASS: $checks real database/HTTP/TLS/script and email-boundary checks.\n";
} finally {
	if (is_resource($server)) {
		proc_terminate($server, SIGKILL);

		foreach ($pipes as $pipe) {
			fclose($pipe);
		} proc_close($server);
	}

	if ($alarmmanager_test_db->inTransaction()) {
		$alarmmanager_test_db->rollBack();
	}
	$alarmmanager_test_db->exec("DROP DATABASE `$database`");

	foreach (glob($tmp . '/*') as $file) {
		unlink($file);
	} rmdir($tmp);
}
