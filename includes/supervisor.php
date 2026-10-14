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
// The parent never bootstraps Cacti or opens a database connection.
function alarmmanager_supervisor_options(?string $path): array {
	$settings = ['processing_workers' => 2, 'delivery_workers' => 4, 'lease_seconds' => 180, 'idle_ms' => 250, 'shutdown_seconds' => 180, 'respawn_base_ms' => 1000];

	if ($path !== null) {
		$data = @file_get_contents($path, false, null, 0, 65537);

		if ($data === false || strlen($data) > 65536) {
			throw new RuntimeException('invalid_supervisor_config');
		}
		$decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);

		if (!is_array($decoded) || array_diff(array_keys($decoded), array_keys($settings))) {
			throw new RuntimeException('invalid_supervisor_config');
		}
		$settings = array_replace($settings, $decoded);
	}

	foreach (['processing_workers' => [1, 64], 'delivery_workers' => [0, 64], 'lease_seconds' => [15, 600], 'idle_ms' => [10, 10000], 'shutdown_seconds' => [1, 600], 'respawn_base_ms' => [100, 30000]] as $key => [$min, $max]) {
		if (!is_int($settings[$key]) || $settings[$key] < $min || $settings[$key] > $max) {
			throw new RuntimeException('invalid_supervisor_config');
		}
	}

	return $settings;
}
function alarmmanager_supervise(?string $config_path, ?string $worker_script = null): void {
	if (!function_exists('pcntl_signal') || !function_exists('proc_open')) {
		throw new RuntimeException('supervisor_runtime_missing');
	}
	$settings    = alarmmanager_supervisor_options($config_path);
	$script      = $worker_script ?? __DIR__ . '/worker.php';
	$stop        = false;
	$reload      = false;
	$children    = [];
	$backoff     = [];
	$stopping_at = null;
	pcntl_signal(SIGCHLD, SIG_DFL);
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
	pcntl_signal(SIGINT, function () use (&$stop): void { $stop = true; });
	pcntl_signal(SIGHUP, function () use (&$reload): void { $reload = true; });

	try {
		while (true) {
			$now = hrtime(true) / 1000000000;

			if ($reload && !$stop) {
				try {
					$next     = alarmmanager_supervisor_options($config_path);
					$settings = $next;

					foreach ($children as &$child) {
						$child['retiring'] = $now;
						proc_terminate($child['process'], SIGTERM);
					}
					unset($child);
				} catch (Throwable $error) {
					fwrite(STDERR, "Invalid reload; keeping previous worker settings.\n");
				}
				$reload = false;
			}

			if ($stop && $stopping_at === null) {
				$stopping_at = $now;

				foreach ($children as &$child) {
					$child['retiring'] = $now;
					proc_terminate($child['process'], SIGTERM);
				}
				unset($child);
			}

			foreach ($children as $key => $child) {
				$status = proc_get_status($child['process']);

				if (!$status['running']) {
					proc_close($child['process']);
					unset($children[$key]);

					if ($child['retiring'] === null) {
						$failures      = $now - $child['started'] >= 30 ? 1 : min(6, ($backoff[$key]['failures'] ?? 0) + 1);
						$backoff[$key] = ['failures' => $failures, 'ready_at' => $now + min(30, $settings['respawn_base_ms'] / 1000 * 2 ** ($failures - 1))];
					} else {
						unset($backoff[$key]);
					}
				} elseif ($child['retiring'] !== null && $now - $child['retiring'] >= $settings['shutdown_seconds']) {
					proc_terminate($child['process'], SIGKILL);
				}
			}

			if ($stop && !$children) {
				break;
			}

			if (!$stop) {
				foreach (['processing' => $settings['processing_workers'], 'delivery' => $settings['delivery_workers']] as $role => $count) {
					// Retiring processes also count toward the pool bound during reload.
					$existing = count(array_filter($children, static fn ($child) => $child['role'] === $role));

					for ($slot = 0; $slot < $count && $existing < $count; $slot++) {
						$key = $role . ':' . $slot;

						if (isset($children[$key]) || ($backoff[$key]['ready_at'] ?? 0) > $now) {
							continue;
						}
						$command = [PHP_BINARY, $script, '--role=' . $role, '--lease-seconds=' . $settings['lease_seconds'], '--idle-ms=' . $settings['idle_ms']];
						$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);

						if (!is_resource($process)) {
							throw new RuntimeException('worker_spawn_failed');
						}
						$children[$key] = ['process' => $process, 'role' => $role, 'started' => $now, 'retiring' => null];
						$existing++;
					}
				}
			}
			usleep(100000);
		}
	} finally {
		// An unrecoverable parent error must not leave orphan worker processes.
		foreach ($children as $child) {
			proc_terminate($child['process'], SIGKILL);
			proc_close($child['process']);
		}
	}
}
