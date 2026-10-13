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
function alarmmanager_schema_producers(): bool {
	$queries = [
		'CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_config (
		 source VARCHAR(16) CHARACTER SET ascii NOT NULL, scope VARCHAR(16) CHARACTER SET ascii NOT NULL,
		 resource_id INT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, definition TEXT NOT NULL,
		 PRIMARY KEY (source, scope, resource_id)) ENGINE=InnoDB',
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_streams (
		 stream_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
		 episode_id VARCHAR(128) CHARACTER SET ascii NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 0,
		 severity VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '', updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB",
		"CREATE TABLE IF NOT EXISTS plugin_alarmmanager_producer_receipts (
		 receipt CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, source VARCHAR(16) CHARACTER SET ascii NOT NULL,
		 stream_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
		 envelope MEDIUMTEXT NOT NULL, channels TEXT NOT NULL, event_row_id BIGINT UNSIGNED NULL,
		 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
		 KEY receipt_status (status, updated_at), KEY source_stream (source, stream_key)) ENGINE=InnoDB"
	];

	foreach ($queries as $sql) {
		if (!db_execute_prepared($sql, [], false)) {
			return false;
		}
	}

	return (bool) db_execute_prepared('UPDATE plugin_alarmmanager_schema SET version = GREATEST(version, 5) WHERE id = 1', [], false);
}
/** Called inside the ingestion transaction: acceptance and producer ownership commit together. */
function alarmmanager_accept_producer_receipt(array $envelope, array $event, int $id): void {
	if (!isset($envelope['producer_receipt'])) {
		return;
	}
	$receipt = $envelope['producer_receipt'];

	if (!is_string($receipt) || !preg_match('/\A[a-f0-9]{64}\z/D', $receipt)) {
		throw new InvalidArgumentException('invalid_producer_receipt');
	}
	$row = alarmmanager_db_row('SELECT status, stream_key, envelope FROM plugin_alarmmanager_producer_receipts WHERE receipt = ? FOR UPDATE', [$receipt]);

	if (!$row || !in_array($row['status'], ['pending', 'accepted', 'observed'], true)) {
		throw new RuntimeException('producer_receipt_unavailable');
	}
	$saved = json_decode($row['envelope'], true, 32, JSON_THROW_ON_ERROR);

	if (($saved['mode'] ?? null) !== $envelope['mode'] || alarmmanager_validate_event($saved['event']) !== $event) {
		throw new InvalidArgumentException('producer_receipt_conflict');
	}
	alarmmanager_db_write('UPDATE plugin_alarmmanager_producer_receipts SET status = ?, event_row_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE receipt = ?', [$envelope['mode'] === 'centralized' ? 'accepted' : 'observed', $id, $receipt]);
	// Duplicate receipts cannot rewind newer source ordering.
	alarmmanager_db_write('UPDATE plugin_alarmmanager_producer_streams SET active = ?, episode_id = ?, severity = ?, updated_at = UTC_TIMESTAMP(6) WHERE stream_key = ? AND sequence = ?', [$event['transition'] === 'recovered' || $event['transition'] === 'occurrence' ? 0 : 1, $event['episode_id'], $event['severity'], $row['stream_key'], $event['sequence']]);
}
