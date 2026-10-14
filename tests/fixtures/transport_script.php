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
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$mode = $argv[1] ?? 'ok';

if ($mode === 'descendants') {
	$pid = pcntl_fork();

	if ($pid === 0) {
		sleep(10);
		exit;
	} file_put_contents($argv[2], (string) $pid);
	sleep(10);
	exit;
}

if ($mode === 'timeout') {
	sleep(10);
	exit;
}

if ($mode === 'large') {
	while (true) {
		print str_repeat('x', 8192);
	}
}

if ($mode === 'retry') {
	exit(75);
}

if ($mode === 'failure') {
	exit(2);
}
$payload = stream_get_contents(STDIN);

if (isset($argv[2])) {
	file_put_contents($argv[2], json_encode(['payload' => json_decode($payload, true), 'arguments' => array_slice($argv, 3), 'environment_filtered' => getenv('ALARMMANAGER_FIXTURE_PRIVATE') === false, 'idempotency' => getenv('ALARM_IDEMPOTENCY_KEY')], JSON_THROW_ON_ERROR));
}

if ($mode === 'reference') {
	print json_encode(['ticket' => ['id' => 'SCRIPT-42', 'url' => 'https://tickets.example.invalid/script']], JSON_THROW_ON_ERROR);
}
exit(0);
