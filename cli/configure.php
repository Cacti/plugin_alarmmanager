<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', ['file:', 'help']);
if (isset($options['help']) || !is_string($options['file'] ?? null)) {
	print "Usage: php cli/configure.php --file=examples/routing.json\n"; exit(isset($options['help']) ? 0 : 2);
}
try {
	$data = @file_get_contents($options['file'], false, null, 0, 1048577);
	if ($data === false || strlen($data) > 1048576) { throw new RuntimeException('invalid_input'); }
	$settings = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
	if (!is_array($settings)) { throw new RuntimeException('invalid_input'); }
} catch (Throwable $error) { fwrite(STDERR, "Invalid bounded configuration file.\n"); exit(2); }
// Force Cacti's documented local/offline bootstrap: never wait for the main DB.
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/routing.php');
try { alarmmanager_apply_configuration($settings); print "Routing configuration applied.\n"; }
catch (Throwable $error) { fwrite(STDERR, "Configuration validation/storage failed.\n"); exit(1); }
