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
require_once(__DIR__ . '/alarmmanager_bridge.php');
function alarmmanager_thold_begin(array $data, array $host, string $class, string $subject, bool $repeat = false, bool $suppressed = false, string $reason = ''): array {
	$sample = (int) ($data['lasttime'] ?? 0);

	if ($sample < 1) {
		return ['owned' => false, 'channels' => [], 'status' => 'missing_sample_identity'];
	}
	$recovery = in_array($class, ['warn2normal', 'alert2normal', 'blnormal'], true);
	$severity = $recovery ? 'clear' : (in_array($class, ['warn', 'alert2warn'], true) ? 'warning' : 'major');
	$identity = hash('sha256', json_encode([$sample, $class, $data['thold_fail_count'] ?? 0, $data['thold_warning_fail_count'] ?? 0, $data['bl_fail_count'] ?? 0], JSON_THROW_ON_ERROR));
	$device   = ['hostname' => (string) ($host['hostname'] ?? ''), 'description' => (string) ($host['description'] ?? ''), 'tags' => []];

	if ((int) ($data['host_id'] ?? 0) > 0) {
		$device['id'] = (int) $data['host_id'];
	}

	return AlarmManagerProducerBridge::publish('thold', (int) $data['id'], (int) ($data['thold_template_id'] ?? 0), $identity,
		['transition'  => $recovery ? 'recovered' : ($class === 'alert2warn' ? 'updated' : 'raised'), 'severity' => $severity, 'repeat' => $repeat, 'fresh_raise' => !$repeat && $class !== 'alert2warn',
			'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $sample), 'device' => $device, 'summary' => $subject,
			'details'     => 'Threshold ' . ($data['name_cache'] ?? $data['id']) . '; value ' . ($data['lastread'] ?? ''),
			'rule_name'   => (string) ($data['name_cache'] ?? $data['id']), 'rule_type' => $class,
			'suppressed'  => $suppressed, 'suppression_reason' => $reason]);
}
/** Source logging remains unchanged. Only logged suppressed notification transitions are optionally published. */
function alarmmanager_thold_log(array $save, array $data, array $host, bool $maintenance): void {
	$status  = $save['status'];
	$classes = [ST_NOTIFYAL => 'alert', ST_NOTIFYRA => 'alert', ST_NOTIFYWA => 'warn', ST_NOTIFYRAW => 'warn', ST_NOTIFYAW => 'alert2warn', ST_NOTIFYRS => 'alert2normal'];

	if (isset($classes[$status]) && ($maintenance || ($data['acknowledgment'] ?? '') !== '')) {
		alarmmanager_thold_begin($data, $host, $classes[$status], (string) $save['description'], in_array($status, [ST_NOTIFYRA, ST_NOTIFYRAW], true), true, $maintenance ? 'source_maintenance' : 'source_acknowledged');
	}
	thold_log($save);
}
