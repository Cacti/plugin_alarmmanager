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
require_once(__DIR__ . '/remote.php');

function alarmmanager_admin_configuration(): array {
	if (!alarmmanager_web_admin()) {
		throw new RuntimeException('permission_denied');
	}

	// One snapshot prevents loading a revision with another transaction's definition.
	return alarmmanager_transaction(function (): array {
		$row        = alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1');
		$history    = alarmmanager_db_row('SELECT definition FROM plugin_alarmmanager_configuration_history WHERE revision = ?', [$row['config_revision']]);
		$definition = $history ? json_decode($history['definition'], true, 24, JSON_THROW_ON_ERROR) : ['version' => 1, 'endpoints' => [], 'rules' => []];

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
		'restapi', 'webhook' => ['provider', 'twilio_whatsapp', 'body_encoding', 'teams_workflow', 'url', 'method', 'ca_bundle', 'credential', 'allow_http', 'success_codes', 'response_match', 'connect_timeout_seconds', 'response_reference', 'transition_overrides'],
		'email'              => ['to', 'from', 'html', 'attachments'],
		'script'             => ['executable', 'arguments', 'compatibility', 'retry_exit_codes', 'response_reference'],
		'snmptrap'           => ['base_oid'],
		'syslog'             => [],
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
	} elseif ($type === 'snmptrap') {
		$transport += ['host' => $input['snmp_host'] ?? '', 'port' => alarmmanager_admin_int($input['snmp_port'] ?? 162, 1, 65535)];

		foreach (['version', 'transport', 'notification'] as $key) {
			$transport[$key] = $input['snmp_' . $key] ?? '';
		}

		if ($transport['version'] === '3') {
			foreach (['security_name', 'security_level', 'auth_protocol', 'priv_protocol'] as $key) {
				$transport[$key] = $input['snmp_' . $key] ?? '';
			}

			if (($input['snmp_engine_id'] ?? '') !== '') {
				$transport['engine_id'] = $input['snmp_engine_id'];
			}
		}
	} elseif ($type === 'syslog') {
		foreach (['host', 'protocol', 'format', 'facility', 'app_name'] as $key) {
			$transport[$key] = $input['syslog_' . $key] ?? '';
		}
		$transport['port'] = alarmmanager_admin_int($input['syslog_port'] ?? 514, 1, 65535);

		if (($input['syslog_ca_bundle'] ?? '') !== '') {
			$transport['ca_bundle'] = $input['syslog_ca_bundle'];
		}
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
/**
 * Read only a PHP-verified upload; never accept a client-supplied filesystem path.
 * @param  array<string, mixed> $file
 * @return array<string, mixed>
 */
function alarmmanager_admin_configuration_upload(array $file): array {
	if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
		throw new InvalidArgumentException('invalid_configuration_upload');
	}
	$json = file_get_contents($file['tmp_name'], false, null, 0, 1048577);

	if ($json === false || $json === '' || strlen($json) > 1048576) {
		throw new InvalidArgumentException('invalid_configuration_upload');
	}

	return alarmmanager_admin_json($json, 1048576);
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
	$policy = ['match' => [], 'alarm_query' => isset($input['all_alarm_types']) ? '' : ($input['alarm_query'] ?? ''), 'device_query' => isset($input['all_devices']) ? '' : ($input['device_query'] ?? ''), 'suppress_acknowledged' => isset($input['suppress_acknowledged'])];

	foreach (isset($input['all_alarm_types']) ? [] : ['sources', 'severities', 'transitions', 'device_ids', 'collector_ids', 'tags'] as $field) {
		if (!isset($input['filter_' . $field])) {
			continue;
		}
		$values = $input['match_' . $field] ?? [];

		if ($field === 'tags') {
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

	$recipients = $input['recipient_numbers'] ?? [];

	if (!is_array($recipients) || count($recipients) > 32) {
		throw new InvalidArgumentException('invalid_twilio_recipients');
	}
	$destinations = $input['endpoints'] ?? [];

	if (!is_array($destinations) || !array_is_list($destinations) || count($destinations) > 32) {
		throw new InvalidArgumentException('invalid_twilio_recipients');
	}
	$destinations = array_map(static fn ($id) => alarmmanager_admin_int($id), $destinations);

	foreach ($recipients as $id => $text) {
		if (!is_string($text) || strlen($text) > 2048) {
			throw new InvalidArgumentException('invalid_twilio_recipients');
		}

		if (!in_array(alarmmanager_admin_int($id), $destinations, true)) {
			continue;
		}
		$numbers = alarmmanager_twilio_recipients(preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));

		if ($numbers) {
			$policy['recipient_overrides'][alarmmanager_admin_int($id)] = $numbers;
		}
	}
	$enabled_templates = $input['template_enabled'] ?? [];
	$templates         = $input['template_mapping'] ?? [];

	if (!is_array($enabled_templates) || !array_is_list($enabled_templates) || count($enabled_templates) > 32 || !is_array($templates)) {
		throw new InvalidArgumentException('invalid_mapping');
	}

	foreach ($enabled_templates as $destination) {
		$id                                = alarmmanager_admin_int($destination);
		$policy['template_overrides'][$id] = alarmmanager_admin_json($templates[$id] ?? '{}');
	}

	return alarmmanager_validate_rule_definition($policy);
}

/**
 * Validate all daemon fields before any settings are written.
 * @param  array<string, mixed> $input
 * @return array<string, mixed>
 */
function alarmmanager_admin_supervisor_settings(array $input): array {
	$settings                       = alarmmanager_supervisor_options();
	$settings['enabled']            = isset($input['daemon_enabled']);
	$settings['processing_workers'] = alarmmanager_admin_int($input['processing_workers'] ?? null, 1, 64);
	$settings['delivery_workers']   = alarmmanager_admin_int($input['delivery_workers'] ?? null, 0, 64);

	if ($settings['enabled'] && !alarmmanager_daemon_status()) {
		throw new RuntimeException('daemon_not_running');
	}

	return $settings;
}

/** This is the sole browser mutation dispatcher; secrets are never returned. */
function alarmmanager_admin_action(#[\SensitiveParameter] array $input): string {
	alarmmanager_web_mutation_guard();
	$action = $input['action'] ?? '';

	if (!is_string($action)) {
		throw new InvalidArgumentException('invalid_action');
	}

	if (in_array($action, ['queue_pause', 'queue_resume'], true)) {
		alarmmanager_transaction(function () use ($action): void {
			alarmmanager_db_write("REPLACE INTO settings (name, value) VALUES ('alarmmanager_notifications_paused', ?)", [$action === 'queue_pause' ? '1' : '0']);
			alarmmanager_request_configuration_replication();
		});
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
		$settings = alarmmanager_admin_supervisor_settings($input);
		alarmmanager_save_supervisor_options($settings);
		alarmmanager_request_configuration_replication();

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
			alarmmanager_request_configuration_replication();
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

	if (!in_array($action, ['configuration_save', 'settings_save', 'device_summaries_save', 'endpoint_save', 'endpoint_delete', 'rule_save'], true)) {
		throw new InvalidArgumentException('invalid_action');
	}
	$test_destination = $action === 'endpoint_save' && ($input['test_destination'] ?? '') === 'yes';

	if ($test_destination && !isset($input['enabled'])) {
		throw new InvalidArgumentException('test_endpoint_unavailable');
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

			foreach ($settings['rules'] as $rule) {
				if (in_array($id, $rule['endpoints'], true)) {
					throw new InvalidArgumentException('endpoint_in_rule');
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
	$supervisor          = null;
	$previous_supervisor = null;
	$supervisor_saved    = false;
	$settings            = $state['configuration'];
	$credential          = null;
	$provider_changed    = false;
	$create_section      = null;
	$id                  = 0;

	if ($action === 'configuration_save') {
		$settings = isset($_FILES['configuration_file'])
			? alarmmanager_admin_configuration_upload($_FILES['configuration_file'])
			: alarmmanager_admin_json($input['configuration_json'] ?? '', 1048576);
	} elseif (in_array($action, ['settings_save', 'device_summaries_save'], true)) {
		$settings['device_summaries'] = ['offline' => $input['offline_summary'] ?? '', 'recovering' => $input['recovering_summary'] ?? '', 'online' => $input['online_summary'] ?? ''];
	} else {
		$id   = alarmmanager_admin_int($input['id'] ?? 0, 0);
		$name = $input['name'] ?? '';

		if (!is_string($name)) {
			throw new InvalidArgumentException('invalid_action');
		}
		$item    = ['id' => $id, 'name' => $name, 'enabled' => isset($input['enabled'])];
		$section = match ($action) {
			'endpoint_save' => 'endpoints', 'rule_save' => 'rules'
		};

		if ($section === 'endpoints') {
			$type = $input['type'] ?? '';

			if (!is_string($type)) {
				throw new InvalidArgumentException('invalid_action');
			}
			$previous = array_column($settings['endpoints'], null, 'id')[$id] ?? [];
			$provider = $input['provider'] ?? ($previous['transport']['provider'] ?? '');

			if (!is_string($provider) || ($provider !== '' && !isset(alarmmanager_provider_definitions()[$provider]))) {
				throw new InvalidArgumentException('invalid_provider');
			}
			$provider_changed = $provider !== ($previous['transport']['provider'] ?? '');

			if ($id > 0 && $provider_changed && empty($input['provider_reset'])) {
				throw new InvalidArgumentException('confirmation_required');
			}

			if ($provider_changed && ($input['auth_type'] ?? '') === 'keep') {
				throw new InvalidArgumentException('provider_credentials_required');
			}

			if ($provider !== '') {
				$type = alarmmanager_integration_templates()[$provider]['type'];

				if (str_starts_with($provider, 'twilio') && ($input['twilio_auth_mode'] ?? '') === 'account') {
					$input['basic_username'] = $input['provider_account_sid'] ?? '';
				}
			}
			$item['type'] = $type;
			$credential   = match (true) {
				$type === 'snmptrap'                                                                => alarmmanager_admin_snmp_credential($input),
				isset($input['endpoint_fields']) && !in_array($type, ['restapi', 'webhook'], true)  => null,
				default                                                                             => alarmmanager_admin_credential($input, $id === 0 || ($provider !== '' && !$item['enabled']))
			};

			if ($credential !== null && !in_array($type, alarmmanager_credential_types(), true)) {
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
					'teams' => true,
					'none'  => false,
					'keep'  => (bool) (array_column($settings['endpoints'], null, 'id')[$id]['transport']['credential'] ?? false),
					default => isset($input['credential']) || $credential !== null
				};
				$transport['allow_http'] = isset($input['allow_http']);
			}

			if (!empty($transport['teams_workflow']) && $item['enabled']) {
				$saved = $credential;

				if ($saved === null && $id > 0 && alarmmanager_db_row('SELECT endpoint_id FROM plugin_alarmmanager_credentials WHERE endpoint_id = ?', [$id])) {
					$saved = alarmmanager_load_credential($id);
				}

				alarmmanager_teams_webhook_url($saved);
			}
			$transport['mapping'] = alarmmanager_admin_json($input['mapping_json'] ?? '{}');

			if ($provider !== '') {
				$transport = alarmmanager_provider_transport($provider, $transport, $input, $item['enabled']);

				if ($item['enabled']) {
					$saved = $credential;

					if ($saved === null && $id > 0 && !$provider_changed) {
						$saved = alarmmanager_load_credential($id);
					}
					alarmmanager_provider_credentials($provider, $saved);
				}
			} else {
				unset($transport['provider']);
			}
			$item['transport']    = $transport;
		} else {
			$list = $input['endpoints'] ?? [];

			if (!is_array($list) || !array_is_list($list) || !$list || count($list) > 32) {
				throw new InvalidArgumentException('invalid_action');
			}
			$item += ['endpoints' => array_values(array_unique(array_map(static fn ($value) => alarmmanager_admin_int($value), $list))), 'priority' => alarmmanager_admin_int($input['priority'] ?? 100, 0, 10000), 'stop' => isset($input['stop']), 'policy' => alarmmanager_admin_rule_policy($input)];
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

	if ($action === 'settings_save') {
		$previous_supervisor = alarmmanager_supervisor_options();
		$supervisor          = alarmmanager_admin_supervisor_settings($input);
	}

	try {
		alarmmanager_transaction(function () use ($settings, $revision, $credential, $create_section, &$id, $supervisor, &$supervisor_saved, $provider_changed): void {
			if ($create_section !== null) {
				$index          = array_key_last($settings[$create_section]);
				$item           = $settings[$create_section][$index];
				[$sql, $params] = match ($create_section) {
					'endpoints' => ['INSERT INTO plugin_alarmmanager_endpoints (name, type) VALUES (?, ?)', [$item['name'], $item['type']]],
					'rules'     => ['INSERT INTO plugin_alarmmanager_rules (name, priority, endpoints_json, definition) VALUES (?, ?, ?, ?)', [$item['name'], $item['priority'], json_encode($item['endpoints'], JSON_THROW_ON_ERROR), json_encode($item['policy'], JSON_THROW_ON_ERROR)]]
				};
				alarmmanager_db_write($sql, $params);
				$id                                      = alarmmanager_config_int(db_fetch_cell_prepared('SELECT LAST_INSERT_ID()', [], '', false), 1, 2147483647);
				$settings[$create_section][$index]['id'] = $id;
			}
			$settings = alarmmanager_configuration($settings);
			alarmmanager_write_configuration($settings, $revision);

			if ($provider_changed) {
				alarmmanager_db_write('DELETE FROM plugin_alarmmanager_credentials WHERE endpoint_id = ?', [$id]);
			}

			if ($credential !== null) {
				alarmmanager_write_credential($id, $credential);
			}

			if ($supervisor !== null) {
				alarmmanager_save_supervisor_options($supervisor);
				$supervisor_saved = true;
			}
		});
	} catch (Throwable $error) {
		if ($supervisor_saved) {
			alarmmanager_save_supervisor_options($previous_supervisor);
		}

		throw $error;
	}
	unset($GLOBALS['alarmmanager_route_cache']);

	if ($test_destination) {
		try {
			$sample            = alarmmanager_admin_json(file_get_contents(__DIR__ . '/../examples/event.json'), 65536);
			$sample['summary'] = alarmmanager_device_summaries()['offline'];
			$event_id          = alarmmanager_enqueue_test($sample, $id);
		} catch (Throwable $error) {
			throw new RuntimeException('test_after_save_failed', 0, $error);
		}

		return 'Destination saved. Test queued for delivery workers; event row ' . $event_id . '.';
	}

	return 'Configuration saved.';
}

/**
 * Blank SNMP secret fields keep the saved record; any entered value replaces the whole record.
 * @param  array<string, mixed>      $input
 * @return array<string, mixed>|null
 */
function alarmmanager_admin_snmp_credential(#[\SensitiveParameter] array $input): ?array {
	$snmp = [];

	foreach (['community', 'auth_passphrase', 'priv_passphrase'] as $key) {
		$value = $input['snmp_' . $key] ?? '';

		if (!is_string($value)) {
			throw new InvalidArgumentException('invalid_snmp_credentials');
		}

		if ($value !== '') {
			$snmp[$key] = $value;
		}
	}

	return $snmp ? alarmmanager_credential_validate(['snmp' => $snmp]) : null;
}
/**
 * @param  array<string, mixed>      $input
 * @return array<string, mixed>|null
 */
function alarmmanager_admin_credential(#[\SensitiveParameter] array $input, bool $allow_unfilled = false): ?array {
	$type = $input['auth_type'] ?? null;

	if ($type !== null) {
		if (!in_array($type, ['none', 'keep', 'bearer', 'basic', 'headers', 'teams', 'provider_token'], true)) {
			throw new InvalidArgumentException('invalid_credentials');
		}

		if ($type === 'none' || $type === 'keep') {
			return null;
		}

		if ($type === 'provider_token') {
			$token = $input['provider_token'] ?? '';

			return $allow_unfilled && $token === '' ? null : alarmmanager_credential_validate(['provider_token' => $token]);
		}

		if ($type === 'teams') {
			$url = $input['teams_webhook_url'] ?? '';

			return $allow_unfilled && $url === '' ? null : alarmmanager_credential_validate(['teams_webhook_url' => $url]);
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
		'endpoint_in_rule'               => 'Remove this integration from notification rules before deleting.',
		'endpoint_has_active_deliveries' => 'This endpoint has queued or running deliveries. Resolve them before deleting.',
		'test_endpoint_unavailable'      => 'Enable the destination before testing it.',
		'test_after_save_failed'         => 'Destination settings were saved, but the test could not be queued. Check the destination mapping and worker availability before trying again.',
		'invalid_credentials'            => 'Enter the credentials for the selected authentication type, choose Keep saved credentials, or choose None for an incoming webhook.',
		'confirmation_required'          => 'Select the confirmation checkbox before continuing.',
		'invalid_configuration_upload'   => 'Choose a readable JSON configuration file no larger than 1 MiB. Check the server upload limit if uploading fails.',
		'configuration_changed'          => 'Configuration changed. Reload before applying your edits.',
		'permission_denied'              => 'Action failed. Administrator access, POST and a valid CSRF token are required.',
		'invalid_provider'               => 'Choose a supported integration provider.',
		'invalid_twilio_recipients'      => 'Enter up to 50 phone numbers in international format, such as +15551234567, separated by commas or new lines.',
		'recipient_destination_invalid'  => 'Rule recipient lists require a selected Twilio SMS or WhatsApp destination.',
		'invalid_twilio_account'         => 'Enter your Twilio Account SID: AC followed by 32 hexadecimal characters.',
		'invalid_twilio_credentials'     => 'Enter an API Key SID (SK…) or Account SID (AC…) with its matching secret.',
		'invalid_provider_instance'      => 'Enter the HTTPS ServiceNow instance URL without an API path.',
		'provider_credentials_required'  => 'Enter credentials for this integration before enabling it. Credentials from another provider cannot be reused.',
		'invalid_transport'              => 'Action failed. Transport settings do not match the endpoint type.',
		'invalid_teams_webhook_url'      => 'Paste the complete signed HTTPS Teams Workflow URL from Microsoft. Legacy connector URLs and tenant-authenticated triggers are not supported.',
		'teams_webhook_missing'          => 'Save a Teams Workflow URL under Authentication before enabling this endpoint.',
		'invalid_twilio_message'         => 'Map nonempty Combined text to text for WhatsApp template variable 1; remove Body and ContentVariables mappings.',
		'invalid_twilio_transport'       => 'Twilio WhatsApp requires POST, form encoding, encrypted authentication and TLS.',
		'invalid_body_encoding'          => 'Use json or form for HTTP body_encoding; Teams Workflows require json.',
		'invalid_teams_workflow'         => 'Teams Workflows require the preset destination, POST, encrypted authentication and TLS. Restore the Teams preset delivery options.',
		'teams_message_too_large'        => 'Shorten the Teams Combined text mapping: the encoded card exceeds 28,000 bytes.',
		'teams_message_required'         => 'Map a nonempty Combined text message to the text field.',
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
		'invalid_transport_host'         => 'Enter the receiver as a hostname or IP address without a scheme or port.',
		'invalid_snmp_settings'          => 'Choose a supported SNMP version, transport and notification type. SNMPv3 needs a security name of up to 32 printable characters; an engine ID is 0x followed by 10–64 hexadecimal digits.',
		'invalid_snmp_credentials'       => 'Enter SNMP secrets as printable characters without spaces, quotes, backslashes or #. SNMPv3 passphrases need at least 8 characters.',
		'invalid_snmp_mapping'           => 'SNMP trap message fields must be CACTI-ALARMMANAGER-MIB object names such as amSummary. Start from the SNMP trap template.',
		'invalid_syslog_settings'        => 'Choose a supported syslog protocol, format and facility. The application name is 1–48 printable characters without spaces.',
		'invalid_syslog_mapping'         => 'Syslog sends one field named message. Start from the Syslog template.',
		'invalid_ca_bundle'              => 'Enter an absolute PEM file path for the certificate authority. Syslog uses it only with TLS.',
		'invalid_mapping'                => 'Action failed. Each mapping transition must contain a list of fields.',
		'invalid_action'                 => 'Action failed. A required field is missing or outside its allowed range.',
		'invalid_rule_filters'           => 'Choose up to 32 values for each enabled alarm filter.',
		'invalid_rule_schedule'          => 'Choose at least one day for each enabled schedule.',
		'invalid_schedule'               => 'Choose a valid timezone and start and end times for each schedule.',
		'invalid_device_summaries'       => 'Enter all device summaries using 1–512 bytes of text without line breaks.',
		'invalid_configuration'          => 'Action failed. Configuration fields are invalid.',
		default                          => 'The action could not be completed. Check the Cacti log for an internal error. Submitted credential values were cleared.'
	};
}
