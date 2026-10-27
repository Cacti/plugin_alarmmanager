<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class OperationalLoggingTest extends AlarmManagerTestCase {
	/** A minimal, always-succeeding transaction/query harness for alarmmanager_finish_delivery(). */
	private function withDeliveryHarness(?array $job, ?array $eventPayload): void {
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn ($sql) => 0;
		$GLOBALS['alarmmanager_test_execute_handler'] = static fn ($sql, $params = []): bool => true;
		$GLOBALS['alarmmanager_test_query_handler']   = static function ($sql, $params = []) use ($job, $eventPayload) {
			if (str_contains($sql, 'FROM plugin_alarmmanager_deliveries WHERE id')) {
				return $job ? [$job] : [];
			}

			if (str_contains($sql, 'SELECT payload FROM plugin_alarmmanager_events')) {
				return $eventPayload === null ? [] : [['payload' => json_encode($eventPayload)]];
			}

			return [];
		};
	}

	private function claim(int $attempts = 1): array {
		return ['id'                       => 1, 'event_row_id' => 7, 'lease_token' => 'tok', 'attempts' => $attempts, 'idempotency_key' => 'k',
			'endpoint'                        => ['id' => 5, 'name' => 'Ops SMS', 'type' => 'restapi', 'max_attempts' => 3]];
	}

	public function testSuccessfulDeliveryLogsAPlainLanguageLine(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_log']         = [];
		$GLOBALS['alarmmanager_test_log_modules'] = [];
		$this->withDeliveryHarness(['id' => 1, 'attempts' => 1], ['device' => ['hostname' => 'core01']]);
		$this->assertTrue(alarmmanager_finish_delivery($this->claim(), ['outcome' => 'success', 'code' => 'http_200']));
		$this->assertSame(['- Processed alarm for core01. Alarm sent to Ops SMS.'], $GLOBALS['alarmmanager_test_log']);
		$this->assertSame(['Alarm Manager'], $GLOBALS['alarmmanager_test_log_modules']);
	}

	public function testTerminalFailureLogsAnError(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_log'] = [];
		$this->withDeliveryHarness(['id' => 1, 'attempts' => 1], ['device' => ['hostname' => 'core01']]);
		$this->assertTrue(alarmmanager_finish_delivery($this->claim(), ['outcome' => 'failure', 'code' => 'http_502']));
		$this->assertSame(['ERROR: Alarm delivery failed for core01 to Ops SMS: http_502'], $GLOBALS['alarmmanager_test_log']);
	}

	public function testExhaustedRetriesAlsoLogAnError(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_log'] = [];
		// attempts already at max_attempts (3): one more retry outcome is terminal.
		$this->withDeliveryHarness(['id' => 1, 'attempts' => 3], ['device' => ['hostname' => 'core01']]);
		$this->assertTrue(alarmmanager_finish_delivery($this->claim(3), ['outcome' => 'retry', 'code' => 'transport_timeout']));
		$this->assertSame(['ERROR: Alarm delivery failed for core01 to Ops SMS: transport_timeout'], $GLOBALS['alarmmanager_test_log']);
	}

	public function testARetryThatCanStillBeAttemptedAgainStaysQuiet(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_log'] = [];
		$this->withDeliveryHarness(['id' => 1, 'attempts' => 1], ['device' => ['hostname' => 'core01']]);
		$this->assertTrue(alarmmanager_finish_delivery($this->claim(1), ['outcome' => 'retry', 'code' => 'transport_timeout']));
		$this->assertSame([], $GLOBALS['alarmmanager_test_log'], 'A transient retry is not yet a failure worth an operator-facing line.');
	}

	public function testAnAlreadyHandledJobLogsNothing(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_log'] = [];
		// No row: another worker (or an expired lease) already finished this delivery.
		$this->withDeliveryHarness(null, ['device' => ['hostname' => 'core01']]);
		$this->assertFalse(alarmmanager_finish_delivery($this->claim(), ['outcome' => 'success', 'code' => 'http_200']));
		$this->assertSame([], $GLOBALS['alarmmanager_test_log']);
	}

	public function testMissingDeviceContextFallsBackToReadablePlaceholders(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_log'] = [];
		$this->withDeliveryHarness(['id' => 1, 'attempts' => 1], null);
		$this->assertTrue(alarmmanager_finish_delivery($this->claim(), ['outcome' => 'success', 'code' => 'http_200']));
		$this->assertSame(['- Processed alarm for an unknown device. Alarm sent to Ops SMS.'], $GLOBALS['alarmmanager_test_log']);
	}

	public function testACourtesyLogFailureNeverRisksTheDeliveryOutcomeItDescribes(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_cell_handler']    = static fn ($sql) => 0;
		$GLOBALS['alarmmanager_test_execute_handler'] = static fn ($sql, $params = []): bool => true;
		$GLOBALS['alarmmanager_test_query_handler']   = static function ($sql) {
			if (str_contains($sql, 'FROM plugin_alarmmanager_deliveries WHERE id')) {
				return [['id' => 1, 'attempts' => 1]];
			}

			if (str_contains($sql, 'SELECT payload FROM plugin_alarmmanager_events')) {
				throw new RuntimeException('storage_failure');
			}

			return [];
		};
		$this->assertTrue(alarmmanager_finish_delivery($this->claim(), ['outcome' => 'success', 'code' => 'http_200']), 'The real delivery outcome is recorded before the courtesy log is even attempted.');
	}

	public function testQueueWaitingCountReflectsThePendingRowCount(): void {
		self::alarmmanager_test_load('includes/queue.php');
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => str_contains($sql, "status = 'pending'") ? [['waiting' => 4]] : [];
		$this->assertSame(4, alarmmanager_queue_waiting_count());
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => [];
		$this->assertSame(0, alarmmanager_queue_waiting_count(), 'No row at all still reads as zero waiting.');
	}

	public function testQueuePauseLogsAWarningAndResumeDoesNot(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$before = [$_SESSION ?? [], $_SERVER['REQUEST_METHOD'] ?? null, $GLOBALS['alarmmanager_test_config_options'] ?? [], $GLOBALS['alarmmanager_test_allowed_realms'] ?? []];

		try {
			$_SESSION['sess_user_id']                     = 9;
			$_SERVER['REQUEST_METHOD']                    = 'POST';
			$GLOBALS['alarmmanager_test_config_options']  = ['auth_method' => 1];
			$GLOBALS['alarmmanager_test_allowed_realms']  = [1, 101];
			$GLOBALS['alarmmanager_test_csrf_valid']      = true;
			$GLOBALS['alarmmanager_test_execute_handler'] = static fn ($sql, $params = []): bool => true;
			$GLOBALS['alarmmanager_test_query_handler']   = static fn ($sql) => str_contains($sql, 'plugin_realms') ? [['id' => 1]] : [];
			$GLOBALS['alarmmanager_test_log']             = [];
			alarmmanager_admin_action(['action' => 'queue_pause', 'revision' => 0]);
			alarmmanager_admin_action(['action' => 'queue_resume', 'revision' => 0]);
			$this->assertSame(['WARNING: Alarm Queue paused by user 9', 'Alarm Queue resumed by user 9'], $GLOBALS['alarmmanager_test_log']);
		} finally {
			[$_SESSION, $_SERVER['REQUEST_METHOD'], $GLOBALS['alarmmanager_test_config_options'], $GLOBALS['alarmmanager_test_allowed_realms']] = $before;
			unset($GLOBALS['alarmmanager_test_csrf_valid']);
		}
	}

	public function testQueueSectionMarkupShowsTheWaitingCount(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'unit-test';
			}
		}

		// Not stubbed globally on this branch: the 'queue' section is otherwise untested.
		if (!function_exists('html_start_box')) {
			function html_start_box(...$args) {
				print '<table>';
			}
			function html_end_box(...$args) {
				print '</table>';
			}
			function html_header(...$args) {
			}
		}
		$GLOBALS['alarmmanager_test_query_handler'] = static fn ($sql) => str_contains($sql, 'COUNT(*) AS waiting') ? [['waiting' => 3]] : [];
		ob_start();

		try {
			alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [], 'rules' => []]], 'queue');
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertStringContainsString('Waiting messages: 3', $html);
	}
}
