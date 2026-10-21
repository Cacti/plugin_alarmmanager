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
		$this->assertSame([], array_diff($GLOBALS['alarmmanager_test_hooks'], ['config_arrays', 'draw_navigation_text', 'alarmmanager_submit', 'poller_bottom', 'replicate_out', 'top_header_tabs', 'top_graph_header_tabs']), 'Unexpected producer/poller hook.');
		$this->assertCount(7, array_unique($GLOBALS['alarmmanager_test_hooks']));
		plugin_alarmmanager_uninstall();
		$this->assertSame($key, $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'], 'Uninstall removed the key.');
	}

	public function testCurrentPluginVersionUpgradesOlderSchema(): void {
		self::alarmmanager_test_load('setup.php');
		$this->assertTrue(plugin_alarmmanager_install());
		$key                                  = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
		$GLOBALS['alarmmanager_test_version'] = plugin_alarmmanager_version()['version'];
		$GLOBALS['alarmmanager_test_schema']  = 6;
		$this->assertTrue(plugin_alarmmanager_upgrade());
		$this->assertSame(12, $GLOBALS['alarmmanager_test_schema']);
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
			$this->assertSame(4, substr_count($html, 'href="alarmmanager.php?tab='));
		}
		ob_start();
		alarmmanager_web_open(['admin' => true], 'admin');
		$html = ob_get_clean();
		$this->assertStringNotContainsString('href="alarmmanager.php"', $html);
		$this->assertStringNotContainsString('alarmmanager.php?tab=', $html);
	}

	public function testAdministrationSectionsUseTheNativeConsoleMenu(): void {
		self::alarmmanager_test_load('setup.php');
		$previous_menu = $GLOBALS['menu'] ?? [];
		$previous_glyphs = $GLOBALS['menu_glyphs'] ?? [];
		try {
			$GLOBALS['menu'] = ['Management' => ['host.php' => 'Devices'], 'Import/Export' => []];
			$GLOBALS['menu_glyphs'] = [];
			alarmmanager_config_arrays();
			$items = $GLOBALS['menu']['Alarm Manager Settings'];
			$this->assertCount(7, $items);
			foreach (alarmmanager_admin_sections() as $section => $label) {
				$this->assertSame($label, $items['plugins/alarmmanager/alarmmanager_admin.php?action=' . $section . '&section=' . $section]);
			}
			$this->assertArrayNotHasKey('plugins/alarmmanager/alarmmanager_admin.php', $items);
			$this->assertSame(['host.php' => 'Devices'], $GLOBALS['menu']['Management']);
		} finally {
			$GLOBALS['menu'] = $previous_menu;
			$GLOBALS['menu_glyphs'] = $previous_glyphs;
		}
	}

	public function testPluginMetadata(): void {
		self::alarmmanager_test_load('setup.php');

		$info = plugin_alarmmanager_version();
		$this->assertSame('alarmmanager', $info['name'], 'Metadata name is invalid.');
		$this->assertSame('1.2.29', $info['compat'], 'Metadata compat is invalid.');
	}
}
