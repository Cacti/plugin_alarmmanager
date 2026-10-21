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
if (PHP_SAPI !== 'cli-server' || getenv('ALARMMANAGER_TEST_WEB') !== '1' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
	http_response_code(403);
	exit;
}
require_once(__DIR__ . '/web_preload.php');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/__fixture/login') {
	session_start();
	$user  = (int) ($_GET['user'] ?? 1);
	$theme = $_GET['theme'] ?? 'modern';

	if (!in_array($user, [1, 2, 3], true) || !in_array($theme, ['modern', 'dark', 'classic'], true)) {
		http_response_code(400);
		exit;
	}
	$_SESSION = ['sess_user_id' => $user, 'selected_theme' => $theme];
	header('Location: /plugins/alarmmanager/alarmmanager.php');
	exit;
}

if (in_array($path, ['/plugins/alarmmanager/alarmmanager.php', '/plugins/alarmmanager/alarmmanager_admin.php'], true)) {
	require(getenv('ALARMMANAGER_WEB_ROOT') . $path);

	return true;
}

if (in_array($path, ['/plugins/alarmmanager/css/dashboard.css', '/plugins/alarmmanager/js/dashboard.js'], true)) {
	return false;
}

if (is_string($path) && preg_match('~\A/include/(?:js/(?:jquery|d3|billboard)\.js|themes/(?:modern|dark|classic)/(?:[A-Za-z0-9._/-]+))\z~D', $path) && !str_contains($path, '..')) {
	$file = getenv('ALARMMANAGER_CACTI_ROOT') . $path;

	if (!is_file($file) || str_ends_with($file, '.php')) {
		http_response_code(404);
		exit;
	}
	header('Content-Type: ' . (str_ends_with($file, '.css') ? 'text/css' : (str_ends_with($file, '.js') ? 'application/javascript' : 'application/octet-stream')));
	readfile($file);

	return true;
}
http_response_code(404);

return true;
