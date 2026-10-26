<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class CoverageGateTest extends AlarmManagerTestCase {
	public function testChangedLinesRequireCoverageAndIgnoreUnchangedLines(): void {
		$root      = dirname(__DIR__, 3);
		$directory = sys_get_temp_dir() . '/alarmmanager-coverage-' . bin2hex(random_bytes(6));
		mkdir($directory, 0700);
		$report = $directory . '/coverage.xml';
		$patch  = $directory . '/patch.diff';
		$name   = htmlspecialchars($root . '/includes/admin_alert.php', ENT_XML1);

		try {
			foreach ([
				['<file name="' . $name . '"><line num="10" type="stmt" count="1"/><line num="11" type="stmt" count="0"/></file>', 0, '1/1'],
				['<file name="' . $name . '"><line num="10" type="stmt" count="0"/></file>', 1, '0/1'],
				['', 2, 'no executable plugin lines'],
			] as [$files, $status, $message]) {
				file_put_contents($report, '<coverage><project>' . $files . '</project></coverage>');
				file_put_contents($patch, "diff --git a/includes/admin_alert.php b/includes/admin_alert.php\n+++ b/includes/admin_alert.php\n@@ -10 +10 @@\n-old\n+new\n");
				$process = proc_open([PHP_BINARY, $root . '/tests/bin/patch-coverage.php', $report, $patch],
					[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
				$this->assertIsResource($process);
				fclose($pipes[0]);
				$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				$this->assertSame($status, proc_close($process), $output);
				$this->assertStringContainsString($message, $output);
			}
		} finally {
			unlink($report);
			unlink($patch);
			rmdir($directory);
		}
	}
}
