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
require_once(__DIR__ . '/../integrations/producer_bridge.php');
function alarmmanager_producer_save(array $input, int $user): string {
	if ((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false) !== 1) {
		throw new RuntimeException('producer_disabled');
	}
	$source = $input['source'] ?? '';
	$scope  = $input['scope'] ?? '';

	if (!in_array($source, ['thold', 'syslog'], true) || !in_array($scope, ['default', 'template', 'resource'], true) || ($source === 'syslog' && $scope === 'template')) {
		throw new InvalidArgumentException('invalid_action');
	}
	$id = alarmmanager_admin_int($input['resource_id'] ?? null, $scope === 'default' ? 0 : 1);

	if ($scope === 'default' && $id !== 0) {
		throw new InvalidArgumentException('invalid_action');
	}
	$revision   = alarmmanager_admin_int($input['producer_revision'] ?? 0, 0);
	$definition = AlarmManagerProducerBridge::validate(['mode' => $input['mode'] ?? '', 'profile_id' => alarmmanager_admin_int($input['profile_id'] ?? 0, 0), 'channels' => $input['channels'] ?? [], 'complete_history' => ($input['complete_history'] ?? '') === 'yes']);

	if ($source === 'thold' && in_array('ticket', $definition['channels'], true)) {
		throw new InvalidArgumentException('invalid_action');
	}

	if (($input['inherit'] ?? '') !== 'yes' && $definition['mode'] === 'centralized' && ($input['migration_confirmed'] ?? '') !== 'yes') {
		throw new InvalidArgumentException('confirmation_required');
	}
	alarmmanager_transaction(function () use ($source, $scope, $id, $revision, $definition, $user, $input): void {
		// Serialize configuration creation as well as edits to absent rows.
		alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');
		$row = alarmmanager_db_row('SELECT revision FROM plugin_alarmmanager_producer_config WHERE source = ? AND scope = ? AND resource_id = ? FOR UPDATE', [$source, $scope, $id]);

		if ((int) ($row['revision'] ?? 0) !== $revision) {
			throw new RuntimeException('configuration_changed');
		}

		if (($input['inherit'] ?? '') === 'yes') {
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_producer_config WHERE source = ? AND scope = ? AND resource_id = ?', [$source, $scope, $id]);
		} else {
			if ($definition['profile_id'] > 0) {
				$profile = alarmmanager_db_row('SELECT enabled FROM plugin_alarmmanager_profiles WHERE id = ?', [$definition['profile_id']]);

				if (!$profile || !(bool) $profile['enabled']) {
					throw new InvalidArgumentException('producer_profile_required');
				}

				if ($definition['mode'] === 'centralized' && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_rules WHERE profile_id = ? AND enabled = 1', [$definition['profile_id']], '', false)) {
					throw new InvalidArgumentException('producer_route_required');
				}
			}
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source, scope, resource_id, revision, definition) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE revision = VALUES(revision), definition = VALUES(definition)', [$source, $scope, $id, $revision + 1, json_encode($definition, JSON_THROW_ON_ERROR)]);
		}
		alarmmanager_audit($user, 'producer_' . $source . '_' . $scope, $id);
	});

	return 'Producer policy saved. Apply the revision-pinned patch on each producer. Existing notification receipts keep their original delivery owner.';
}
function alarmmanager_producer_markup(int $revision): void {
	print '<p>' . __esc('Pending producer receipts', 'alarmmanager') . ': ' . (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_producer_receipts WHERE status = 'pending'", [], '', false) . '. ' . __esc('Use the producer CLI to retry pending receipts after database recovery.', 'alarmmanager') . '</p>';
	$rows = alarmmanager_db_rows('SELECT source, scope, resource_id, revision, definition FROM plugin_alarmmanager_producer_config ORDER BY source, scope, resource_id LIMIT 257');
	print '<p>' . __esc('Legacy is the default. Resource overrides template overrides plugin default. Saved policy remains intact if Alarm Manager is disabled; new notifications then use legacy fallback. Accepted notifications retain centralized ownership.', 'alarmmanager') . '</p>';
	print '<p>' . __esc('Centralized channel handoff replaces the selected source delivery with the routing profile endpoints. Configure recipients, templates, attachments and reviewed script wrappers on those endpoints before enabling it. Unselected source channels retain their existing behavior.', 'alarmmanager') . '</p>';

	if (count($rows) > 256) {
		throw new RuntimeException('producer_configuration_limit');
	}
	$rows[] = ['source' => 'thold', 'scope' => 'default', 'resource_id' => 0, 'revision' => 0, 'definition' => '{}'];

	foreach ($rows as $row) {
		$definition = AlarmManagerProducerBridge::validate(json_decode($row['definition'], true, 8, JSON_THROW_ON_ERROR));
		$new        = (int) $row['revision'] === 0;
		print '<details class="alarmmanagerDashboardCard" ' . ($new ? 'open' : '') . '><summary>' . __esc($new ? 'New producer policy' : $row['source'] . ' ' . $row['scope'] . ' #' . $row['resource_id'], 'alarmmanager') . '</summary>';
		alarmmanager_form_start('producer_save', $revision, ['producer_revision' => $row['revision']]);

		foreach (['source' => ['thold', 'syslog'], 'scope' => ['default', 'template', 'resource'], 'mode' => ['legacy', 'observe', 'centralized']] as $field => $options) {
			$value = $field === 'mode' ? $definition['mode'] : $row[$field];
			print '<label>' . __esc(ucfirst($field), 'alarmmanager') . ' <select name="' . $field . '" ' . (!$new && $field !== 'mode' ? 'disabled' : '') . '>';

			foreach ($options as $option) {
				print '<option ' . ($value === $option ? 'selected' : '') . '>' . $option . '</option>';
			} print '</select></label>';

			if (!$new && $field !== 'mode') {
				print '<input type="hidden" name="' . $field . '" value="' . html_escape($value) . '">';
			}
		}
		alarmmanager_input('resource_id', 'Resource or template ID (default uses 0)', $row['resource_id'], 'number', 'min="0" required' . ($new ? '' : ' readonly'));
		alarmmanager_input('profile_id', 'Routing profile ID (0 uses ordinary matching rules in observe mode)', $definition['profile_id'], 'number', 'min="0" required');

		foreach (['email', 'script', 'ticket'] as $channel) {
			print '<label><input type="checkbox" name="channels[]" value="' . $channel . '" ' . (in_array($channel, $definition['channels'], true) ? 'checked' : '') . '> ' . __esc('Hand off ' . $channel, 'alarmmanager') . '</label>';
		}
		alarmmanager_checkbox('complete_history', 'Include source-logged suppressed notification events', $definition['complete_history']);
		alarmmanager_checkbox('migration_confirmed', 'I have configured the replacement endpoints and reviewed recipient, template, attachment and script behavior');

		if (!$new) {
			alarmmanager_checkbox('inherit', 'Remove this override and inherit the parent policy');
		}
		print '<button type="submit">' . __esc('Save producer policy', 'alarmmanager') . '</button></form></details>';
	}
}
