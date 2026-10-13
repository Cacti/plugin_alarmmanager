<?php
// SPDX-License-Identifier: GPL-2.0-or-later

/** Setup foundation only. Credential encryption and rotation arrive in phase 5. */
function alarmmanager_decode_key($encoded): string {
	if (!is_string($encoded)) {
		throw new RuntimeException('Alarm Manager credential key is missing.');
	}

	$key = base64_decode($encoded, true);
	if ($key === false || strlen($key) !== 32 || base64_encode($key) !== $encoded) {
		throw new RuntimeException('Alarm Manager credential key is malformed.');
	}

	return $key;
}

/**
 * Servcheck-style settings_user storage, with explicit installation ownership.
 * INSERT IGNORE prevents concurrent setup calls from replacing the winning key.
 * Call only on initial installation; enable and upgrade never regenerate keys.
 */
function alarmmanager_setup_key(): void {
	global $config;
	if (!empty($config['DEBUG_SQL_FLOW'])) {
		throw new RuntimeException('Disable SQL flow tracing before credential-key setup.');
	}
	$encoded = db_fetch_cell_prepared('SELECT value FROM settings_user WHERE user_id = ? AND name = ?', [1, 'alarmmanager_key'], '', false);
	if ($encoded === false || $encoded === null) {
		$candidate = base64_encode(random_bytes(32));
		if (!db_execute_prepared('INSERT IGNORE INTO settings_user (user_id, name, value) VALUES (?, ?, ?)', [1, 'alarmmanager_key', $candidate], false)) {
			throw new RuntimeException('Alarm Manager credential key could not be stored.');
		}
		$encoded = db_fetch_cell_prepared('SELECT value FROM settings_user WHERE user_id = ? AND name = ?', [1, 'alarmmanager_key'], '', false);
	}

	alarmmanager_decode_key($encoded);
}

function alarmmanager_key_ready(): bool {
	global $config;
	if (!empty($config['DEBUG_SQL_FLOW'])) {
		return false;
	}
	try {
		alarmmanager_decode_key(db_fetch_cell_prepared('SELECT value FROM settings_user WHERE user_id = ? AND name = ?', [1, 'alarmmanager_key'], '', false));
		return true;
	} catch (RuntimeException $error) {
		return false;
	}
}
