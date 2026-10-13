<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$cacti = getenv('ALARMMANAGER_CACTI_ROOT');
if (!$cacti || !is_file($cacti . '/lib/functions.php')) { fwrite(STDERR, "Pinned Cacti source path ALARMMANAGER_CACTI_ROOT required.\n"); exit(2); }
require_once(__DIR__ . '/../../includes/transports.php');
$checks = 0;
function cmcheck(bool $condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function db_fetch_assoc_prepared($sql, $params = [], $log = true) { return [['definition' => json_encode($GLOBALS['mail_transport_definition'], JSON_THROW_ON_ERROR)]]; }
$tmp = sys_get_temp_dir() . '/alarmmanager-cacti-mail-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700); mkdir($tmp . '/include'); mkdir($tmp . '/plugins'); mkdir($tmp . '/plugins/alarmmanager'); mkdir($tmp . '/plugins/alarmmanager/includes');
copy(__DIR__ . '/../../includes/mail_child.php', $tmp . '/plugins/alarmmanager/includes/mail_child.php');
copy(__DIR__ . '/../../includes/email_boundary.php', $tmp . '/plugins/alarmmanager/includes/email_boundary.php');
$server = proc_open(['python3', __DIR__ . '/../fixtures/smtp_server.py', $tmp . '/message.json'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
 stream_set_timeout($pipes[1], 5); $port = (int) fgets($pipes[1]); cmcheck($port > 0, 'SMTP fixture ready');
 $cached = ['settings_how' => 2, 'settings_smtp_host' => '127.0.0.1', 'settings_smtp_port' => $port, 'settings_smtp_username' => '', 'settings_smtp_password' => '', 'settings_smtp_secure' => 'none', 'settings_smtp_timeout' => 2, 'settings_from_name' => 'Alarm', 'settings_from_email' => 'alarm@example.test', 'settings_wordwrap' => 76, 'selective_debug' => '', 'selective_plugin_debug' => '', 'log_verbosity' => 0, 'log_destination' => 1, 'path_cactilog' => $tmp . '/cacti.log', 'client_timezone_support' => ''];
 $child_config = ['base_path' => $cacti, 'include_path' => $cacti . '/include', 'is_web' => false, 'poller_id' => 2, 'config_options_array' => $cached];
 $bootstrap = '<?php if (($conn_mode ?? null) !== "offline") { throw new RuntimeException("main_connection_attempted"); } $config = ' . var_export($child_config, true) . '; $cacti_locale = "en-US"; $mail_methods = [2 => "SMTP"]; require_once ' . var_export($cacti . '/include/global_constants.php', true) . '; require_once ' . var_export($cacti . '/lib/functions.php', true) . ';';
 file_put_contents($tmp . '/include/cli_check.php', $bootstrap);
 $config = ['base_path' => $tmp];
 $event = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true));
 $GLOBALS['mail_transport_definition'] = ['to' => [['email' => 'one@example.test', 'name' => 'One'], ['email' => 'two@example.test', 'name' => 'Two']], 'from' => [['email' => 'alarm@example.test', 'name' => 'Alarm']], 'html' => true, 'mapping' => ['default' => [['to' => 'subject', 'constant' => 'Fixture alert'], ['to' => 'body', 'constant' => '<b>Fixture body</b>']]], 'attachments' => [['attachment' => 'fixture attachment', 'filename' => 'test.txt', 'mime_type' => 'text/plain', 'inline' => 'attachment']]];
 $result = alarmmanager_email_transport($event, ['id' => 1, 'type' => 'email', 'timeout_seconds' => 5], str_repeat('6', 64));
 cmcheck($result === ['outcome' => 'success', 'code' => 'email_sent'], 'Actual pinned Cacti send_mail/PHPMailer delivery');
 $message = json_decode(file_get_contents($tmp . '/message.json'), true);
 cmcheck(count($message['recipients']) === 2 && str_contains(implode(' ', $message['recipients']), 'one@example.test') && str_contains(implode(' ', $message['recipients']), 'two@example.test'), 'Actual recipients preserved');
 cmcheck($message['subject'] === 'Fixture alert' && $message['idempotency'] === str_repeat('6', 64), 'Actual subject/idempotency header');
 $html = array_values(array_filter($message['parts'], fn($part) => $part['type'] === 'text/html'));
 $attachments = array_values(array_filter($message['parts'], fn($part) => $part['filename'] === 'test.txt'));
 cmcheck(count($html) === 1 && str_contains($html[0]['data'], '<b>Fixture body</b>'), 'Actual HTML formatting');
 cmcheck(count($attachments) === 1 && $attachments[0]['data'] === 'fixture attachment', 'Actual attachment preserved');
 // A child that stalls in bootstrap/native work is terminated by the same parent deadline.
 file_put_contents($tmp . '/include/cli_check.php', '<?php sleep(10);');
 $start = microtime(true); $result = alarmmanager_email_transport($event, ['id' => 1, 'type' => 'email', 'timeout_seconds' => 1], str_repeat('7', 64));
 cmcheck($result['code'] === 'email_timeout' && microtime(true) - $start < 2.5, 'Fresh mail child deadline');
 print "PASS: $checks pinned Cacti mailer/SMTP/deadline checks (fixture bootstrap; no full installation).\n";
} finally {
 proc_terminate($server, SIGKILL); foreach ($pipes as $pipe) { fclose($pipe); } proc_close($server);
 $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
 foreach ($iterator as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } } rmdir($tmp);
}
