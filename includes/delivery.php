<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/queue.php');
require_once(__DIR__ . '/events.php');
require_once(__DIR__ . '/transports.php');

/** Trusted code registry only; configuration cannot name/evaluate PHP callbacks. */
function alarmmanager_delivery_handlers(): array {
	$handlers = ($GLOBALS['alarmmanager_transport_handlers'] ?? []) + ['restapi' => 'alarmmanager_http_transport', 'webhook' => 'alarmmanager_http_transport', 'email' => 'alarmmanager_email_transport', 'script' => 'alarmmanager_script_transport'];
	$result = [];
	foreach (['restapi', 'webhook', 'email', 'script', 'websocket'] as $type) {
		if (isset($handlers[$type]) && is_callable($handlers[$type])) {
			$result[$type] = $handlers[$type];
		}
	}
	return $result;
}
function alarmmanager_run_delivery(array $claim): bool {
	$handlers = alarmmanager_delivery_handlers();
	$handler = $handlers[$claim['endpoint']['type']] ?? null;
	if (!$handler) {
		return alarmmanager_finish_delivery($claim, ['outcome' => 'retry', 'code' => 'adapter_unavailable']);
	}
	// A delayed/stale worker must not begin a new external effect with an expired lease.
	if (!alarmmanager_db_row("SELECT id FROM plugin_alarmmanager_deliveries WHERE id = ? AND status = 'leased' AND lease_token = ? AND lease_until > DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)", [$claim['id'], $claim['lease_token'], (int) $claim['endpoint']['timeout_seconds']])) { return false; }
	$started = hrtime(true);
	$previous_handler = null;
	$previous_async = null;
	$armed = false;
	try {
		$row = alarmmanager_db_row('SELECT payload FROM plugin_alarmmanager_events WHERE id = ?', [$claim['event_row_id']]);
		if (!$row || !function_exists('pcntl_alarm')) {
			throw new RuntimeException('transport_unavailable');
		}
		$event = alarmmanager_validate_event(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR));
		require_once(__DIR__ . '/device.php');
		if (!alarmmanager_device_event_authorized($event, (int) $claim['endpoint']['timeout_seconds'] + 5)) { return alarmmanager_finish_delivery($claim, ['outcome' => 'failure', 'code' => 'device_owner_fenced']); }
		$previous_handler = pcntl_signal_get_handler(SIGALRM);
		$previous_async = pcntl_async_signals(true);
		pcntl_signal(SIGALRM, function (): void { throw new RuntimeException('transport_timeout'); });
		pcntl_alarm((int) $claim['endpoint']['timeout_seconds']);
		$armed = true;
		$result = $handler($event, $claim['endpoint'], $claim['idempotency_key']);
		if (!is_array($result)) {
			$result = ['outcome' => 'retry', 'code' => 'invalid_transport_result'];
		}
	} catch (InvalidArgumentException | JsonException $error) {
		$result = ['outcome' => 'failure', 'code' => 'transport_config_invalid'];
	} catch (Throwable $error) {
		$result = ['outcome' => 'retry', 'code' => $error instanceof RuntimeException && $error->getMessage() === 'transport_timeout' ? 'transport_timeout' : 'transport_exception'];
		if (str_starts_with($error->getMessage(), 'credential') || str_contains($error->getMessage(), 'credential key')) { $result = ['outcome' => 'failure', 'code' => 'credential_unavailable']; }
	} finally {
		if ($armed) {
			pcntl_alarm(0);
			pcntl_signal(SIGALRM, $previous_handler);
			pcntl_async_signals($previous_async);
		}
	}
	$result['duration_ms'] = (int) ((hrtime(true) - $started) / 1000000);
	return alarmmanager_finish_delivery($claim, $result);
}

