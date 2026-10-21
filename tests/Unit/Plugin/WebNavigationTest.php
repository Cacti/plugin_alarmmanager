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

final class WebNavigationTest extends AlarmManagerTestCase {
	public function testNativeEndpointFieldsPreserveOnlyRelevantOptions(): void {
		require_once dirname(__DIR__, 3) . '/includes/admin.php';
		$email = alarmmanager_admin_transport_fields('email', ['url' => 'https://example.test', 'attachments' => []], ['endpoint_fields' => 'yes', 'email_to' => 'ops@example.test,admin@example.test', 'email_from' => '', 'email_html' => 'yes']);
		$this->assertSame(['attachments' => [], 'to' => 'ops@example.test,admin@example.test', 'from' => '', 'html' => true], $email);
		$this->assertSame(['ops@example.test' => 'Operations'], alarmmanager_admin_transport_fields('email', [], ['endpoint_fields' => 'yes', 'email_to' => '{"ops@example.test":"Operations"}'])['to']);
		$script = alarmmanager_admin_transport_fields('script', $email + ['retry_exit_codes' => [75]], ['endpoint_fields' => 'yes', 'script_executable' => '/opt/notify', 'script_arguments' => '["--alarm", "two words"]', 'script_compatibility' => 'syslog_ticket']);
		$this->assertSame(['retry_exit_codes' => [75], 'executable' => '/opt/notify', 'arguments' => ['--alarm', 'two words'], 'compatibility' => 'json'], $script);
		$this->assertSame(['success_codes' => [202]], alarmmanager_admin_transport_fields('restapi', $script + ['success_codes' => [202]], ['endpoint_fields' => 'yes']));
		$this->assertSame($email, alarmmanager_admin_transport_fields('email', $email, []));
		$this->expectException(JsonException::class);
		alarmmanager_admin_transport_fields('script', [], ['endpoint_fields' => 'yes', 'script_arguments' => 'not JSON']);
	}

	public function testExplicitNavigationUsesCactiRequestFilters(): void {
		$script = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/functions.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[2] . '/includes/admin_view.php';
$admin = file_get_contents($argv[2] . '/alarmmanager_admin.php');
preg_match('/^const ALARMMANAGER_SECTIONS = .*;$/m', $admin, $constant);
eval($constant[0]);
$cases = [
	[$admin, 'section', ALARMMANAGER_SECTIONS, 'endpoints'],
	[file_get_contents($argv[2] . '/includes/web_view.php'), 'tab', ALARMMANAGER_TABS, 'dashboard']
];
foreach ($cases as [$source, $name, $values, $default]) {
	// Evaluate only the selector expression so no authenticated page or database is bootstrapped.
	preg_match("/get_filter_request_var\\('$name', FILTER_CALLBACK, .*\\);/", $source, $match);
	foreach ([null, ...$values] as $value) {
		$_REQUEST = $value === null ? [] : [$name => $value];
		$_CACTI_REQUEST = [];
		$actual = eval('return ' . $match[0]);
		if ($actual !== ($value ?? $default)) {
			throw new RuntimeException("Navigation selector failed: $name");
		}
	}
}
echo 'Navigation filters passed';
PHP;
		$process = proc_open([PHP_BINARY, '-r', $script, dirname((new ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 4), dirname(__DIR__, 3)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $error);
		$this->assertSame('Navigation filters passed', $output);
	}
}
