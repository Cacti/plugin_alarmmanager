<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class ReportTableTest extends AlarmManagerTestCase {
	public function testEventDetailsRenderDecisionsWithoutRowIdsAndPreserveEventLinks(): void {
		self::alarmmanager_test_load('includes/web_view.php');

		if (!function_exists('html_start_box')) {
			function html_start_box(...$args) {
				print '<table>';
			}
		}

		if (!function_exists('html_end_box')) {
			function html_end_box(...$args) {
				print '</table>';
			}
		}

		if (!function_exists('html_header')) {
			function html_header(...$args) {
			}
		}

		if (!function_exists('form_alternate_row')) {
			function form_alternate_row(...$args) {
				print '<tr>';
			}
		}

		if (!function_exists('form_end_row')) {
			function form_end_row(...$args) {
				print '</tr>';
			}
		}

		if (!function_exists('form_selectable_cell')) {
			function form_selectable_cell($value, ...$args) {
				print '<td>' . $value . '</td>';
			}
		}
		$event              = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true);
		$event['_row_id']   = 42;
		$event['_workflow'] = ['decisions' => [['rule_id' => 1, 'endpoint_id' => 9, 'reason' => 'matched', 'created_at' => '2026-10-26 20:42:02']]];
		set_error_handler(static function ($severity, $message, $file, $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
		ob_start();

		try {
			alarmmanager_event_detail_markup($event);
			$decisions = ob_get_contents();
			ob_clean();
			alarmmanager_report_table('events', ['rows' => [['id' => 42, 'event_row_id' => 42]]], ['admin' => false], [], true);
			$local = ob_get_contents();
			ob_clean();
			alarmmanager_report_table('events', ['rows' => [['id' => null, 'event_row_id' => 42]]], ['admin' => false], [], true);
			$mirror = ob_get_contents();
		} finally {
			ob_end_clean();
			restore_error_handler();
		}
		$this->assertStringContainsString('Routing decisions', $decisions);
		$this->assertStringContainsString('matched', $decisions);
		$this->assertStringContainsString('event_id=42', $local);
		$this->assertStringNotContainsString('event_id=42', $mirror);
	}
}
