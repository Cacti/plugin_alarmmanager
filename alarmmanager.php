<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/

require_once(__DIR__ . '/../../include/auth.php');
require_once(__DIR__ . '/includes/web_view.php');

header('Cache-Control: no-store');

try {
	$scope = alarmmanager_web_scope();

	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
		throw new RuntimeException('permission_denied');
	}

	if (get_nfilter_request_var('action') === 'dashboard_data') {
		alarmmanager_dashboard_response($scope);
	}

	$filters = alarmmanager_view_filters();
	$tab     = alarmmanager_view_tab();
	$data    = $tab === 'dashboard' ? null : alarmmanager_report_list($tab, $scope, $filters);
	$detail  = $tab === 'events' ? alarmmanager_event_detail($scope, $filters) : null;
} catch (Throwable $error) {
	alarmmanager_view_error($error);
}

top_header();

alarmmanager_web_assets();
alarmmanager_web_open($scope, $tab);
alarmmanager_web_filters($filters, $tab, $tab === 'dashboard');

if ($tab === 'dashboard') {
	alarmmanager_dashboard_markup();
} else {
	if ($detail !== null) {
		alarmmanager_event_detail_markup($detail);
	}

	alarmmanager_report_table($tab, $data, $scope, $filters);
}

bottom_footer();
