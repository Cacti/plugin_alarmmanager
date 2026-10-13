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
require_once(__DIR__ . '/remote.php');
function alarmmanager_remote_markup(): void {
	print '<h2>' . alarmmanager_label('Collectors and remote history') . '</h2><p>' . alarmmanager_label('Each collector owns its local delivery. Imported history records the original events and outcomes. Collector history does not create central delivery jobs.') . '</p>';
	$peers = alarmmanager_db_rows('SELECT poller_id, instance_uuid, enabled, last_seen, TIMESTAMPDIFF(SECOND, last_seen, UTC_TIMESTAMP()) AS age_seconds FROM plugin_alarmmanager_sync_peers ORDER BY poller_id LIMIT 256');

	foreach ($peers as $peer) {
		$status = !$peer['enabled'] ? 'Disabled' : ($peer['last_seen'] === null ? 'Not yet seen' : ((int) $peer['age_seconds'] <= 90 ? 'Online' : 'Offline'));
		print '<p>' . alarmmanager_label('Collector') . ' ' . (int) $peer['poller_id'] . ': ' . alarmmanager_label($status) . ' — ' . alarmmanager_escape($peer['last_seen'] ?? '') . '</p>';
	}
	$grants = alarmmanager_db_rows('SELECT device_id, poller_id, epoch, owner, mode, valid_from, valid_until FROM plugin_alarmmanager_device_owners ORDER BY device_id LIMIT 100');
	print '<details><summary>' . alarmmanager_label('Device ownership grants (first 100)') . '</summary><pre>' . alarmmanager_escape(json_encode($grants, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre></details>';
	$poller = filter_var($_GET['collector'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);

	if ($poller === false) {
		throw new InvalidArgumentException('invalid_action');
	}
	$entity   = $_GET['entity'] ?? 'event';
	$entities = alarmmanager_sync_entities();

	if (!is_string($entity) || !isset($entities[$entity])) {
		throw new InvalidArgumentException('invalid_action');
	}
	print '<form method="get"><input type="hidden" name="section" value="collectors"><label>' . alarmmanager_label('Collector (0 selects all)') . ' <input name="collector" type="number" min="0" value="' . $poller . '"></label><label>' . alarmmanager_label('Record type') . ' <select name="entity">';

	foreach (array_keys($entities) as $type) {
		print '<option ' . ($type === $entity ? 'selected' : '') . '>' . $type . '</option>';
	} print '</select></label><button>' . alarmmanager_label('Filter') . '</button></form>';
	$rows = alarmmanager_db_rows('SELECT poller_id, instance_uuid, entity_id, revision, definition, received_at FROM plugin_alarmmanager_remote_history WHERE entity = ?' . ($poller ? ' AND poller_id = ?' : '') . ' ORDER BY received_at DESC, entity_id DESC LIMIT 50', $poller ? [$entity, $poller] : [$entity]);
	print '<p>' . alarmmanager_label('Latest 50 matching remote records; original IDs and delivery ownership are preserved.') . '</p>';

	foreach ($rows as $row) {
		print '<details class="alarmmanagerDashboardCard"><summary>' . alarmmanager_label('Collector') . ' ' . (int) $row['poller_id'] . ' / ' . alarmmanager_escape($entity) . ' #' . (int) $row['entity_id'] . ' — ' . alarmmanager_escape($row['received_at']) . '</summary><pre>' . alarmmanager_escape(json_encode(json_decode($row['definition'], true, 32, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</pre></details>';
	}
}
