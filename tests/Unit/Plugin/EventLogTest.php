<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class EventLogTest extends AlarmManagerTestCase {
	private function sampleEvent(): array {
		return ['occurred_at'  => '2026-10-27 03:00:00', 'event_id' => 42, 'source' => 'poller', 'transition' => 'raised', 'severity' => 'critical',
			'episode_id'          => 'ep-1', 'alarm_key' => 'disk', 'resource_id' => 'sda1', 'collector_id' => 1, 'summary' => 'disk /var 92%',
			'device'              => ['id' => 7, 'hostname' => 'core01', 'description' => 'Core switch', 'location' => 'Rack 7']];
	}

	public function testRecordMapsFixedFieldsAndFillsMissingOnesWithNull(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$record = alarmmanager_event_log_record($this->sampleEvent());
		$this->assertSame('2026-10-27 03:00:00', $record['occurred_at']);
		$this->assertSame(42, $record['event_id']);
		$this->assertSame('raised', $record['transition']);
		$this->assertSame('critical', $record['severity']);
		$this->assertSame('ep-1', $record['episode_id']);
		$this->assertSame('disk', $record['alarm_key']);
		$this->assertSame(['id' => 7, 'hostname' => 'core01', 'description' => 'Core switch', 'location' => 'Rack 7'], $record['device']);
		$this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $record['logged_at']);
		$this->assertSame(['logged_at'  => $record['logged_at'], 'occurred_at' => null, 'event_id' => null, 'source' => null, 'transition' => null, 'severity' => null,
			'episode_id'                   => null, 'alarm_key' => null, 'resource_id' => null, 'collector_id' => null, 'summary' => null,
			'device'                       => ['id' => null, 'hostname' => null, 'description' => null, 'location' => null]], alarmmanager_event_log_record([]));
	}

	public function testPathValidationRejectsUnsafeTargetsAndAcceptsAGoodOne(): void {
		self::alarmmanager_test_load('includes/eventlog.php');

		foreach (['relative/events.jsonl', '/nonexistent-am-test-root-xyz/events.jsonl'] as $bad) {
			try {
				alarmmanager_event_log_path_validate($bad);
				$this->fail("Accepted an unsafe path: $bad");
			} catch (RuntimeException $error) {
				$this->assertSame('invalid_event_log_path', $error->getMessage());
			}
		}
		$plugin_root = dirname(__DIR__, 3);

		try {
			alarmmanager_event_log_path_validate($plugin_root . '/includes/should-not-be-writable.jsonl');
			$this->fail('Accepted a path inside the plugin directory.');
		} catch (RuntimeException $error) {
			$this->assertSame('invalid_event_log_path', $error->getMessage());
		}
		$directory = sys_get_temp_dir() . '/am-eventlog-test-' . bin2hex(random_bytes(8));
		mkdir($directory);

		try {
			$real = $directory . '/real.jsonl';
			file_put_contents($real, '');
			$link = $directory . '/link.jsonl';
			symlink($real, $link);

			try {
				alarmmanager_event_log_path_validate($link);
				$this->fail('Accepted a symlink target.');
			} catch (RuntimeException $error) {
				$this->assertSame('invalid_event_log_path', $error->getMessage());
			}

			// A trailing separator makes is_link() follow a symlink-to-file, so it must be rejected.
			$linkdir = $directory . '/linkdir';
			symlink($real, $linkdir);

			foreach ([$linkdir . '/', $directory . '/'] as $trailing) {
				try {
					alarmmanager_event_log_path_validate($trailing);
					$this->fail("Accepted a trailing-separator path: $trailing");
				} catch (RuntimeException $error) {
					$this->assertSame('invalid_event_log_path', $error->getMessage());
				}
			}

			// An existing non-regular target (here a directory) would block or corrupt an append.
			mkdir($directory . '/nested');

			try {
				alarmmanager_event_log_path_validate($directory . '/nested');
				$this->fail('Accepted an existing non-regular target.');
			} catch (RuntimeException $error) {
				$this->assertSame('invalid_event_log_path', $error->getMessage());
			}

			// The forbidden Cacti root is the running base_path, not the plugin's ancestors.
			$before_base                    = $GLOBALS['config']['base_path'] ?? null;
			$GLOBALS['config']['base_path'] = $directory;

			try {
				alarmmanager_event_log_path_validate($directory . '/inside-web-root.jsonl');
				$this->fail('Accepted a path inside the Cacti base_path.');
			} catch (RuntimeException $error) {
				$this->assertSame('invalid_event_log_path', $error->getMessage());
			} finally {
				$GLOBALS['config']['base_path'] = $before_base;
			}
			$this->assertSame($directory . '/events.jsonl', alarmmanager_event_log_path_validate($directory . '/events.jsonl'));
		} finally {
			foreach (glob($directory . '/*') ?: [] as $entry) {
				if (is_dir($entry) && !is_link($entry)) {
					rmdir($entry);
				} else {
					unlink($entry);
				}
			}
			rmdir($directory);
		}
	}

	/** @return array{enabled: bool, target: string, path: ?string, max_bytes: int} */
	private function withEventLog(array $event_log): callable {
		return static fn ($sql, $params = []) => str_contains($sql, 'configuration_history')
			? [['definition' => json_encode(['event_log' => $event_log])]] : [];
	}

	public function testDisabledWritesNothing(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$GLOBALS['alarmmanager_test_log']           = [];
		$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => false, 'target' => 'cacti_log']);
		alarmmanager_event_log_write($this->sampleEvent());
		$this->assertSame([], $GLOBALS['alarmmanager_test_log']);
	}

	public function testCactiLogTargetWritesOneTaggedJsonLine(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$GLOBALS['alarmmanager_test_log']           = [];
		$GLOBALS['alarmmanager_test_log_modules']   = [];
		$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'cacti_log']);
		alarmmanager_event_log_write($this->sampleEvent());
		$this->assertCount(1, $GLOBALS['alarmmanager_test_log']);
		$this->assertSame('ALARMMANAGER-EVENT', $GLOBALS['alarmmanager_test_log_modules'][0]);
		$decoded = json_decode($GLOBALS['alarmmanager_test_log'][0], true, 16, JSON_THROW_ON_ERROR);
		$this->assertSame('disk', $decoded['alarm_key']);
	}

	public function testFileTargetAppendsJsonLinesAndRotatesPastTheSizeLimit(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$directory = sys_get_temp_dir() . '/am-eventlog-test-' . bin2hex(random_bytes(8));
		mkdir($directory);
		$path = $directory . '/events.jsonl';

		try {
			$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'file', 'path' => $path, 'max_bytes' => 1048576]);
			alarmmanager_event_log_write($this->sampleEvent());
			alarmmanager_event_log_write($this->sampleEvent());
			$lines = array_filter(explode("\n", (string) file_get_contents($path)));
			$this->assertCount(2, $lines);

			foreach ($lines as $line) {
				$this->assertSame('disk', json_decode($line, true, 16, JSON_THROW_ON_ERROR)['alarm_key']);
			}
			$this->assertFileDoesNotExist($path . '.1', 'No rotation yet below the size limit.');

			// A limit smaller than one line forces every write to rotate the previous content out.
			$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'file', 'path' => $path, 'max_bytes' => 10]);
			alarmmanager_event_log_write($this->sampleEvent());
			$this->assertFileExists($path . '.1');
			$this->assertCount(1, array_filter(explode("\n", (string) file_get_contents($path))), 'Rotation keeps the active file to the latest write.');
		} finally {
			array_map('unlink', glob($directory . '/*'));
			rmdir($directory);
		}
	}

	public function testWriteFailureIsSwallowedAndNeverStopsAlarmProcessing(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$GLOBALS['alarmmanager_test_log']           = [];
		$GLOBALS['alarmmanager_test_log_modules']   = [];
		$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'file', 'path' => '/nonexistent-am-test-root-xyz/events.jsonl', 'max_bytes' => 1048576]);
		alarmmanager_event_log_write($this->sampleEvent());
		$this->assertSame(['Alarm Manager'], $GLOBALS['alarmmanager_test_log_modules']);
		$this->assertStringContainsString('Event log write skipped', $GLOBALS['alarmmanager_test_log'][0]);
	}

	public function testNonStringPathIsSwallowedTheSameWayAsAnUnsafeOne(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$GLOBALS['alarmmanager_test_log']           = [];
		$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'file', 'path' => null, 'max_bytes' => 1048576]);
		alarmmanager_event_log_write($this->sampleEvent());
		$this->assertStringContainsString('event_log_path_unset', $GLOBALS['alarmmanager_test_log'][0]);
	}

	public function testExistingNonRegularTargetIsRejectedBeforeOpening(): void {
		if (!file_exists('/dev/full')) {
			self::markTestSkipped('/dev/full is required to exercise a non-regular target.');
		}
		self::alarmmanager_test_load('includes/eventlog.php');
		$GLOBALS['alarmmanager_test_log']           = [];
		// /dev/full is a character device: a valid path that is not a regular file. Opening it
		// for append could block or discard data, so validation must reject it up front rather
		// than letting the writer touch it while holding the event-log lock.
		$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'file', 'path' => '/dev/full', 'max_bytes' => 1048576]);
		alarmmanager_event_log_write($this->sampleEvent());
		$this->assertStringContainsString('invalid_event_log_path', $GLOBALS['alarmmanager_test_log'][0]);
	}

	public function testShortWriteAtThePrimitiveRaisesWriteFailed(): void {
		self::alarmmanager_test_load('includes/eventlog.php');

		if (!in_array('amshortwrite', stream_get_wrappers(), true)) {
			stream_wrapper_register('amshortwrite', AlarmManagerShortWriteStream::class);
		}

		// The stream reports one byte short on every write without raising a warning, so the
		// write primitive must detect the partial write and raise event_log_write_failed.
		try {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('event_log_write_failed');
			alarmmanager_event_log_append('amshortwrite://log', 'one line', 1048576);
		} finally {
			stream_wrapper_unregister('amshortwrite');
		}
	}

	public function testRotationFailureIsReportedAndSkipsTheAppend(): void {
		self::alarmmanager_test_load('includes/eventlog.php');
		$directory = sys_get_temp_dir() . '/am-eventlog-rotate-' . bin2hex(random_bytes(8));
		mkdir($directory);

		try {
			$path = $directory . '/events.jsonl';
			file_put_contents($path, str_repeat('x', 4096));
			// An unreplaceable backup (a non-empty directory where path.1 would go) makes rename()
			// fail, so rotation must raise rather than keep appending past the size limit.
			mkdir($path . '.1');
			file_put_contents($path . '.1/blocker', 'x');
			$GLOBALS['alarmmanager_test_log']           = [];
			$GLOBALS['alarmmanager_test_query_handler'] = $this->withEventLog(['enabled' => true, 'target' => 'file', 'path' => $path, 'max_bytes' => 1024]);
			alarmmanager_event_log_write($this->sampleEvent());
			$this->assertStringContainsString('event_log_rotate_failed', $GLOBALS['alarmmanager_test_log'][0]);
			$this->assertSame(4096, filesize($path), 'A failed rotation must not append to the over-limit file.');
		} finally {
			if (is_file($path . '.1/blocker')) {
				unlink($path . '.1/blocker');
			}

			if (is_dir($path . '.1')) {
				rmdir($path . '.1');
			}

			foreach (glob($directory . '/*') ?: [] as $entry) {
				if (is_dir($entry) && !is_link($entry)) {
					rmdir($entry);
				} else {
					unlink($entry);
				}
			}
			rmdir($directory);
		}
	}

	public function testRouteEventLogsEveryEventRegardlessOfDispositionOrRuleMatch(): void {
		self::alarmmanager_test_load('includes/processor.php');
		$GLOBALS['alarmmanager_test_log']             = [];
		$GLOBALS['alarmmanager_test_log_modules']     = [];
		$GLOBALS['alarmmanager_test_execute_handler'] = static fn ($sql, $params = []): bool => true;
		$GLOBALS['alarmmanager_test_query_handler']   = $this->withEventLog(['enabled' => true, 'target' => 'cacti_log']);
		// Archived/historical events return immediately after this call, so this also
		// confirms logging happens independently of rule matching and routing disposition.
		alarmmanager_route_event(['id' => 1, 'mode' => 'historical_only'], $this->sampleEvent(), [], 'applied');
		$this->assertContains('ALARMMANAGER-EVENT', $GLOBALS['alarmmanager_test_log_modules']);
	}

	public function testConfigurationValidatesEventLogWithFileAndCactiLogTargets(): void {
		self::alarmmanager_test_load('includes/routing.php');
		$this->assertSame(['enabled' => false, 'target' => 'cacti_log', 'path' => null, 'max_bytes' => 52428800], alarmmanager_configuration(['version' => 1])['event_log'], 'Defaults to disabled when omitted.');
		$this->assertSame(['enabled' => true, 'target' => 'cacti_log', 'path' => null, 'max_bytes' => 52428800],
			alarmmanager_configuration(['version' => 1, 'event_log' => ['enabled' => true, 'target' => 'cacti_log']])['event_log']);
		$this->assertSame(['enabled' => true, 'target' => 'file', 'path' => '/var/log/alarmmanager/events.jsonl', 'max_bytes' => 10485760],
			alarmmanager_configuration(['version' => 1, 'event_log' => ['enabled' => true, 'target' => 'file', 'path' => '/var/log/alarmmanager/events.jsonl', 'max_bytes' => 10485760]])['event_log']);

		foreach ([['target' => 'file'], ['target' => 'file', 'path' => 'relative.jsonl'], ['target' => 'file', 'path' => " /var/log/x\n.jsonl"]] as $bad) {
			try {
				alarmmanager_configuration(['version' => 1, 'event_log' => $bad]);
				$this->fail('Accepted an invalid file target: ' . json_encode($bad));
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_event_log_path', $error->getMessage());
			}
		}

		foreach (['not-an-array', ['target' => 'syslog']] as $bad) {
			try {
				alarmmanager_configuration(['version' => 1, 'event_log' => $bad]);
				$this->fail('Accepted invalid event_log shape: ' . json_encode($bad));
			} catch (InvalidArgumentException $error) {
				$this->assertSame('invalid_event_log', $error->getMessage());
			}
		}
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_configuration(['version' => 1, 'event_log' => ['max_bytes' => 999]]);
	}

	public function testConfigurationEventLogSurvivesAJsonRoundTrip(): void {
		self::alarmmanager_test_load('includes/routing.php');
		$configured = alarmmanager_configuration(['version' => 1, 'event_log' => ['enabled' => true, 'target' => 'file', 'path' => '/var/log/alarmmanager/events.jsonl', 'max_bytes' => 2097152]]);
		$stored     = json_decode(json_encode($configured, JSON_THROW_ON_ERROR), true);
		$this->assertSame($configured['event_log'], alarmmanager_configuration($stored)['event_log']);
	}

	public function testAdminActionSavesEventLogSettingsIntoConfigurationHistory(): void {
		self::alarmmanager_test_load('includes/admin.php');
		$before = [$_SESSION ?? [], $_SERVER['REQUEST_METHOD'] ?? null, $GLOBALS['alarmmanager_test_config_options'] ?? [], $GLOBALS['alarmmanager_test_allowed_realms'] ?? []];

		try {
			$_SESSION['sess_user_id']                     = 1;
			$_SERVER['REQUEST_METHOD']                    = 'POST';
			$GLOBALS['alarmmanager_test_config_options']  = ['auth_method' => 1];
			$GLOBALS['alarmmanager_test_allowed_realms']  = [1, 101];
			$GLOBALS['alarmmanager_test_csrf_valid']      = true;
			$writes                                       = [];
			$GLOBALS['alarmmanager_test_execute_handler'] = static function ($sql, $params) use (&$writes) {
				$writes[] = [$sql, $params];

				return true;
			};
			$GLOBALS['alarmmanager_test_cell_handler']  = static fn ($sql) => str_contains($sql, 'config_revision') ? 2 : 0;
			$GLOBALS['alarmmanager_test_query_handler'] = static function ($sql) {
				if (str_contains($sql, 'plugin_realms')) {
					return [['id' => 1]];
				}

				if (str_contains($sql, 'plugin_alarmmanager_schema') && !str_contains($sql, 'configuration_history')) {
					return [['config_revision' => 1]];
				}

				if (str_contains($sql, 'configuration_history')) {
					return [['definition' => json_encode(['version' => 1, 'endpoints' => [], 'rules' => []])]];
				}

				return [];
			};
			$this->assertSame('Configuration saved.', alarmmanager_admin_action(['action' => 'event_log_save', 'revision' => 1,
				'event_log_enabled'                                                          => 'yes', 'event_log_target' => 'file', 'event_log_path' => '/var/log/alarmmanager/events.jsonl', 'event_log_max_mb' => '25']));
			$history = array_values(array_filter($writes, static fn ($write): bool => str_contains($write[0], 'configuration_history')));
			$this->assertNotEmpty($history, 'The configuration history insert was never reached.');
			$saved = json_decode(end($history)[1][1], true, 16, JSON_THROW_ON_ERROR);
			$this->assertSame(['enabled' => true, 'target' => 'file', 'path' => '/var/log/alarmmanager/events.jsonl', 'max_bytes' => 26214400], $saved['event_log']);
		} finally {
			[$_SESSION, $_SERVER['REQUEST_METHOD'], $GLOBALS['alarmmanager_test_config_options'], $GLOBALS['alarmmanager_test_allowed_realms']] = $before;
			unset($GLOBALS['alarmmanager_test_csrf_valid']);
		}
	}

	public function testConfigurationPageHasAnEventLogFormWithTheFileOnlyFieldsGrouped(): void {
		self::alarmmanager_test_load('includes/admin_view.php');

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'unit-test';
			}
		}
		ob_start();

		try {
			alarmmanager_admin_markup(['revision' => 1, 'configuration' => ['version' => 1, 'endpoints' => [], 'rules' => [],
				'event_log'                                                              => ['enabled' => true, 'target' => 'file', 'path' => '/var/log/alarmmanager/events.jsonl', 'max_bytes' => 10485760]]], 'configuration');
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$document = new DOMDocument();
		$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		$xpath = new DOMXPath($document);
		$this->assertSame(1, $xpath->query('//form[input[@name="action" and @value="event_log_save"]]')->length);
		$this->assertSame(1, $xpath->query('//form[input[@value="event_log_save"]]//input[@name="event_log_enabled" and @checked]')->length);
		$this->assertSame(1, $xpath->query('//form[input[@value="event_log_save"]]//select[@name="event_log_target"]/option[@value="file" and @selected]')->length);
		$this->assertSame(1, $xpath->query('//form[input[@value="event_log_save"]]//*[@data-event-log-targets="file"]//input[@name="event_log_path" and @value="/var/log/alarmmanager/events.jsonl"]')->length);
		$this->assertSame(1, $xpath->query('//form[input[@value="event_log_save"]]//*[@data-event-log-targets="file"]//input[@name="event_log_max_mb" and @value="10"]')->length);
	}
}

/** Test double: a writable stream that always reports one byte short, with no warning. */
final class AlarmManagerShortWriteStream {
	/** @var resource */
	public $context;

	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
		return true;
	}

	public function stream_write(string $data): int {
		return max(0, strlen($data) - 1);
	}

	public function stream_lock(int $operation): bool {
		return true;
	}

	public function stream_eof(): bool {
		return true;
	}

	public function stream_close(): void {
	}

	/** @return array<int|string, int> */
	public function url_stat(string $path, int $flags): array {
		return ['mode' => 0100644, 'size' => 0];
	}
}
