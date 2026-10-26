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
function alarmmanager_daemon_config_path(): string {
	global $config;

	return ($config['base_path'] ?? (getenv('CACTI_PATH') ?: dirname(__DIR__, 3))) . '/cache/alarmmanager/workers.json';
}

function alarmmanager_supervisor_options(?string $path = null): array {
	$path ??= alarmmanager_daemon_config_path();
	$settings = ['enabled' => false, 'processing_workers' => 2, 'delivery_workers' => 4, 'lease_seconds' => 180, 'idle_ms' => 250, 'shutdown_seconds' => 180, 'respawn_base_ms' => 1000];

	if (is_file($path) || $path !== alarmmanager_daemon_config_path()) {
		$data = @file_get_contents($path, false, null, 0, 65537);

		if ($data === false || strlen($data) > 65536) {
			throw new RuntimeException('invalid_supervisor_config');
		}
		$decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);

		if (!is_array($decoded) || array_diff(array_keys($decoded), array_keys($settings))) {
			throw new RuntimeException('invalid_supervisor_config');
		}
		$settings = array_replace($settings, ['enabled' => true], $decoded);
	}

	alarmmanager_supervisor_validate($settings);

	return $settings;
}

/** @param array<string, mixed> $settings */
function alarmmanager_supervisor_validate(array $settings): void {
	if (!is_bool($settings['enabled'] ?? null) || array_diff(array_keys($settings), ['enabled', 'processing_workers', 'delivery_workers', 'lease_seconds', 'idle_ms', 'shutdown_seconds', 'respawn_base_ms'])) {
		throw new RuntimeException('invalid_supervisor_config');
	}

	foreach (['processing_workers' => [1, 64], 'delivery_workers' => [0, 64], 'lease_seconds' => [15, 600], 'idle_ms' => [10, 10000], 'shutdown_seconds' => [1, 600], 'respawn_base_ms' => [100, 30000]] as $key => [$min, $max]) {
		if (!is_int($settings[$key] ?? null) || $settings[$key] < $min || $settings[$key] > $max) {
			throw new RuntimeException('invalid_supervisor_config');
		}
	}
}

/** @param array<string, mixed> $data */
function alarmmanager_daemon_write_json(string $path, array $data): void {
	$directory = dirname($path);

	if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
		throw new RuntimeException('daemon_config_write_failed');
	}
	$temp = @tempnam($directory, '.daemon-');

	if ($temp === false) {
		throw new RuntimeException('daemon_config_write_failed');
	}

	try {
		$json = json_encode($data, JSON_THROW_ON_ERROR);

		if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@rename($temp, $path)) {
			throw new RuntimeException('daemon_config_write_failed');
		}
	} finally {
		if (is_file($temp)) {
			unlink($temp);
		}
	}
}

/** @param array<string, mixed> $settings */
function alarmmanager_save_supervisor_options(array $settings): void {
	alarmmanager_supervisor_validate($settings);
	alarmmanager_daemon_write_json(alarmmanager_daemon_config_path(), $settings);
}

/** @return array<string, mixed> */
function alarmmanager_daemon_status(): array {
	$path = dirname(alarmmanager_daemon_config_path()) . '/status.json';

	if (!is_file($path)) {
		return [];
	}
	$data = @file_get_contents($path, false, null, 0, 4097);

	try {
		$status = $data !== false && strlen($data) <= 4096 ? json_decode($data, true, 8, JSON_THROW_ON_ERROR) : [];
	} catch (JsonException $error) {
		return [];
	}

	return is_array($status) && isset($status['updated_at']) && time() - (int) $status['updated_at'] >= 0 && time() - (int) $status['updated_at'] <= 10 ? $status : [];
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
	$next_check  = 0;
	$managed     = $config_path === null || $config_path === alarmmanager_daemon_config_path();
	pcntl_signal(SIGCHLD, SIG_DFL);
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
	pcntl_signal(SIGINT, function () use (&$stop): void { $stop = true; });
	pcntl_signal(SIGHUP, function () use (&$reload): void { $reload = true; });

	try {
		while (true) {
			$now = hrtime(true) / 1000000000;

			if (!$stop && ($reload || $now >= $next_check)) {
				try {
					$next     = alarmmanager_supervisor_options($config_path);

					if ($reload || $next !== $settings) {
						$settings = $next;

						foreach ($children as &$child) {
							$child['retiring'] = $now;
							proc_terminate($child['process'], SIGTERM);
						}
						unset($child);
					}
				} catch (Throwable $error) {
					fwrite(STDERR, "Invalid reload; keeping previous worker settings.\n");
				}
				$next_check = $now + 2;

				if ($managed) {
					try {
						alarmmanager_daemon_write_json(dirname(alarmmanager_daemon_config_path()) . '/status.json', ['updated_at' => time(), 'enabled' => $settings['enabled'], 'processing_workers' => count(array_filter($children, static fn ($child) => $child['role'] === 'processing' && $child['retiring'] === null)), 'delivery_workers' => count(array_filter($children, static fn ($child) => $child['role'] === 'delivery' && $child['retiring'] === null))]);
					} catch (Throwable $error) {
						fwrite(STDERR, "Daemon status could not be written.\n");
					}
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

			if (!$stop && $settings['enabled']) {
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
