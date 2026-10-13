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
require_once(__DIR__ . '/runtime.php');
require_once(__DIR__ . '/events.php');
function alarmmanager_device_grant_validate(array $grant): array {
	$keys = ['device_id', 'deployment_uuid', 'poller_id', 'epoch', 'owner', 'mode', 'profile_id', 'channels', 'valid_from', 'valid_until', 'last_sequence', 'seed_event', 'last_episode', 'last_transition'];
	alarmmanager_event_keys($grant, $keys);

	foreach (['device_id', 'poller_id', 'epoch', 'profile_id'] as $field) {
		$grant[$field] = filter_var($grant[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $field === 'epoch' ? 1000000 : 2147483647]]);

		if (!$grant[$field]) {
			throw new InvalidArgumentException('invalid_device_grant');
		}
	}

	if (!is_string($grant['deployment_uuid'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $grant['deployment_uuid']) || !in_array($grant['owner'] ?? '', ['core', 'thold'], true) || !in_array($grant['mode'] ?? '', ['legacy', 'observe', 'centralized'], true)) {
		throw new InvalidArgumentException('invalid_device_grant');
	}
	$grant['channels'] = is_string($grant['channels'] ?? null) ? json_decode($grant['channels'], true, 4, JSON_THROW_ON_ERROR) : ($grant['channels'] ?? []);

	if ($grant['channels'] !== ['email', 'script']) {
		throw new InvalidArgumentException('device_channels_require_explicit_migration');
	}

	foreach (['valid_from', 'valid_until'] as $field) {
		$grant[$field] = str_replace(['T', 'Z'], [' ', ''], alarmmanager_event_time($grant[$field]));
	}

	if ($grant['valid_from'] >= $grant['valid_until']) {
		throw new InvalidArgumentException('invalid_device_grant');
	}
	$seed = $grant['seed_event'] ?? null;

	if (is_string($seed)) {
		$seed = json_decode($seed, true, 32, JSON_THROW_ON_ERROR);
	}

	if ($seed !== null) {
		$seed = alarmmanager_validate_event($seed);

		if ($seed['source'] !== 'cacti_device' || $seed['source_instance'] !== $grant['deployment_uuid'] || (int) $seed['device']['id'] !== $grant['device_id'] || !in_array($seed['transition'], ['raised', 'recovered'], true) || (int) ($seed['context']['ownership_epoch'] ?? 0) >= $grant['epoch']) {
			throw new InvalidArgumentException('device_handoff_seed_invalid');
		}
	}

	if ($grant['epoch'] > 1 && $seed === null) {
		throw new InvalidArgumentException('device_handoff_seed_required');
	}
	$grant['seed_event'] = $seed;
	unset($grant['last_sequence']);

	return $grant;
}
function alarmmanager_device_grant(int $device, bool $lock = false): array {
	return alarmmanager_db_row('SELECT * FROM plugin_alarmmanager_device_owners WHERE device_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$device]);
}
function alarmmanager_device_live(array $grant, int $seconds = 0): bool {
	if (!$grant || (int) $grant['poller_id'] !== alarmmanager_local_poller()) {
		return false;
	}

	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_device_owners WHERE device_id = ? AND epoch = ? AND valid_from <= UTC_TIMESTAMP(6) AND valid_until > DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)', [(int) $grant['device_id'], (int) $grant['epoch'], $seconds], '', false) === 1;
}
function alarmmanager_device_event_authorized(array $event, int $seconds = 0, ?string $mode = null): bool {
	if ($event['source'] !== 'cacti_device') {
		return true;
	}
	$grant = alarmmanager_device_grant((int) ($event['device']['id'] ?? 0));

	return $grant && ($mode === null || $grant['mode'] === $mode) && (int) ($event['context']['ownership_epoch'] ?? 0) === (int) $grant['epoch']
		&& $event['collector_id']                                                                                         === alarmmanager_local_poller() && $event['resource_id'] === (string) $grant['device_id'] && $event['alarm_key'] === 'device:' . $grant['device_id']
		&& (int) ($event['context']['routing_profile_id'] ?? 0)                                                           === (int) $grant['profile_id']
		&& $event['sequence'] > (int) $grant['epoch'] * 1000000000 && $event['sequence'] < ((int) $grant['epoch'] + 1) * 1000000000
		&& $event['source_instance'] === $grant['deployment_uuid'] && ($event['context']['rule_type'] ?? '') === $grant['owner'] . '-device'
		&& alarmmanager_device_live($grant, $seconds);
}
function alarmmanager_device_times(array $host): array {
	$result = [];

	foreach (['status_fail_date', 'status_rec_date'] as $field) {
		$value          = $host[$field] ?? 0;
		$result[$field] = is_int($value) || ctype_digit((string) $value) ? (int) $value : (int) db_fetch_cell_prepared('SELECT UNIX_TIMESTAMP(?)', [(string) $value], '', false);
	}

	return $result;
}
function alarmmanager_device_identity(array $grant, array $host, string $kind, int $sequence): string {
	return hash('sha256', implode('|', [$grant['deployment_uuid'], (string) $host['id'], (string) $grant['epoch'], (string) $sequence, $kind]));
}
function alarmmanager_device_receipt(array $grant, array $host, string $kind): array {
	$times = alarmmanager_device_times($host);

	if (($grant['last_transition'] ?? '') !== $kind) {
		return [];
	}

	return alarmmanager_db_row('SELECT mode, channels, event_row_id FROM plugin_alarmmanager_device_receipts WHERE device_id = ? AND epoch = ? AND transition = ? AND fail_epoch = ? AND rec_epoch = ? ORDER BY sequence DESC LIMIT 1', [$host['id'], $grant['epoch'], $kind, $times['status_fail_date'], $kind === 'recovered' ? $times['status_rec_date'] : 0]);
}

/** Must run inside the caller's transaction, alongside the source status update. */
function alarmmanager_device_persist(array $grant, array $host, string $kind, bool $fresh = false): array {
	require_once(__DIR__ . '/ingestion.php');
	$times   = alarmmanager_device_times($host);
	$receipt = $fresh ? [] : alarmmanager_device_receipt($grant, $host, $kind);

	if ($receipt) {
		return $receipt;
	}
	$sequence = (int) $grant['last_sequence'] + 1;
	$id       = alarmmanager_device_identity($grant, $host, $kind, $sequence);
	$episode  = $kind === 'raised' ? hash('sha256', $id . '|episode') : ($grant['last_episode'] ?: hash('sha256', $grant['deployment_uuid'] . '|' . $host['id'] . '|unobserved|' . $times['status_fail_date']));

	if ($sequence >= 1000000000 || $times['status_fail_date'] < 1 || ($kind === 'recovered' && $times['status_rec_date'] < 1)) {
		throw new InvalidArgumentException('invalid_device_transition');
	}
	$event = alarmmanager_validate_event(['event_id' => $id, 'source' => 'cacti_device', 'source_instance' => $grant['deployment_uuid'], 'resource_id' => (string) $host['id'], 'alarm_key' => 'device:' . $host['id'],
		'episode_id'                                    => $episode, 'sequence' => (int) $grant['epoch'] * 1000000000 + $sequence,
		'transition'                                    => $kind, 'severity' => $kind === 'raised' ? 'major' : 'clear', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $times[$kind === 'raised' ? 'status_fail_date' : 'status_rec_date']),
		'collector_id'                                  => alarmmanager_local_poller(), 'device' => ['id' => (int) $host['id'], 'hostname' => (string) $host['hostname'], 'description' => (string) $host['description'], 'tags' => []],
		'summary'                                       => $kind === 'raised' ? 'Device confirmed down' : 'Device confirmed recovered', 'context' => ['rule_type' => $grant['owner'] . '-device', 'ownership_epoch' => (int) $grant['epoch'], 'routing_profile_id' => (int) $grant['profile_id'], 'source_notification_channels' => ['email', 'script']]]);
	$result = alarmmanager_persist_event($event, $grant['mode'], alarmmanager_local_poller());
	alarmmanager_db_write('UPDATE plugin_alarmmanager_device_owners SET last_sequence = ?, last_episode = ?, last_transition = ? WHERE device_id = ? AND epoch = ?', [$sequence, $episode, $kind, $host['id'], $grant['epoch']]);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_device_receipts (event_id, device_id, transition, epoch, sequence, fail_epoch, rec_epoch, event_row_id, mode, channels, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$id, $host['id'], $kind, $grant['epoch'], $sequence, $times['status_fail_date'], $kind === 'recovered' ? $times['status_rec_date'] : 0, $result['event_row_id'], $grant['mode'], $grant['channels']]);

	return ['mode' => $grant['mode'], 'channels' => $grant['channels'], 'event_row_id' => $result['event_row_id']];
}
/** Only confirmed transitions reach this helper; normal ping samples do not probe plugin configuration. */
function alarmmanager_device_core_commit(array $host, int $previous_status, callable $write_status): bool {
	try {
		$grant = alarmmanager_device_grant((int) $host['id']);
	} catch (Throwable $error) {
		return (bool) $write_status();
	}

	if ((int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false) !== 0) {
		return (bool) $write_status();
	}

	if (!$grant || $grant['owner'] !== 'core' || $grant['mode'] === 'legacy' || !alarmmanager_runtime_enabled() || !alarmmanager_device_live($grant)) {
		return (bool) $write_status();
	}

	try {
		return alarmmanager_transaction(function () use ($host, $previous_status, $write_status): bool {
			$grant = alarmmanager_device_grant((int) $host['id'], true);

			if ($grant['owner'] !== 'core' || $grant['mode'] === 'legacy' || !alarmmanager_device_live($grant)) {
				throw new RuntimeException('device_owner_expired');
			}
			$live = alarmmanager_db_row('SELECT status, poller_id, deleted, UNIX_TIMESTAMP(status_fail_date) AS alarmmanager_fail_epoch FROM host WHERE id = ? FOR UPDATE', [$host['id']]);

			if (!$live || (int) $live['poller_id'] !== alarmmanager_local_poller() || $live['deleted'] !== '' || (int) $live['status'] !== $previous_status) {
				return false;
			}

			if ((int) $host['status'] === 3 && (int) $live['alarmmanager_fail_epoch'] !== alarmmanager_device_times($host)['status_fail_date']) {
				return false;
			}

			if (!$write_status()) {
				throw new RuntimeException('device_status_write_failed');
			}
			alarmmanager_device_persist($grant, $host, (int) $host['status'] === 1 ? 'raised' : 'recovered', true);

			return true;
		});
	} catch (Throwable $error) {
		alarmmanager_log('ERROR: confirmed transition could not commit with local host state; next poll must retry detection: ' . $error->getMessage());

		return false;
	}
}
/** Same canonical receipt for either source; core and thold cannot both publish device notifications. */
function alarmmanager_device_thold_decision(array $host, string $kind): array {
	$legacy = ['owned' => false, 'channels' => []];

	try {
		$grant = alarmmanager_device_grant((int) $host['id']);

		if (!$grant) {
			return $legacy;
		}
		$receipt = alarmmanager_device_receipt($grant, $host, $kind);

		if ($receipt) {
			return ['owned' => $receipt['mode'] === 'centralized', 'channels' => json_decode($receipt['channels'], true)];
		}

		if ($grant['mode'] === 'centralized' && !alarmmanager_device_live($grant)) {
			return ['owned' => true, 'channels' => ['email', 'script']];
		}

		if (!empty($grant['seed_event'])) {
			$seed  = json_decode($grant['seed_event'], true, 32, JSON_THROW_ON_ERROR);
			$times = alarmmanager_device_times($host);

			if ($seed['transition'] === $kind && (int) strtotime($seed['occurred_at']) === $times[$kind === 'raised' ? 'status_fail_date' : 'status_rec_date']) {
				return ['owned' => $grant['mode'] === 'centralized', 'channels' => ['email', 'script']];
			}
		}

		if ($grant['owner'] !== 'thold' || $grant['mode'] === 'legacy' || !alarmmanager_runtime_enabled()) {
			return $legacy;
		}
		$result = alarmmanager_transaction(function () use ($host, $kind): array {
			$grant = alarmmanager_device_grant((int) $host['id'], true);

			if (!alarmmanager_device_live($grant)) {
				throw new RuntimeException('device_owner_expired');
			}

			return alarmmanager_device_persist($grant, $host, $kind);
		});

		return ['owned' => $result['mode'] === 'centralized', 'channels' => json_decode($result['channels'], true)];
	} catch (Throwable $error) {
		return $legacy;
	}
}
