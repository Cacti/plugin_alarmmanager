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
require_once(__DIR__ . '/ingestion.php');
require_once(__DIR__ . '/routing.php');

/** API credentials authorize a single producer identity; no plugin-specific adapters run here. */
/**
 * @param  array<string, mixed> $client
 * @return array<string, mixed>
 */
function alarmmanager_api_client_validate(array $client): array {
	alarmmanager_event_keys($client, ['name', 'source', 'source_instance', 'collector_id', 'mode', 'profile_id', 'device_ids', 'all_devices', 'unmapped_devices']);
	$name   = trim(alarmmanager_event_text($client['name'] ?? '', 128));
	$source = alarmmanager_event_token($client['source'] ?? null, 32);

	if ($name === '' || $source === 'cacti_device') {
		throw new InvalidArgumentException('invalid_api_client');
	}
	$instance  = alarmmanager_event_token($client['source_instance'] ?? null, 128);
	$collector = $client['collector_id'] ?? null;
	$profile   = $client['profile_id'] ?? null;
	$mode      = $client['mode'] ?? null;
	$devices   = $client['device_ids'] ?? [];
	$all       = $client['all_devices'] ?? false;
	$unmapped  = $client['unmapped_devices'] ?? false;

	if (!is_int($collector) || $collector < 1 || $collector > 2147483647 || !is_int($profile) || $profile < 0 || $profile > 2147483647
		|| !in_array($mode, ['observe', 'centralized'], true) || ($mode === 'centralized' && $profile === 0)
		|| !is_bool($all) || !is_bool($unmapped) || !is_array($devices) || !array_is_list($devices) || count($devices) > 4096) {
		throw new InvalidArgumentException('invalid_api_client');
	}

	foreach ($devices as $id) {
		if (!is_int($id) || $id < 1 || $id > 2147483647) {
			throw new InvalidArgumentException('invalid_api_client');
		}
	}

	if (!$all && !$unmapped && !$devices) {
		throw new InvalidArgumentException('api_device_scope_required');
	}
	$devices = array_values(array_unique($devices));
	sort($devices);

	return ['name' => $name, 'source' => $source, 'source_instance' => $instance, 'collector_id' => $collector, 'mode' => $mode, 'profile_id' => $profile, 'device_ids' => $devices, 'all_devices' => $all, 'unmapped_devices' => $unmapped];
}

/** The token is returned once; only its hash is stored. */
/**
 * @param  array<string, mixed>               $client
 * @return array{id: int, token: string|null}
 */
function alarmmanager_api_client_save(array $client, int $id = 0, int $revision = 0, bool $enabled = true, bool $rotate = false): array {
	$client = alarmmanager_api_client_validate($client);

	return alarmmanager_transaction(function () use ($client, $id, $revision, $enabled, $rotate): array {
		if ($id === 0) {
			alarmmanager_db_row('SELECT config_revision FROM plugin_alarmmanager_schema WHERE id = 1 FOR UPDATE');

			if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_api_clients', [], '', false) >= 256) {
				throw new InvalidArgumentException('api_client_limit');
			}
		}
		$existing = $id > 0 ? alarmmanager_db_row('SELECT id, revision FROM plugin_alarmmanager_api_clients WHERE id = ? FOR UPDATE', [$id]) : null;

		if (($id > 0 && !$existing) || ($existing && (int) $existing['revision'] !== $revision)) {
			throw new InvalidArgumentException('configuration_changed');
		}

		if ($client['profile_id'] > 0) {
			$profile = alarmmanager_db_row('SELECT enabled FROM plugin_alarmmanager_profiles WHERE id = ?', [$client['profile_id']]);

			if (!$profile || !(bool) $profile['enabled'] || ($client['mode'] === 'centralized' && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_rules WHERE profile_id = ? AND enabled = 1', [$client['profile_id']], '', false))) {
				throw new InvalidArgumentException('api_profile_required');
			}
		}
		$token      = !$existing || $rotate ? 'amapi_' . bin2hex(random_bytes(32)) : null;
		$definition = json_encode($client, JSON_THROW_ON_ERROR);

		if ($existing) {
			alarmmanager_db_write('UPDATE plugin_alarmmanager_api_clients SET definition = ?, enabled = ?, revision = revision + 1, updated_at = UTC_TIMESTAMP(6)' . ($token !== null ? ', token_hash = ?' : '') . ' WHERE id = ?', [...[$definition, (int) $enabled], ...($token !== null ? [hash('sha256', $token)] : []), $id]);
		} else {
			alarmmanager_db_write('INSERT INTO plugin_alarmmanager_api_clients (token_hash, definition, enabled, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [hash('sha256', $token), $definition, (int) $enabled]);
			$id = alarmmanager_config_int(db_fetch_cell_prepared('SELECT LAST_INSERT_ID()', [], '', false), 1, 2147483647);
		}

		return ['id' => $id, 'token' => $token];
	});
}

/**
 * @return array{id: int, definition: array<string, mixed>}
 */
function alarmmanager_api_authenticate(#[\SensitiveParameter] string $authorization): array {
	if (!preg_match('/\ABearer (amapi_[a-f0-9]{64})\z/D', $authorization, $match)) {
		throw new RuntimeException('api_unauthorized');
	}
	$row = alarmmanager_db_row('SELECT id, definition FROM plugin_alarmmanager_api_clients WHERE token_hash = ? AND enabled = 1', [hash('sha256', $match[1])]);

	if (!$row) {
		throw new RuntimeException('api_unauthorized');
	}
	$definition = alarmmanager_api_client_validate(json_decode($row['definition'], true, 16, JSON_THROW_ON_ERROR));

	return ['id' => (int) $row['id'], 'definition' => $definition];
}

/**
 * @param  array<string, mixed> $request
 * @param  array<string, mixed> $client
 * @return array<string, mixed>
 */
function alarmmanager_api_envelope(array $request, array $client): array {
	alarmmanager_event_keys($request, ['contract_version', 'mode', 'event']);

	if (($request['contract_version'] ?? null) !== 1 || ($request['mode'] ?? null) !== $client['mode'] || !is_array($request['event'] ?? null)) {
		throw new InvalidArgumentException('api_contract_invalid');
	}
	$event = alarmmanager_validate_event($request['event']);

	if ($event['source'] !== $client['source'] || $event['source_instance'] !== $client['source_instance'] || $event['collector_id'] !== $client['collector_id']
		|| ($event['device']['id'] === null ? !$client['unmapped_devices'] : (!$client['all_devices'] && !in_array($event['device']['id'], $client['device_ids'], true)))) {
		throw new RuntimeException('api_scope_denied');
	}
	// The authenticated client's routing scope always wins over producer-supplied context.
	unset($event['context']['routing_profile_id'], $event['context']['ownership_epoch']);

	if ($client['profile_id'] > 0) {
		$event['context']['routing_profile_id'] = $client['profile_id'];
	}

	return ['contract_version' => 1, 'mode' => $client['mode'], 'event' => $event];
}

/** Uses the shared transaction/deduplication path; acknowledgement follows COMMIT. */
/**
 * @return array<string, mixed>
 */
function alarmmanager_api_receive(#[\SensitiveParameter] string $authorization, string $body): array {
	if (strlen($body) > 32768) {
		throw new InvalidArgumentException('api_payload_too_large');
	}
	$client = alarmmanager_api_authenticate($authorization);

	try {
		$request = json_decode($body, true, 24, JSON_THROW_ON_ERROR);

		if (!is_array($request)) {
			throw new InvalidArgumentException('api_contract_invalid');
		}
		$envelope = alarmmanager_api_envelope($request, $client['definition']);
		$result   = alarmmanager_submit($envelope);
		$status   = $result['status'];

		if (!in_array($status, ['accepted', 'already_accepted'], true)) {
			throw new RuntimeException($result['error'] ?? 'api_unavailable');
		}

		return ['status' => $status, 'event_id' => $envelope['event']['event_id'], 'event_row_id' => $result['event_row_id'], 'mode' => $envelope['mode'], 'owned' => $envelope['mode'] === 'centralized'];
	} finally {
		// Monitoring must never turn a committed acknowledgement into a failure.
		try {
			alarmmanager_db_write('UPDATE plugin_alarmmanager_api_clients SET last_seen = UTC_TIMESTAMP(6), last_status = ? WHERE id = ?', [$status ?? 'rejected', $client['id']]);
		} catch (Throwable $ignored) {
		}
	}
}
