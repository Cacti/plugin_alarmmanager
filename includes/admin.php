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
require_once(__DIR__ . '/supervisor.php');

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
/** Native endpoint fields override advanced JSON; other transports' options never carry over. */
/**
 * @param  array<string, mixed> $transport
 * @param  array<string, mixed> $input
 * @return array<string, mixed>
 */
function alarmmanager_admin_transport_fields(string $type, array $transport, array $input): array {
	if (!isset($input['endpoint_fields'])) {
		return $transport;
	}
	$keys = match ($type) {
		'restapi', 'webhook' => ['url', 'method', 'ca_bundle', 'credential', 'allow_http', 'success_codes', 'response_match', 'connect_timeout_seconds', 'response_reference', 'transition_overrides'],
		'email'              => ['to', 'from', 'html', 'attachments'],
		'script'             => ['executable', 'arguments', 'compatibility', 'retry_exit_codes', 'response_reference'],
		default              => []
	};
	$transport = array_intersect_key($transport, array_flip($keys));

	if (in_array($type, ['restapi', 'webhook', 'script'], true) && array_key_exists('reference_id_path', $input)) {
		$id_path  = $input['reference_id_path'];
		$url_path = $input['reference_url_path'] ?? '';

		if (!is_string($id_path) || !is_string($url_path) || ($id_path === '' && $url_path !== '')) {
			throw new InvalidArgumentException('invalid_reference_paths');
		}
		$transport['response_reference'] = $id_path === '' ? null : ['id' => $id_path] + ($url_path === '' ? [] : ['url' => $url_path]);
	}

	if ($type === 'email') {
		foreach (['to', 'from'] as $key) {
			$value = $input['email_' . $key] ?? '';

			if (!is_string($value) || strlen($value) > 8192) {
				throw new InvalidArgumentException('invalid_recipients');
			}
			$value           = trim($value);
			$transport[$key] = str_starts_with($value, '[') || str_starts_with($value, '{') ? alarmmanager_admin_json($value, 8192) : $value;
		}
		$transport['html'] = isset($input['email_html']);
	} elseif ($type === 'script') {
		$transport['executable']    = $input['script_executable'] ?? '';
		$transport['arguments']     = alarmmanager_admin_json($input['script_arguments'] ?? '[]');
		$transport['compatibility'] = 'json';
	}

	return $transport;
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
function alarmmanager_admin_replay(string $queue, int $id): void {
	$table = match ($queue) {
		'processing' => 'plugin_alarmmanager_jobs', 'delivery' => 'plugin_alarmmanager_deliveries', default => throw new InvalidArgumentException('invalid_queue')
	};
	alarmmanager_transaction(function () use ($table, $queue, $id): void {
		if ($queue === 'delivery' && !alarmmanager_db_row('SELECT ep.id FROM plugin_alarmmanager_endpoints AS ep INNER JOIN plugin_alarmmanager_deliveries AS d ON d.endpoint_id = ep.id WHERE d.id = ? FOR UPDATE', [$id])) {
			throw new InvalidArgumentException('invalid_endpoint');
		}
		$row = alarmmanager_db_row("SELECT id FROM $table WHERE id = ? AND status = 'failed' FOR UPDATE", [$id]);

		if (!$row) {
			throw new InvalidArgumentException('replay_requires_failed_job');
		}
		alarmmanager_db_write("UPDATE $table SET status = 'pending', attempts = 0, available_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), lease_token = NULL, lease_until = NULL, last_error = '' WHERE id = ?", [$id]);
		alarmmanager_db_write('DELETE FROM plugin_alarmmanager_failed_jobs WHERE queue = ? AND job_id = ?', [$queue, $id]);
	});
}
/**
 * @param  array<string, mixed> $input
 * @return array<string, mixed>
 */
function alarmmanager_admin_rule_policy(array $input): array {
	if (!isset($input['rule_fields'])) {
		return alarmmanager_validate_rule_definition(alarmmanager_admin_json($input['policy_json'] ?? '{}'));
	}
	$policy = ['match' => [], 'suppress_acknowledged' => isset($input['suppress_acknowledged'])];

	foreach (['sources', 'severities', 'transitions', 'device_ids', 'collector_ids', 'tags'] as $field) {
		if (!isset($input['filter_' . $field])) {
			continue;
		}
		$values = $input['match_' . $field] ?? [];

		if (in_array($field, ['sources', 'tags'], true)) {
			if (!is_string($values) || strlen($values) > 8192) {
				throw new InvalidArgumentException('invalid_rule_filters');
			}
			$values = alarmmanager_admin_json($values, 8192);
		}

		if (!is_array($values) || !array_is_list($values) || count($values) > 32) {
			throw new InvalidArgumentException('invalid_rule_filters');
		}

		if (in_array($field, ['device_ids', 'collector_ids'], true)) {
			$values = array_map(static fn ($value) => alarmmanager_admin_int($value), $values);
		}
		$policy['match'][$field] = $values;
	}

	foreach (['schedule', 'maintenance_schedule'] as $field) {
		$policy[$field] = null;

		if (!isset($input[$field . '_enabled'])) {
			continue;
		}
		$days = $input[$field . '_days'] ?? [];

		if (!is_array($days) || !array_is_list($days) || !$days || count($days) > 7) {
			throw new InvalidArgumentException('invalid_rule_schedule');
		}
		$policy[$field] = ['timezone' => $input[$field . '_timezone'] ?? '', 'days' => array_map(static fn ($day) => alarmmanager_admin_int($day, 1, 7), $days), 'start' => $input[$field . '_start'] ?? '', 'end' => $input[$field . '_end'] ?? ''];
	}

	foreach (['cooldown_seconds', 'duplicate_seconds'] as $field) {
		$policy[$field] = alarmmanager_admin_int($input[$field] ?? 0, 0, 86400);
	}

	return alarmmanager_validate_rule_definition($policy);
}

/** This is the sole browser mutation dispatcher; secrets are never returned. */
function alarmmanager_admin_action(#[\SensitiveParameter] array $input): string {
	alarmmanager_web_mutation_guard();
	$action = $input['action'] ?? '';

	if (!is_string($action)) {
		throw new InvalidArgumentException('invalid_action');
	}

	if (in_array($action, ['queue_pause', 'queue_resume'], true)) {
		alarmmanager_db_write("REPLACE INTO settings (name, value) VALUES ('alarmmanager_notifications_paused', ?)", [$action === 'queue_pause' ? '1' : '0']);
		alarmmanager_log('Alarm Queue ' . ($action === 'queue_pause' ? 'paused' : 'resumed') . ' by user ' . alarmmanager_web_user());

		return 'Alarm Queue updated.';
	}

	if ($action === 'queue_clear') {
		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		}
		alarmmanager_db_write("UPDATE plugin_alarmmanager_deliveries AS d
			INNER JOIN plugin_alarmmanager_events AS e ON e.id = d.event_row_id
			SET d.status = 'failed', d.last_error = 'queue_cleared', d.updated_at = UTC_TIMESTAMP(6)
			WHERE d.status = 'pending' AND e.processing_poller_id = ?", [alarmmanager_local_poller()]);
		alarmmanager_log('Waiting notifications cleared by user ' . alarmmanager_web_user());

		return 'Waiting notifications cleared. History preserved.';
	}

	if ($action === 'daemon_save') {
		$settings                       = alarmmanager_supervisor_options();
		$settings['enabled']            = isset($input['daemon_enabled']);
		$settings['processing_workers'] = alarmmanager_admin_int($input['processing_workers'] ?? null, 1, 64);
		$settings['delivery_workers']   = alarmmanager_admin_int($input['delivery_workers'] ?? null, 0, 64);

		if ($settings['enabled'] && !alarmmanager_daemon_status()) {
			throw new RuntimeException('daemon_not_running');
		}
		alarmmanager_save_supervisor_options($settings);

		return 'Daemon settings saved.';
	}

	if ($action === 'credential_save') {
		$id     = alarmmanager_admin_int($input['id'] ?? null);
		$record = alarmmanager_admin_credential($input) ?? ['headers' => []];
		alarmmanager_save_credential($id, $record);

		return 'Credential saved. Existing values are never displayed.';
	}

	if ($action === 'credential_remove') {
		$id = alarmmanager_admin_int($input['id'] ?? null);
		alarmmanager_transaction(function () use ($id): void {
			alarmmanager_secret_key(true);
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_credentials WHERE endpoint_id = ?', [$id]);
		});

		return 'Credential removed.';
	}

	if ($action === 'key_rotate') {
		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		} alarmmanager_rotate_key();

		return 'Local key rotated. Create a new protected backup using the credential CLI.';
	}

	if ($action === 'replay') {
		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		} alarmmanager_admin_replay($input['queue'] ?? '', alarmmanager_admin_int($input['id'] ?? null, 1, PHP_INT_MAX));

		return 'Failed job requeued with its original event and idempotency key.';
	}

	if ($action === 'acknowledge') {
		$stream = $input['stream_key'] ?? '';

		if (!is_string($stream) || !preg_match('/\A[a-f0-9]{64}\z/D', $stream)) {
			throw new InvalidArgumentException('invalid_action');
		}
		$value = ($input['acknowledged'] ?? '') === 'yes';
		alarmmanager_transaction(function () use ($stream, $value): void {
			$row = alarmmanager_db_row('SELECT last_event_id FROM plugin_alarmmanager_states WHERE stream_key = ? AND active = 1 FOR UPDATE', [$stream]);

			if (!$row) {
				throw new InvalidArgumentException('active_condition_required');
			} alarmmanager_db_write('UPDATE plugin_alarmmanager_states SET acknowledged = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ?', [(int) $value, $stream]);
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
		$id = alarmmanager_enqueue_test($event, $id);

		return 'Test queued for delivery workers; event row ' . $id . '.';
	}

	if (!in_array($action, ['configuration_save', 'endpoint_save', 'endpoint_delete', 'profile_save', 'rule_save'], true)) {
		throw new InvalidArgumentException('invalid_action');
	}
	$revision = alarmmanager_admin_int($input['revision'] ?? null, 0, PHP_INT_MAX);
	$state    = alarmmanager_admin_configuration();

	if ($state['revision'] !== $revision) {
		throw new InvalidArgumentException('configuration_changed');
	}

	if ($action === 'endpoint_delete') {
		$id = alarmmanager_admin_int($input['id'] ?? null, 1);

		if (($input['confirmed'] ?? '') !== 'yes') {
			throw new InvalidArgumentException('confirmation_required');
		}
		alarmmanager_transaction(function () use ($state, $revision, $id): void {
			$locked = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');

			if ((int) $locked['config_revision'] !== $revision) {
				throw new InvalidArgumentException('configuration_changed');
			}
			$endpoint = alarmmanager_db_row('SELECT id, enabled FROM plugin_alarmmanager_endpoints WHERE id = ? FOR UPDATE', [$id]);

			if (!$endpoint) {
				throw new InvalidArgumentException('invalid_action');
			}
			$settings = $state['configuration'];

			foreach ($settings['profiles'] as $profile) {
				if (in_array($id, $profile['endpoints'], true)) {
					throw new InvalidArgumentException('endpoint_in_profile');
				}
			}

			if (alarmmanager_db_row("SELECT id FROM plugin_alarmmanager_deliveries WHERE endpoint_id = ? AND status IN ('pending', 'leased') LIMIT 1 FOR UPDATE", [$id])) {
				throw new InvalidArgumentException('endpoint_has_active_deliveries');
			}
			$settings['endpoints'] = array_values(array_filter($settings['endpoints'], static fn (array $item): bool => (int) $item['id'] !== $id));

			foreach (['credentials', 'transports', 'notification_state'] as $table) {
				alarmmanager_db_write('DELETE FROM plugin_alarmmanager_' . $table . ' WHERE endpoint_id = ?', [$id]);
			}
			alarmmanager_db_write('DELETE FROM plugin_alarmmanager_endpoints WHERE id = ?', [$id]);
			alarmmanager_write_configuration(alarmmanager_configuration($settings), $revision);
		});

		return 'Endpoint deleted.';
	}
	$settings       = $state['configuration'];
	$credential     = null;
	$create_section = null;
	$id             = 0;

	if ($action === 'configuration_save') {
		$settings = alarmmanager_admin_json($input['configuration_json'] ?? '', 1048576);
	} else {
		$id   = alarmmanager_admin_int($input['id'] ?? 0, 0);
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
			$credential   = isset($input['endpoint_fields']) && !in_array($type, ['restapi', 'webhook'], true) ? null : alarmmanager_admin_credential($input, $id === 0);

			if ($credential !== null && !in_array($type, ['restapi', 'webhook'], true)) {
				throw new InvalidArgumentException('credential_endpoint_invalid');
			}

			$item['max_concurrency'] = array_column($settings['endpoints'], null, 'id')[$id]['max_concurrency'] ?? 1;

			foreach (['timeout_seconds' => [1, 120, 10], 'max_attempts' => [1, 20, 5], 'min_interval_ms' => [0, 3600000, 0]] as $key => [$min, $max, $default]) {
				$item[$key] = alarmmanager_admin_int($input[$key] ?? $default, $min, $max);
			}
			$transport = alarmmanager_admin_transport_fields($type, alarmmanager_admin_json($input['transport_json'] ?? '{}'), $input);

			if (in_array($type, ['restapi', 'webhook'], true)) {
				$transport['url']        = $input['url'] ?? '';
				// Browser field is http_method (a "method" control would shadow
				// form.method and break csrf-magic.js); the stored key stays "method".
				$transport['method']     = $input['http_method'] ?? 'POST';
				$transport['ca_bundle']  = ($input['ca_bundle'] ?? '') === '' ? null : $input['ca_bundle'];
				$transport['credential'] = match ($input['auth_type'] ?? '') {
					'none'  => false,
					'keep'  => (bool) (array_column($settings['endpoints'], null, 'id')[$id]['transport']['credential'] ?? false),
					default => isset($input['credential']) || $credential !== null
				};
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
			$item += ['priority' => alarmmanager_admin_int($input['priority'] ?? 100, 0, 10000), 'profile_id' => alarmmanager_admin_int($input['profile_id'] ?? null), 'stop' => isset($input['stop']), 'policy' => alarmmanager_admin_rule_policy($input)];
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
			if ($id !== 0) {
				throw new InvalidArgumentException('invalid_action');
			}
			$settings[$section][] = $item;
			$create_section       = $section;
		}
	}

	alarmmanager_transaction(function () use ($settings, $revision, $credential, $create_section, $id): void {
		if ($create_section !== null) {
			$index          = array_key_last($settings[$create_section]);
			$item           = $settings[$create_section][$index];
			[$sql, $params] = match ($create_section) {
				'endpoints' => ['INSERT INTO plugin_alarmmanager_endpoints (name, type) VALUES (?, ?)', [$item['name'], $item['type']]],
				'profiles'  => ['INSERT INTO plugin_alarmmanager_profiles (name, endpoints_json) VALUES (?, ?)', [$item['name'], json_encode($item['endpoints'], JSON_THROW_ON_ERROR)]],
				'rules'     => ['INSERT INTO plugin_alarmmanager_rules (name, priority, profile_id, definition) VALUES (?, ?, ?, ?)', [$item['name'], $item['priority'], $item['profile_id'], json_encode($item['policy'], JSON_THROW_ON_ERROR)]]
			};
			alarmmanager_db_write($sql, $params);
			$id                                      = alarmmanager_config_int(db_fetch_cell_prepared('SELECT LAST_INSERT_ID()', [], '', false), 1, 2147483647);
			$settings[$create_section][$index]['id'] = $id;
		}
		$settings = alarmmanager_configuration($settings);
		alarmmanager_write_configuration($settings, $revision);

		if ($credential !== null) {
			alarmmanager_write_credential($id, $credential);
		}
	});
	unset($GLOBALS['alarmmanager_route_cache']);

	return 'Configuration saved.';
}

/**
 * @param  array<string, mixed>      $input
 * @return array<string, mixed>|null
 */
function alarmmanager_admin_credential(#[\SensitiveParameter] array $input, bool $allow_unfilled = false): ?array {
	$type = $input['auth_type'] ?? null;

	if ($type !== null) {
		if (!in_array($type, ['none', 'keep', 'bearer', 'basic', 'headers'], true)) {
			throw new InvalidArgumentException('invalid_credentials');
		}

		if ($type === 'none' || $type === 'keep') {
			return null;
		}
		$fields = match ($type) {
			'bearer' => ['bearer_token'], 'basic' => ['basic_username', 'basic_password'], 'headers' => ['headers']
		};
		$input = array_intersect_key($input, array_flip($fields));
	}
	$record = ['headers' => alarmmanager_admin_json($input['headers'] ?? '{}', 65536)];
	$token  = $input['bearer_token'] ?? '';

	if (!is_string($token)) {
		throw new InvalidArgumentException('invalid_credentials');
	}

	if ($token !== '') {
		foreach (array_keys($record['headers']) as $name) {
			if (is_string($name) && strtolower($name) === 'authorization') {
				throw new InvalidArgumentException('invalid_credentials');
			}
		}
		$record['headers']['Authorization'] = 'Bearer ' . $token;
	}

	if (($input['basic_username'] ?? '') !== '' || ($input['basic_password'] ?? '') !== '') {
		$record['basic'] = ['username' => $input['basic_username'] ?? '', 'password' => $input['basic_password'] ?? ''];
	}
	$record = alarmmanager_credential_validate($record);

	// Creating an endpoint may defer credentials: an integration preset draft
	// saves first and the credential record is attached afterwards, so an
	// explicitly chosen authentication type stays valid while every credential
	// field is still empty. Credential replacement always requires material.
	if ($allow_unfilled && !$record['headers'] && !isset($record['basic'])) {
		return null;
	}

	if ($type !== null && (!$record['headers'] && !isset($record['basic']) || $type === 'basic' && ($record['basic']['username'] ?? '') === '')) {
		throw new InvalidArgumentException('invalid_credentials');
	}

	return $record['headers'] || isset($record['basic']) ? $record : null;
}

/** Return only approved messages; exception text may contain submitted secrets. */
function alarmmanager_admin_error_message(Throwable $error): string {
	if ($error instanceof JsonException) {
		return 'A JSON field is invalid. Check the advanced message configuration, transport settings and value replacements.';
	}

	return match ($error->getMessage()) {
		'daemon_not_running'             => 'Start the Alarm Manager service on this collector before enabling daemon workers.',
		'daemon_config_write_failed'     => 'Daemon settings could not be saved. Check the Cacti cache directory permissions.',
		'endpoint_in_profile'            => 'Remove this endpoint from its routing profiles before deleting.',
		'endpoint_has_active_deliveries' => 'This endpoint has queued or running deliveries. Resolve them before deleting.',
		'test_endpoint_unavailable'      => 'The selected endpoint is disabled or no longer exists. Enable and save it before queuing a test send.',
		'invalid_credentials'            => 'Enter the credentials for the selected authentication type, choose Keep saved credentials, or choose None for an incoming webhook.',
		'confirmation_required'          => 'Select the confirmation checkbox before continuing.',
		'configuration_changed'          => 'Configuration changed. Reload before applying your edits.',
		'permission_denied'              => 'Action failed. Administrator access, POST and a valid CSRF token are required.',
		'invalid_transport'              => 'Action failed. Transport settings do not match the endpoint type.',
		'invalid_transport_url'          => 'Action failed. Use an HTTPS URL without credentials, query parameters or fragments.',
		'invalid_mapping_path'           => 'Message field names must start with a letter or underscore and use letters, digits, underscores or dot-separated paths.',
		'conflicting_mapping'            => 'Message fields have duplicate or overlapping destination names. Give each field a unique destination name.',
		'ambiguous_mapping'              => 'Each message field must use exactly one Cacti value, custom value or combined text template.',
		'unknown_mapping_field'          => 'A message uses an unknown variable. Choose variables from Insert variable or the Cacti Value list.',
		'invalid_mapping_template'       => 'Combined text contains an invalid variable. Use {{device.hostname}} syntax or Insert variable.',
		'mapping_type'                   => 'A message value does not match its selected format. Lists such as device tags cannot be inserted into Combined text.',
		'mapping_required'               => 'A required message value is missing from this event. Set a fallback or choose an available value.',
		'mapping_value'                  => 'A message value has no matching entry in Value replacements.',
		'mapping_limit'                  => 'Message configuration exceeds the supported size. Shorten the message or reduce its fields.',
		'invalid_transport_method'       => 'Choose POST, PUT or PATCH for the delivery method.',
		'invalid_recipients'             => 'Enter valid email recipients and sender settings.',
		'invalid_executable'             => 'Enter an absolute executable path for script delivery.',
		'invalid_mapping'                => 'Action failed. Each mapping transition must contain a list of fields.',
		'invalid_action'                 => 'Action failed. A required field is missing or outside its allowed range.',
		'invalid_rule_filters'           => 'Choose up to 32 values for each enabled alarm filter.',
		'invalid_rule_schedule'          => 'Choose at least one day for each enabled schedule.',
		'invalid_schedule'               => 'Choose a valid timezone and start and end times for each schedule.',
		'invalid_configuration'          => 'Action failed. Configuration fields are invalid.',
		default                          => 'The action could not be completed. Check the Cacti log for an internal error. Submitted credential values were cleared.'
	};
}
