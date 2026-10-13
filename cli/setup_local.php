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
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$options = getopt('', ['local', 'help']);

if (isset($options['help']) || !isset($options['local'])) {
	print "Usage: php cli/setup_local.php --local\nInitialize this poller's schema and setup key after Cacti plugin installation.\n";
	exit(isset($options['help']) ? 0 : 2);
}
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../setup.php');

try {
	$installed = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false);

	if ((int) $installed !== 1 || PHP_VERSION_ID < 80200 || !extension_loaded('openssl')) {
		throw new RuntimeException('local_setup_unavailable');
	}
	alarmmanager_setup_key();

	if (!alarmmanager_schema_install()) {
		throw new RuntimeException('schema_setup_failed');
	}

	if (!db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [plugin_alarmmanager_version()['version'], 'alarmmanager'], false)) {
		throw new RuntimeException('metadata_update_failed');
	}
	print "Local Alarm Manager schema and key are ready. Plugin enabled/disabled status is unchanged.\n";
} catch (Throwable $error) {
	fwrite(STDERR, "Local setup failed; verify Cacti installation, MariaDB version and existing key backup.\n");
	exit(1);
}
