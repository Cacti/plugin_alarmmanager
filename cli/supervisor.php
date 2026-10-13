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
$options = getopt('', ['config:', 'help']);

if (isset($options['help'])) {
	print "Usage: php cli/supervisor.php [--config=/etc/cacti/alarmmanager-workers.json]\n";
	exit;
}

if (isset($options['config']) && !is_string($options['config'])) {
	fwrite(STDERR, "Invalid configuration path.\n");
	exit(2);
}
require_once(__DIR__ . '/../includes/supervisor.php');

try {
	alarmmanager_supervise($options['config'] ?? null);
} catch (Throwable $error) {
	fwrite(STDERR, "Alarm Manager supervisor configuration/runtime failure.\n");
	exit(1);
}
