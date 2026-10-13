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
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
	http_response_code(403);
	exit;
}
$cacti = getenv('ALARMMANAGER_CACTI_ROOT');

if (!$cacti || !is_file($cacti . '/lib/auth.php')) {
	throw new RuntimeException('Pinned Cacti source path required.');
}
$config        = ['base_path' => $cacti, 'url_path' => '/', 'cacti_db_version' => '1.2.32', 'poller_id' => 2, 'is_web' => false, 'config_options_array' => ['auth_method' => 1, 'graph_auth_method' => 1, 'guest_user' => 'guest', 'selected_theme' => 'modern', 'selective_debug' => '', 'selective_plugin_debug' => '', 'client_timezone_support' => '', 'log_destination' => 1, 'log_verbosity' => 0, 'path_cactilog' => '/dev/null']];
$settings_user = ['fixture' => ['hide_disabled' => ['default' => false]]];
$themes        = ['modern' => 'Modern', 'dark' => 'Dark', 'classic' => 'Classic'];
require_once($cacti . '/include/global_constants.php');
require_once($cacti . '/lib/functions.php');
require_once($cacti . '/lib/auth.php');
