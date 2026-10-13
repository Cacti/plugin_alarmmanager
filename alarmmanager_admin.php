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
require_once(__DIR__ . '/../../include/auth.php');
require_once(__DIR__ . '/includes/admin_view.php');
header('Cache-Control: no-store');

try {
	if (!alarmmanager_web_admin()) {
		throw new RuntimeException('permission_denied');
	}

	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		try {
			$_SESSION['alarmmanager_notice'] = alarmmanager_admin_action($_POST);
		} catch (Throwable $error) {
			$message = match ($error->getMessage()) {
				'configuration_changed' => 'Configuration changed. Reload before applying your edits.',
				'permission_denied'     => 'Action failed. Administrator access, POST and a valid CSRF token are required.',
				'invalid_transport'     => 'Action failed. Transport settings do not match the endpoint type.',
				'invalid_transport_url' => 'Action failed. Use an HTTPS URL without credentials, query parameters or fragments.',
				'invalid_mapping'       => 'Action failed. Each mapping transition must contain a list of fields.',
				'invalid_action'        => 'Action failed. A required field is missing or outside its allowed range.',
				'invalid_configuration' => 'Action failed. Configuration fields are invalid.',
				default                 => 'Action failed. Check configuration, permissions and CSRF token. Submitted credential values were cleared.'
			};
			$_SESSION['alarmmanager_notice'] = __($message, 'alarmmanager');
		}
		header('Location: alarmmanager_admin.php');
		exit;
	}

	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
		throw new RuntimeException('permission_denied');
	}
	$section = $_GET['section'] ?? 'endpoints';

	if (!is_string($section) || !in_array($section, ['endpoints', 'profiles', 'producers', 'collectors', 'rules', 'credentials', 'test', 'configuration', 'health', 'audit'], true)) {
		throw new InvalidArgumentException('invalid_section');
	}
	$state = alarmmanager_admin_configuration();
} catch (Throwable $error) {
	http_response_code($error instanceof InvalidArgumentException ? 400 : ($error instanceof \AlarmManager\StorageException ? 503 : 403));
	print alarmmanager_label('Administration unavailable or permission denied.');
	exit;
}
top_header();
alarmmanager_web_assets();
alarmmanager_web_open(['admin' => true], 'admin');

if (isset($_SESSION['alarmmanager_notice'])) {
	print '<pre role="status" class="alarmmanagerNotice">' . alarmmanager_escape($_SESSION['alarmmanager_notice']) . '</pre>';
	unset($_SESSION['alarmmanager_notice']);
}
alarmmanager_admin_markup($state, $section);
print '</main>';
bottom_footer();
