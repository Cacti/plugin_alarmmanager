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
/** Fresh child, no shell, clean environment, bounded pipes/runtime and group cleanup. */
function alarmmanager_transport_process(array $arguments, string $input, int $timeout, array $environment = []): array {
	if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
		throw new RuntimeException('process_transport_unavailable');
	}
	$process = proc_open(['/usr/bin/setsid', '--', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $environment + ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8']);

	if (!is_resource($process)) {
		throw new RuntimeException('process_start_failed');
	}
	$status   = proc_get_status($process);
	$pid      = $status['pid'];
	$offset   = 0;
	$bytes    = 0;
	$stdout   = '';
	$deadline = hrtime(true) + $timeout * 1000000000;

	foreach ($pipes as $pipe) {
		stream_set_blocking($pipe, false);
	}

	try {
		while (true) {
			foreach ([1, 2] as $descriptor) {
				$chunk = fread($pipes[$descriptor], 8192);
				$chunk = $chunk === false ? '' : $chunk;
				$bytes += strlen($chunk);

				if ($descriptor === 1) {
					$stdout .= $chunk;
				}
			}

			if ($bytes > 65536) {
				return ['code' => 'output_limit', 'exit_code' => -1, 'stdout' => ''];
			}
			$status = proc_get_status($process);

			if (!$status['running']) {
				// Drain the final buffered output before applying the same output bound.
				foreach ([1, 2] as $descriptor) {
					$chunk = stream_get_contents($pipes[$descriptor], 65537);
					$chunk = $chunk === false ? '' : $chunk;
					$bytes += strlen($chunk);

					if ($descriptor === 1) {
						$stdout .= $chunk;
					}
				}

				return $bytes > 65536 ? ['code' => 'output_limit', 'exit_code' => -1, 'stdout' => ''] : ['code' => 'exited', 'exit_code' => $status['exitcode'], 'stdout' => $stdout];
			}

			if (hrtime(true) >= $deadline) {
				return ['code' => 'timeout', 'exit_code' => -1, 'stdout' => ''];
			}

			if (isset($pipes[0])) {
				if ($offset < strlen($input)) {
					$written = @fwrite($pipes[0], substr($input, $offset, 8192));

					if ($written === false) {
						fclose($pipes[0]);
						unset($pipes[0]);
					} else {
						$offset += $written;
					}
				}

				if (isset($pipes[0]) && $offset >= strlen($input)) {
					fclose($pipes[0]);
					unset($pipes[0]);
				}
			}
			usleep(10000);
		}
	} finally {
		@posix_kill(-$pid, SIGKILL);
		@proc_terminate($process, SIGKILL);

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		proc_close($process);
	}
}
