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

const ALARMMANAGER_SECTIONS = ['endpoints', 'profiles', 'producers', 'collectors', 'rules', 'credentials', 'test', 'configuration', 'health', 'audit'];

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
			alarmmanager_admin_action($post);
			raise_message('alarmmanager_notice', __('The administration action succeeded.', 'alarmmanager'), MESSAGE_LEVEL_INFO);
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
			raise_message('alarmmanager_notice', __($message, 'alarmmanager'), MESSAGE_LEVEL_ERROR);
		}
		cacti_redirect('alarmmanager_admin.php');
	}

	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
		throw new RuntimeException('permission_denied');
	}

	$state   = alarmmanager_admin_configuration();
	$section = (string) get_filter_request_var('section', FILTER_CALLBACK, ['options' => ['options' => static fn ($value) => in_array($value, ALARMMANAGER_SECTIONS, true) ? $value : false], 'default' => 'endpoints']);
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
