<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', ['config:', 'help']);
if (isset($options['help'])) { print "Usage: php cli/supervisor.php [--config=/etc/cacti/alarmmanager-workers.json]\n"; exit; }
if (isset($options['config']) && !is_string($options['config'])) { fwrite(STDERR, "Invalid configuration path.\n"); exit(2); }
require_once(__DIR__ . '/../includes/supervisor.php');
try { alarmmanager_supervise($options['config'] ?? null); }
catch (Throwable $error) { fwrite(STDERR, "Alarm Manager supervisor configuration/runtime failure.\n"); exit(1); }
