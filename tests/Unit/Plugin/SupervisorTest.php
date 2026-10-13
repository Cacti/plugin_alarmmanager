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

final class SupervisorTest extends AlarmManagerTestCase {
	/**
	 * @var array<int, string> Temporary fixture files removed in tearDown().
	 */
	private array $fixture_files = [];

	protected function tearDown(): void {
		foreach ($this->fixture_files as $file) {
			if (file_exists($file)) {
				unlink($file);
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function supervisorRecords(string $path): array {
		$lines = (is_file($path) && ($content = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) !== false) ? $content : [];

		return array_map(static fn ($line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function supervisorActive(array $rows): array {
		$active = [];

		foreach ($rows as $row) {
			if ($row['op'] === 'start') {
				$active[$row['pid']] = $row;
			} else {
				unset($active[$row['pid']]);
			}
		}

		return $active;
	}

	private function supervisorWait(callable $ready): bool {
		$deadline = microtime(true) + 5;

		do {
			if ($ready()) {
				return true;
			} usleep(20000);
		} while (microtime(true) < $deadline);

		return false;
	}

	public function testSupervisorLifecycle(): void {
		if (!function_exists('posix_kill')) {
			$this->markTestSkipped('pcntl and posix required.');
		}

		self::alarmmanager_test_load('includes/supervisor.php');

		$prefix              = sys_get_temp_dir() . '/alarmmanager-supervisor-' . bin2hex(random_bytes(6));
		$configuration       = $prefix . '.json';
		$log                 = $prefix . '.log';
		$marker              = $prefix . '.marker';
		$this->fixture_files = [$configuration, $log, $marker];
		$options             = ['processing_workers' => 1, 'delivery_workers' => 1, 'shutdown_seconds' => 1, 'respawn_base_ms' => 100];
		file_put_contents($configuration, json_encode($options, JSON_THROW_ON_ERROR));
		putenv('ALARMMANAGER_SUPERVISOR_TEST_LOG=' . $log);
		putenv('ALARMMANAGER_SUPERVISOR_TEST_CRASH_MARKER=' . $marker);
		$pid = pcntl_fork();

		if ($pid === 0) {
			alarmmanager_supervise($configuration, __DIR__ . '/../../fixtures/supervisor_worker.php');
			exit(0);
		}

		try {
			$this->assertGreaterThan(0, $pid, 'Supervisor fork failed.');
			$this->assertTrue($this->supervisorWait(function () use ($log): bool {
				$rows = $this->supervisorRecords($log);

				return count(array_filter($rows, static fn ($row) => $row['op'] === 'start' && $row['role'] === 'processing')) >= 2 && count($this->supervisorActive($rows)) === 2;
			}), 'Failed worker was not respawned.');

			$rows      = $this->supervisorRecords($log);
			$crash     = array_values(array_filter($rows, static fn ($row) => $row['op'] === 'crash'))[0];
			$restarted = array_values(array_filter($rows, static fn ($row) => $row['op'] === 'start' && $row['role'] === 'processing' && $row['time'] > $crash['time']))[0];
			$this->assertGreaterThanOrEqual(0.09, $restarted['time'] - $crash['time'], 'Respawn had no backoff.');

			$options['processing_workers'] = 2;
			file_put_contents($configuration, json_encode($options, JSON_THROW_ON_ERROR));
			posix_kill($pid, SIGHUP);
			$this->assertTrue($this->supervisorWait(function () use ($log): bool {
				$active = $this->supervisorActive($this->supervisorRecords($log));

				return count(array_filter($active, static fn ($row) => $row['role'] === 'processing')) === 2 && count(array_filter($active, static fn ($row) => $row['role'] === 'delivery')) === 1;
			}), 'Reload did not resize pools.');

			file_put_contents($configuration, '{bad');
			posix_kill($pid, SIGHUP);
			usleep(200000);
			$this->assertCount(3, $this->supervisorActive($this->supervisorRecords($log)), 'Invalid reload changed pools.');
			posix_kill($pid, SIGTERM);
			pcntl_waitpid($pid, $status);
			$pid = 0;
			$this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Supervisor shutdown failed.');
			$this->assertSame([], $this->supervisorActive($this->supervisorRecords($log)), 'Graceful shutdown left fixture workers running.');

			// A stubborn fixture validates bounded escalation rather than hanging forever.
			file_put_contents($configuration, json_encode(['processing_workers' => 1, 'delivery_workers' => 1, 'shutdown_seconds' => 1, 'respawn_base_ms' => 100], JSON_THROW_ON_ERROR));
			file_put_contents($log, '');
			putenv('ALARMMANAGER_SUPERVISOR_TEST_IGNORE_DELIVERY=1');
			$pid = pcntl_fork();

			if ($pid === 0) {
				alarmmanager_supervise($configuration, __DIR__ . '/../../fixtures/supervisor_worker.php');
				exit(0);
			}
			$this->assertTrue($this->supervisorWait(fn (): bool => count($this->supervisorActive($this->supervisorRecords($log))) === 2), 'Escalation fixture did not start.');
			$active  = $this->supervisorActive($this->supervisorRecords($log));
			$started = microtime(true);
			posix_kill($pid, SIGTERM);
			pcntl_waitpid($pid, $status);
			$pid = 0;
			$this->assertLessThan(2.5, microtime(true) - $started, 'Shutdown escalation was unbounded.');
			$this->assertSame(0, pcntl_wexitstatus($status), 'Shutdown escalation was unbounded.');

			foreach ($active as $child) {
				$this->assertFalse((bool) @posix_kill($child['pid'], 0), 'Escalation left child running.');
			}
		} finally {
			if ($pid > 0) {
				posix_kill($pid, SIGTERM);
				pcntl_waitpid($pid, $status);
			}
		}
	}
}
