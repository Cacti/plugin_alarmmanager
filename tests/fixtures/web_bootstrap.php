<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli-server' || getenv('ALARMMANAGER_TEST_WEB') !== '1' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') { http_response_code(403); exit; }
require_once(__DIR__ . '/../Integration/bootstrap.php');
$alarmmanager_test_db = alarmmanager_test_connect();
$database = trim(file_get_contents(getenv('ALARMMANAGER_WEB_ROOT') . '/database.txt'));
if (!preg_match('/\Aalarmmanager_test_[a-f0-9]{12}\z/D', $database)) { throw new RuntimeException('Invalid fixture database.'); }
$alarmmanager_test_db->exec("USE `$database`");
session_start();
require_once(__DIR__ . '/cacti_web_functions.php');
function csrf_startup(): void {
	csrf_conf('defer', true); csrf_conf('rewrite', false);
	csrf_conf('secret', file_get_contents(getenv('ALARMMANAGER_WEB_ROOT') . '/csrf-secret.txt'));
	csrf_conf('url_path', '/');
}
require_once(getenv('ALARMMANAGER_CACTI_ROOT') . '/include/vendor/csrf/csrf-magic.php');
$config['base_path'] = getenv('ALARMMANAGER_WEB_ROOT');
$no_session_write = [];
function isset_request_var($key) { return isset($_REQUEST[$key]); }
function get_nfilter_request_var($key) { return $_REQUEST[$key] ?? null; }
function db_close() {}
