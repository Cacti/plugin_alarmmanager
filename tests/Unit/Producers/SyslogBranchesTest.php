<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/
if (PHP_SAPI !== 'cli' || !getenv('ALARMMANAGER_SYSLOG_PATCHED_ROOT')) {
	exit(2);
}
$patched = getenv('ALARMMANAGER_SYSLOG_PATCHED_ROOT');
$checks  = 0;
$calls   = [];
$allow   = true;
$repeat  = false;
$records = [['seq' => 9, 'host' => 'router.test', 'logtime' => '2026-10-13 12:00:00', 'priority_id' => 3, 'facility_id' => 1, 'message' => 'Fixture message']];
final class AlarmManagerProducerBridge {
	public static $decision = ['owned' => false, 'channels' => []];
	public static $calls    = [];
	public static function publish($source, $resource, $template, $identity, $input): array {
		self::$calls[] = compact('source', 'resource', 'identity', 'input');

		return self::$decision;
	}
	public static function owns($decision, $channel): bool {
		return ($decision['owned'] ?? false) && in_array($channel, $decision['channels'] ?? [], true);
	}
}
function scheck($ok, $label): void {
	global $checks;

	if (!$ok) {
		throw new RuntimeException($label);
	} $checks++;
}
function read_config_option($name, $force = false) {
	return match ($name) {
		'settings_from_email' => 'fixture@example.test', 'settings_from_name' => 'Fixture', 'syslog_maxrecords' => 20, 'poller_interval' => 300, 'base_url' => 'http://example.test', default => ''
	};
}
function syslog_debug($message) {
}
function cacti_log($message, $a = null, $b = null) {
}
function __($text, ...$args) {
	if ($args) {
		array_pop($args);
	}

return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args) {
	return __($text, ...$args);
}
function html_escape($value) {
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function cacti_sizeof($data) {
	return count($data);
}
function syslog_db_fetch_assoc_prepared($sql, $params) {
	return $GLOBALS['records'];
}
function syslog_db_fetch_cell_prepared($sql, $params) {
	return $GLOBALS['repeat'] ? 1 : 0;
}
function syslog_alert_suppression_check($alert, $hostname, $at) {
	return ['allowed' => $GLOBALS['allow']];
}
function syslog_alert_suppression_record($alert, $state) {
	$GLOBALS['calls'][] = 'suppression_record';
}
function syslog_log_alert(...$args) {
	$GLOBALS['calls'][] = ['log', $args];

	return 12;
}
function syslog_sendemail(...$args) {
	$GLOBALS['calls'][] = ['email', $args];
}
function alert_setup_environment(...$args) {
	$GLOBALS['calls'][] = ['environment', $args];
}
function syslog_execute_ticket_command(...$args) {
	$GLOBALS['calls'][] = ['ticket', $args];
}
function syslog_execute_alert_command(...$args) {
	$GLOBALS['calls'][] = ['script', $args];
}
// Load the exact patched processing function; replace only its framework/network boundaries above.
function extract_function(string $path, string $name): string {
	$tokens  = token_get_all(file_get_contents($path));
	$collect = false;
	$found   = false;
	$brace   = 0;
	$code    = '';

	for ($i = 0; $i < count($tokens); $i++) {
		$t = $tokens[$i];

		if (!$collect && is_array($t) && $t[0] === T_FUNCTION) {
			$j = $i + 1;

			while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}

			if (is_array($tokens[$j]) && $tokens[$j][1] === $name) {
				$collect = true;
			}
		}

		if ($collect) {
			$code .= is_array($t) ? $t[1] : $t;

			if ($t === '{') {
				$brace++;
				$found = true;
			}

if ($t === '}') {
				$brace--;

				if ($found && $brace === 0) {
					return $code;
				}
			}
		}
	}

	throw new RuntimeException('function_not_found');
}
eval(extract_function($patched . '/includes/functions.php', 'syslog_process_alert'));
require_once($patched . '/includes/alarmmanager_adapter.php');
$fixture = sys_get_temp_dir() . '/alarmmanager_syslog_' . bin2hex(random_bytes(6));
mkdir($fixture . '/lib', 0700, true);
file_put_contents($fixture . '/lib/reports.php', '<?php');
$config        = ['base_path' => $fixture];
$severities    = [0=>'Notice', 1=>'Warning', 2=>'Critical'];
$syslog_levels = [3=>'Error'];
function run_alert(int $method, array $channels = [], bool $owned = false): array {
	$GLOBALS['calls']                     = [];
	AlarmManagerProducerBridge::$calls    = [];
	AlarmManagerProducerBridge::$decision = ['owned'=>$owned, 'channels'=>$channels];
	$alert                                = ['id'=>1, 'name'=>'Fixture alert', 'severity'=>2, 'method'=>$method, 'email'=>'ops@example.test', 'notify'=>0, 'repeat_alert'=> $GLOBALS['repeat'] ? 1 : 0, 'body'=>'', 'num'=>1, 'message'=>'Fixture', 'command'=>'configured-wrapper'];
	$count                                = syslog_process_alert($alert,'fixture',[],1,'router.test');

	return [$count, $GLOBALS['calls']];
}

try {
	foreach ([0, 1] as $method) {
		[$count,$legacy] = run_alert($method);
		scheck($count === 1,'source alert count');
		$types = array_map(fn ($c) =>is_array($c) ? $c[0] : $c,$legacy);

		foreach (['log', 'email', 'script', 'ticket', 'suppression_record'] as $type) {
			scheck(in_array($type,$types,true),'legacy ' . $type);
		}
		[$count,$central] = run_alert($method,['email', 'script', 'ticket'],true);
		$types            = array_map(fn ($c) =>is_array($c) ? $c[0] : $c,$central);
		scheck(!array_intersect(['email', 'script', 'ticket'],$types),'central skips selected channels');
		scheck(in_array('log',$types,true) && in_array('suppression_record',$types,true),'source log suppression state retained');
		scheck(AlarmManagerProducerBridge::$calls[0]['input']['transition'] === 'occurrence','syslog normalized occurrence');
		scheck(AlarmManagerProducerBridge::$calls[0]['input']['severity'] === 'critical','pinned syslog numeric severity');
		[$count,$partial] = run_alert($method,['email'],true);
		$types            = array_map(fn ($c) =>is_array($c) ? $c[0] : $c,$partial);
		scheck(in_array('script',$types,true) && in_array('ticket',$types,true) && !in_array('email',$types,true),'partial handoff');
		$GLOBALS['allow'] = false;
		run_alert($method);
		scheck(AlarmManagerProducerBridge::$calls[0]['input']['suppressed'],'source suppression metadata');
		scheck(!$GLOBALS['calls'],'suppressed no legacy side effects');
		$GLOBALS['allow']  = true;
		$GLOBALS['repeat'] = true;
		run_alert($method);
		scheck(AlarmManagerProducerBridge::$calls[0]['input']['suppression_reason'] === 'source_repeat','repeat suppression retained');
		$GLOBALS['repeat'] = false;
	}
	$reordered           = $records;
	$reordered[]         = $records[0] + [];
	$reordered[1]['seq'] = 10;
	alarmmanager_syslog_begin(['id'=>1, 'name'=>'Test', 'severity'=>2, 'method'=>0],$reordered,'router.test','body',true,false);
	$first = end(AlarmManagerProducerBridge::$calls)['identity'];
	alarmmanager_syslog_begin(['id'=>1, 'name'=>'Test', 'severity'=>2, 'method'=>0],array_reverse($reordered),'router.test','body',true,false);
	scheck(end(AlarmManagerProducerBridge::$calls)['identity'] === $first,'order-independent batch identity');
	print "PASS: $checks pinned syslog branch checks (delivery doubles).\n";
} finally {
	unlink($fixture . '/lib/reports.php');
	rmdir($fixture . '/lib');
	rmdir($fixture);
}
