<?php
// SPDX-License-Identifier: GPL-2.0-or-later

require_once(__DIR__ . '/events.php');

/** Functional admin_alert hook: only durable ingestion claims notification ownership. */
function alarmmanager_admin_alert(mixed $alert): mixed {
	global $config;
	static $active = false;

	if (!is_array($alert) || $active || ($alert['status'] ?? null) !== 'unhandled') {
		return $alert;
	}

	$active = true;

	try {
		if (($alert['contract_version'] ?? null) !== 1
			|| !is_int($alert['collector_id'] ?? null)
			|| $alert['collector_id'] !== (int) ($config['poller_id'] ?? 1)) {
			return $alert;
		}

		$id        = alarmmanager_event_token($alert['event_id'] ?? null, 128);
		$collector = db_fetch_row_prepared('SELECT name FROM poller WHERE id = ?', [$alert['collector_id']], false);
		$name      = ($collector['name'] ?? '') ?: ('Poller #' . $alert['collector_id']);
		$hostname  = gethostname() ?: '';
		$event     = alarmmanager_validate_event([
			'event_id'        => $id,
			'source'          => 'cacti',
			'source_instance' => 'poller:' . $alert['collector_id'],
			'resource_id'     => 'poller:' . $alert['collector_id'],
			'alarm_key'       => 'admin_alert:' . $id,
			'episode_id'      => $id,
			'sequence'        => 1,
			'transition'      => 'occurrence',
			'severity'        => 'warning',
			'occurred_at'     => $alert['occurred_at'] ?? null,
			'collector_id'    => $alert['collector_id'],
			'summary'         => $alert['subject'] ?? null,
			'details'         => $alert['message'] ?? null,
			'device'          => ['hostname' => $hostname, 'description' => $name, 'collector_name' => $name],
			'context'         => ['source_notification_channels' => ['email']],
		]);
		$result = api_plugin_hook_function('alarmmanager_submit', [
			'contract_version' => 1,
			'status'           => 'unhandled',
			'event'            => $event,
		]);

		if (is_array($result) && in_array($result['status'] ?? null, ['accepted', 'already_accepted'], true)) {
			$alert['status'] = $result['status'];
		}
	} catch (Throwable $error) {
		// Leave core email available; logging here could recursively generate admin alerts.
	} finally {
		$active = false;
	}

	return $alert;
}
