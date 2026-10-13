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
// Internal email worker subprocess; not an administrator command.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

try {
	$input = stream_get_contents(STDIN, 1048577);

	if (strlen($input) > 1048576) {
		throw new RuntimeException('mail_input_limit');
	}
	$message = json_decode($input, true, 24, JSON_THROW_ON_ERROR);

	if (!is_array($message)) {
		throw new RuntimeException('mail_input_invalid');
	}
	$conn_mode = 'offline';
	require_once(__DIR__ . '/../../../include/cli_check.php');
	require_once(__DIR__ . '/email_boundary.php');
	print json_encode(alarmmanager_email_send($message), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
	print '{"outcome":"retry","code":"email_failed"}';
	exit(1);
}
