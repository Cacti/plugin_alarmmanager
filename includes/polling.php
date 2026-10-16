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
		$started = microtime(true);
		$devices = alarmmanager_device_status_check();
		if (alarmmanager_supervisor_options()['enabled']) {
			return;
		}

		$counts = [];

		foreach (['processing', 'delivery'] as $role) {
			$counts[$role] = alarmmanager_worker_loop($role, alarmmanager_poller_budget() + ['once' => true, 'worker_id' => alarmmanager_poller_worker_id($role)], static fn (): bool => false);
		}
		cacti_log(sprintf('ALARMMANAGER STATS: Time:%.4f Mode:poller Processing:%d Delivery:%d Devices:%d', microtime(true) - $started, $counts['processing'], $counts['delivery'], $devices), false, 'SYSTEM');
	} catch (Throwable $error) {
		alarmmanager_log('poller drain failed: ' . $error->getMessage());
	}
}
