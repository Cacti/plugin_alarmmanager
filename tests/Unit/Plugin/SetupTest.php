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

final class SetupTest extends AlarmManagerTestCase {
	public function testKeyLifecycleAndRegistration(): void {
		self::alarmmanager_test_load('setup.php');

		$this->assertTrue(plugin_alarmmanager_install(), 'Installation failed.');
		$key = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
		$this->assertSame(32, strlen(alarmmanager_decode_key($key)), 'Key length must be 256 bits.');
		$this->assertTrue(plugin_alarmmanager_install(), 'Repeat install failed.');
		$this->assertSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'], 'Repeat install changed the key.');
		$this->assertTrue(plugin_alarmmanager_upgrade(), 'Upgrade readiness failed.');
		$this->assertSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'], 'Upgrade changed the key.');
		$this->assertSame([], array_diff($GLOBALS['alarmmanager_test_hooks'], ['config_arrays', 'config_settings', 'settings_bottom', 'global_settings_update', 'draw_navigation_text', 'alarmmanager_submit', 'admin_alert', 'poller_bottom', 'replicate_out', 'top_header_tabs', 'top_graph_header_tabs']), 'Unexpected producer/poller hook.');
		$this->assertCount(11, array_unique($GLOBALS['alarmmanager_test_hooks']));
		plugin_alarmmanager_uninstall();
		$this->assertSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'], 'Uninstall removed the key.');
	}

	public function testCurrentVersionRepairsMissingAdminAlertRegistration(): void {
		self::alarmmanager_test_load('setup.php');
		$this->assertTrue(plugin_alarmmanager_install());
		$GLOBALS['alarmmanager_test_version'] = plugin_alarmmanager_version()['version'];
		$GLOBALS['alarmmanager_test_schema']  = 14;
		$key                                  = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
		$GLOBALS['alarmmanager_test_hooks']   = array_values(array_diff($GLOBALS['alarmmanager_test_hooks'], ['admin_alert']));
		$this->assertTrue(plugin_alarmmanager_upgrade());
		$this->assertContains('admin_alert', $GLOBALS['alarmmanager_test_hooks']);
		$this->assertSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key']);
		$this->assertTrue(plugin_alarmmanager_upgrade());
		unset($GLOBALS['alarmmanager_test_store']['1:alarmmanager_key']);
		$this->assertFalse(plugin_alarmmanager_upgrade(), 'Current-version repair must not recreate a lost encryption key.');
	}

	public function testCurrentPluginVersionUpgradesOlderSchema(): void {
		self::alarmmanager_test_load('setup.php');
		$this->assertTrue(plugin_alarmmanager_install());
		$key                                  = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
		$GLOBALS['alarmmanager_test_version'] = plugin_alarmmanager_version()['version'];
		$GLOBALS['alarmmanager_test_schema']  = 6;
		$this->assertTrue(plugin_alarmmanager_upgrade());
		$this->assertSame(14, $GLOBALS['alarmmanager_test_schema']);
		$this->assertSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key']);
	}

	public function testMalformedKeysAreRejected(): void {
		self::alarmmanager_test_load('setup.php');

		foreach ([false, null, '', 'invalid!', base64_encode('short'), base64_encode(str_repeat('x', 33))] as $invalid) {
			$rejected = false;

			try {
				alarmmanager_decode_key($invalid);
			} catch (RuntimeException $error) {
				$rejected = true;
			}
			$this->assertTrue($rejected, 'Malformed key was accepted.');
		}

		$GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] = 'broken';
		$this->assertFalse(plugin_alarmmanager_check_config(), 'Malformed persisted key enabled plugin.');
		$rejected = false;

		try {
			alarmmanager_setup_key();
		} catch (RuntimeException $error) {
			$rejected = true;
		}
		$this->assertTrue($rejected && $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] === 'broken', 'Setup replaced a malformed key.');

		$GLOBALS['alarmmanager_test_store']       = [];
		$GLOBALS['alarmmanager_test_credentials'] = 1;
		$rejected                                 = false;

		try {
			alarmmanager_setup_key();
		} catch (RuntimeException $error) {
			$rejected = true;
		}
		$this->assertTrue($rejected && $GLOBALS['alarmmanager_test_store'] === [], 'Lost key was recreated over stored credentials.');

		$GLOBALS['alarmmanager_test_write_failure'] = true;
		$rejected                                   = false;

		try {
			alarmmanager_setup_key();
		} catch (RuntimeException $error) {
			$rejected = true;
		}
		$this->assertTrue($rejected && $GLOBALS['alarmmanager_test_store'] === [], 'Failed storage was treated as success.');
		$GLOBALS['alarmmanager_test_write_failure'] = false;
	}

	/**
	 * @return void
	 */
	public function testUpgradeRefusesLostKeysAndFailedWrites(): void {
		self::alarmmanager_test_load('setup.php');

		$GLOBALS['alarmmanager_test_store'] = [];
		$this->assertFalse(plugin_alarmmanager_upgrade(), 'Upgrade silently recreated a lost key.');
	}

	/**
	 * @return void
	 */
	public function testSeparateInstallationsGenerateSeparateKeys(): void {
		self::alarmmanager_test_load('setup.php');

		$GLOBALS['alarmmanager_test_store'] = [];
		$this->assertTrue(plugin_alarmmanager_install(), 'Install with fresh store failed.');
		$key                                = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
		$GLOBALS['alarmmanager_test_store'] = [];
		alarmmanager_setup_key();
		$this->assertNotSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'], 'Separate installation reused a key.');
	}

	/**
	 * @return void
	 */
	public function testSqlFlowDebugBlocksKeyAccess(): void {
		self::alarmmanager_test_load('setup.php');

		alarmmanager_setup_key();
		$GLOBALS['config']['DEBUG_SQL_FLOW'] = true;
		$this->assertFalse(alarmmanager_key_ready(), 'SQL flow tracing may expose keys.');
		unset($GLOBALS['config']['DEBUG_SQL_FLOW']);
	}

	/**
	 * @return void
	 */
	public function testRegistrationFailureIsDetected(): void {
		self::alarmmanager_test_load('setup.php');

		$this->assertTrue(plugin_alarmmanager_install(), 'Initial install failed.');
		$saved_key                           = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
		$this->resetAlarmManagerTestState();
		$GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] = $saved_key;
		$GLOBALS['alarmmanager_test_registration_failure']        = true;
		$this->assertFalse(plugin_alarmmanager_install(), 'Missing hook metadata was accepted after void registration.');
		$this->assertSame($saved_key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'], 'Registration failure changed the key.');
		$GLOBALS['alarmmanager_test_registration_failure'] = false;
	}

	/**
	 * @return void
	 */
	public function testReportNavigationRespectsViewAndAdministrationPermissions(): void {
		self::alarmmanager_test_load('setup.php');
		self::alarmmanager_test_load('includes/web_view.php');
		$GLOBALS['alarmmanager_test_allowed_pages'] = [];
		ob_start();
		alarmmanager_show_tab();
		$this->assertSame('', ob_get_clean(), 'No top tab without the view realm');
		$GLOBALS['alarmmanager_test_allowed_pages'] = ['alarmmanager.php'];
		ob_start();
		alarmmanager_show_tab();
		$this->assertStringContainsString('alt="Alarm Manager"', ob_get_clean());
		unset($GLOBALS['alarmmanager_test_allowed_pages']);

		foreach ([false, true] as $admin) {
			ob_start();
			alarmmanager_web_open(['admin' => $admin], 'dashboard');
			$html = ob_get_clean();
			$this->assertStringNotContainsString('href="alarmmanager_admin.php"', $html);
			$this->assertSame(3, substr_count($html, 'href="alarmmanager.php?tab='), 'Routing decisions are part of Events');
		}
		ob_start();
		alarmmanager_web_open(['admin' => true], 'admin');
		$html = ob_get_clean();
		$this->assertStringNotContainsString('href="alarmmanager.php"', $html);
		$this->assertStringNotContainsString('alarmmanager.php?tab=', $html);
	}

	public function testAdministrationSectionsUseTheNativeConsoleMenu(): void {
		self::alarmmanager_test_load('setup.php');
		$previous_menu    = $GLOBALS['menu'] ?? [];
		$previous_glyphs  = $GLOBALS['menu_glyphs'] ?? [];
		$previous_enabled = $GLOBALS['alarmmanager_test_plugin_enabled'] ?? null;

		try {
			$GLOBALS['menu']                              = ['Management' => ['host.php' => 'Devices'], 'Import/Export' => []];
			$GLOBALS['menu_glyphs']                       = [];
			$GLOBALS['alarmmanager_test_plugin_enabled']  = true;
			alarmmanager_config_arrays();
			$items = $GLOBALS['menu']['Alarm Manager Settings'];
			$this->assertCount(6, $items);

			foreach (alarmmanager_admin_sections() as $section => $label) {
				$this->assertSame($label, $items['plugins/alarmmanager/alarmmanager_admin.php?action=' . $section . '&section=' . $section]);
			}
			$this->assertArrayNotHasKey('plugins/alarmmanager/alarmmanager_admin.php', $items);
			$this->assertSame(['host.php' => 'Devices'], $GLOBALS['menu']['Management']);
		} finally {
			$GLOBALS['menu']                              = $previous_menu;
			$GLOBALS['menu_glyphs']                       = $previous_glyphs;
			$GLOBALS['alarmmanager_test_plugin_enabled']  = $previous_enabled;
		}
	}

	public function testDisabledPluginDoesNotInjectTheConsoleMenu(): void {
		// Cacti core never disables the config_arrays hook itself (so a disabled
		// plugin can still reach its settings); the menu gate has to live here.
		self::alarmmanager_test_load('setup.php');
		$previous_menu    = $GLOBALS['menu'] ?? [];
		$previous_glyphs  = $GLOBALS['menu_glyphs'] ?? [];
		$previous_enabled = $GLOBALS['alarmmanager_test_plugin_enabled'] ?? null;

		try {
			$GLOBALS['menu']                              = ['Management' => ['host.php' => 'Devices'], 'Import/Export' => []];
			$GLOBALS['menu_glyphs']                       = [];
			$GLOBALS['alarmmanager_test_plugin_enabled']  = false;
			alarmmanager_config_arrays();
			$this->assertArrayNotHasKey('Alarm Manager Settings', $GLOBALS['menu'], 'A disabled plugin must not advertise its administration menu.');
			$this->assertSame(['Management' => ['host.php' => 'Devices'], 'Import/Export' => []], $GLOBALS['menu']);
		} finally {
			$GLOBALS['menu']                              = $previous_menu;
			$GLOBALS['menu_glyphs']                       = $previous_glyphs;
			$GLOBALS['alarmmanager_test_plugin_enabled']  = $previous_enabled;
		}
	}

	public function testDeviceBackfillAvoidsCollationMixAndLogsFailedSteps(): void {
		self::alarmmanager_test_load('includes/schema.php');
		$writes                                       = [];
		$GLOBALS['alarmmanager_test_log']             = [];
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn () => 1;
		$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql) use (&$writes) {
			$writes[] = $sql;

			return true;
		};
		$this->assertTrue(alarmmanager_schema_ui());
		$this->assertStringContainsString("CAST(JSON_TYPE(JSON_EXTRACT(payload, '$.device.id')) AS BINARY) = 'INTEGER'", $writes[0], 'JSON_TYPE is compared as bytes, not in a collation (issue #15).');
		$this->assertSame([], $GLOBALS['alarmmanager_test_log']);

		$GLOBALS['alarmmanager_test_execute_handler'] = static function () {
			$GLOBALS['database_last_error'] = 'DB Execute Failed!, Error 1267: Illegal mix of collations';

			return false;
		};
		$this->assertFalse(alarmmanager_schema_ui());
		$this->assertSame(['schema step v4 failed (storage_failure): DB Execute Failed!, Error 1267: Illegal mix of collations'], $GLOBALS['alarmmanager_test_log']);

		$GLOBALS['alarmmanager_test_log'] = [];
		$this->assertFalse(alarmmanager_schema_failed('v2', 'newer_schema'));
		$this->assertSame(['schema step v2 failed (newer_schema)'], $GLOBALS['alarmmanager_test_log'], 'A stale database error is not blamed for a non-storage failure.');
		unset($GLOBALS['database_last_error']);
	}

	/** One case per failure branch of each schema step: the step function, the step and reason it
	 * must log, the information_schema/cell answers to override, and the SQL whose write fails. */
	public static function schemaStepFailures(): array {
		$innodb_count = "ENGINE <> 'InnoDB'";
		$version      = 'SELECT version FROM plugin_alarmmanager_schema';

		return [
			'v1 MySQL server'               => ['alarmmanager_schema_install', 'v1', 'requires_mariadb_10_11', ['SELECT VERSION()' => '8.0.36'], null],
			'v1 MariaDB older than 10.11'   => ['alarmmanager_schema_install', 'v1', 'requires_mariadb_10_11', ['SELECT VERSION()' => '5.5.5-10.6.18-MariaDB'], null],
			'v1 create table'               => ['alarmmanager_schema_install', 'v1', 'storage_failure', [], 'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_jobs'],
			'v1 tables not InnoDB'          => ['alarmmanager_schema_install', 'v1', 'tables_not_innodb', ["ENGINE = 'InnoDB'" => 2], null],
			'v1 version row'                => ['alarmmanager_schema_install', 'v1', 'storage_failure', [], 'INSERT INTO plugin_alarmmanager_schema'],
			'v2 version unreadable'         => ['alarmmanager_schema_runtime', 'v2', 'storage_failure', [$version => false], null],
			'v2 newer schema'               => ['alarmmanager_schema_runtime', 'v2', 'newer_schema', [$version => 15], null],
			'v2 engine check unreadable'    => ['alarmmanager_schema_runtime', 'v2', 'storage_failure', [$innodb_count => false], null],
			'v2 tables not InnoDB'          => ['alarmmanager_schema_runtime', 'v2', 'tables_not_innodb', [$innodb_count => 1], null],
			'v2 write'                      => ['alarmmanager_schema_runtime', 'v2', 'storage_failure', [], 'SET stream_key = SHA2'],
			'v3 engine check unreadable'    => ['alarmmanager_schema_delivery', 'v3', 'storage_failure', ['plugin_alarmmanager_transports' => false], null],
			'v3 tables not InnoDB'          => ['alarmmanager_schema_delivery', 'v3', 'tables_not_innodb', ['plugin_alarmmanager_transports' => 1], null],
			'v3 write'                      => ['alarmmanager_schema_delivery', 'v3', 'storage_failure', [], 'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_credentials'],
			'v5 create table'               => ['alarmmanager_schema_producers', 'v5', 'storage_failure', [], 'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_streams'],
			'v5 version update'             => ['alarmmanager_schema_producers', 'v5', 'storage_failure', [], 'GREATEST(version, 5)'],
			'v6 create table'               => ['alarmmanager_schema_remote', 'v6', 'storage_failure', [], 'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_sync_outbox'],
			'v6 node identity insert'       => ['alarmmanager_schema_remote', 'v6', 'storage_failure', [], 'INSERT IGNORE INTO plugin_alarmmanager_node'],
			'v6 node identity missing'      => ['alarmmanager_schema_remote', 'v6', 'node_identity_missing', [$version => 6, 'SELECT instance_uuid' => false], null],
			'v6 sync sender'                => ['alarmmanager_schema_remote', 'v6', 'storage_failure', [], 'INSERT IGNORE INTO plugin_alarmmanager_sync_sender'],
			'v6 outbox trigger'             => ['alarmmanager_schema_remote', 'v6', 'storage_failure', [], 'CREATE TRIGGER IF NOT EXISTS plugin_alarmmanager_sync_delivery_update'],
			'v6 version update'             => ['alarmmanager_schema_remote', 'v6', 'storage_failure', [], 'GREATEST(version, 6)'],
			'v7 column check unreadable'    => ['alarmmanager_schema_record_ids', 'v7', 'storage_failure', ["EXTRA LIKE '%auto_increment%'" => false], null],
			'v7 write'                      => ['alarmmanager_schema_record_ids', 'v7', 'storage_failure', ["EXTRA LIKE '%auto_increment%'" => 0], 'MODIFY COLUMN id'],
			'v9 write'                      => ['alarmmanager_schema_integrations', 'v9', 'storage_failure', [], 'GREATEST(version, 9)'],
			'v10 write'                     => ['alarmmanager_schema_device_status', 'v10', 'storage_failure', [], 'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_device_status'],
			'v11 write'                     => ['alarmmanager_schema_sync', 'v11', 'storage_failure', [], 'GREATEST(version, 11)'],
			'v12 write'                     => ['alarmmanager_schema_direct_routes', 'v12', 'storage_failure', [], 'DROP TABLE IF EXISTS plugin_alarmmanager_profiles'],
			'v13 index check unreadable'    => ['alarmmanager_schema_recipients', 'v13', 'storage_failure', ["INDEX_NAME = 'event_endpoint'" => false], null],
			'v14 write'                     => ['alarmmanager_schema_retired', 'v14', 'storage_failure', [], 'DROP TABLE IF EXISTS plugin_alarmmanager_api_clients'],
		];
	}

	/** Answers the schema steps' reads like a healthy MariaDB 10.11 with plugin tables at v1,
	 * except for $cells, and fails every write containing $fail_on. Successful writes are
	 * recorded in $GLOBALS['alarmmanager_test_schema_writes']. */
	private static function fakeSchemaDatabase(array $cells, ?string $fail_on): void {
		$GLOBALS['alarmmanager_test_schema_writes']   = [];
		$GLOBALS['alarmmanager_test_log']             = [];
		$GLOBALS['database_last_error']               = 'DB Execute Failed!, Error 2006: MySQL server has gone away';
		$GLOBALS['alarmmanager_test_cell_handler']    = static function (string $sql) use ($cells) {
			foreach ($cells as $needle => $value) {
				if (str_contains($sql, $needle)) {
					return $value;
				}
			}

			return match (true) {
				$sql === 'SELECT VERSION()'                          => '10.11.14-MariaDB',
				str_contains($sql, "ENGINE <> 'InnoDB'")             => 0,
				str_contains($sql, 'plugin_alarmmanager_transports') => 2,
				str_contains($sql, "ENGINE = 'InnoDB'")              => 3,
				str_contains($sql, 'SELECT instance_uuid')           => str_repeat('a', 32),
				default                                              => 1,
			};
		};
		$GLOBALS['alarmmanager_test_execute_handler'] = static function (string $sql) use ($fail_on) {
			if ($fail_on !== null && str_contains($sql, $fail_on)) {
				return false;
			}
			$GLOBALS['alarmmanager_test_schema_writes'][] = $sql;

			return true;
		};
	}

	public function testSchemaInstallSucceedsAgainstTheFakeDatabase(): void {
		self::alarmmanager_test_load('includes/schema.php');
		self::fakeSchemaDatabase([], null);

		try {
			$this->assertTrue(alarmmanager_schema_install());
			$this->assertSame([], $GLOBALS['alarmmanager_test_log'], 'A healthy install logs no schema failure.');
			$this->assertNotEmpty(preg_grep('/SET version = 14 WHERE id = 1/', $GLOBALS['alarmmanager_test_schema_writes']), 'A healthy install reaches v14.');
		} finally {
			unset($GLOBALS['database_last_error'], $GLOBALS['alarmmanager_test_schema_writes']);
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('schemaStepFailures')]
	public function testSchemaStepFailureIsLoggedAndDoesNotAdvanceTheVersion(string $function, string $step, string $reason, array $cells, ?string $fail_on): void {
		self::alarmmanager_test_load('includes/schema.php');
		self::fakeSchemaDatabase($cells, $fail_on);

		try {
			$this->assertFalse($function(), "$function() reports the failure.");
			$detail = in_array($reason, ['storage_failure', 'schema_failure'], true) ? ': ' . $GLOBALS['database_last_error'] : '';
			$this->assertSame(["schema step $step failed ($reason)$detail"], $GLOBALS['alarmmanager_test_log'], 'Exactly one line names the failed step and reason.');
			$this->assertSame([], preg_grep('/plugin_alarmmanager_schema SET version|INSERT INTO plugin_alarmmanager_schema/', $GLOBALS['alarmmanager_test_schema_writes']), 'A failed step does not advance the schema version.');
		} finally {
			unset($GLOBALS['database_last_error'], $GLOBALS['alarmmanager_test_schema_writes']);
		}
	}

	public function testPluginMetadata(): void {
		self::alarmmanager_test_load('setup.php');

		$info = plugin_alarmmanager_version();
		$this->assertSame('alarmmanager', $info['name'], 'Metadata name is invalid.');
		$this->assertSame('1.2.29', $info['compat'], 'Metadata compat is invalid.');
	}
}
