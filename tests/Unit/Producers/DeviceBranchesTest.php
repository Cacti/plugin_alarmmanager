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
if (PHP_SAPI !== 'cli' || !getenv('ALARMMANAGER_CACTI_PATCHED_ROOT')) {
	exit(2);
}
$root = getenv('ALARMMANAGER_CACTI_PATCHED_ROOT');
require_once($root . '/include/global_constants.php');
require_once(__DIR__ . '/../../fixtures/source_functions.php');
$checks       = 0;
$writes       = 0;
$publications = [];
$host         = ['id'=>42, 'status'=>HOST_UP, 'status_event_count'=>0, 'status_fail_date'=>'', 'status_rec_date'=>'', 'snmp_community'=>'CREDENTIAL_CANARY', 'snmp_version'=>2, 'failed_polls'=>0, 'total_polls'=>1, 'availability'=>100, 'status_last_error'=>'', 'min_time'=>0, 'max_time'=>0, 'cur_time'=>0, 'avg_time'=>0];
function dcheck(bool $ok,string $label):void {
	global $checks;

	if (!$ok) {
		throw new RuntimeException($label);
	}$checks++;
}
function read_config_option($name) {
	return in_array($name,['ping_failure_count', 'ping_recovery_count'],true) ? 2 : '';
}
function db_fetch_row_prepared($sql,$params = []) {
	return $GLOBALS['host'];
}
function db_execute_prepared($sql,$params = []) {
	if (str_contains($sql,'UPDATE host SET')) {
		$columns = ['status', 'status_event_count', 'status_fail_date', 'status_rec_date', 'status_last_error', 'min_time', 'max_time', 'cur_time', 'avg_time', 'total_polls', 'failed_polls', 'availability'];

		foreach ($columns as $i=>$field) {
			$GLOBALS['host'][$field] = in_array($field,['status_fail_date', 'status_rec_date'],true) ? gmdate('Y-m-d H:i:s',(int)$params[$i]) : $params[$i];
		}$GLOBALS['writes']++;
	}

return true;
}
function cacti_log(...$args) {
}
function alarmmanager_device_bridge_poll_allowed($id):bool {
	return true;
}
function alarmmanager_device_bridge_core($safe,$previous,$write):bool {
	$GLOBALS['publications'][] = [$safe, $previous];

	return $write();
}
$fixture = sys_get_temp_dir() . '/alarmmanager_core_' . bin2hex(random_bytes(6));
mkdir($fixture . '/lib',0700,true);
file_put_contents($fixture . '/lib/alarmmanager_device_bridge.php','<?php');
$code = alarmmanager_test_source_function($root . '/lib/functions.php','update_host_status');
$code = str_replace('__DIR__',var_export($fixture . '/lib',true),$code);
eval($code);
$ping = (object)['ping_response'=>'Fixture ping', 'snmp_response'=>'Fixture SNMP', 'ping_time'=>1, 'snmp_time'=>1, 'ping_status'=>true, 'snmp_status'=>true];

try {
	update_host_status(HOST_DOWN,42,$ping,AVAIL_PING,false);
	dcheck($host['status'] === HOST_UP && !$publications,'transient failure creates no alarm');
	update_host_status(HOST_DOWN,42,$ping,AVAIL_PING,false);
	dcheck($host['status'] === HOST_DOWN && count($publications) === 1,'configured failure count raises once');
	dcheck($publications[0][0]['status'] === HOST_DOWN,'normalized down boundary');
	dcheck(!str_contains(json_encode($publications),'CREDENTIAL_CANARY'),'host credentials excluded');
	update_host_status(HOST_DOWN,42,$ping,AVAIL_PING,false);
	dcheck(count($publications) === 1,'continued failure no per-sample publication');
	update_host_status(HOST_UP,42,$ping,AVAIL_PING,false);
	dcheck($host['status'] === HOST_RECOVERING && count($publications) === 1,'transient up not recovery');
	update_host_status(HOST_DOWN,42,$ping,AVAIL_PING,false);
	dcheck($host['status'] === HOST_DOWN && count($publications) === 1,'failed recovery no new outage');
	update_host_status(HOST_UP,42,$ping,AVAIL_PING,false);
	update_host_status(HOST_UP,42,$ping,AVAIL_PING,false);
	dcheck($host['status'] === HOST_UP && count($publications) === 2,'configured recovery count publishes once');
	dcheck($publications[1][0]['status'] === HOST_UP,'recovery boundary');
	update_host_status(HOST_UP,42,$ping,AVAIL_PING,false);
	dcheck(count($publications) === 2 && $writes === 8,'normal polling still persists native counters');
	// Exercise the actual pinned custom functional-hook dispatch on a disconnected collector.
	$config = ['poller_id'=>2, 'connection'=>'offline'];
	eval(alarmmanager_test_source_function($root . '/lib/plugins.php','api_plugin_run_plugin_hook_function'));

	foreach (['offline', 'recovery', 'online'] as $connection) {
		$config['connection'] = $connection;
		$called               = false;
		$returned             = api_plugin_run_plugin_hook_function('alarmmanager_submit','alarmmanager',static function ($envelope) use (&$called) {
			$called = true;

			return $envelope;
		},['status'=> 'unhandled']);
		dcheck($called && $returned['status'] === 'unhandled','custom functional dispatch ' . $connection);
	}
	print "PASS: $checks pinned Cacti transition/hook checks (framework/publication doubles).\n";
} finally {
	unlink($fixture . '/lib/alarmmanager_device_bridge.php');
	rmdir($fixture . '/lib');
	rmdir($fixture);
}
function api_plugin_remote_capabilities($plugin) {
	return ['remote_collect'=>1, 'remote_poller'=>1];
}
function api_plugin_hook_is_remote_collect(...$args) {
	return false;
}
function api_plugin_status_run(...$args) {
	return true;
}
