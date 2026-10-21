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
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$options = getopt('', ['identity', 'provision:', 'configure:', 'grant-file:', 'send', 'limit:', 'seed:', 'after:', 'export-for:', 'endpoints:', 'output:', 'help']);

if (isset($options['help'])) {
	print "Usage:\n  php cli/remote.php --identity\n  php cli/remote.php --export-for=2 --endpoints=1,2 --output=/secure/poller-config.json\n  php cli/remote.php --provision=/secure/poller-config.json\n  php cli/remote.php --configure=/secure/routing.json\n  php cli/remote.php --grant-file=/secure/device-grant.json\n  php cli/remote.php --send [--limit=100]\n  php cli/remote.php --seed=event|processing|decision|delivery|attempt [--after=0 --limit=100]\nHistory uses Cacti's existing main database connection; no peer provisioning is required. Secrets are read/written only through owner-private files. Seed is bounded migration of prior local history.\n";
	exit;
}
$conn_mode = isset($options['send']) ? 'online' : 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/remote.php');

try {
	$actions = array_intersect(['identity', 'provision', 'configure', 'grant-file', 'send', 'seed', 'export-for'], array_keys($options));

	if (count($actions) !== 1) {
		throw new RuntimeException('invalid_options');
	}
	$action = array_values($actions)[0];
	$limit  = filter_var($options['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);

	if (!$limit) {
		throw new RuntimeException('invalid_options');
	}

	if ($action === 'identity') {
		print json_encode(['poller_id' => alarmmanager_local_poller(), 'instance_uuid' => alarmmanager_node_uuid()]) . "\n";
	} elseif ($action === 'provision') {
		alarmmanager_remote_provision(json_decode(alarmmanager_remote_private_read($options[$action], 8388608), true, 32, JSON_THROW_ON_ERROR));
		print "Local configuration and usable credentials provisioned.\n";
	} elseif ($action === 'configure') {
		$settings = json_decode(alarmmanager_remote_private_read((string) $options[$action], 1048576), true, 32, JSON_THROW_ON_ERROR);

		if (!is_array($settings)) {
			throw new RuntimeException('invalid_options');
		}
		alarmmanager_apply_configuration($settings);
		print "Routing configuration applied.\n";
	} elseif ($action === 'export-for') {
		$poller    = filter_var($options['export-for'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
		$endpoints = explode(',', $options['endpoints'] ?? '');
		$ids       = [];

		foreach ($endpoints as $value) {
			$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

			if (!$id) {
				throw new RuntimeException('invalid_options');
			} $ids[] = $id;
		}

		if (!$poller || !isset($options['output'])) {
			throw new RuntimeException('invalid_options');
		}
		$bundle = alarmmanager_remote_export_configuration($poller, $ids);
		alarmmanager_remote_private_write($options['output'], json_encode($bundle, JSON_THROW_ON_ERROR));
		print "Selected configuration exported to a private file. Transfer securely and remove plaintext provisioning files after import.\n";
	} elseif ($action === 'grant-file') {
		if (alarmmanager_local_poller() !== 1) {
			throw new RuntimeException('main_only');
		}
		$grant = alarmmanager_device_grant_validate(json_decode(alarmmanager_remote_private_read($options[$action], 16384), true, 8, JSON_THROW_ON_ERROR));
		alarmmanager_transaction(static function () use ($grant): void { alarmmanager_install_device_grant($grant); });
		print "Device ownership grant installed; provision it on its authorized owner.\n";
	} elseif ($action === 'send') {
		alarmmanager_sync_send($limit);
		print "History/heartbeat committed to main through Cacti.\n";
	} else {
		$entities = alarmmanager_sync_entities();
		$entity   = $options['seed'];

		if (!isset($entities[$entity])) {
			throw new RuntimeException('invalid_options');
		}
		$after = filter_var($options['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

		if ($after === false) {
			throw new RuntimeException('invalid_options');
		}
		[$table] = $entities[$entity];
		$rows    = alarmmanager_db_rows('SELECT id FROM plugin_alarmmanager_' . $table . ' WHERE id > ? ORDER BY id LIMIT ' . $limit, [$after]);
		alarmmanager_transaction(static function () use ($rows, $entity): void {
			foreach ($rows as $row) {
				alarmmanager_db_write('INSERT IGNORE INTO plugin_alarmmanager_sync_outbox (entity, entity_id, seed, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP(6))', [$entity, $row['id']]);
			}
		});
		print json_encode(['seeded' => count($rows), 'after' => $rows ? (int) end($rows)['id'] : $after]) . "\n";
	}
} catch (Throwable $error) {
	alarmmanager_log('remote command failed: ' . $error->getMessage());
	fwrite(STDERR, "Remote action failed; no credentials, peer keys, payloads or network diagnostics displayed. Pending history remains available for retry.\n");
	exit(1);
}
