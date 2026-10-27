<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class ChangedPathsTest extends AlarmManagerTestCase {
	public function testStringEventIdsKeepIntegrationStatusAndRemoteRowsStayRemote(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$queries                                    = [];
		$GLOBALS['alarmmanager_test_query_handler'] = function ($sql, $params) use (&$queries) {
			$queries[] = [$sql, $params];

			if (str_contains($sql, 'GROUP BY event_row_id')) {
				$this->assertSame([42], $params, 'Only the local BIGINT string ID is looked up.');

				return [];
			}

			if (str_contains($sql, 'AS suppressed')) {
				return [['id' => '42', 'status' => 'pending', 'suppressed' => 0]];
			}

			if (str_contains($sql, 'FROM plugin_alarmmanager_decisions')) {
				$this->assertSame([42], $params, 'Routing looks up only local event rows.');

				return [['event_row_id' => '42', 'endpoint_id' => '7', 'reason' => 'queued']];
			}

			if (str_contains($sql, 'FROM plugin_alarmmanager_endpoints')) {
				return [['id' => '7', 'name' => 'Slack']];
			}

			return [['id' => '42'], ['id' => null]];
		};
		$rows = alarmmanager_report_list('events', ['device_sql' => 'e.device_id = 42'], alarmmanager_report_filters([]))['rows'];
		$this->assertSame('Pending processing', $rows[0]['integration_status']);
		$this->assertSame('Remote history', $rows[1]['integration_status']);
		$this->assertSame(['Queued → Slack', '—'], array_column($rows, 'routing'));
		$this->assertCount(5, $queries);
	}

	public function testRemoteEventAndConditionQueriesKeepScopeAndCollector(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$queries                                    = [];
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql, $params) use (&$queries) {
			$queries[] = [$sql, $params];

			return [];
		};

		foreach (['events', 'conditions'] as $kind) {
			$filters = alarmmanager_report_filters(['collector_id' => 3]);

			if ($kind === 'events') {
				$filters['status'] = 'failed';
			}
			$this->assertSame([], alarmmanager_report_list($kind, ['device_sql' => 'e.device_id = 42'], $filters)['rows']);
			[$sql, $params] = $queries[array_key_last($queries)];
			$this->assertStringContainsString('UNION ALL', $sql);
			$this->assertStringContainsString('e.device_id = 42', $sql);
			$this->assertStringContainsString('h.poller_id = ?', $sql);
			$this->assertContains(3, $params);
		}
	}

	public function testHeartbeatAcknowledgesDigestAfterCommittingPeer(): void {
		self::alarmmanager_test_load('includes/remote.php');
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_query_handler']   = static fn () => [['id' => 3]];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$writes) {
			$writes[] = [$sql, $params];

			return true;
		};
		$packet = ['version' => 1, 'poller_id' => 3, 'instance_uuid' => str_repeat('b', 32),
			'receiver_uuid'     => str_repeat('a', 32), 'through' => 0, 'records' => [], 'heartbeat' => true];
		$body = json_encode($packet, JSON_THROW_ON_ERROR);
		$ack  = json_decode(alarmmanager_sync_receive($body, 3), true, 8, JSON_THROW_ON_ERROR);
		$this->assertSame(hash('sha256', $body), $ack['digest']);
		$this->assertSame(3, $ack['poller_id']);
		$this->assertSame('START TRANSACTION', $writes[0][0]);
		$this->assertStringContainsString('INSERT INTO plugin_alarmmanager_sync_peers', $writes[1][0]);
		$this->assertSame([3, str_repeat('b', 32)], $writes[1][1]);
		$this->assertSame('COMMIT', $writes[2][0]);
	}
	public function testSettingsSaveAndInlineTestFailurePreserveSavedConfiguration(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$before = [$GLOBALS['config'], $_SESSION ?? [], $_SERVER['REQUEST_METHOD'] ?? null,
			$GLOBALS['alarmmanager_test_config_options'] ?? [], $GLOBALS['alarmmanager_test_allowed_realms'] ?? []];
		$root                                         = sys_get_temp_dir() . '/alarmmanager-changed-paths-' . bin2hex(random_bytes(6));
		$GLOBALS['config']['base_path']               = $root;
		$GLOBALS['config']['poller_id']               = 1;
		$_SESSION['sess_user_id']                     = 1;
		$_SERVER['REQUEST_METHOD']                    = 'POST';
		$GLOBALS['alarmmanager_test_config_options']  = ['auth_method' => 1];
		$GLOBALS['alarmmanager_test_allowed_realms']  = [1, 101];
		$GLOBALS['alarmmanager_test_csrf_valid']      = true;
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$writes) {
			$writes[] = [$sql, $params];

			return true;
		};
		$GLOBALS['alarmmanager_test_cell_handler']  = static fn ($sql) => str_contains($sql, 'config_revision') ? 2 : 0;
		$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) {
			if (str_contains($sql, 'plugin_realms')) {
				return [['id' => 1]];
			}

			if (str_contains($sql, 'plugin_alarmmanager_schema') && !str_contains($sql, 'configuration_history')) {
				return [['config_revision' => 1]];
			}

			if (str_contains($sql, 'configuration_history')) {
				return [['definition' => json_encode(['version' => 1, 'endpoints' => [['id' => 7, 'name' => 'Test', 'type' => 'webhook', 'transport' => alarmmanager_integration_templates()['teams']['transport']]], 'rules' => []])]];
			}

			return [];
		};

		try {
			$this->assertSame('Configuration saved.', alarmmanager_admin_action(['action' => 'settings_save', 'revision' => 1,
				'offline_summary'                                                            => 'Offline', 'recovering_summary' => 'Recovering', 'online_summary' => 'Online',
				'processing_workers'                                                         => 3, 'delivery_workers' => 2]));
			$this->assertSame(3, alarmmanager_supervisor_options()['processing_workers']);
			$teams                                      = alarmmanager_integration_templates()['teams']['transport'];
			$url                                        = 'https://prod.logic.azure.com/workflows/fixture?sig=fixture';
			$key                                        = random_bytes(32);
			$cipher                                     = alarmmanager_encrypt_credential(['teams_webhook_url' => $url], 7, $key);
			$query_handler                              = $GLOBALS['alarmmanager_test_query_handler'];
			$cell_handler                               = $GLOBALS['alarmmanager_test_cell_handler'];
			$GLOBALS['alarmmanager_test_cell_handler']  = static fn ($sql) => str_contains($sql, 'settings_user') ? base64_encode($key) : $cell_handler($sql);
			$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) use ($query_handler, $cipher) {
				if (str_contains($sql, 'SELECT id FROM plugin_alarmmanager_endpoints')) {
					return [['id' => 7]];
				}

				if (str_contains($sql, 'SELECT endpoint_id FROM plugin_alarmmanager_credentials')) {
					return [['endpoint_id' => 7]];
				}

				if (str_contains($sql, 'SELECT ciphertext')) {
					return [['ciphertext' => $cipher]];
				}

				return $query_handler($sql);
			};

			foreach (['teams', 'keep'] as $auth) {
				$this->assertSame('Configuration saved.', alarmmanager_admin_action(['action' => 'endpoint_save', 'revision' => 1, 'id' => 7, 'name' => 'Teams',
					'enabled'                                                                    => 'yes', 'type' => 'webhook', 'url' => $teams['url'], 'auth_type' => $auth, 'teams_webhook_url' => $url,
					'transport_json'                                                             => json_encode($teams), 'mapping_json' => json_encode($teams['mapping'])]));
			}
			$GLOBALS['alarmmanager_test_query_handler'] = $query_handler;
			$GLOBALS['alarmmanager_test_cell_handler']  = $cell_handler;

			$writes = [];

			try {
				alarmmanager_admin_action(['action' => 'endpoint_save', 'revision' => 1, 'id' => 7, 'name' => 'Saved destination',
					'enabled'                          => 'yes', 'type' => 'webhook', 'url' => 'https://example.test/alerts',
					'transport_json'                   => '{}', 'mapping_json' => '{}', 'test_destination' => 'yes']);
				$this->fail('Unavailable ingestion should report the inline test failure.');
			} catch (RuntimeException $error) {
				$this->assertSame('test_after_save_failed', $error->getMessage());
			}
			$this->assertSame('COMMIT', $writes[array_key_last($writes)][0], 'Destination changes commit before attempting the inline test.');
			$this->assertTrue((bool) array_filter($writes, static fn ($write) => str_contains($write[0], 'INSERT INTO plugin_alarmmanager_configuration_history')));
		} finally {
			[$GLOBALS['config'], $_SESSION, $_SERVER['REQUEST_METHOD'],
				$GLOBALS['alarmmanager_test_config_options'], $GLOBALS['alarmmanager_test_allowed_realms']] = $before;
			unset($GLOBALS['alarmmanager_test_csrf_valid']);

			foreach (glob($root . '/cache/alarmmanager/*') ?: [] as $file) {
				unlink($file);
			}

			if (is_dir($root . '/cache/alarmmanager')) {
				rmdir($root . '/cache/alarmmanager');
				rmdir($root . '/cache');
				rmdir($root);
			}
		}
	}
}
