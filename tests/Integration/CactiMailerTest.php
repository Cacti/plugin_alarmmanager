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

use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class CactiMailerTest extends AlarmManagerTestCase {
	/**
	 * Pinned Cacti send_mail/PHPMailer delivery through the SMTP fixture with parent deadlines.
	 */
	public function testPinnedCactiMailerDeliveryAndDeadline(): void {
		self::requireAlarmManagerTestDb();

		$cacti = getenv('ALARMMANAGER_CACTI_ROOT');

		if (!$cacti || !is_file($cacti . '/lib/functions.php')) {
			$this->markTestSkipped('Pinned Cacti source path ALARMMANAGER_CACTI_ROOT required.');
		}

		if (trim((string) @shell_exec('command -v python3')) === '') {
			$this->markTestSkipped('python3 is required for the SMTP fixture.');
		}

		if (!defined('SIGKILL')) {
			$this->markTestSkipped('The pcntl constants are required to stop the SMTP fixture.');
		}

		self::alarmmanager_test_load('includes/transports.php');
		/*
		 * The original standalone script overrode db_fetch_assoc_prepared() at file scope; the
		 * integration bootstrap owns that function in this process, so the same fixture value is
		 * served through a statement double on the global connection instead.
		 */
		$GLOBALS['alarmmanager_test_db'] = $this->alarmmanager_test_mail_database();
		$tmp                             = sys_get_temp_dir() . '/alarmmanager-cacti-mail-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		mkdir($tmp . '/include');
		mkdir($tmp . '/plugins');
		mkdir($tmp . '/plugins/alarmmanager');
		mkdir($tmp . '/plugins/alarmmanager/includes');
		copy(__DIR__ . '/../../includes/mail_child.php', $tmp . '/plugins/alarmmanager/includes/mail_child.php');
		copy(__DIR__ . '/../../includes/email_boundary.php', $tmp . '/plugins/alarmmanager/includes/email_boundary.php');
		$server = proc_open(['python3', __DIR__ . '/../fixtures/smtp_server.py', $tmp . '/message.json'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

		try {
			stream_set_timeout($pipes[1], 5);
			$port = (int) fgets($pipes[1]);
			$this->assertGreaterThan(0, $port, 'SMTP fixture ready');
			$cached       = ['settings_how' => 2, 'settings_smtp_host' => '127.0.0.1', 'settings_smtp_port' => $port, 'settings_smtp_username' => '', 'settings_smtp_password' => '', 'settings_smtp_secure' => 'none', 'settings_smtp_timeout' => 2, 'settings_from_name' => 'Alarm', 'settings_from_email' => 'alarm@example.test', 'settings_wordwrap' => 76, 'selective_debug' => '', 'selective_plugin_debug' => '', 'log_verbosity' => 0, 'log_destination' => 1, 'path_cactilog' => $tmp . '/cacti.log', 'client_timezone_support' => '', 'i18n_language_support' => 0];
			$child_config = ['base_path' => $cacti, 'include_path' => $cacti . '/include', 'is_web' => false, 'poller_id' => 2, 'config_options_array' => $cached];
			/*
			 * The mail child must survive current pinned-Cacti drift without a real database:
			 * functions.php now reads CACTI_WEB and i18n support, mailer() autoloads Symfony
			 * Mailer, and global_languages.php needs the helpers and paths those files set.
			 */
			$bootstrap    = '<?php if (($conn_mode ?? null) !== "offline") { throw new RuntimeException("main_connection_attempted"); } define("CACTI_WEB", false); $config = '
				. var_export($child_config, true) . '; $cacti_locale = "en-US"; $mail_methods = [2 => "SMTP"]; require_once '
				. var_export($cacti . '/include/global_constants.php', true) . '; if (is_file(' . var_export($cacti . '/include/global_path.php', true) . ')) { require_once ' . var_export($cacti . '/include/global_path.php', true) . '; } require_once '
				. var_export($cacti . '/lib/functions.php', true) . '; require_once ' . var_export($cacti . '/lib/html_utility.php', true) . '; require_once '
				. var_export($cacti . '/lib/database.php', true) . '; require_once ' . var_export($cacti . '/include/vendor/autoload.php', true) . '; require_once '
				. var_export($cacti . '/include/global_languages.php', true) . ';';
			file_put_contents($tmp . '/include/cli_check.php', $bootstrap);
			$GLOBALS['config']                    = ['base_path' => $tmp];
			$event                                = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true));
			$GLOBALS['mail_transport_definition'] = ['to' => [['email' => 'one@example.test', 'name' => 'One'], ['email' => 'two@example.test', 'name' => 'Two']], 'from' => [['email' => 'alarm@example.test', 'name' => 'Alarm']], 'html' => true, 'mapping' => ['default' => [['to' => 'subject', 'constant' => 'Fixture alert'], ['to' => 'body', 'constant' => '<b>Fixture body</b>']]], 'attachments' => [['attachment' => 'fixture attachment', 'filename' => 'test.txt', 'mime_type' => 'text/plain', 'inline' => 'attachment']]];
			$result                               = alarmmanager_email_transport($event, ['id' => 1, 'type' => 'email', 'timeout_seconds' => 5], str_repeat('6', 64));
			$this->assertSame(['outcome' => 'success', 'code' => 'email_sent'], $result, 'Actual pinned Cacti send_mail/PHPMailer delivery');
			$message = json_decode(file_get_contents($tmp . '/message.json'), true);
			$this->assertTrue(count($message['recipients']) === 2 && str_contains(implode(' ', $message['recipients']), 'one@example.test') && str_contains(implode(' ', $message['recipients']), 'two@example.test'), 'Actual recipients preserved');
			$this->assertTrue($message['subject'] === 'Fixture alert' && $message['idempotency'] === str_repeat('6', 64), 'Actual subject/idempotency header');
			$html        = array_values(array_filter($message['parts'], fn ($part) => $part['type'] === 'text/html'));
			$attachments = array_values(array_filter($message['parts'], fn ($part) => $part['filename'] === 'test.txt'));
			$this->assertTrue(count($html) === 1 && str_contains($html[0]['data'], '<b>Fixture body</b>'), 'Actual HTML formatting');
			$this->assertTrue(count($attachments) === 1 && $attachments[0]['data'] === 'fixture attachment', 'Actual attachment preserved');
			// A child that stalls in bootstrap/native work is terminated by the same parent deadline.
			file_put_contents($tmp . '/include/cli_check.php', '<?php sleep(10);');
			$start  = microtime(true);
			$result = alarmmanager_email_transport($event, ['id' => 1, 'type' => 'email', 'timeout_seconds' => 1], str_repeat('7', 64));
			$this->assertTrue($result['code'] === 'email_timeout' && microtime(true) - $start < 2.5, 'Fresh mail child deadline');
		} finally {
			proc_terminate($server, SIGKILL);

			foreach ($pipes as $pipe) {
				fclose($pipe);
			} proc_close($server);
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

			foreach ($iterator as $file) {
				if ($file->isDir()) {
					rmdir($file->getPathname());
				} else {
					unlink($file->getPathname());
				}
			} rmdir($tmp);
		}
	}

	/**
	 * Connection double that serves the fixture transport definition for the mail-boundary lane.
	 */
	private function alarmmanager_test_mail_database(): object {
		return new class {
			public function prepare(string $sql): object {
				return new class {
					public function execute(array $params = []): bool {
						return true;
					}

					public function fetchAll(int $mode = PDO::FETCH_ASSOC): array {
						return [['definition' => json_encode($GLOBALS['mail_transport_definition'] ?? [], JSON_THROW_ON_ERROR)]];
					}
				};
			}
		};
	}
}
