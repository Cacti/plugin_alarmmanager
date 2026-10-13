<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/queue.php');
require_once(__DIR__ . '/events.php');

/** Trusted code registry only; configuration cannot name/evaluate PHP callbacks. */
function alarmmanager_delivery_handlers(): array {
	$handlers = $GLOBALS['alarmmanager_transport_handlers'] ?? [];
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
		$previous_handler = pcntl_signal_get_handler(SIGALRM);
		$previous_async = pcntl_async_signals(true);
		pcntl_signal(SIGALRM, function (): void { throw new RuntimeException('transport_timeout'); });
		pcntl_alarm((int) $claim['endpoint']['timeout_seconds']);
		$armed = true;
		$result = $handler($event, $claim['endpoint'], $claim['idempotency_key']);
		if (!is_array($result)) {
			$result = ['outcome' => 'retry', 'code' => 'invalid_transport_result'];
		}
	} catch (Throwable $error) {
		$result = ['outcome' => 'retry', 'code' => $error instanceof RuntimeException && $error->getMessage() === 'transport_timeout' ? 'transport_timeout' : 'transport_exception'];
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
