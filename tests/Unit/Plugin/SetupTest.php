<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
require_once(__DIR__ . '/../../bootstrap.php');
require_once(__DIR__ . '/../../../setup.php');

$checks = 0;
function alarmmanager_test_assert(bool $result, string $message): void {
	global $checks;
	if (!$result) {
		throw new RuntimeException($message);
	}
	$checks++;
}

alarmmanager_test_assert(plugin_alarmmanager_install(), 'Installation failed.');
$key = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
alarmmanager_test_assert(strlen(alarmmanager_decode_key($key)) === 32, 'Key length must be 256 bits.');
alarmmanager_test_assert(plugin_alarmmanager_install(), 'Repeat install failed.');
alarmmanager_test_assert($GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] === $key, 'Repeat install changed the key.');
alarmmanager_test_assert(plugin_alarmmanager_upgrade(), 'Upgrade readiness failed.');
alarmmanager_test_assert($GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] === $key, 'Upgrade changed the key.');
alarmmanager_test_assert(array_diff($GLOBALS['alarmmanager_test_hooks'], ['config_arrays', 'draw_navigation_text', 'alarmmanager_submit']) === [], 'Unexpected producer/poller hook.');
plugin_alarmmanager_uninstall();
alarmmanager_test_assert($GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] === $key, 'Uninstall removed the key.');

foreach ([false, null, '', 'invalid!', base64_encode('short'), base64_encode(str_repeat('x', 33))] as $invalid) {
	$rejected = false;
	try {
		alarmmanager_decode_key($invalid);
	} catch (RuntimeException $error) {
		$rejected = true;
	}
	alarmmanager_test_assert($rejected, 'Malformed key was accepted.');
}

$GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] = 'broken';
alarmmanager_test_assert(!plugin_alarmmanager_check_config(), 'Malformed persisted key enabled plugin.');
$rejected = false;
try {
	alarmmanager_setup_key();
} catch (RuntimeException $error) {
	$rejected = true;
}
alarmmanager_test_assert($rejected && $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] === 'broken', 'Setup replaced a malformed key.');
$GLOBALS['alarmmanager_test_store'] = [];
alarmmanager_test_assert(!plugin_alarmmanager_upgrade(), 'Upgrade silently recreated a lost key.');
$GLOBALS['alarmmanager_test_write_failure'] = true;
$rejected = false;
try {
	alarmmanager_setup_key();
} catch (RuntimeException $error) {
	$rejected = true;
}
alarmmanager_test_assert($rejected && $GLOBALS['alarmmanager_test_store'] === [], 'Failed storage was treated as success.');
$GLOBALS['alarmmanager_test_write_failure'] = false;
alarmmanager_setup_key();
alarmmanager_test_assert($GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] !== $key, 'Separate installation reused a key.');
$saved_key = $GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'];
$GLOBALS['alarmmanager_test_store'] = [];
$GLOBALS['alarmmanager_test_credentials'] = 1;
$rejected = false;
try {
	alarmmanager_setup_key();
} catch (RuntimeException $error) {
	$rejected = true;
}
alarmmanager_test_assert($rejected && $GLOBALS['alarmmanager_test_store'] === [], 'Lost key was recreated over stored credentials.');
$GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] = $saved_key;
$config['DEBUG_SQL_FLOW'] = true;
alarmmanager_test_assert(!alarmmanager_key_ready(), 'SQL flow tracing may expose keys.');
$config['DEBUG_SQL_FLOW'] = false;
$saved_hooks = $GLOBALS['alarmmanager_test_hooks'];
$GLOBALS['alarmmanager_test_hooks'] = [];
$GLOBALS['alarmmanager_test_registration_failure'] = true;
alarmmanager_test_assert(!plugin_alarmmanager_install(), 'Missing hook metadata was accepted after void registration.');
alarmmanager_test_assert($GLOBALS['alarmmanager_test_store']['1:alarmmanager_key'] === $saved_key, 'Registration failure changed the key.');
$GLOBALS['alarmmanager_test_registration_failure'] = false;
$GLOBALS['alarmmanager_test_hooks'] = $saved_hooks;
$info = plugin_alarmmanager_version();
alarmmanager_test_assert($info['name'] === 'alarmmanager' && $info['compat'] === '1.2.32', 'Metadata is invalid.');
print "PASS: $checks setup checks (in-memory database double; no live Cacti/database).\n";
