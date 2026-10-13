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
 | Static-analysis bootstrap. Loads the supported Cacti installation's     |
 | core definitions so PHPStan sees real helper signatures instead of      |
 | hand-written stubs, mirroring Cacti develop's own .phpstan.neon         |
 | bootstrapFiles list. The Cacti root is resolved from CACTI_PATH or from |
 | the standard plugins/<dir> layout when this repository is checked out   |
 | inside a Cacti installation.                                            |
 +-------------------------------------------------------------------------+
*/

define('PHP_TESTING', true);

// PHP_TESTING alone no longer arms the DB short-circuit in include/global.php;
// the CACTI_TEST_BOOTSTRAP env var must also be set.
putenv('CACTI_TEST_BOOTSTRAP=1');
$_ENV['CACTI_TEST_BOOTSTRAP'] = '1';

$candidate_roots = [];

if (getenv('CACTI_PATH') !== false) {
	$candidate_roots[] = rtrim((string) getenv('CACTI_PATH'), '/');
}

// tests/Helpers -> plugin root (2 levels) -> plugins -> cacti root (4 levels)
$candidate_roots[] = dirname(__DIR__, 4);

$cacti_root = null;

foreach ($candidate_roots as $candidate_root) {
	if (is_file($candidate_root . '/include/global.php') && is_file($candidate_root . '/include/vendor/autoload.php')) {
		$cacti_root = $candidate_root;

		break;
	}
}

if ($cacti_root === null) {
	throw new RuntimeException('PHPStan bootstrap: no Cacti installation found; set CACTI_PATH to a Cacti checkout with a Composer vendor tree.');
}

require_once $cacti_root . '/include/vendor/autoload.php';
require_once $cacti_root . '/include/config.php.dist';
require_once $cacti_root . '/include/global_constants.php';
require_once $cacti_root . '/lib/functions.php';
require_once $cacti_root . '/include/global.php';
require_once $cacti_root . '/include/global_languages.php';
require_once $cacti_root . '/include/global_arrays.php';
require_once $cacti_root . '/include/global_form.php';
require_once $cacti_root . '/include/global_settings.php';
