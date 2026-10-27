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

final class SnmpSyslogTest extends AlarmManagerTestCase {
	/** @return array<string, mixed> */
	private function sampleEvent(array $changes = []): array {
		return array_replace_recursive(json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR), $changes);
	}

	private function reject(callable $call, string $code): void {
		try {
			$call();
			$this->fail('Expected ' . $code);
		} catch (InvalidArgumentException $error) {
			$this->assertSame($code, $error->getMessage());
		}
	}

	/** @return array<string, mixed> */
	private function template(string $key, array $changes = []): array {
		return array_replace(alarmmanager_integration_templates()[$key]['transport'], $changes);
	}

	/** Serve one stored transport definition (and optional ciphertext) to the endpoint loader. */
	private function storeEndpoint(string $type, array $transport, ?string $cipher = null, ?string $key = null): void {
		$definition                                 = json_encode(alarmmanager_transport_configuration($type, $transport));
		$GLOBALS['alarmmanager_test_cell_handler']  = static fn ($sql) => str_contains($sql, 'settings_user') ? base64_encode($key ?? random_bytes(32)) : (str_contains($sql, '@@in_transaction') ? 0 : 2);
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) use ($definition, $cipher) {
			if (str_contains($sql, 'SELECT definition FROM plugin_alarmmanager_transports')) {
				return [['definition' => $definition]];
			}

			if (str_contains($sql, 'SELECT ciphertext') && $cipher !== null) {
				return [['ciphertext' => $cipher]];
			}

			return [];
		};
	}

	public function testSettingsValidateStrictly(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$trap = alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap'));
		$this->assertSame(['credential' => true, 'version' => '2c', 'host' => 'trap-receiver.example.invalid', 'port' => 162, 'transport' => 'udp', 'notification' => 'trap', 'base_oid' => '1.3.6.1.4.1.23925.6'], array_diff_key($trap, ['mapping' => true]));
		$this->assertSame($trap, alarmmanager_transport_configuration('snmptrap', json_decode(json_encode($trap), true)));
		$v3 = alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', ['version' => '3', 'host' => '2001:db8::1', 'security_name' => 'nms', 'engine_id' => '0x80001f8880aabbccdd']));
		$this->assertSame(['authPriv', 'SHA', 'AES', '0x80001f8880aabbccdd'], [$v3['security_level'], $v3['auth_protocol'], $v3['priv_protocol'], $v3['engine_id']]);
		$syslog = alarmmanager_transport_configuration('syslog', $this->template('syslog', ['protocol' => 'tls', 'ca_bundle' => '/etc/ssl/ca.pem']));
		$this->assertSame([6514, 'rfc5424', 'local0', 'cacti-alarmmanager'], [$syslog['port'], $syslog['format'], $syslog['facility'], $syslog['app_name']]);
		$this->assertSame(514, alarmmanager_transport_configuration('syslog', $this->template('syslog'))['port']);

		foreach ([['host' => 'https://receiver'], ['host' => 'bad host'], ['host' => null], ['host' => '-bad.example']] as $bad) {
			$this->reject(fn () => alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', $bad)), $bad['host'] === null ? 'Invalid event text.' : 'invalid_transport_host');
		}

		foreach ([['version' => '1'], ['transport' => 'sctp'], ['notification' => 'ack'], ['base_oid' => '1.3.6.x'], ['base_oid' => 7], ['version' => '3'], ['version' => '3', 'security_name' => 'has space'], ['version' => '3', 'security_name' => 'nms', 'engine_id' => '80001f'], ['version' => '3', 'security_name' => 'nms', 'auth_protocol' => 'MD5']] as $bad) {
			$this->reject(fn () => alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', $bad)), 'invalid_snmp_settings');
		}
		$this->reject(fn () => alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', ['port' => 70000])), 'invalid_configuration');
		$this->reject(fn () => alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', ['mapping' => alarmmanager_integration_templates()['webhook']['transport']['mapping']])), 'invalid_snmp_mapping');
		$this->reject(fn () => alarmmanager_transport_configuration('syslog', $this->template('syslog', ['mapping' => ['default' => [['to' => 'text', 'source' => 'alarm.summary']]]])), 'invalid_syslog_mapping');

		foreach ([['protocol' => 'relp'], ['format' => 'cef'], ['facility' => 'kern'], ['app_name' => 'has space'], ['app_name' => str_repeat('a', 49)]] as $bad) {
			$this->reject(fn () => alarmmanager_transport_configuration('syslog', $this->template('syslog', $bad)), 'invalid_syslog_settings');
		}

		foreach (['relative.pem', '/etc/ssl/ca.pem'] as $ca) {
			$this->reject(fn () => alarmmanager_transport_configuration('syslog', $this->template('syslog', ['ca_bundle' => $ca])), 'invalid_ca_bundle');
		}
		$this->reject(fn () => alarmmanager_transport_configuration('syslog', $this->template('syslog', ['credential' => true])), 'Unknown event fields.');
	}

	public function testSnmpSecretsUseTheEncryptedCredentialRecord(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$record = ['snmp' => ['community' => 'c0mmunity!', 'auth_passphrase' => 'authpass1', 'priv_passphrase' => 'privpass1']];
		$this->assertSame($record['snmp'], alarmmanager_credential_validate($record)['snmp']);
		$key = random_bytes(32);
		$this->assertSame($record['snmp'], alarmmanager_decrypt_credential(alarmmanager_encrypt_credential($record, 3, $key), 3, $key)['snmp']);
		$this->assertContains('snmptrap', alarmmanager_credential_types());
		$this->assertNotContains('syslog', alarmmanager_credential_types());

		foreach ([[], ['community' => 'with space'], ['community' => 'quo"te'], ['community' => 'hash#'], ['community' => 'back\\slash'], ['auth_passphrase' => 'short'], ['other' => 'value'], ['community' => 7]] as $bad) {
			$this->expectExceptionSafe(fn () => alarmmanager_credential_validate(['snmp' => $bad]));
		}
		$this->reject(fn () => alarmmanager_credential_validate(['snmp' => ['community' => 'public'], 'basic' => ['username' => 'a', 'password' => 'b']]), 'invalid_snmp_credentials');
		$this->assertNull(alarmmanager_admin_snmp_credential(['snmp_community' => '', 'snmp_auth_passphrase' => '']));
		$this->assertSame(['community' => 'public'], alarmmanager_admin_snmp_credential(['snmp_community' => 'public'])['snmp']);
		$this->reject(fn () => alarmmanager_admin_snmp_credential(['snmp_community' => ['public']]), 'invalid_snmp_credentials');
	}

	private function expectExceptionSafe(callable $call): void {
		try {
			$call();
			$this->fail('Expected invalid SNMP credentials');
		} catch (InvalidArgumentException $error) {
			$this->assertContains($error->getMessage(), ['invalid_snmp_credentials', 'Unknown event fields.']);
		}
	}

	public function testTrapCommandKeepsSecretsOutOfArgv(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$settings      = alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap'));
		$event         = alarmmanager_validate_event($this->sampleEvent(['details' => str_repeat('d', 2000), 'summary' => '-v looks like an option']));
		$payload       = alarmmanager_notification_payload($settings, $event);
		[$argv, $conf] = alarmmanager_snmptrap_command('/usr/bin/snmptrap', $settings, ['snmp' => ['community' => 'topsecret']], $payload, 'raised', 4);
		$this->assertSame("defCommunity topsecret\n", $conf);
		$this->assertNotContains('topsecret', $argv);
		$this->assertSame(['/usr/bin/snmptrap', '-v', '2c', '-m', '', '-M', '/nonexistent', '--', 'udp:trap-receiver.example.invalid:162', '', '1.3.6.1.4.1.23925.6.0.1'], array_slice($argv, 0, 11));
		$varbinds = array_chunk(array_slice($argv, 11), 3);
		$byOid    = array_column($varbinds, null, 0);
		$this->assertSame(['i', '2'], array_slice($byOid['1.3.6.1.4.1.23925.6.1.5.0'], 1), 'major severity');
		$this->assertSame(['u', '42'], array_slice($byOid['1.3.6.1.4.1.23925.6.1.9.0'], 1));
		$this->assertSame('-v looks like an option', $byOid['1.3.6.1.4.1.23925.6.1.10.0'][2]);
		$this->assertSame(1024, strlen($byOid['1.3.6.1.4.1.23925.6.1.11.0'][2]));
		$this->assertArrayNotHasKey('1.3.6.1.4.1.23925.6.1.13.0', $byOid, 'missing rule name is omitted');

		$v3            = alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', ['version' => '3', 'host' => '2001:db8::1', 'notification' => 'inform', 'security_name' => 'nms', 'auth_protocol' => 'SHA-256', 'priv_protocol' => 'AES-256', 'engine_id' => '0x80001f8880aabbccdd']));
		[$argv, $conf] = alarmmanager_snmptrap_command('/usr/bin/snmptrap', $v3, ['snmp' => ['auth_passphrase' => 'authsecret', 'priv_passphrase' => 'privsecret']], $payload, 'recovered', 3);
		$this->assertSame("defAuthPassphrase authsecret\ndefPrivPassphrase privsecret\n", $conf);
		$this->assertSame([], array_values(array_filter($argv, static fn ($arg) => str_contains($arg, 'secret'))));
		$this->assertSame(['-u', 'nms', '-l', 'authPriv', '-a', 'SHA-256', '-x', 'AES-256', '-Ci', '-r', '0', '-t', '3', '--', 'udp6:[2001:db8::1]:162', '', '1.3.6.1.4.1.23925.6.0.2'], array_slice($argv, 7, 17), 'INFORM skips the trap engine ID');
		[$argv, $conf] = alarmmanager_snmptrap_command('/usr/bin/snmptrap', ['notification' => 'trap', 'security_level' => 'noAuthNoPriv'] + $v3, [], new stdClass(), 'raised', 3);
		$this->assertSame('', $conf);
		$this->assertSame(['-u', 'nms', '-l', 'noAuthNoPriv', '-e', '0x80001f8880aabbccdd', '--'], array_slice($argv, 7, 7));

		$this->reject(fn () => alarmmanager_snmptrap_command('/usr/bin/snmptrap', $settings, [], $payload, 'raised', 3), 'snmp_credentials_missing');
		$this->reject(fn () => alarmmanager_snmptrap_command('/usr/bin/snmptrap', ['security_level' => 'authNoPriv'] + $v3, ['snmp' => ['priv_passphrase' => 'privsecret']], $payload, 'raised', 3), 'snmp_credentials_missing');
		$this->reject(fn () => alarmmanager_snmptrap_command('/usr/bin/snmptrap', $v3, ['snmp' => ['auth_passphrase' => 'authsecret']], $payload, 'raised', 3), 'snmp_credentials_missing');
		$this->reject(fn () => alarmmanager_snmptrap_command('/usr/bin/snmptrap', $settings, ['snmp' => ['community' => 'c']], (object) ['url' => 'x'], 'raised', 3), 'invalid_snmp_mapping');
		$this->reject(fn () => alarmmanager_snmptrap_command('/usr/bin/snmptrap', $settings, ['snmp' => ['community' => 'c']], (object) ['amSummary' => ['list']], 'raised', 3), 'invalid_snmp_mapping');
		$this->reject(fn () => alarmmanager_snmptrap_command('/usr/bin/snmptrap', $settings, ['snmp' => ['community' => 'c']], (object) ['amDeviceId' => -1], 'raised', 3), 'mapping_type');
		[$argv] = alarmmanager_snmptrap_command('/usr/bin/snmptrap', $settings, ['snmp' => ['community' => 'c']], (object) ['amMetric' => true], 'raised', 3);
		$this->assertSame('true', end($argv));
	}

	public function testTrapTransportSendsThroughNetSnmpAndCleansUp(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
		$port   = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
		$key    = random_bytes(32);
		$this->storeEndpoint('snmptrap', $this->template('snmptrap', ['host' => '127.0.0.1', 'port' => $port]), alarmmanager_encrypt_credential(['snmp' => ['community' => 'trap-community']], 5, $key), $key);
		$endpoint = ['id' => 5, 'type' => 'snmptrap', 'timeout_seconds' => 5];
		$event    = alarmmanager_validate_event($this->sampleEvent());

		$GLOBALS['alarmmanager_test_config_options']['path_snmptrap'] = 'snmptrap';
		$this->assertSame(['outcome' => 'failure', 'code' => 'snmptrap_unavailable'], alarmmanager_snmptrap_transport($event, $endpoint, 'key'));

		if (!is_executable('/usr/bin/snmptrap') || !function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
			$this->markTestSkipped('net-snmp snmptrap is not installed.');
		}
		$GLOBALS['alarmmanager_test_config_options']['path_snmptrap']  = '/usr/bin/snmptrap';
		$before                                                        = glob(sys_get_temp_dir() . '/alarmmanager-snmp-*') ?: [];
		$this->assertSame(['outcome' => 'success', 'code' => 'snmp_trap_sent'], alarmmanager_snmptrap_transport($event, $endpoint, 'key'));
		$this->assertSame($before, glob(sys_get_temp_dir() . '/alarmmanager-snmp-*') ?: [], 'private snmp.conf directory removed');
		stream_set_timeout($server, 2);
		$packet = stream_socket_recvfrom($server, 65535);
		fclose($server);
		$this->assertStringContainsString('trap-community', $packet, 'community comes from the private config file');
		$this->assertStringContainsString('Sample device down', $packet);
		$this->assertStringContainsString('router.example.test', $packet);

		$this->storeEndpoint('snmptrap', $this->template('snmptrap', ['host' => '127.0.0.1', 'port' => $port]), null);
		$this->reject(fn () => alarmmanager_snmptrap_transport($event, $endpoint, 'key'), 'snmp_credentials_missing');
	}

	public function testTrapTransportReportsProcessOutcomes(): void {
		self::alarmmanager_test_load('includes/transports.php');

		if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
			$this->markTestSkipped('posix and setsid are required for the process runner.');
		}
		$tmp = sys_get_temp_dir() . '/alarmmanager-fake-snmptrap-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		$event    = alarmmanager_validate_event($this->sampleEvent());
		$endpoint = ['id' => 5, 'type' => 'snmptrap', 'timeout_seconds' => 2];
		$key      = random_bytes(32);

		try {
			foreach (['fail' => "#!/bin/sh\nexit 3\n", 'slow' => "#!/bin/sh\nexec sleep 5\n"] as $name => $script) {
				file_put_contents($tmp . '/' . $name, $script);
				chmod($tmp . '/' . $name, 0700);
			}
			$this->storeEndpoint('snmptrap', $this->template('snmptrap', ['notification' => 'inform']), alarmmanager_encrypt_credential(['snmp' => ['community' => 'public']], 5, $key), $key);
			$GLOBALS['alarmmanager_test_config_options']['path_snmptrap'] = $tmp . '/fail';
			$this->assertSame(['outcome' => 'retry', 'code' => 'snmptrap_failed'], alarmmanager_snmptrap_transport($event, $endpoint, 'key'));
			$GLOBALS['alarmmanager_test_config_options']['path_snmptrap'] = $tmp . '/slow';
			$this->assertSame(['outcome' => 'retry', 'code' => 'snmptrap_timeout'], alarmmanager_snmptrap_transport($event, $endpoint, 'key'), 'bounded below the worker deadline');

			// A damaged credential key is not the same as an endpoint without secrets.
			$this->storeEndpoint('snmptrap', $this->template('snmptrap'), alarmmanager_encrypt_credential(['snmp' => ['community' => 'public']], 5, $key), random_bytes(32));
			$this->expectException(RuntimeException::class);
			alarmmanager_snmptrap_transport($event, $endpoint, 'key');
		} finally {
			array_map('unlink', glob($tmp . '/*') ?: []);
			rmdir($tmp);
		}
	}

	public function testSnmpCredentialsRestoreProvisionAndReplicate(): void {
		self::alarmmanager_test_load('includes/replication.php');
		self::alarmmanager_test_load('includes/remote.php');
		$GLOBALS['config']['poller_id']               = 1;
		$key                                          = random_bytes(32);
		$record                                       = ['snmp' => ['community' => 'replicated']];
		$cipher                                       = alarmmanager_encrypt_credential($record, 5, $key);
		$configuration                                = ['version' => 1, 'endpoints' => [['id' => 5, 'name' => 'NMS', 'type' => 'snmptrap', 'transport' => $this->template('snmptrap')], ['id' => 6, 'name' => 'Log', 'type' => 'syslog', 'transport' => $this->template('syslog')]], 'rules' => []];
		$checked                                      = [];
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn ($sql) => str_contains($sql, 'settings_user') ? base64_encode($key) : (str_contains($sql, '@@in_transaction') ? 0 : 2);
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$writes) {
			$writes[] = [$sql, $params];

			return true;
		};
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql, $params) use (&$checked, $cipher, $configuration) {
			if (str_contains($sql, 'SELECT id FROM plugin_alarmmanager_endpoints')) {
				$checked[] = $params;

				return [['id' => 5]];
			}

			if (str_contains($sql, 'SELECT endpoint_id, ciphertext')) {
				return [['endpoint_id' => 5, 'ciphertext' => $cipher]];
			}

			if (str_contains($sql, 'configuration_history')) {
				return [['definition' => json_encode($configuration)]];
			}

			if (str_contains($sql, 'config_revision')) {
				return [['config_revision' => 1]];
			}

			return [];
		};

		alarmmanager_credential_restore(['version' => 1, 'credentials' => [['endpoint_id' => 5, 'ciphertext' => $cipher]]], base64_encode($key));
		$this->assertSame([5, 'restapi', 'webhook', 'snmptrap'], $checked[0], 'restore accepts SNMP trap credentials');
		$this->assertSame($record['snmp'], alarmmanager_replication_configuration()['credentials'][5]['snmp'], 'collectors receive SNMP secrets');

		$bundle = ['version' => 2, 'poller_id' => 1, 'configuration' => $configuration, 'producers' => [], 'grants' => [], 'credentials' => [['endpoint_id' => 5, 'record' => $record]]];
		alarmmanager_remote_provision($bundle);
		$this->assertNotEmpty(array_filter($writes, static fn ($write) => str_starts_with($write[0], 'INSERT INTO plugin_alarmmanager_credentials') && $write[1][0] === 5));
		$bundle['credentials'][0]['endpoint_id'] = 6;
		$this->reject(fn () => alarmmanager_remote_provision($bundle), 'provision_credential_invalid');
	}

	public function testSyslogMessagesCarryCorrelationInStructuredData(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$settings = alarmmanager_transport_configuration('syslog', $this->template('syslog'));
		$event    = alarmmanager_validate_event($this->sampleEvent(['severity' => 'critical', 'device' => ['hostname' => 'r"1]\\x']]));
		$this->assertSame('<130>1 2026-10-13T03:00:00.000000Z cacti.example cacti-alarmmanager - raised [alarm@23925 key="device:42:reachability" episode="sample-outage-0001" event="sample-event-0001" severity="critical" transition="raised" source="sample" device="r\\"1\\]\\\\x"] Down | Host: r1',
			alarmmanager_syslog_message($settings, $event, "Down\r\n  Host: r1\x07", 'cacti.example'));
		$this->assertStringStartsWith('<133>1 ', alarmmanager_syslog_message($settings, alarmmanager_validate_event($this->sampleEvent(['severity' => 'clear', 'transition' => 'recovered'])), 'ok', 'host'));
		$this->assertStringContainsString(' - - raised ', alarmmanager_syslog_message(['app_name' => '-'] + $settings, $event, 'x', " \t"), 'blank hostname becomes NILVALUE');
		$legacy = alarmmanager_transport_configuration('syslog', $this->template('syslog', ['format' => 'rfc3164', 'facility' => 'user', 'app_name' => 'cacti/alarm[1]']));
		$this->assertSame('<10>Oct 13 03:00:00 cacti cactialarm1: Down', alarmmanager_syslog_message($legacy, $event, 'Down', 'cacti'));
		$this->assertStringContainsString(' alarmmanager: ', alarmmanager_syslog_message(['app_name' => '[]'] + $legacy, alarmmanager_validate_event($this->sampleEvent(['occurred_at' => '2026-10-03T03:00:00Z'])), 'x', 'cacti'));
		$this->assertStringContainsString('Oct  3 ', alarmmanager_syslog_message($legacy, alarmmanager_validate_event($this->sampleEvent(['occurred_at' => '2026-10-03T03:00:00Z'])), 'x', 'cacti'));
	}

	public function testSyslogSendsOverUdpAndFramedTcp(): void {
		self::alarmmanager_test_load('includes/transports.php');
		$udp  = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
		$port = (int) substr(strrchr(stream_socket_get_name($udp, false), ':'), 1);
		$this->storeEndpoint('syslog', $this->template('syslog', ['host' => '127.0.0.1', 'port' => $port, 'mapping' => ['default' => [['to' => 'message', 'source' => 'alarm.details', 'type' => 'string']]]]));
		$event = alarmmanager_validate_event($this->sampleEvent(['details' => str_repeat('é', 1500)]));
		$this->assertSame(['outcome' => 'success', 'code' => 'syslog_sent'], alarmmanager_syslog_transport($event, ['id' => 1, 'type' => 'syslog', 'timeout_seconds' => 3], 'key'));
		$datagram = stream_socket_recvfrom($udp, 65535);
		fclose($udp);
		$this->assertLessThanOrEqual(2048, strlen($datagram));
		$this->assertTrue(mb_check_encoding($datagram, 'UTF-8'), 'truncation keeps whole characters');
		$this->assertStringStartsWith('<131>1 ', $datagram);

		$tcp  = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int) substr(strrchr(stream_socket_get_name($tcp, false), ':'), 1);
		$this->assertSame(['outcome' => 'success', 'code' => 'syslog_sent'], alarmmanager_syslog_send(['protocol' => 'tcp', 'host' => '127.0.0.1', 'port' => $port, 'ca_bundle' => null], 'hello world', 2));
		$client = stream_socket_accept($tcp, 2);
		$this->assertSame('11 hello world', stream_get_contents($client));
		fclose($client);
		fclose($tcp);
		$this->assertSame(['outcome' => 'retry', 'code' => 'syslog_connect_failed'], alarmmanager_syslog_send(['protocol' => 'tcp', 'host' => '127.0.0.1', 'port' => $port, 'ca_bundle' => null], 'closed', 1));

		$this->storeEndpoint('syslog', $this->template('syslog', ['host' => '127.0.0.1', 'mapping' => ['default' => [['to' => 'message', 'source' => 'context.rule_name']]]]));
		$this->reject(fn () => alarmmanager_syslog_transport($event, ['id' => 1, 'type' => 'syslog', 'timeout_seconds' => 3], 'key'), 'syslog_message_required');
	}

	public function testSyslogTlsVerifiesTheReceiverCertificate(): void {
		self::alarmmanager_test_load('includes/transports.php');

		foreach (['openssl', 'python3'] as $tool) {
			if (trim((string) shell_exec('command -v ' . $tool)) === '') {
				$this->markTestSkipped("$tool is required for the TLS syslog fixture.");
			}
		}
		$tmp = sys_get_temp_dir() . '/alarmmanager-syslog-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		$server = null;

		try {
			$openssl = proc_open(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $tmp . '/key.pem', '-out', $tmp . '/cert.pem', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $unused);
			$this->assertTrue(is_resource($openssl) && proc_close($openssl) === 0, 'TLS fixture certificate');
			$server = proc_open(['python3', '-I', __DIR__ . '/../../fixtures/syslog_server.py', $tmp . '/cert.pem', $tmp . '/key.pem'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
			stream_set_timeout($pipes[1], 10);
			$settings = ['protocol' => 'tls', 'host' => 'localhost', 'port' => (int) fgets($pipes[1]), 'ca_bundle' => $tmp . '/cert.pem'];
			$this->assertSame(['outcome' => 'success', 'code' => 'syslog_sent'], alarmmanager_syslog_send($settings, '<131>1 - - - - - - tls', 5));
			$this->assertSame('22 <131>1 - - - - - - tls', base64_decode(trim(fgets($pipes[1])), true), 'octet-counted frame over TLS');
			$this->assertSame(['outcome' => 'retry', 'code' => 'syslog_connect_failed'], alarmmanager_syslog_send(['ca_bundle' => null] + $settings, 'untrusted', 5), 'self-signed certificate rejected without its CA');
			$this->assertSame('', base64_decode(trim(fgets($pipes[1])), true));
			$this->assertSame(['outcome' => 'retry', 'code' => 'syslog_connect_failed'], alarmmanager_syslog_send(['host' => '127.0.0.1'] + $settings, 'wrong name', 5), 'certificate name must match the receiver');
		} finally {
			if (is_resource($server)) {
				proc_terminate($server);
				proc_close($server);
			}

			foreach (glob($tmp . '/*') ?: [] as $file) {
				unlink($file);
			}
			rmdir($tmp);
		}
	}

	public function testAdminSavesAndRendersTrapAndSyslogDestinations(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'fixture';
			}
		}
		$trap = alarmmanager_admin_transport_fields('snmptrap', ['base_oid' => '1.3.6.1.4.1.9', 'url' => 'https://dropped'], ['endpoint_fields' => 'yes', 'snmp_host' => 'nms.example', 'snmp_port' => '1162', 'snmp_version' => '3', 'snmp_transport' => 'tcp', 'snmp_notification' => 'inform', 'snmp_security_name' => 'nms', 'snmp_security_level' => 'authNoPriv', 'snmp_auth_protocol' => 'SHA-512', 'snmp_priv_protocol' => 'AES', 'snmp_engine_id' => '', 'snmp_community' => 'ignored']);
		$this->assertSame(['base_oid' => '1.3.6.1.4.1.9', 'host' => 'nms.example', 'port' => 1162, 'version' => '3', 'transport' => 'tcp', 'notification' => 'inform', 'security_name' => 'nms', 'security_level' => 'authNoPriv', 'auth_protocol' => 'SHA-512', 'priv_protocol' => 'AES'], $trap);
		$this->assertArrayNotHasKey('security_name', alarmmanager_admin_transport_fields('snmptrap', [], ['endpoint_fields' => 'yes', 'snmp_version' => '2c', 'snmp_security_name' => 'x', 'snmp_engine_id' => '0x80001f8880aa']));
		$this->assertSame('0x80001f8880aa', alarmmanager_admin_transport_fields('snmptrap', [], ['endpoint_fields' => 'yes', 'snmp_version' => '3', 'snmp_engine_id' => '0x80001f8880aa'])['engine_id']);
		$syslog = alarmmanager_admin_transport_fields('syslog', ['host' => 'dropped'], ['endpoint_fields' => 'yes', 'syslog_host' => 'log.example', 'syslog_port' => '6514', 'syslog_protocol' => 'tls', 'syslog_format' => 'rfc5424', 'syslog_facility' => 'local3', 'syslog_app_name' => 'cacti', 'syslog_ca_bundle' => '/etc/ca.pem']);
		$this->assertSame(['host' => 'log.example', 'protocol' => 'tls', 'format' => 'rfc5424', 'facility' => 'local3', 'app_name' => 'cacti', 'port' => 6514, 'ca_bundle' => '/etc/ca.pem'], $syslog);
		$this->assertArrayNotHasKey('ca_bundle', alarmmanager_admin_transport_fields('syslog', [], ['endpoint_fields' => 'yes', 'syslog_ca_bundle' => '']));

		ob_start();

		try {
			alarmmanager_endpoint_editor(['id' => 4, 'name' => 'NMS', 'type' => 'snmptrap', 'transport' => alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap', ['version' => '3', 'security_name' => 'nms', 'priv_protocol' => 'AES-256']))], 1);
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertStringContainsString('<option value="snmptrap" selected>', $html);
		$this->assertStringContainsString('<option value="3" selected>', $html);
		$this->assertStringContainsString('<option value="AES-256" selected>', $html);
		$this->assertStringContainsString('name="snmp_community" type="password" value=""', $html);
		$this->assertStringContainsString('data-endpoint-types="syslog"', $html);
		$this->assertStringContainsString('<option value="local0" selected>', $html);

		foreach (['invalid_transport_host', 'invalid_snmp_settings', 'invalid_snmp_credentials', 'invalid_snmp_mapping', 'invalid_syslog_settings', 'invalid_syslog_mapping', 'invalid_ca_bundle'] as $code) {
			$this->assertStringNotContainsString('could not be completed', alarmmanager_admin_error_message(new InvalidArgumentException($code)));
		}
	}

	public function testAdminPagesUseGalleryDividerAndPrimaryActions(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'fixture';
			}
		}

		if (!function_exists('get_filter_request_var')) {
			function get_filter_request_var($name, $filter, $options) {
				return $options['options']($_REQUEST[$name] ?? '') ?: ($options['default'] ?? '');
			}
		}
		$before   = $_REQUEST;
		$_REQUEST = [];
		ob_start();

		try {
			alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [], 'rules' => []]], 'endpoints');
			$html = ob_get_contents();
			ob_clean();
			alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [], 'rules' => []]], 'rules');
			$rules = ob_get_contents();
		} finally {
			ob_end_clean();
			$_REQUEST = $before;
		}
		$this->assertStringContainsString('<details class="alarmmanagerTemplateGallery" open>', $html, 'gallery opens when nothing is configured');
		$this->assertStringNotContainsString('name="integration_template"', $html, 'no dropdown remains');
		$this->assertStringContainsString('href="alarmmanager_admin.php?section=endpoints&amp;add=1&amp;integration_template=syslog"', $html);
		$this->assertStringContainsString('Configured integrations <span class="alarmmanagerBadge">0</span>', $html);
		$this->assertStringContainsString('No integrations yet.', $html);
		$this->assertLessThan(strpos($html, 'alarmmanagerSectionDivider'), strpos($html, 'alarmmanagerTemplateGallery'), 'gallery sits above the divider');
		$this->assertStringContainsString('<a class="alarmmanagerPrimaryAction" data-icon="add" href="#alarmmanager_new_rule">Create a rule</a>', $rules, 'Create a rule is a primary action button');
		$this->assertStringNotContainsString('Local configuration revision', $rules, 'Rules print only their own heading');
		$this->assertSame(1, substr_count($rules, '<h2>'));
	}

	public function testQueueControlsAreNotSplitByTableMarkup(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'fixture';
			}
		}

		$GLOBALS['alarmmanager_test_boxes']         = [];
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => str_contains($sql, 'alarmmanager_notifications_paused') ? [['value' => '1']] : (str_contains($sql, 'COUNT(*)') ? [['waiting' => 3]] : []);
		ob_start();

		try {
			alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [], 'rules' => []]], 'queue');
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$boxes = $GLOBALS['alarmmanager_test_boxes'];
		$this->assertSame(['html_start_box', true], [$boxes[0][0], $boxes[0][1][2]], 'queue controls open a div box, not a table');
		$this->assertSame(['html_end_box', [true, true]], $boxes[1], 'and close it as a div box');
		$this->assertMatchesRegularExpression('~value="queue_resume">(?:<input type="hidden"[^>]*>)*<button type="submit">Resume delivery</button></form>~', $html);
		$this->assertStringNotContainsString('View waiting messages', $html);
		$this->assertStringNotContainsString('View messages being sent', $html);
		$this->assertStringNotContainsString('Local configuration revision', $html, 'settings pages no longer show the revision');
	}

	public function testTrapAndSyslogTypesAreRegisteredForDelivery(): void {
		self::alarmmanager_test_load('includes/delivery.php');
		$handlers = alarmmanager_delivery_handlers();
		$this->assertSame('alarmmanager_snmptrap_transport', $handlers['snmptrap']);
		$this->assertSame('alarmmanager_syslog_transport', $handlers['syslog']);
		$definition                                 = json_encode(alarmmanager_transport_configuration('snmptrap', $this->template('snmptrap')));
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => str_contains($sql, 'SELECT definition') ? [['definition' => $definition]] : [];
		$preview                                    = alarmmanager_delivery_preview($this->sampleEvent(), ['id' => 2, 'type' => 'snmptrap']);
		$this->assertSame('[REDACTED]', $preview['credentials'], 'preview never decrypts SNMP secrets');
		$this->assertSame('sample-outage-0001', $preview['payload']->amEpisodeId);
	}
}
