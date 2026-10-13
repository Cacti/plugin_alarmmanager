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
 | Shared base case: plugin source loading with read-source access for     |
 | security scans, guarded doubles state reset between tests, and the      |
 | @group db opt-in used by the PDO-backed integration suite.              |
 +-------------------------------------------------------------------------+
*/

abstract class AlarmManagerTestCase extends \PHPUnit\Framework\TestCase {
	/**
	 * Load one plugin source file relative to the plugin root, once.
	 *
	 * @param string $relative_file Plugin-relative path such as 'setup.php' or 'includes/ingestion.php'.
	 */
	public static function alarmmanager_test_load(string $relative_file): void {
		$real = realpath(__DIR__ . '/../' . $relative_file);

		if ($real === false || !is_readable($real)) {
			throw new RuntimeException("Test source file is not readable: $relative_file");
		}

		require_once $real;
	}

	/**
	 * Return the contents of one plugin source file for source-scan tests.
	 *
	 * @param string $relative_file Plugin-relative path.
	 */
	public static function alarmmanager_test_read_source(string $relative_file): string {
		$real = realpath(__DIR__ . '/../' . $relative_file);

		if ($real === false || !is_readable($real)) {
			throw new RuntimeException("Test source file is not readable: $relative_file");
		}

		return (string) file_get_contents($real);
	}

	/**
	 * Reset the guarded recording doubles between tests.
	 */
	public static function resetAlarmManagerTestState(): void {
		if (function_exists('alarmmanager_test_reset_state')) {
			alarmmanager_test_reset_state();
		}
	}

	/**
	 * Skip (not fail) the test when the disposable MariaDB DSN is absent.
	 */
	public static function requireAlarmManagerTestDb(): void {
		if (getenv('ALARMMANAGER_TEST_DSN') === false) {
			self::markTestSkipped('ALARMMANAGER_TEST_DSN is not set; DB-backed lane skipped.');
		}
	}

	protected function setUp(): void {
		self::resetAlarmManagerTestState();
	}
}
