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

final class EmailHtmlTest extends AlarmManagerTestCase {
	private string $root = '';

	protected function setUp(): void {
		parent::setUp();

		if (!is_executable('/usr/bin/setsid') || !function_exists('posix_getpgid')) {
			$this->markTestSkipped('Subprocess transports need /usr/bin/setsid and the posix extension.');
		}
		self::alarmmanager_test_load('includes/transports.php');
		self::alarmmanager_test_load('includes/mapping.php');
		$this->root = sys_get_temp_dir() . '/alarmmanager_mail_' . bin2hex(random_bytes(5));
		mkdir($this->root . '/plugins/alarmmanager/includes', 0700, true);
		// A stand-in for the mail child: record what the transport hands it and report success.
		file_put_contents($this->root . '/plugins/alarmmanager/includes/mail_child.php', '<?php file_put_contents(__DIR__ . "/../../../captured.json", stream_get_contents(STDIN)); echo json_encode(["outcome" => "success", "code" => "email_sent"]);');
	}

	protected function tearDown(): void {
		foreach (['/captured.json', '/plugins/alarmmanager/includes/mail_child.php'] as $file) {
			@unlink($this->root . $file);
		}
		@rmdir($this->root . '/plugins/alarmmanager/includes');
		@rmdir($this->root . '/plugins/alarmmanager');
		@rmdir($this->root . '/plugins');
		@rmdir($this->root);
		unset($GLOBALS['alarmmanager_test_query_handler']);
		parent::tearDown();
	}

	/** @return array<string, mixed> The message the transport handed to the mail child. */
	private function send(bool $html): array {
		$before                         = $GLOBALS['config'];
		$event                          = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true));
		$event['device']['description'] = '<img src=x onerror=1>';
		$definition                     = ['to' => [['email' => 'one@example.test', 'name' => 'One']], 'from' => [['email' => 'alarm@example.test', 'name' => 'Alarm']], 'html' => $html,
			'mapping'                              => ['default' => [['to' => 'subject', 'source' => 'device.description'], ['to' => 'body', 'template' => '<b>Device</b> {{device.description}}']]], 'attachments' => []];
		$GLOBALS['alarmmanager_test_query_handler'] = static fn () => [['definition' => json_encode($definition)]];
		$GLOBALS['config']['base_path']             = $this->root;

		try {
			$result = alarmmanager_email_transport($event, ['id' => 1, 'type' => 'email', 'timeout_seconds' => 5], str_repeat('6', 64));
		} finally {
			$GLOBALS['config'] = $before;
		}
		$this->assertSame(['outcome' => 'success', 'code' => 'email_sent'], $result);

		return json_decode(file_get_contents($this->root . '/captured.json'), true);
	}

	public function testHtmlEmailEncodesEventValuesInTheBodyOnly(): void {
		$message = $this->send(true);
		$this->assertTrue($message['html']);
		$this->assertSame('<b>Device</b> &lt;img src=x onerror=1&gt;', $message['body'], 'Device text is encoded; the administrator markup around it is kept.');
		$this->assertSame('<img src=x onerror=1>', $message['subject'], 'The subject is a header, not HTML.');
	}

	public function testPlainTextEmailIsUnchanged(): void {
		$message = $this->send(false);
		$this->assertFalse($message['html']);
		$this->assertSame('<b>Device</b> <img src=x onerror=1>', $message['body']);
	}
}
