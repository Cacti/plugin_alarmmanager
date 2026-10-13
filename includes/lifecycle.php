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
	$state['episode_id']    = $event['episode_id'];
	$state['last_sequence'] = $event['sequence'];
	$state['active']        = $kind === 'recovered' ? 0 : 1;
	$state['severity']      = $event['severity'];
	$state['summary']       = $event['summary'];

	return [$state, 'applied'];
}

function alarmmanager_acknowledge(string $stream_key, bool $acknowledged): void {
	if (!preg_match('/\A[a-f0-9]{64}\z/D', $stream_key)) {
		throw new InvalidArgumentException('invalid_stream');
	}
	alarmmanager_db_write('UPDATE plugin_alarmmanager_states SET acknowledged = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ?', [$acknowledged ? 1 : 0, $stream_key]);
}
