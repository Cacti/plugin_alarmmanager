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

function alarmmanager_web_user(): int {
	$id = filter_var($_SESSION['sess_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

	if (!$id) {
		throw new RuntimeException('permission_denied');
	}

	return $id;
}
function alarmmanager_web_realm(string $display): bool {
	if (!function_exists('is_realm_allowed')) {
		return false;
	}
	$file = match ($display) {
		'Alarm Manager View' => 'alarmmanager.php', 'Alarm Manager Administration' => 'alarmmanager_admin.php', default => throw new InvalidArgumentException('invalid_realm')
	};
	$rows = alarmmanager_db_rows('SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?', ['alarmmanager', $file]);

	foreach ($rows as $row) {
		if (is_realm_allowed((int) $row['id'] + 100)) {
			return true;
		}
	}

	return false;
}
function alarmmanager_web_admin(): bool {
	$user = alarmmanager_web_user();

	if (function_exists('get_guest_account') && (int) get_guest_account() === $user) {
		return false;
	}

	return function_exists('read_config_option') && (int) read_config_option('auth_method') > 0 && is_realm_allowed(1) && alarmmanager_web_realm('Alarm Manager Administration');
}
function alarmmanager_web_mutation_guard(): void {
	if (!alarmmanager_web_admin() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		throw new RuntimeException('permission_denied');
	}
}
/** Mirrors core get_allowed_devices() SQL policies without loading all hosts into PHP. */
function alarmmanager_web_scope(): array {
	$user  = alarmmanager_web_user();
	$admin = alarmmanager_web_admin();

	if (!$admin && !alarmmanager_web_realm('Alarm Manager View')) {
		throw new RuntimeException('permission_denied');
	}

	if ($admin) {
		return ['user_id' => $user, 'admin' => true, 'device_sql' => '1 = 1'];
	}

	foreach (['get_policies', 'get_policy_where', 'read_user_setting'] as $function) {
		if (!function_exists($function)) {
			throw new RuntimeException('permission_unavailable');
		}
	}
	$policies = get_policies($user);

	if (!is_array($policies) || !$policies || count($policies) > 128) {
		throw new RuntimeException('permission_unavailable');
	}

	foreach ($policies as &$policy) {
		if (!is_array($policy) || !in_array($policy['type'] ?? '', ['user', 'group'], true)) {
			throw new RuntimeException('permission_unavailable');
		}
		$policy['id'] = filter_var($policy['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if (!$policy['id']) {
			throw new RuntimeException('permission_unavailable');
		}

		foreach (['policy_graphs', 'policy_hosts', 'policy_graph_templates', 'policy_trees'] as $key) {
			$policy[$key] = (int) ($policy[$key] ?? 0);

			if (!in_array($policy[$key], [1, 2], true)) {
				throw new RuntimeException('permission_unavailable');
			}
		}
	} unset($policy);
	$method = (int) read_config_option('graph_auth_method');

	if (!in_array($method, [1, 2, 3, 4], true)) {
		throw new RuntimeException('permission_unavailable');
	}
	$where = 'WHERE h.id = e.device_id AND h.deleted = \'\'';

	if (read_user_setting('hide_disabled', false, false, $user) === 'on') {
		$where .= " AND (h.disabled = '' OR h.disabled IS NULL)";
	}
	$where = get_policy_where($method, $policies, $where);

	return ['user_id' => $user, 'admin' => false, 'device_sql' => 'e.device_id IS NOT NULL AND EXISTS (SELECT 1 FROM host AS h LEFT JOIN graph_local AS gl ON h.id = gl.host_id LEFT JOIN graph_templates AS gt ON gt.id = gl.graph_template_id LEFT JOIN host_template AS ht ON h.host_template_id = ht.id ' . $where . ')'];
}
