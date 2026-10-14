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

final class PollingTest extends AlarmManagerTestCase {
	public function testPollerBudgetIsBounded(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$budget = alarmmanager_poller_budget();

		$this->assertSame(['max_jobs' => 500, 'max_seconds' => 60, 'lease_seconds' => 180, 'idle_ms' => 100], $budget, 'Poller drain budget drifted from the bounded defaults.');
	}

	public function testPollerWorkerIdentityIsDeterministic(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$processing = alarmmanager_poller_worker_id('processing');
		$delivery   = alarmmanager_poller_worker_id('delivery');

		$this->assertSame(32, strlen($processing), 'Poller worker identity must fit the CHAR(32) heartbeat key.');
		$this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $processing, 'Poller worker identity must be 32 hex characters.');
		$this->assertSame($processing, alarmmanager_poller_worker_id('processing'), 'Poller worker identity must upsert, not accumulate.');
		$this->assertNotSame($processing, $delivery, 'Roles must not share a heartbeat identity.');
	}

	public function testPollerBottomSkipsDisabledRuntime(): void {
		self::alarmmanager_test_load('includes/polling.php');
		$GLOBALS['alarmmanager_test_status'] = 0;
		alarmmanager_poller_bottom(['poller_bottom']);

		$this->assertSame([], $GLOBALS['alarmmanager_test_workers'], 'Disabled runtime must not drain or heartbeat.');
	}

	public function testPollerBottomDrainsBothRoles(): void {
		self::alarmmanager_test_load('includes/polling.php');
		alarmmanager_poller_bottom(['poller_bottom']);
		$this->assertMatchesRegularExpression('/SYSTEM ALARMMANAGER STATS: Mode:poller Time:[0-9.]+ Processing:0 Delivery:0/', end($GLOBALS['alarmmanager_test_log']));
		$workers = $GLOBALS['alarmmanager_test_workers'];
		ksort($workers);

		$this->assertSame([alarmmanager_poller_worker_id('delivery'), alarmmanager_poller_worker_id('processing')], array_keys($workers), 'Each role must drain once under its deterministic identity.');
		$this->assertSame('delivery', $workers[alarmmanager_poller_worker_id('delivery')]['role'], 'Delivery heartbeat must carry its role.');
		$this->assertSame('stopped', $workers[alarmmanager_poller_worker_id('delivery')]['status'], 'Delivery drain must finish with a stopped heartbeat.');
		$this->assertSame('processing', $workers[alarmmanager_poller_worker_id('processing')]['role'], 'Processing heartbeat must carry its role.');
		$this->assertSame('stopped', $workers[alarmmanager_poller_worker_id('processing')]['status'], 'Processing drain must finish with a stopped heartbeat.');
	}
}
