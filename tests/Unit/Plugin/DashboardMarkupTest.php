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
*/

final class DashboardMarkupTest extends AlarmManagerTestCase {
	/**
	 * The statistics dashboard panels render with the shared dashboarding Card
	 * API chrome (.cc* from include/css/cards.css) so AlarmManager matches the
	 * other Cacti plugins; the admin configuration accordions keep their own
	 * .alarmmanagerDashboardCard markup and are not touched here.
	 */
	public function testDashboardMarkupUsesSharedCardChrome(): void {
		self::alarmmanager_test_load('includes/web_view.php');

		ob_start();
		alarmmanager_dashboard_markup();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('<div class="alarmmanagerDashboardGrid">', $html);
		$this->assertStringContainsString('class="ccCard"', $html);
		$this->assertStringContainsString('class="ccCardHeader"', $html);
		$this->assertStringContainsString('class="ccTitle"', $html);
		$this->assertStringContainsString('data-severity="none"', $html);
		$this->assertStringContainsString('class="ccTool alarmmanagerCardToggle"', $html);
		$this->assertStringContainsString('class="ccCardBody alarmmanagerCardBody"', $html);

		// One card per configured statistic panel, all on the shared chrome.
		$this->assertSame(9, substr_count($html, 'class="ccCard"'));

		// The retired dashboard-only chrome classes are gone from this surface.
		$this->assertStringNotContainsString('alarmmanagerDashboardCardHeader', $html);
		$this->assertStringNotContainsString('alarmmanagerDashboardCardTitle', $html);
	}
}
