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

final class TholdBranchesTest extends AlarmManagerTestCase {
	private static bool $pinned_loaded = false;

	private static function loadDoubles(): void {
		require_once __DIR__ . '/../../Helpers/ProducersDoubles.php';
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function requirePinnedTholdCheckout(): array {
		$reference = (string) (getenv('ALARMMANAGER_THOLD_ROOT') ?: '');
		$patched   = (string) (getenv('ALARMMANAGER_THOLD_PATCHED_ROOT') ?: '');

		if ($reference === '' || $patched === '' || !is_dir($reference) || !is_dir($patched) || !is_file($reference . '/tests/Helpers/CactiStubs.php') || !is_file($reference . '/tests/Helpers/ThresholdOutcome.php') || !is_file($reference . '/tests/Helpers/ThresholdScenario.php') || !is_file($reference . '/tests/bootstrap-unit.php') || !is_file($patched . '/includes/functions.php')) {
			$this->markTestSkipped('Pinned thold checkouts unavailable; branch test skipped.');
		}

		return [$reference, $patched];
	}

	private function bootstrapPinnedThold(string $reference, string $patched): void {
		if (self::$pinned_loaded) {
			return;
		}
		self::loadDoubles();

		// Use upstream characterization fixtures without PHPUnit; all Cacti boundaries are recorded doubles.
		require_once($reference . '/tests/Helpers/CactiStubs.php');
		require_once($reference . '/tests/Helpers/ThresholdOutcome.php');
		require_once($reference . '/tests/Helpers/ThresholdScenario.php');
		$cacti_root    = dirname($patched, 2);
		$cacti_version = '1.2.32';
		$bootstrap     = file_get_contents($reference . '/tests/bootstrap-unit.php');
		$marker        = strpos($bootstrap, "if (!defined('POLLER_VERBOSITY_MEDIUM'))");

		if ($marker === false) {
			throw new RuntimeException('Pinned thold bootstrap marker is missing.');
		}
		$bootstrap = substr($bootstrap, $marker);
		$bootstrap = str_replace('__DIR__', var_export($reference . '/tests', true), $bootstrap);
		eval($bootstrap);
		require_once($patched . '/includes/functions.php');
		require_once($reference . '/includes/settings.php');
		thold_config_insert();
		self::$pinned_loaded = true;
	}

	private function scenario(array $data, array $channels = [], bool $owned = false, array $options = []): ThresholdOutcome {
		CactiStubs::reset();
		AlarmManagerProducerBridge::$calls    = [];
		AlarmManagerProducerBridge::$decision = ['owned' => $owned, 'channels' => $channels];
		$GLOBALS['config']['base_path']       = dirname(getenv('ALARMMANAGER_THOLD_PATCHED_ROOT'), 2);
		$GLOBALS['config']['library_path']    = getenv('ALARMMANAGER_THOLD_ROOT') . '/tests/Fixtures/cacti-lib';

		foreach ($options as $name => $value) {
			CactiStubs::$configOptions[$name] = $value;
		}
		$scenario = ThresholdScenario::threshold($data + ['lasttime' => 1791892800, 'thold_hi' => 90, 'thold_low' => 10, 'thold_warning_hi' => 80, 'thold_warning_low' => 20])->alertRecipient('ops@example.test');

		if ((int) ($data['thold_type'] ?? 0) === 1) {
			$scenario->referenceStatistics(100, 100, 100, 100);
		}

		return $scenario->poll();
	}

	public function testLegacyAlertMailAndLog(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$legacy = $this->scenario(['lastread' => 95]);

		$this->assertSame(1, $legacy->mailCount(), 'legacy alert mail');
		$this->assertSame([ST_NOTIFYAL], $legacy->logStatuses(), 'legacy alert log');
	}

	public function testCentralHandoffPreservesSourceLog(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$legacyLogs = $this->scenario(['lastread' => 95])->logStatuses();
		$central    = $this->scenario(['lastread' => 95], ['email'], true);

		$this->assertSame(0, $central->mailCount(), 'central handoff mail skipped');
		$this->assertSame($legacyLogs, $central->logStatuses(), 'central source log preserved');
		$this->assertSame(1, count(AlarmManagerProducerBridge::$calls), 'one submission per branch');
		$input = AlarmManagerProducerBridge::$calls[0]['input'];
		$this->assertTrue($input['transition'] === 'raised' && $input['severity'] === 'major', 'normalized alert');
		$this->assertSame(['hostname', 'description', 'location', 'contact', 'sysname', 'tags', 'id'], array_keys($input['device']), 'safe device allowlist');
	}

	public function testUnselectedChannelRetainsSourceMail(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$unselected = $this->scenario(['lastread' => 95], ['script'], true);

		$this->assertSame(1, $unselected->mailCount(), 'unselected mail retained');
	}

	public function testWarningHandoff(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$warn = $this->scenario(['lastread' => 85], ['email'], true);

		$this->assertSame(0, $warn->mailCount(), 'warning handoff');
		$this->assertSame('warning', AlarmManagerProducerBridge::$calls[0]['input']['severity'], 'warning severity');
	}

	public function testRecoveryHandoff(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$recover = $this->scenario(['lastread' => 50, 'thold_alert' => 1, 'thold_fail_count' => 2], ['email'], true);

		$this->assertSame(0, $recover->mailCount(), 'recovery handoff');
		$this->assertSame('recovered', AlarmManagerProducerBridge::$calls[0]['input']['transition'], 'recovery normalized');
		$this->assertTrue(in_array(ST_NOTIFYRS, $recover->logStatuses(), true), 'recovery log retained');
	}

	public function testRepeatRetained(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$this->scenario(['lastread' => 95, 'thold_fail_count' => 1, 'repeat_alert' => 2], ['email'], true);

		$this->assertSame(true, AlarmManagerProducerBridge::$calls[0]['input']['repeat'], 'repeat retained');
	}

	public function testAcknowledgmentRetained(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$ack = $this->scenario(['lastread' => 95, 'acknowledgment' => 'on'], ['email'], true);

		$this->assertSame(0, $ack->mailCount(), 'source acknowledgment retained');
		$this->assertTrue(AlarmManagerProducerBridge::$calls[0]['input']['suppressed'], 'ack metadata');
	}

	public function testMissingSampleIdentityLegacyFallback(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$sample = $this->scenario(['lastread' => 95, 'lasttime' => 0], ['email'], true);

		$this->assertTrue($sample->mailCount() === 1 && AlarmManagerProducerBridge::$calls === [], 'missing sample identity legacy fallback');
	}

	public function testDisabledRecoveryRemainsSilent(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$disabledRecovery = $this->scenario(['lastread' => 50, 'thold_alert' => 1, 'thold_fail_count' => 2, 'restored_alert' => 'on'], ['email'], true);

		$this->assertSame(0, $disabledRecovery->mailCount(), 'disabled recovery remains silent');
		$this->assertTrue(AlarmManagerProducerBridge::$calls[0]['input']['transition'] === 'recovered' && AlarmManagerProducerBridge::$calls[0]['input']['suppressed'], 'disabled recovery closes central condition');
	}

	public function testBaselineAndTimeBasedHandoffParity(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);

		foreach ([['thold_type' => 1, 'lastread' => 500, 'bl_type' => 0, 'bl_pct_up' => 10, 'bl_pct_down' => 10, 'bl_ref_time_range' => 3600, 'bl_fail_trigger' => 1], ['thold_type' => 1, 'lastread' => 100, 'bl_alert' => STAT_HI, 'bl_fail_count' => 3, 'bl_type' => 0, 'bl_pct_up' => 10, 'bl_pct_down' => 10, 'bl_ref_time_range' => 3600, 'bl_fail_trigger' => 1], ['thold_type' => 2, 'lastread' => 95, 'time_hi' => 90, 'time_low' => 10, 'time_fail_trigger' => 1]] as $data) {
			$before      = $this->scenario($data);
			$beforeCount = $before->mailCount();
			$beforeLogs  = $before->logStatuses();
			$after       = $this->scenario($data, ['email'], true);

			$this->assertTrue($beforeCount === 1 && $after->mailCount() === 0, 'baseline/time handoff');
			$this->assertSame($beforeLogs, $after->logStatuses(), 'baseline/time source log parity');
		}
	}

	public function testWarningTransitionRecoveryBranches(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);

		foreach ([
			['data' => ['lastread' => 85, 'thold_alert' => 1, 'thold_fail_count' => 1, 'thold_warning_fail_count' => 1], 'class' => 'alert2warn'],
			['data' => ['lastread' => 50, 'thold_alert' => 1, 'thold_warning_fail_count' => 2], 'class' => 'warn2normal'],
			['data' => ['thold_type' => 2, 'lastread' => 85, 'time_hi' => 90, 'time_low' => 10, 'time_warning_hi' => 80, 'time_warning_low' => 20, 'time_warning_fail_trigger' => 1], 'class' => 'warn'],
			['data' => ['thold_type' => 2, 'lastread' => 50, 'thold_alert' => 1, 'time_hi' => 90, 'time_low' => 10, 'time_warning_fail_trigger' => 0], 'class' => 'warn2normal'],
			['data' => ['thold_type' => 2, 'lastread' => 50, 'thold_alert' => 1, 'time_hi' => 90, 'time_low' => 10, 'time_fail_trigger' => 0, 'time_warning_fail_trigger' => 1], 'class' => 'alert2normal']
		] as $case) {
			$this->scenario($case['data'], ['email'], true);

			$this->assertSame($case['class'], AlarmManagerProducerBridge::$calls[0]['input']['rule_type'] ?? null, 'warning transition/recovery branch ' . $case['class']);
		}
	}

	public function testSelectedScriptSkippedWhileMailQueueRetained(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);
		$scriptData     = ['lastread' => 95, 'trigger_cmd_high' => '/bin/echo fixture'];
		$scriptSettings = ['thold_enable_scripts' => 'on', 'thold_notification_queue' => 'on'];
		$this->scenario($scriptData, [], false, $scriptSettings);
		$before = count(array_filter(CactiStubs::callsTo('db_execute_prepared'), fn ($c) => str_contains($c['sql'], 'INSERT INTO notification_queue')));
		$this->scenario($scriptData, ['script'], true, $scriptSettings);
		$after = count(array_filter(CactiStubs::callsTo('db_execute_prepared'), fn ($c) => str_contains($c['sql'], 'INSERT INTO notification_queue')));

		$this->assertSame($after + 1, $before, 'selected source script skipped while mail queue retained');
	}

	public function testAllElevenSourceBranchesPatched(): void {
		[$reference, $patched] = $this->requirePinnedTholdCheckout();
		$this->bootstrapPinnedThold($reference, $patched);

		// All patched send branches must reach handoff before any original side effects.
		$text = file_get_contents($patched . '/includes/functions.php');

		$this->assertSame(11, substr_count($text, '$alarmmanager_delivery = alarmmanager_thold_begin('), 'all eleven source branches patched');
	}
}
