<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/runtime.php');

/** Condition state is independent of acknowledgment and notification state. */
function alarmmanager_transition(array $state, array $event): array {
	if ($event['sequence'] <= (int) $state['last_sequence']) {
		return [$state, 'stale'];
	}
	$kind = $event['transition'];
	if ($kind === 'occurrence') {
		$state['last_sequence'] = $event['sequence'];
		return [$state, 'occurrence'];
	}
	if ($kind !== 'raised' && ($state['episode_id'] !== $event['episode_id'] || !(bool) $state['active'])) {
		return [$state, 'ignored_episode'];
	}
	if ($kind === 'raised' && $state['episode_id'] === $event['episode_id'] && !(bool) $state['active'] && (int) $state['last_sequence'] > 0) {
		return [$state, 'closed_episode'];
	}
	if ($kind === 'raised' && $state['episode_id'] !== $event['episode_id']) {
		$state['acknowledged'] = 0;
	}
	$state['episode_id'] = $event['episode_id'];
	$state['last_sequence'] = $event['sequence'];
	$state['active'] = $kind === 'recovered' ? 0 : 1;
	$state['severity'] = $event['severity'];
	$state['summary'] = $event['summary'];
	return [$state, 'applied'];
}

function alarmmanager_acknowledge(string $stream_key, bool $acknowledged): void {
	if (!preg_match('/\A[a-f0-9]{64}\z/D', $stream_key)) {
		throw new InvalidArgumentException('invalid_stream');
	}
	alarmmanager_db_write('UPDATE plugin_alarmmanager_states SET acknowledged = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ?', [$acknowledged ? 1 : 0, $stream_key]);
}
