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
// Standalone browser fixture: real rule markup and policy parsing, no database or delivery.
if (PHP_SAPI !== 'cli' || getenv('CACTI_TEST_BOOTSTRAP') !== '1') {
	exit(1);
}
require_once dirname(__DIR__) . '/bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/admin_view.php';

if (($argv[1] ?? '') === '--parse') {
	parse_str(stream_get_contents(STDIN), $input);
	print json_encode(alarmmanager_admin_rule_policy($input), JSON_THROW_ON_ERROR);
	exit;
}
function csrf_get_tokens(): string {
	return 'rule-editor-fixture-token';
}

if (($argv[1] ?? '') === '--empty') {
	alarmmanager_admin_markup(['revision' => 5, 'configuration' => ['endpoints' => [], 'rules' => []]], 'rules');
	exit;
}
$configuration = ['endpoints' => [['id' => 3, 'name' => 'Operations webhook', 'enabled' => true]]];
$choices       = ['severities' => ['critical' => 'Critical', 'major' => 'Major'], 'transitions' => ['raised' => 'Raised', 'recovered' => 'Recovered'], 'device_ids' => [42 => 'Core router (#42)'], 'collector_ids' => [2 => 'Remote collector (#2)']];
print '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Alarm Manager rule editor fixture</title></head><body><main id="alarmmanager_app" data-theme="modern">';
alarmmanager_rule_editor(['id' => 0, 'name' => '', 'enabled' => true, 'policy' => []], 5, $configuration, $choices);
alarmmanager_rule_editor(['id' => 9, 'name' => '<img src=x onerror=alert(1)>', 'enabled' => false, 'endpoints' => [3], 'priority' => 10, 'policy' => ['match' => ['device_ids' => [999], 'severities' => []]]], 5, $configuration, $choices);
print '</main></body></html>';
