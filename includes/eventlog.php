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
require_once(__DIR__ . '/runtime.php');

/**
 * Unconditional audit trail, independent of rule routing: every event that reaches
 * alarmmanager_route_event() is a candidate, whether or not any rule matches it.
 * Defaults to disabled; read fresh per event so a saved change takes effect immediately.
 * @return array{enabled: bool, target: string, path: ?string, max_bytes: int}
 */
function alarmmanager_event_log_settings(): array {
	$row           = alarmmanager_db_row('SELECT h.definition FROM plugin_alarmmanager_configuration_history AS h JOIN plugin_alarmmanager_schema AS s ON s.config_revision = h.revision WHERE s.id = 1');
	$configuration = $row ? json_decode($row['definition'], true, 24, JSON_THROW_ON_ERROR) : [];

	return ($configuration['event_log'] ?? []) + ['enabled' => false, 'target' => 'cacti_log', 'path' => null, 'max_bytes' => 52428800];
}
/**
 * The JSON Lines record: fixed fields Splunk and similar tools can index directly,
 * built from the event data already validated by alarmmanager_validate_event().
 * @param  array<string, mixed> $event
 * @return array<string, mixed>
 */
function alarmmanager_event_log_record(array $event): array {
	$device = $event['device'] ?? [];

	return ['logged_at'     => gmdate('Y-m-d\TH:i:s\Z'), 'occurred_at' => $event['occurred_at'] ?? null, 'event_id' => $event['event_id'] ?? null,
		'source'               => $event['source'] ?? null, 'transition' => $event['transition'] ?? null, 'severity' => $event['severity'] ?? null,
		'episode_id'           => $event['episode_id'] ?? null, 'alarm_key' => $event['alarm_key'] ?? null, 'resource_id' => $event['resource_id'] ?? null,
		'collector_id'         => $event['collector_id'] ?? null, 'summary' => $event['summary'] ?? null,
		'device'               => ['id' => $device['id'] ?? null, 'hostname' => $device['hostname'] ?? null, 'description' => $device['description'] ?? null, 'location' => $device['location'] ?? null]];
}
/**
 * Absolute path, parent directory must already exist (the plugin never creates
 * directories for this), and the resolved directory must sit outside both the
 * plugin's own tree and Cacti's web root so the log can never become web-servable.
 * Re-checked against live disk state on every write: configuration_save only
 * confirms the path looks like a path, not that the filesystem still agrees.
 */
function alarmmanager_event_log_path_validate(string $path): string {
	if (!str_starts_with($path, '/') || is_link($path)) {
		throw new RuntimeException('invalid_event_log_path');
	}
	$directory = realpath(dirname($path));
	$name      = basename($path);

	if (!$directory || $name === '') {
		throw new RuntimeException('invalid_event_log_path');
	}
	$forbidden = array_filter([realpath(__DIR__ . '/..'), realpath(__DIR__ . '/../../..')]);

	foreach ($forbidden as $root) {
		if (str_starts_with($directory . '/', $root . '/')) {
			throw new RuntimeException('invalid_event_log_path');
		}
	}

	return $directory . '/' . $name;
}
/** Rotates one backup (path.1) past the size threshold; called with the write lock already held. */
function alarmmanager_event_log_rotate(string $path, int $max_bytes): void {
	clearstatcache(true, $path);

	// The common case is the very first write, when there is nothing to rotate yet.
	if (is_file($path) && filesize($path) >= $max_bytes) {
		@rename($path, $path . '.1');
	}
}
/**
 * Best effort: a full disk, a revoked path or a permissions change must never
 * stop alarm processing, so failures are logged and swallowed here, not thrown.
 * @param array<string, mixed> $event
 */
function alarmmanager_event_log_write(array $event): void {
	try {
		$settings = alarmmanager_event_log_settings();

		if (!$settings['enabled']) {
			return;
		}
		$line = json_encode(alarmmanager_event_log_record($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		if ($settings['target'] === 'cacti_log') {
			cacti_log($line, false, 'ALARMMANAGER-EVENT');

			return;
		}

		if (!is_string($settings['path'])) {
			throw new RuntimeException('event_log_path_unset');
		}
		$path = alarmmanager_event_log_path_validate($settings['path']);

		// A dedicated lock file sidesteps the hazard of locking a handle whose
		// underlying inode rotate() then renames out from under it. It lives in the
		// system temp directory rather than beside the log, so a destination whose
		// directory allows appending to one pre-existing file but nothing else still
		// works. A fopen() or flock() failure here is a resource (not bool) type
		// mismatch a few lines down, which the catch below reports like any other.
		$lock = fopen(sys_get_temp_dir() . '/alarmmanager-eventlog-' . hash('sha256', $path) . '.lock', 'cb');

		try {
			flock($lock, LOCK_EX);
			alarmmanager_event_log_rotate($path, (int) $settings['max_bytes']);
			$handle = fopen($path, 'ab');

			try {
				if (@fwrite($handle, $line . "\n") !== strlen($line) + 1) {
					throw new RuntimeException('event_log_write_failed');
				}
			} finally {
				fclose($handle);
			}
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	} catch (Throwable $error) {
		alarmmanager_log('Event log write skipped: ' . $error->getMessage());
	}
}
