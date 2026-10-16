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
require_once(__DIR__ . '/../../include/auth.php');
require_once(__DIR__ . '/includes/admin_view.php');
header('Cache-Control: no-store');

const ALARMMANAGER_SECTIONS = ['endpoints', 'profiles', 'collectors', 'rules', 'credentials', 'test', 'configuration', 'daemon', 'queue', 'health'];

try {
	if (!alarmmanager_web_admin()) {
		throw new RuntimeException('permission_denied');
	}

	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		// Enumerate keys only; every value is read back through Cacti's
		// unfiltered accessor before the action validates it.
		$post = [];

		foreach (array_keys($_POST) as $key) {
			$post[$key] = get_nfilter_request_var((string) $key);
		}

		try {
			$result = alarmmanager_admin_action($post);

			if (in_array($post['action'] ?? '', ['preview', 'test_send'], true)) {
				$_SESSION['alarmmanager_test_result'] = $result;
			}
			raise_message('alarmmanager_notice', __('The administration action succeeded.', 'alarmmanager'), MESSAGE_LEVEL_INFO);
		} catch (Throwable $error) {
			$message = alarmmanager_admin_error_message($error);
			alarmmanager_log('ERROR: Administration action failed: ' . $message . ' [' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . ']');
			raise_message('alarmmanager_notice', __($message, 'alarmmanager'), MESSAGE_LEVEL_ERROR);
		}
		cacti_redirect(match ($post['action'] ?? '') {
			'queue_pause', 'queue_resume', 'queue_clear' => 'alarmmanager_admin.php?section=queue', 'preview', 'test_send' => 'alarmmanager_admin.php?section=test', 'daemon_save' => 'alarmmanager_admin.php?section=daemon', 'rule_save' => 'alarmmanager_admin.php?section=rules', 'profile_save' => 'alarmmanager_admin.php?section=profiles', 'sync_peer_enable', 'sync_peer_save', 'sync_peer_rotate', 'sync_peer_disable' => 'alarmmanager_admin.php?section=collectors', default => 'alarmmanager_admin.php'
		});
	}

	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
		throw new RuntimeException('permission_denied');
	}

	$state   = alarmmanager_admin_configuration();
	$section = (string) get_filter_request_var('section', FILTER_CALLBACK, ['options' => static fn ($value) => in_array($value, ALARMMANAGER_SECTIONS, true) ? $value : false, 'default' => 'endpoints']);
} catch (Throwable $error) {
	http_response_code($error instanceof InvalidArgumentException ? 400 : ($error instanceof \AlarmManager\StorageException ? 503 : 403));
	print html_escape(__('Administration unavailable or permission denied.', 'alarmmanager'));
	exit;
}
top_header();

alarmmanager_web_assets();
alarmmanager_web_open(['admin' => true], 'admin');
alarmmanager_admin_markup($state, $section);
bottom_footer();
