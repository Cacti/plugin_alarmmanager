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
require_once(__DIR__ . '/lifecycle.php');
require_once(__DIR__ . '/routing.php');

function alarmmanager_decision(int $event_id, int $rule_id, int $endpoint_id, string $reason, int $revision = 0): void {
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_decisions (event_row_id, rule_id, endpoint_id, reason, config_revision, created_at)
		VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE reason = reason', [$event_id, $rule_id, $endpoint_id, $reason, $revision]);
}
function alarmmanager_route_event(array $record, array $event, array $state, string $disposition): void {
	$event_id = (int) $record['id'];

	if (!in_array($disposition, ['applied', 'occurrence'], true)) {
		alarmmanager_decision($event_id, 0, 0, $disposition);

		return;
	}
	$snapshot = alarmmanager_routing_snapshot();
	// With no custom rules, every enabled endpoint receives alarms through the global default route.
	if ($snapshot['default_route']) {
		$endpoints = array_keys(array_filter($snapshot['endpoints'], static fn (array $endpoint): bool => (bool) $endpoint['enabled']));
		if ($endpoints) {
			$snapshot['profiles'][0] = ['enabled' => true, 'endpoints' => $endpoints];
			$snapshot['rules'][0] = ['id' => 0, 'profile_id' => 0, 'enabled' => true, 'stop_processing' => false, 'policy' => alarmmanager_validate_rule_definition([])];
		}
	}

	$seen     = [];
	$matched  = false;
	$now      = db_fetch_cell_prepared('SELECT UTC_TIMESTAMP(6)', [], '', false);

	if (!is_string($now)) {
		throw new RuntimeException('storage_failure');
	}
	$now_epoch   = (new DateTimeImmutable($now, new DateTimeZone('UTC')))->getTimestamp();
	$fingerprint = hash('sha256', json_encode([$event['transition'], $event['summary'], $event['details'], $event['device']['id'], $event['device']['hostname']], JSON_THROW_ON_ERROR));

	foreach ($snapshot['rules'] as $rule) {
		if (isset($event['context']['routing_profile_id']) && (int) $rule['profile_id'] !== $event['context']['routing_profile_id']) {
			continue;
		}

		if (!(bool) $rule['enabled'] || !alarmmanager_rule_matches($rule['policy'], $event)) {
			continue;
		}
		$matched = true;
		$rule_id = (int) $rule['id'];
		$profile = $snapshot['profiles'][(int) $rule['profile_id']] ?? null;

		if (!$profile || !(bool) $profile['enabled']) {
			alarmmanager_decision($event_id, $rule_id, 0, 'profile_disabled', $snapshot['revision']);
		} else {
			foreach ($profile['endpoints'] as $endpoint_id) {
				$endpoint_id = (int) $endpoint_id;

				if (isset($seen[$endpoint_id])) {
					alarmmanager_decision($event_id, $rule_id, $endpoint_id, 'duplicate_route', $snapshot['revision']);

					continue;
				}
				$seen[$endpoint_id] = true;
				$endpoint           = $snapshot['endpoints'][$endpoint_id] ?? null;
				$reason             = 'queued';
				$policy             = $rule['policy'];

				if (!$endpoint || !(bool) $endpoint['enabled']) {
					$reason = 'endpoint_disabled';
				} elseif ($event['context']['source_suppressed'] ?? false) {
					$reason = 'source_suppressed';
				} elseif ($policy['maintenance_schedule'] !== null && alarmmanager_schedule_matches($policy['maintenance_schedule'], $event['occurred_at'])) {
					$reason = 'maintenance';
				} elseif ($policy['suppress_acknowledged'] && (bool) $state['acknowledged'] && $event['transition'] !== 'recovered' && $event['transition'] !== 'occurrence') {
					$reason = 'acknowledged';
				}
				$previous = [];
				$age      = PHP_INT_MAX;

				if ($reason === 'queued') {
					// Materialize before locking: locking an absent unique key creates avoidable gap-lock deadlocks.
					alarmmanager_db_write("INSERT INTO plugin_alarmmanager_notification_state (stream_key, endpoint_id, episode_id, fingerprint, queued_at) VALUES (?, ?, '', ?, '1970-01-01') ON DUPLICATE KEY UPDATE stream_key = stream_key", [$record['stream_key'], $endpoint_id, str_repeat('0', 64)]);
					$previous = alarmmanager_db_row('SELECT episode_id, fingerprint, queued_at FROM plugin_alarmmanager_notification_state WHERE stream_key = ? AND endpoint_id = ? FOR UPDATE', [$record['stream_key'], $endpoint_id]);
					$age      = $now_epoch - (new DateTimeImmutable($previous['queued_at'], new DateTimeZone('UTC')))->getTimestamp();
				}

				if ($reason === 'queued' && $previous && $event['transition'] !== 'recovered') {
					if ($previous['episode_id'] === $event['episode_id'] && $age < $policy['cooldown_seconds']) {
						$reason = 'cooldown';
					} elseif ($previous['fingerprint'] === $fingerprint && $age < $policy['duplicate_seconds']) {
						$reason = 'duplicate';
					}
				}

				if ($reason === 'queued') {
					alarmmanager_db_write('INSERT INTO plugin_alarmmanager_notification_state (stream_key, endpoint_id, episode_id, fingerprint, queued_at)
						VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE episode_id = VALUES(episode_id), fingerprint = VALUES(fingerprint), queued_at = VALUES(queued_at)',
						[$record['stream_key'], $endpoint_id, $event['episode_id'], $fingerprint, $now]);

					if ($record['mode'] === 'observe') {
						$reason = 'observe';
					} else {
						$key = hash('sha256', implode('|', [$event['source'], $event['source_instance'], $event['event_id'], 'centralized', (string) $endpoint_id]));
						alarmmanager_db_write('INSERT INTO plugin_alarmmanager_deliveries
							(event_row_id, endpoint_id, stream_key, sequence, priority, available_at, created_at, updated_at, idempotency_key)
							VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)
							ON DUPLICATE KEY UPDATE event_row_id = event_row_id',
							[$event_id, $endpoint_id, $record['stream_key'], $event['sequence'], alarmmanager_priority($event['severity']), $key]);
					}
				}
				alarmmanager_decision($event_id, $rule_id, $endpoint_id, $reason, $snapshot['revision']);
			}
		}

		if ((bool) $rule['stop_processing']) {
			break;
		}
	}

	if (!$matched) {
		alarmmanager_decision($event_id, 0, 0, 'no_route', $snapshot['revision']);
	}
}
function alarmmanager_priority(string $severity): int {
	return match ($severity) {
		'critical' => 3, 'major' => 2, 'minor', 'warning' => 1, default => 0
	};
}
function alarmmanager_process_job(array $claim): bool {
	return alarmmanager_transaction(function () use ($claim): bool {
		$job = alarmmanager_db_row("SELECT id, event_row_id FROM plugin_alarmmanager_jobs WHERE id = ? AND status = 'leased' AND lease_token = ? AND lease_until > UTC_TIMESTAMP(6) FOR UPDATE", [$claim['id'], $claim['lease_token']]);

		if (!$job) {
			return false;
		}
		$record = alarmmanager_db_row('SELECT id, payload, stream_key, mode FROM plugin_alarmmanager_events WHERE id = ?', [$job['event_row_id']]);

		if (!$record) {
			throw new RuntimeException('event_missing');
		}
		$event = alarmmanager_validate_event(json_decode($record['payload'], true, 32, JSON_THROW_ON_ERROR));
		alarmmanager_db_write('INSERT INTO plugin_alarmmanager_states (stream_key, updated_at) VALUES (?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE stream_key = stream_key', [$record['stream_key']]);
		$state                = alarmmanager_db_row('SELECT episode_id, last_sequence, active, acknowledged, severity, summary FROM plugin_alarmmanager_states WHERE stream_key = ? FOR UPDATE', [$record['stream_key']]);
		[$next, $disposition] = alarmmanager_transition($state, $event);

		if ($disposition === 'applied' && $event['transition'] === 'raised') {
			alarmmanager_db_write("INSERT INTO plugin_alarmmanager_episodes (stream_key, episode_id, status, raised_at) VALUES (?, ?, 'active', ?) ON DUPLICATE KEY UPDATE episode_id = episode_id", [$record['stream_key'], $event['episode_id'], str_replace(['T', 'Z'], [' ', ''], $event['occurred_at'])]);
			$episode = alarmmanager_db_row('SELECT status FROM plugin_alarmmanager_episodes WHERE stream_key = ? AND episode_id = ? FOR UPDATE', [$record['stream_key'], $event['episode_id']]);

			if ($episode && $episode['status'] !== 'active') {
				$next        = $state;
				$disposition = 'closed_episode';
			} else {
				alarmmanager_db_write("UPDATE plugin_alarmmanager_episodes SET status = 'superseded', ended_at = ? WHERE stream_key = ? AND status = 'active' AND episode_id <> ?", [str_replace(['T', 'Z'], [' ', ''], $event['occurred_at']), $record['stream_key'], $event['episode_id']]);
			}
		} elseif ($disposition === 'applied' && $event['transition'] === 'recovered') {
			alarmmanager_db_write("UPDATE plugin_alarmmanager_episodes SET status = 'recovered', ended_at = ? WHERE stream_key = ? AND episode_id = ?", [str_replace(['T', 'Z'], [' ', ''], $event['occurred_at']), $record['stream_key'], $event['episode_id']]);
		}

		if (in_array($disposition, ['applied', 'occurrence'], true)) {
			alarmmanager_db_write('UPDATE plugin_alarmmanager_states SET episode_id = ?, last_sequence = ?, active = ?, acknowledged = ?, severity = ?, summary = ?, last_event_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ?',
				[$next['episode_id'], $next['last_sequence'], $next['active'], $next['acknowledged'], $next['severity'], $next['summary'], $record['id'], $record['stream_key']]);
		}
		$notification_state = $next;

		if ($record['mode'] === 'observe') {
			$live = alarmmanager_db_row('SELECT episode_id, acknowledged FROM plugin_alarmmanager_states WHERE stream_key = ?', [alarmmanager_stream_key($event, 'centralized')]);

			if ($live && $live['episode_id'] === $event['episode_id']) {
				$notification_state['acknowledged'] = $live['acknowledged'];
			}
		}
		alarmmanager_route_event($record, $event, $notification_state, $disposition);
		$owner = alarmmanager_db_row("SELECT id FROM plugin_alarmmanager_jobs WHERE id = ? AND status = 'leased' AND lease_token = ? AND lease_until > UTC_TIMESTAMP(6)", [$claim['id'], $claim['lease_token']]);

		if (!$owner) {
			throw new RuntimeException('lease_lost');
		}
		alarmmanager_db_write("UPDATE plugin_alarmmanager_jobs SET status = 'completed', lease_token = NULL, lease_until = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND lease_token = ?", [$claim['id'], $claim['lease_token']]);

		return true;
	});
}
