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
require_once(__DIR__ . '/workers.php');
require_once(__DIR__ . '/supervisor.php');
require_once(__DIR__ . '/device.php');
require_once(__DIR__ . '/schema.php');

/** Bounded per-role drain options for the poller-integrated mode, matching alarmmanager_worker_loop()'s contract.
 * @return array{max_jobs: int, max_seconds: int, lease_seconds: int, idle_ms: int}
 */
function alarmmanager_poller_budget(): array {
	// Bounded inline drain at the tail of each Cacti poller cycle: two 60
	// second budgets stay well inside the 300 second default interval.
	// Backlogs that outgrow this budget belong on the resident daemon.
	return ['max_jobs' => 500, 'max_seconds' => 60, 'lease_seconds' => 180, 'idle_ms' => 100];
}

function alarmmanager_poller_worker_id(string $role): string {
	// Deterministic heartbeat identity so repeated cycles upsert the same
	// plugin_alarmmanager_workers rows instead of accumulating new ones,
	// and so coexistence with an idle daemon worker stays auditable.
	return substr(hash('sha256', 'poller-' . alarmmanager_local_poller() . '-' . $role), 0, 32);
}

/** Cacti poller_bottom hook: drain bounded processing/delivery work at the tail of each poller cycle.
 * @param array<int, string> $args Hook argument list as Cacti passes it ('poller_bottom').
 */
function alarmmanager_poller_bottom(array $args = []): void {
	global $config;

	// poller.php bootstraps Cacti before hooks fire; the drain must never
	// break a cycle, so every failure is logged and swallowed.
	if (empty($config) || alarmmanager_runtime_enabled() !== true) {
		return;
	}

	try {
		// A collector missing its schema recreates it before the drain; DDL
		// also fixes partial replication that disabled remote tables.
		if (!alarmmanager_schema_ensure()) {
			return;
		}

		try {
			alarmmanager_daemon_sync();
		} catch (Throwable $error) {
			alarmmanager_log('daemon settings could not be applied; previous local settings retained');
		}
		$started = microtime(true);
		$devices = alarmmanager_device_status_check();

		try {
			alarmmanager_history_purge();
		} catch (Throwable $error) {
			alarmmanager_log('history purge failed; aged history retained');
		}

		if (alarmmanager_supervisor_options()['enabled'] && (alarmmanager_local_poller() === 1 || alarmmanager_daemon_status())) {
			return;
		}

		$counts = [];

		foreach (['processing', 'delivery'] as $role) {
			$counts[$role] = alarmmanager_worker_loop($role, alarmmanager_poller_budget() + ['once' => true, 'worker_id' => alarmmanager_poller_worker_id($role)], static fn (): bool => false);
		}
		cacti_log(sprintf('ALARMMANAGER STATS: Time:%.4f Mode:poller Processing:%d Delivery:%d Devices:%d', microtime(true) - $started, $counts['processing'], $counts['delivery'], $devices), false, 'SYSTEM');
	} catch (Throwable $error) {
		if ($error instanceof \AlarmManager\MissingTableException && alarmmanager_schema_ensure()) {
			alarmmanager_log('missing plugin tables recreated; processing resumes next cycle');
		} else {
			alarmmanager_log('poller drain failed: ' . $error->getMessage());
		}
	} finally {
		// Sync is independent of delivery and also runs when the daemon owns the local queues.
		if (alarmmanager_local_poller() > 1) {
			try {
				require_once(__DIR__ . '/remote.php');
				alarmmanager_sync_send(500);
			} catch (Throwable $error) {
				alarmmanager_log('collector history sync failed; pending history retained');
			}
		}
	}
}

/** A worker count setting; an unset option keeps the current value, and 0 is a valid delivery count. */
function alarmmanager_daemon_workers(string $name, int $current): int {
	$value = read_config_option($name, true);

	return is_numeric($value) ? (int) $value : $current;
}

/**
 * Write the daemon's workers.json from the Cacti settings (replicated natively to collectors) when they differ.
 * The daemon never bootstraps Cacti, so the file stays its only input; tuning values already in it are kept.
 */
function alarmmanager_daemon_sync(): void {
	try {
		$current = alarmmanager_supervisor_options();
	} catch (Throwable $error) {
		$current = alarmmanager_supervisor_defaults();
	}
	$desired = array_replace($current, [
		'enabled'            => read_config_option('alarmmanager_daemon_enabled', true) === 'on',
		'processing_workers' => alarmmanager_daemon_workers('alarmmanager_daemon_processing_workers', $current['processing_workers']),
		'delivery_workers'   => alarmmanager_daemon_workers('alarmmanager_daemon_delivery_workers', $current['delivery_workers'])
	]);

	if (is_file(alarmmanager_daemon_config_path()) ? $desired !== $current : $desired['enabled']) {
		alarmmanager_save_supervisor_options($desired);
	}
}

/** Cacti global_settings_update hook: apply Alarm Manager daemon settings after the Settings tab is saved. */
function alarmmanager_settings_update(): void {
	if (get_nfilter_request_var('tab') !== 'alarmmanager') {
		return;
	}

	if (read_config_option('alarmmanager_daemon_enabled', true) === 'on' && !alarmmanager_daemon_status()) {
		set_config_option('alarmmanager_daemon_enabled', '');
		raise_message('alarmmanager_daemon', __('Start the Alarm Manager service on this collector before enabling daemon workers.', 'alarmmanager'), MESSAGE_LEVEL_ERROR);
	}

	try {
		alarmmanager_daemon_sync();
	} catch (Throwable $error) {
		alarmmanager_log('daemon settings could not be applied');
		raise_message('alarmmanager_daemon', __('Daemon settings could not be saved. Check the Cacti cache directory permissions.', 'alarmmanager'), MESSAGE_LEVEL_ERROR);
	}
}
