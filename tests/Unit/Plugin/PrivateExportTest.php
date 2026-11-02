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

final class PrivateExportTest extends AlarmManagerTestCase {
	private string $root = '';

	protected function setUp(): void {
		parent::setUp();
		self::alarmmanager_test_load('includes/remote.php');
		$this->root = sys_get_temp_dir() . '/alarmmanager_export_' . bin2hex(random_bytes(5));
		mkdir($this->root, 0700);
		chmod($this->root, 0700);
	}

	protected function tearDown(): void {
		foreach (glob($this->root . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
			if (basename($entry) !== '.' && basename($entry) !== '..') {
				is_dir($entry) && !is_link($entry) ? rmdir($entry) : unlink($entry);
			}
		}
		@rmdir($this->root);
		parent::tearDown();
	}

	private function reject(string $path, string $code): void {
		try {
			alarmmanager_remote_private_write($path, '{"credentials":"DUMMY"}');
			$this->fail('Expected ' . $code);
		} catch (RuntimeException $error) {
			$this->assertSame($code, $error->getMessage());
		}
	}

	public function testWritesANewOwnerOnlyFile(): void {
		alarmmanager_remote_private_write($this->root . '/bundle.json', '{"credentials":"DUMMY"}');
		$this->assertSame('{"credentials":"DUMMY"}', file_get_contents($this->root . '/bundle.json'));
		$this->assertSame(0600, fileperms($this->root . '/bundle.json') & 0777);
	}

	public function testRefusesAPlantedSymlinkAndCreatesNothingThroughIt(): void {
		$target = $this->root . '/planted-target.json';
		symlink($target, $this->root . '/bundle.json');
		$this->reject($this->root . '/bundle.json', 'private_output_invalid');
		$this->assertFileDoesNotExist($target, 'A dangling link must not be followed to create its target.');
	}

	public function testNeverOverwritesAnExistingFile(): void {
		file_put_contents($this->root . '/bundle.json', 'original');

		// Count every diagnostic, including ones silenced with @, because a strict runner fails on them.
		$seen = [];
		set_error_handler(static function (int $number, string $message) use (&$seen): bool {
			$seen[] = $message;

			return true;
		});

		try {
			$this->reject($this->root . '/bundle.json', 'private_output_invalid');
		} finally {
			restore_error_handler();
		}
		$this->assertSame([], $seen, 'An existing file is refused before any open is attempted.');
		$this->assertSame('original', file_get_contents($this->root . '/bundle.json'));
	}

	public function testRefusesADirectoryOtherAccountsCanModify(): void {
		// Group write is enough to plant a link, and it shares the mask test with the world bit. The test never grants access to others.
		foreach ([0770, 0720] as $mode) {
			chmod($this->root, $mode);
			$this->reject($this->root . '/bundle.json', 'private_output_directory_required');
		}
		$this->assertFileDoesNotExist($this->root . '/bundle.json');
	}

	public function testRefusesRelativePathsAndThePluginTree(): void {
		$this->reject('bundle.json', 'private_output_outside_plugin_required');
		$this->reject(dirname(__DIR__, 3) . '/bundle.json', 'private_output_outside_plugin_required');
		$this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/bundle.json');
	}
}
