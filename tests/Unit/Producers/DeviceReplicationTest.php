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
if (PHP_SAPI !== 'cli') {
	exit(2);
}
$checks       = 0;
$queries      = [];
$replications = [];
$peers        = [['poller_id'=>2]];
$config       = ['poller_id'=>2];
require_once(__DIR__ . '/../../../integrations/device_bridge.php');
function pcheck(bool $ok,string $label):void {
	global $checks;

	if (!$ok) {
		throw new RuntimeException($label);
	}$checks++;
}
function db_fetch_assoc_prepared($sql,$params = [],$log = true) {
	$GLOBALS['queries'][] = $sql;

	if (str_contains($sql,'SELECT poller_id FROM')) {
		return $GLOBALS['peers'];
	}

if (str_contains($sql,'SELECT device_id')) {
		return [['device_id'=>42, 'poller_id'=>2, 'valid_from'=>gmdate('Y-m-d H:i:s',time() - 60), 'valid_until'=>gmdate('Y-m-d H:i:s',time() + 300)]];
	}

return [];
}
function db_table_exists(...$args) {
	return true;
}
function db_fetch_assoc($sql,$log = true,$connection = false) {
	return $GLOBALS['mismatch'] ?? false ? [] : [['Field'=>'id'], ['Field'=>'status']];
}
function db_execute_prepared($sql,$params = [],$log = true,$connection = false) {
	$GLOBALS['queries'][] = $sql;

	return true;
}
function cacti_sizeof($data) {
	return count($data);
}
function cacti_log(...$args) {
}
function replicate_out_table($connection,$data,$table,$poller,$truncate = true,$exclude = false) {
	$GLOBALS['replications'][] = [$table, $poller, $truncate, $exclude];
}
$data = [['id'=>42], ['id'=>43]];
pcheck(alarmmanager_device_bridge_replicate_hosts('fixture',$data,2),'enrolled collector handled');
pcheck($replications[0][2] === false,'no host truncation');

foreach (['status', 'status_event_count', 'status_fail_date', 'status_rec_date', 'failed_polls', 'availability'] as $field) {
	pcheck(in_array($field,$replications[0][3],true),'local field preserved ' . $field);
}
pcheck((bool)array_filter($queries,fn ($q) =>str_contains($q,"SET h.deleted = 'on'")),'removed assignments retired without wiping survivors');
$peers = [];
pcheck(!alarmmanager_device_bridge_replicate_hosts('fixture',$data,3),'unenrolled legacy replication unchanged');
$before = count($queries);
pcheck(alarmmanager_device_bridge_poll_allowed(42),'owned lease allows normal polling');
$first = count($queries);
pcheck(alarmmanager_device_bridge_poll_allowed(42) && count($queries) === $first,'no per-sample config/schema query');
pcheck($first === $before + 1,'one bounded ownership cache query');
$config['poller_id'] = 3;
pcheck(!alarmmanager_device_bridge_poll_allowed(42),'wrong collector polling fenced');
print "PASS: $checks configuration replication/cache checks (framework doubles).\n";
