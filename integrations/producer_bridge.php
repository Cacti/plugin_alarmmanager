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

// Copied into each patched producer. Deliberately independent of Alarm Manager files/lifecycle.
if (!class_exists('AlarmManagerProducerBridge', false)) {
	final class AlarmManagerProducerBridge {
		public static function validate(array $definition): array {
			if (array_diff(array_keys($definition), ['mode', 'profile_id', 'channels', 'complete_history'])) {
				throw new InvalidArgumentException('invalid_producer_configuration');
			}
			// Accept and discard retired mode/profile keys when reading saved configurations.
			$mode     = $definition['mode'] ?? 'centralized';
			$channels = $definition['channels'] ?? [];

			if (!in_array($mode, ['legacy', 'observe', 'centralized'], true) || !is_array($channels) || !array_is_list($channels) || array_diff($channels, ['email', 'script', 'ticket']) || !is_bool($definition['complete_history'] ?? false)) {
				throw new InvalidArgumentException('invalid_producer_configuration');
			}

			return ['channels' => array_values(array_unique($channels)), 'complete_history' => $definition['complete_history'] ?? false];
		}
		public static function configuration(string $source, int $resource, int $template = 0): array {
			if (!preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,31}\z/D', $source)) {
				throw new InvalidArgumentException('invalid_producer');
			}
			$rows = db_fetch_assoc_prepared('SELECT scope, resource_id, definition FROM plugin_alarmmanager_producer_config WHERE source = ? AND ((scope = ? AND resource_id = ?) OR (scope = ? AND resource_id = ?) OR (scope = ? AND resource_id = 0))', [$source, 'resource', $resource, 'template', $template, 'default'], false);

			if (!is_array($rows)) {
				throw new RuntimeException('producer_configuration_unavailable');
			}
			$definitions = [];

			foreach ($rows as $row) {
				$definitions[$row['scope']] = self::validate(json_decode($row['definition'], true, 8, JSON_THROW_ON_ERROR));
			}

			return $definitions['resource'] ?? ($template > 0 ? ($definitions['template'] ?? null) : null) ?? $definitions['default'] ?? self::validate([]);
		}
		public static function text(string $value, int $limit): string {
			if (!preg_match('//u', $value)) {
				throw new InvalidArgumentException('invalid_producer_text');
			}
			$value = substr($value, 0, $limit);

			while ($value !== '' && !preg_match('//u', $value)) {
				$value = substr($value, 0, -1);
			}

			return $value;
		}
		/** Snapshot only safe device fields; retries reuse the saved event rather than refresh metadata.
		 * @param array<string, mixed> $host
		 *
		 * @return array<string, mixed>
		 */
		public static function deviceDetails(array $host, int $collector): array {
			if (!empty($host['id'])) {
				$host += self::row('SELECT location, external_id, snmp_sysLocation, snmp_sysContact, snmp_sysName, snmp_sysUpTimeInstance, status_fail_date, status_rec_date, status_last_error FROM host WHERE id = ?', [(int) $host['id']]);
			}
			$name   = self::row('SELECT name FROM poller WHERE id = ?', [$collector]);
			$fields = ['collector_name' => (string) ($name['name'] ?? '')];

			foreach (['cacti_location' => 'location', 'location' => 'snmp_sysLocation', 'contact' => 'snmp_sysContact', 'sysname' => 'snmp_sysName', 'external_id' => 'external_id', 'status_last_error' => 'status_last_error', 'snmp_sysUpTimeInstance' => 'snmp_sysUpTimeInstance'] as $key => $column) {
				$fields[$key] = self::text((string) ($host[$column] ?? ''), $key === 'status_last_error' ? 2048 : 512);
			}
			$ticks            = (int) ($host['snmp_sysUpTimeInstance'] ?? 0);
			$seconds          = intdiv(max(0, $ticks), 100);
			$fields['uptime'] = $ticks > 0 ? sprintf('%dd %dh %dm %ds', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : '';

			foreach (['status_fail_date', 'status_rec_date'] as $column) {
				$value           = $host[$column] ?? 0;
				$epoch           = is_int($value) || ctype_digit((string) $value) ? (int) $value : (int) (self::row('SELECT UNIX_TIMESTAMP(?) AS epoch', [(string) $value])['epoch'] ?? 0);
				$fields[$column] = $epoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', $epoch) : '';
			}

			return $fields;
		}
		public static function owns(array $decision, string $channel): bool {
			return ($decision['owned'] ?? false) === true && in_array($channel, $decision['channels'] ?? [], true);
		}
		private static function write(string $sql, array $params = []): void {
			if (!db_execute_prepared($sql, $params, false)) {
				throw new RuntimeException('producer_storage_failure');
			}
		}
		private static function row(string $sql, array $params = []): array {
			$rows = db_fetch_assoc_prepared($sql, $params, false);

			if (!is_array($rows)) {
				throw new RuntimeException('producer_storage_failure');
			}

			return $rows[0] ?? [];
		}
		private static function decision(array $row): array {
			return ['owned' => $row['status'] === 'accepted', 'status' => $row['status'], 'channels' => json_decode($row['channels'], true, 4, JSON_THROW_ON_ERROR)];
		}
		private static function fallback(string $receipt): array {
			self::write("UPDATE plugin_alarmmanager_producer_receipts SET status = 'legacy', updated_at = UTC_TIMESTAMP(6) WHERE receipt = ? AND status = 'pending'", [$receipt]);
			$row = self::row('SELECT status, channels FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt]);

			if (!$row) {
				throw new RuntimeException('producer_receipt_unavailable');
			}

			return self::decision($row);
		}
		/** Recovery never invokes source delivery: only retry still-pending saved envelopes. */
		public static function retry(string $receipt, ?callable $submit = null): string {
			if (!preg_match('/\A[a-f0-9]{64}\z/D', $receipt)) {
				throw new InvalidArgumentException('invalid_producer_receipt');
			}
			$row = self::row('SELECT status, envelope FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt]);

			if (!$row) {
				throw new RuntimeException('producer_receipt_unavailable');
			}

			if ($row['status'] !== 'pending') {
				return $row['status'];
			}

			$envelope = json_decode($row['envelope'], true, 32, JSON_THROW_ON_ERROR);

			if ($submit !== null) {
				$submit($envelope);
			} elseif (function_exists('api_plugin_is_enabled') && api_plugin_is_enabled('alarmmanager') && function_exists('api_plugin_hook_function')) {
				api_plugin_hook_function('alarmmanager_submit', $envelope);
			}

			return self::row('SELECT status FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt])['status'];
		}
		/** notification identity is producer-owned, stable across submission retries, and contains no credentials. */
		public static function publish(string $source, int $resource, int $template, string $identity, array $input): array {
			global $config;
			$legacy        = ['owned' => false, 'channels' => [], 'status' => 'legacy'];
			$started       = false;
			$locked        = false;
			$reserved      = false;
			$receipt       = hash('sha256', $source . '|' . ($config['poller_id'] ?? 1) . '|' . $resource . '|' . $identity);
			$lock          = 'am-producer:' . substr(hash('sha256', $source . '|' . ($config['poller_id'] ?? 1) . '|' . $resource . '|' . ($input['stream_suffix'] ?? '')), 0, 48);

			try {
				$transaction = db_fetch_cell_prepared('SELECT @@in_transaction', [], '', false);

				if ($transaction === false || $transaction === null) {
					return $legacy;
				}

				if ((int) $transaction !== 0) {
					$known = self::row('SELECT status, channels, envelope FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt]);

					return $known && $known['status'] !== 'pending' ? self::decision($known) : $legacy;
				}
				$acquired = db_fetch_cell_prepared('SELECT GET_LOCK(?, 2)', [$lock], '', false);

				if ($acquired === false || $acquired === null) {
					return $legacy;
				}

				if ((int) $acquired !== 1) {
					return ['owned' => true, 'channels' => ['email', 'script', 'ticket'], 'status' => 'busy'];
				}
				$locked = true;
				$row    = self::row('SELECT status, envelope, channels, stream_key FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt]);

				if ($row && $row['status'] !== 'pending') {
					return self::decision($row);
				}
				$reserved      = (bool) $row;
				$envelope      = $row ? json_decode($row['envelope'], true, 32, JSON_THROW_ON_ERROR) : [];
				$settings      = self::configuration($source, $resource, $template);

				if (!$row && (($input['suppressed'] ?? false) && !$settings['complete_history'] && $input['transition'] !== 'recovered')) {
					return $legacy;
				}

				if (!$row) {
					if (!function_exists('api_plugin_is_enabled') || !api_plugin_is_enabled('alarmmanager') || !function_exists('api_plugin_hook_function')) {
						return $legacy;
					}
					// Preserve existing producer stream identities after removing mode selection.
					$mode   = 'centralized';
					$stream = hash('sha256', $source . '|' . ($config['poller_id'] ?? 1) . '|' . $resource . '|' . ($input['stream_suffix'] ?? '') . '|' . $mode);
					self::write('START TRANSACTION');
					$started = true;
					self::write('INSERT INTO plugin_alarmmanager_producer_streams (stream_key, updated_at) VALUES (?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE stream_key = stream_key', [$stream]);
					$state   = self::row('SELECT sequence, episode_id, active, severity FROM plugin_alarmmanager_producer_streams WHERE stream_key = ? FOR UPDATE', [$stream]);
					$kind    = $input['transition'];
					$episode = $state['episode_id'];

					if ($kind === 'updated' && !(bool) $state['active']) {
						$kind = 'raised';
					}

					if ($kind === 'raised') {
						if ((bool) $state['active']) {
							if (($input['fresh_raise'] ?? false) && $state['severity'] === $input['severity']) {
								$episode = bin2hex(random_bytes(16));
							} else {
								$kind = ($input['repeat'] ?? false) ? 'reminder' : 'updated';
							}
						} else {
							$episode = bin2hex(random_bytes(16));
						}
					}

					if ($episode === '' || $kind === 'occurrence') {
						$episode = bin2hex(random_bytes(16));
					}
					$sequence = (int) $state['sequence'] + 1;
					$event    = ['event_id' => $receipt, 'source' => $source, 'source_instance' => 'cacti-poller-' . (int) ($config['poller_id'] ?? 1),
						'resource_id'          => (string) $resource, 'alarm_key' => $source . ':' . $resource . ':' . ($input['stream_suffix'] ?? ''),
						'episode_id'           => $episode, 'sequence' => $sequence, 'transition' => $kind, 'severity' => $input['severity'],
						'occurred_at'          => $input['occurred_at'], 'collector_id' => (int) ($config['poller_id'] ?? 1),
						'device'               => array_replace($input['device'], self::deviceDetails(array_intersect_key($input['device'], ['id' => true]), (int) ($config['poller_id'] ?? 1))), 'summary' => self::text($input['summary'], 512), 'details' => self::text($input['details'] ?? '', 16384),
						'context'              => ['rule_name' => self::text($input['rule_name'] ?? $input['summary'], 512), 'rule_type' => $input['rule_type'] ?? $source,
							'source_suppressed'                   => $input['suppressed'] ?? false, 'suppression_reason' => $input['suppression_reason'] ?? '', 'source_notification_channels' => $settings['channels']]];

					if (isset($input['metric_value'])) {
						$event['context']['metric_value'] = (string) $input['metric_value'];
					}

					$envelope = ['contract_version' => 1, 'status' => 'unhandled', 'event' => $event, 'producer_receipt' => $receipt];

					self::write('UPDATE plugin_alarmmanager_producer_streams SET sequence = ? WHERE stream_key = ?', [$sequence, $stream]);
					self::write('INSERT INTO plugin_alarmmanager_producer_receipts (receipt, source, stream_key, envelope, channels, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$receipt, $source, $stream, json_encode($envelope, JSON_THROW_ON_ERROR), json_encode($settings['channels'], JSON_THROW_ON_ERROR)]);
					self::write('COMMIT');
					$started  = false;
					$reserved = true;
					$row      = ['status' => 'pending', 'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'channels' => json_encode($settings['channels'], JSON_THROW_ON_ERROR)];
				}
				$envelope = json_decode($row['envelope'], true, 32, JSON_THROW_ON_ERROR);

				if (function_exists('api_plugin_is_enabled') && api_plugin_is_enabled('alarmmanager') && function_exists('api_plugin_hook_function')) {
					// Ignore truthy values and claimed acceptance: the committed receipt is authoritative.
					api_plugin_hook_function('alarmmanager_submit', $envelope);
				}
				$saved = self::row('SELECT status, channels FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt]);

				if ($saved && $saved['status'] !== 'pending') {
					return self::decision($saved);
				}

				return self::fallback($receipt);
			} catch (Throwable $error) {
				if ($started) {
					try {
						self::write('ROLLBACK');
					} catch (Throwable $ignored) {
					}
				}

				// A hook can throw after committing. Recover ownership from its atomic receipt.
				if ($locked && $reserved) {
					try {
						$saved = self::row('SELECT status, channels FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [$receipt]);

						if ($saved && $saved['status'] !== 'pending') {
							return self::decision($saved);
						}

						return self::fallback($receipt);
					} catch (Throwable $ignored) {
						if (function_exists('cacti_log')) {
							cacti_log('ERROR: Alarm Manager producer ownership is unknown; retry pending producer receipts after database recovery.', false, 'ALARMMANAGER');
						}

						return ['owned' => ($envelope['mode'] ?? 'centralized') === 'centralized', 'channels' => json_decode($row['channels'] ?? '[]', true, 4, JSON_THROW_ON_ERROR), 'status' => 'ownership_unknown'];
					}
				}

				return $legacy;
			} finally {
				if ($locked) {
					try {
						db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock], '', false);
					} catch (Throwable $ignored) {
					}
				}
			}
		}
	}
}
