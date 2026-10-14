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
require_once(__DIR__ . '/web_auth.php');
require_once(__DIR__ . '/routing.php');
require_once(__DIR__ . '/credentials.php');
require_once(__DIR__ . '/delivery.php');
require_once(__DIR__ . '/../integrations/producer_bridge.php');

function alarmmanager_audit(int $user, string $action, int $resource = 0): void {
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_audit (user_id, action, resource_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [$user, $action, $resource]);
}
function alarmmanager_admin_configuration(): array {
	if (!alarmmanager_web_admin()) {
		throw new RuntimeException('permission_denied');
	}

	// One snapshot prevents loading a revision with another transaction's definition.
	return alarmmanager_transaction(function (): array {
		$row        = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1');
		$history    = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_configuration_history WHERE revision = ?', [$row['config_revision']]);
		$definition = $history ? json_decode($history['definition'], true, 24, JSON_THROW_ON_ERROR) : ['version' => 1, 'endpoints' => [], 'profiles' => [], 'rules' => []];

		return ['revision' => (int) $row['config_revision'], 'configuration' => $definition];
	});
}
function alarmmanager_admin_int($value, int $min = 1, int $max = 2147483647): int {
	$result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

	if ($result === false) {
		throw new InvalidArgumentException('invalid_action');
	}

	return $result;
}
function alarmmanager_admin_json($value, int $limit = 131072): array {
	if (!is_string($value) || strlen($value) > $limit) {
		throw new InvalidArgumentException('invalid_action');
	}
	$result = json_decode($value, true, 24, JSON_THROW_ON_ERROR);

	if (!is_array($result)) {
		throw new InvalidArgumentException('invalid_action');
	}

	return $result;
}
function alarmmanager_admin_replay(string $queue, int $id, int $user): void {
	$table = match ($queue) {
		'processing' => 'plugin_alarmmanager_jobs', 'delivery' => 'plugin_alarmmanager_deliveries', default => throw new InvalidArgumentException('invalid_queue')
	};
	alarmmanager_transaction(function () use ($table, $queue, $id, $user): void {
		$row = alarmmanager_db_row("SELECT id FROM $table WHERE id = ? AND status = 'failed' FOR UPDATE", [$id]);

		if (!$row) {
			throw new InvalidArgumentException('replay_requires_failed_job');
		}
		alarmmanager_db_write("UPDATE $table SET status = 'pending', attempts = 0, available_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), lease_token = NULL, lease_until = NULL, last_error = '' WHERE id = ?", [$id]);
		alarmmanager_db_write('DELETE FROM plugin_alarmmanager_failed_jobs WHERE queue = ? AND job_id = ?', [$queue, $id]);
		alarmmanager_audit($user, 'replay_' . $queue, $id);
	});
}
/** This is the sole browser mutation dispatcher; secrets are never returned. */
function alarmmanager_admin_action(#[\SensitiveParameter] array $input): string {
	alarmmanager_web_mutation_guard();
	$user   = alarmmanager_web_user();
	$action = $input['action'] ?? '';

	if (!is_string($action)) {
		throw new InvalidArgumentException('invalid_action');
	}

	if ($action === 'producer_save') {
		return alarmmanager_producer_save($input, $user);
	}

	if ($action === 'credential_save') {
		$id     = alarmmanager_admin_int($input['id'] ?? null);
		$record = ['headers' => alarmmanager_admin_json($input['headers'] ?? '{}', 65536)];

		if (($input['basic_username'] ?? '') !== '' || ($input['basic_password'] ?? '') !== '') {
			$record['basic'] = ['username' => $input['basic_username'] ?? '', 'password' => $input['basic_password'] ?? ''];
		}
		alarmmanager_save_credential($id, $record, $user);

		return 'Credential saved. Existing values are never displayed.';
	}

	if ($action === 'credential_remove') {
		$id = alarmmanager_admin_int($input['id'] ?? null);
		alarmmanager_transaction(function () use ($id, $user): void {
			alarmmanager_secret_key(true);
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_credentials WHERE endpoint_id = ?', [$id]);
			alarmmanager_audit($user, 'credential_remove', $id);
		});

		return 'Credential removed.';
	}

	if ($action === 'key_rotate') {
		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		} alarmmanager_rotate_key($user);

		return 'Local key rotated. Create a new protected backup using the credential CLI.';
	}

	if ($action === 'replay') {
		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		} alarmmanager_admin_replay($input['queue'] ?? '', alarmmanager_admin_int($input['id'] ?? null, 1, PHP_INT_MAX), $user);

		return 'Failed job requeued with its original event and idempotency key.';
	}

	if ($action === 'acknowledge') {
		$stream = $input['stream_key'] ?? '';

		if (!is_string($stream) || !preg_match('/\A[a-f0-9]{64}\z/D', $stream)) {
			throw new InvalidArgumentException('invalid_action');
		}
		$value = ($input['acknowledged'] ?? '') === 'yes';
		alarmmanager_transaction(function () use ($stream, $value, $user): void {
			$row = alarmmanager_db_row('SELECT last_event_id FROM plugin_alarmmanager_states WHERE stream_key = ? AND active = 1 FOR UPDATE', [$stream]);

			if (!$row) {
				throw new InvalidArgumentException('active_condition_required');
			} alarmmanager_db_write('UPDATE plugin_alarmmanager_states SET acknowledged = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ?', [(int) $value, $stream]);
			alarmmanager_audit($user, $value ? 'acknowledge' : 'unacknowledge', (int) $row['last_event_id']);
		});

		return 'Acknowledgment updated. Detection and recovery state are unchanged.';
	}

	if ($action === 'test_send' || $action === 'preview') {
		$id    = alarmmanager_admin_int($input['id'] ?? null);
		$event = alarmmanager_admin_json($input['event_json'] ?? '', 65536);

		if ($action === 'preview') {
			$endpoint = alarmmanager_db_row('SELECT id, type FROM plugin_alarmmanager_endpoints WHERE id = ?', [$id]);

			if (!$endpoint) {
				throw new InvalidArgumentException('invalid_endpoint');
			}

			return json_encode(alarmmanager_delivery_preview($event, $endpoint), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
		}

		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		}
		$id = alarmmanager_enqueue_test($event, $id, $user);

		return 'Test queued for delivery workers; event row ' . $id . '.';
	}

	if (!in_array($action, ['configuration_save', 'endpoint_save', 'profile_save', 'rule_save'], true)) {
		throw new InvalidArgumentException('invalid_action');
	}
	$revision = alarmmanager_admin_int($input['revision'] ?? null, 0, PHP_INT_MAX);
	$state    = alarmmanager_admin_configuration();

	if ($state['revision'] !== $revision) {
		throw new InvalidArgumentException('configuration_changed');
	}
	$settings = $state['configuration'];

	if ($action === 'configuration_save') {
		$settings = alarmmanager_admin_json($input['configuration_json'] ?? '', 1048576);
	} else {
		$id   = alarmmanager_admin_int($input['id'] ?? null);
		$name = $input['name'] ?? '';

		if (!is_string($name)) {
			throw new InvalidArgumentException('invalid_action');
		}
		$item    = ['id' => $id, 'name' => $name, 'enabled' => isset($input['enabled'])];
		$section = match ($action) {
			'endpoint_save' => 'endpoints', 'profile_save' => 'profiles', 'rule_save' => 'rules'
		};

		if ($section === 'endpoints') {
			$type = $input['type'] ?? '';

			if (!is_string($type)) {
				throw new InvalidArgumentException('invalid_action');
			}
			$item['type'] = $type;

			foreach (['max_concurrency' => [1, 64, 1], 'timeout_seconds' => [1, 120, 10], 'max_attempts' => [1, 20, 5], 'min_interval_ms' => [0, 3600000, 0]] as $key => [$min, $max, $default]) {
				$item[$key] = alarmmanager_admin_int($input[$key] ?? $default, $min, $max);
			}
			$transport = alarmmanager_admin_json($input['transport_json'] ?? '{}');

			if (in_array($type, ['restapi', 'webhook'], true)) {
				$transport['url']        = $input['url'] ?? '';
				// Browser field is http_method (a "method" control would shadow
				// form.method and break csrf-magic.js); the stored key stays "method".
				$transport['method']     = $input['http_method'] ?? 'POST';
				$transport['ca_bundle']  = ($input['ca_bundle'] ?? '') === '' ? null : $input['ca_bundle'];
				$transport['credential'] = isset($input['credential']);
				$transport['allow_http'] = isset($input['allow_http']);
			}
			$transport['mapping'] = alarmmanager_admin_json($input['mapping_json'] ?? '{}');
			$item['transport']    = $transport;
		} elseif ($section === 'profiles') {
			$list = $input['endpoints'] ?? [];

			if (!is_array($list) || !array_is_list($list) || count($list) > 32) {
				throw new InvalidArgumentException('invalid_action');
			}
			$item['endpoints'] = array_map(static fn ($value) => alarmmanager_admin_int($value), $list);
		} else {
			$item += ['priority' => alarmmanager_admin_int($input['priority'] ?? 100, 0, 10000), 'profile_id' => alarmmanager_admin_int($input['profile_id'] ?? null), 'stop' => isset($input['stop']), 'policy' => alarmmanager_admin_json($input['policy_json'] ?? '{}')];
		}
		$found = false;

		foreach ($settings[$section] as &$existing) {
			if ((int) $existing['id'] === $id) {
				$existing = $item;
				$found    = true;

				break;
			}
		} unset($existing);

		if (!$found) {
			$settings[$section][] = $item;
		}
	}
	alarmmanager_apply_configuration($settings, $revision, $user);

	return 'Configuration saved.';
}

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
