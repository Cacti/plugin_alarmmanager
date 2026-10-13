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
if (PHP_SAPI !== 'cli' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
	exit(2);
}
require_once(__DIR__ . '/../Integration/bootstrap.php');
require_once(__DIR__ . '/web_schema.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/processor.php');
require_once(__DIR__ . '/../../includes/queue.php');
require_once(__DIR__ . '/../../includes/credential_store.php');
$config = ['poller_id' => 2];
$root   = getenv('ALARMMANAGER_WEB_ROOT');

if (!$root || !str_starts_with($root, '/') || file_exists($root)) {
	throw new RuntimeException('New absolute disposable fixture directory required.');
}
mkdir($root, 0700, true);
mkdir($root . '/sessions', 0700);
$alarmmanager_test_db = alarmmanager_test_connect();
$database             = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$alarmmanager_test_db->exec("USE `$database`");
file_put_contents($root . '/database.txt', $database);
file_put_contents($root . '/csrf-secret.txt', bin2hex(random_bytes(32)));
alarmmanager_test_web_schema($alarmmanager_test_db);

if (!plugin_alarmmanager_install()) {
	throw new RuntimeException('Fixture install failed');
} alarmmanager_test_web_grants($alarmmanager_test_db);
$routes                              = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true);
$routes['endpoints'][0]['name']      = 'REST API <img src=x onerror=window.ALARM_BAD=1>';
$routes['endpoints'][0]['transport'] = ['url' => 'https://example.test/alarms', 'credential' => true, 'mapping' => ['default' => [['to' => 'hostname', 'source' => 'device.id']]]];
$routes['endpoints'][1]['transport'] = ['url' => 'https://webhook.example.test/alarms', 'mapping' => ['default' => [['to' => 'host', 'source' => 'device.id']]]];
alarmmanager_apply_configuration($routes);
alarmmanager_save_credential(1, ['headers' => ['Authorization' => 'Bearer WEB_BROWSER_SECRET']]);
$event = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true);

for ($i = 1; $i <= 80; $i++) {
	$sample                 = $event;
	$sample['event_id']     = 'browser:' . $i;
	$sample['alarm_key']    = 'browser:' . $i;
	$sample['source']       = $i % 2 ? 'cacti' : 'thold';
	$sample['device']['id'] = $i <= 60 ? 42 : 43;
	$sample['summary']      = $i <= 60 ? 'Visible <img src=x onerror=window.ALARM_BAD=1>' : 'HIDDEN_FORBIDDEN_MARKER';
	$sample['severity']     = ['critical', 'major', 'minor', 'warning'][$i % 4];
	$response               = alarmmanager_submit(['contract_version' => 1, 'status' => 'unhandled', 'mode' => 'centralized', 'event' => $sample]);

	if ($response['status'] !== 'accepted') {
		throw new RuntimeException('Fixture event failed');
	}
}

while ($claim = alarmmanager_claim_processing()) {
	alarmmanager_process_job($claim);
}

for ($i = 0; $i < 12; $i++) {
	$claim = alarmmanager_claim_delivery(['restapi', 'webhook']);

	if (!$claim) {
		break;
	} alarmmanager_finish_delivery($claim, ['outcome' => $i % 4 === 0 ? 'failure' : 'success', 'code' => $i % 4 === 0 ? 'fixture_failure' : 'fixture_ok', 'duration_ms' => 20 + $i]);
}

foreach (['events' => 'received_at', 'deliveries' => 'created_at', 'attempts' => 'started_at', 'decisions' => 'created_at'] as $table => $column) {
	alarmmanager_db_write("UPDATE plugin_alarmmanager_$table SET $column = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 SECOND)");
}
alarmmanager_heartbeat(str_repeat('a', 32), 'processing', 'idle');
alarmmanager_heartbeat(str_repeat('b', 32), 'delivery', 'idle');
$source   = realpath(__DIR__ . '/../..');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
	$relative = substr($file->getPathname(), strlen($source) + 1);

	if (!$file->isFile() || str_starts_with($relative, 'tests/') || str_starts_with($relative, 'docs/')) {
		continue;
	}
	$dest = $root . '/plugins/alarmmanager/' . $relative;

	if (!is_dir(dirname($dest))) {
		mkdir(dirname($dest), 0700, true);
	} copy($file->getPathname(), $dest);
}
mkdir($root . '/include', 0700);
file_put_contents($root . '/include/auth.php', '<?php require_once ' . var_export(__DIR__ . '/web_bootstrap.php', true) . ';');
symlink(getenv('ALARMMANAGER_CACTI_ROOT') . '/include/themes', $root . '/include/themes');
file_put_contents($root . '/include/global_session.php', '<?php');
file_put_contents($root . '/include/bottom_footer.php', '</div></body></html>');
file_put_contents($root . '/include/top_header.php', <<<'HEADER'
<?php $theme = get_selected_theme(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Alarm Manager fixture</title>
<link rel="stylesheet" href="/include/themes/<?php print $theme; ?>/main.css"><link rel="stylesheet" href="/include/themes/<?php print $theme; ?>/billboard.css"><script src="/include/js/d3.js"></script><script src="/include/js/billboard.js"></script><style>html,body{overflow:auto;height:auto}.cactiContent{padding:12px;min-width:0;box-sizing:border-box;max-width:100%}</style></head><body><div class="cactiContent">
HEADER
);
print "Fixture database and local UI root ready.\n";
