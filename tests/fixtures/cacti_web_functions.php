<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') { http_response_code(403); exit; }
$cacti = getenv('ALARMMANAGER_CACTI_ROOT');
if (!$cacti || !is_file($cacti . '/lib/auth.php')) { throw new RuntimeException('Pinned Cacti source path required.'); }
$config = ['base_path' => $cacti, 'url_path' => '/', 'cacti_db_version' => '1.2.32', 'poller_id' => 2, 'is_web' => false, 'config_options_array' => ['auth_method' => 1, 'graph_auth_method' => 1, 'guest_user' => 'guest', 'selected_theme' => 'modern', 'selective_debug' => '', 'selective_plugin_debug' => '', 'client_timezone_support' => '', 'log_destination' => 1, 'log_verbosity' => 0, 'path_cactilog' => '/dev/null']];
$settings_user = ['fixture' => ['hide_disabled' => ['default' => false]]];
$themes = ['modern' => 'Modern', 'dark' => 'Dark', 'classic' => 'Classic'];
require_once($cacti . '/include/global_constants.php');
require_once($cacti . '/lib/functions.php');
require_once($cacti . '/lib/auth.php');
