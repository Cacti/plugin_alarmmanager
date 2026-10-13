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
 | Unit-suite bootstrap. Locates the supported Cacti installation (either  |
 | the plugins/<dir> layout or CACTI_PATH), requires its Composer vendor   |
 | tree, verifies the tests/.cacti-version sentinel, and loads the guarded |
 | recording doubles from tests/Helpers/CactiStubs.php. Never load this    |
 | bootstrap into a live Cacti installation.                               |
 +-------------------------------------------------------------------------+
*/

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

$candidate_roots = [];

if (getenv('CACTI_PATH') !== false) {
	$candidate_roots[] = rtrim((string) getenv('CACTI_PATH'), '/');
}

// tests/ -> plugins -> cacti root
$candidate_roots[] = dirname(__DIR__, 3);

$cacti_root = null;

foreach ($candidate_roots as $candidate_root) {
	if (is_file($candidate_root . '/include/vendor/autoload.php') && is_file($candidate_root . '/include/cacti_version')) {
		$cacti_root = $candidate_root;

		break;
	}
}

if ($cacti_root === null) {
	throw new RuntimeException('Cacti Composer autoloader is not readable; set CACTI_PATH to a Cacti checkout with include/vendor.');
}

$expected_version = trim((string) file_get_contents(__DIR__ . '/.cacti-version'));
$cacti_version    = trim((string) file_get_contents($cacti_root . '/include/cacti_version'));

if (!in_array($expected_version, ['1.2.x', 'develop'], true) && $cacti_version !== $expected_version) {
	throw new RuntimeException("Expected Cacti $expected_version, found $cacti_version in $cacti_root");
}

require_once $cacti_root . '/include/vendor/autoload.php';
require_once __DIR__ . '/Helpers/CactiStubs.php';
require_once __DIR__ . '/TestCase.php';

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 5);
}

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 3);
}

$GLOBALS['config'] = [
	'base_path'       => $cacti_root,
	'url_path'        => '/cacti/',
	'cacti_version'   => $cacti_version,
	'cacti_server_os' => 'unix',
];
