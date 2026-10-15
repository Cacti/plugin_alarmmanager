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

const ALARMMANAGER_SECTIONS = ['endpoints', 'profiles', 'clients', 'producers', 'collectors', 'rules', 'credentials', 'test', 'configuration', 'daemon', 'health'];

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
			$message = match ($error->getMessage()) {
				'daemon_not_running'         => 'Start the Alarm Manager service on this collector before enabling daemon workers.',
				'daemon_config_write_failed' => 'Daemon settings could not be saved. Check the Cacti cache directory permissions.',
				'endpoint_in_profile' => 'Remove this endpoint from its routing profiles before deleting.',
				'endpoint_has_active_deliveries' => 'This endpoint has queued or running deliveries. Resolve them before deleting.',
				'test_endpoint_unavailable' => 'The selected endpoint is disabled or no longer exists. Enable and save it before queuing a test send.',
				'invalid_credentials' => 'Enter the credentials for the selected authentication type, choose Keep saved credentials, or choose None for an incoming webhook.',
				'confirmation_required' => 'Select the confirmation checkbox before continuing.',
				'configuration_changed'      => 'Configuration changed. Reload before applying your edits.',
				'permission_denied'          => 'Action failed. Administrator access, POST and a valid CSRF token are required.',
				'invalid_transport'          => 'Action failed. Transport settings do not match the endpoint type.',
				'invalid_transport_url'      => 'Action failed. Use an HTTPS URL without credentials, query parameters or fragments.',
				'invalid_mapping'            => 'Action failed. Each mapping transition must contain a list of fields.',
				'api_device_scope_required'  => 'Choose allowed devices or explicitly allow all devices or unmapped events.',
				'api_profile_required'       => 'Choose an enabled routing profile with an enabled routing rule for centralized delivery.',
				'invalid_api_client'         => 'API client settings are invalid. Check the source, collector, mode and device scope.',
				'invalid_action'             => 'Action failed. A required field is missing or outside its allowed range.',
				'invalid_configuration'      => 'Action failed. Configuration fields are invalid.',
				default                      => 'Action failed. Check configuration, permissions and CSRF token. Submitted credential values were cleared.'
			};
			raise_message('alarmmanager_notice', __($message, 'alarmmanager'), MESSAGE_LEVEL_ERROR);
		}
		cacti_redirect(match ($post['action'] ?? '') {
			'preview', 'test_send' => 'alarmmanager_admin.php?section=test', 'daemon_save' => 'alarmmanager_admin.php?section=daemon', 'api_client_save' => 'alarmmanager_admin.php?section=clients', default => 'alarmmanager_admin.php'
		});
	}

	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
		throw new RuntimeException('permission_denied');
	}

	$state   = alarmmanager_admin_configuration();
	$section = (string) get_filter_request_var('section', FILTER_CALLBACK, ['options' => static fn ($value) => in_array($value, ALARMMANAGER_SECTIONS, true) ? $value : false, 'default' => 'endpoints']);

	if ($section === 'producers') {
		$section = 'clients';
	}
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
