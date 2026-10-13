<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { exit(2); }
$reference = getenv('ALARMMANAGER_THOLD_ROOT'); $patched = getenv('ALARMMANAGER_THOLD_PATCHED_ROOT');
if (!$reference || !$patched) { fwrite(STDERR, "Set pinned reference and patched thold roots.\n"); exit(2); }
// Use upstream characterization fixtures without PHPUnit; all Cacti boundaries are recorded doubles.
require_once($reference . '/tests/Helpers/CactiStubs.php');
require_once($reference . '/tests/Helpers/ThresholdOutcome.php');
require_once($reference . '/tests/Helpers/ThresholdScenario.php');
$cacti_root = dirname($patched, 2); $cacti_version = '1.2.32';
$bootstrap = file_get_contents($reference . '/tests/bootstrap-unit.php');
$bootstrap = substr($bootstrap, strpos($bootstrap, "if (!defined('POLLER_VERBOSITY_MEDIUM'))"));
$bootstrap = str_replace('__DIR__', var_export($reference . '/tests', true), $bootstrap);
eval($bootstrap);
final class AlarmManagerProducerBridge {
	public static $decision = ['owned' => false, 'channels' => []]; public static $calls = [];
	public static function publish($source, $resource, $template, $identity, $input): array {
		self::$calls[] = compact('source', 'resource', 'template', 'identity', 'input');
		return self::$decision;
	}
	public static function owns($decision, $channel): bool { return ($decision['owned'] ?? false) && in_array($channel, $decision['channels'] ?? [], true); }
}
require_once($patched . '/includes/functions.php');
require_once($reference . '/includes/settings.php'); thold_config_insert();
$checks = 0;
function bcheck($ok, $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function scenario(array $data, array $channels = [], bool $owned = false, array $options = []): ThresholdOutcome {
	CactiStubs::reset(); AlarmManagerProducerBridge::$calls = []; AlarmManagerProducerBridge::$decision = ['owned' => $owned, 'channels' => $channels];
	$GLOBALS['config']['base_path'] = dirname(getenv('ALARMMANAGER_THOLD_PATCHED_ROOT'), 2);
	$GLOBALS['config']['library_path'] = getenv('ALARMMANAGER_THOLD_ROOT') . '/tests/Fixtures/cacti-lib';
	foreach ($options as $name => $value) { CactiStubs::$configOptions[$name] = $value; }
	$scenario = ThresholdScenario::threshold($data + ['lasttime' => 1791892800, 'thold_hi' => 90, 'thold_low' => 10, 'thold_warning_hi' => 80, 'thold_warning_low' => 20])->alertRecipient('ops@example.test');
	if ((int) ($data['thold_type'] ?? 0) === 1) { $scenario->referenceStatistics(100, 100, 100, 100); }
	return $scenario->poll();
}
$legacy = scenario(['lastread' => 95]); bcheck($legacy->mailCount() === 1, 'legacy alert mail'); bcheck($legacy->logStatuses() === [ST_NOTIFYAL], 'legacy alert log'); $legacyLogs = $legacy->logStatuses();
$central = scenario(['lastread' => 95], ['email'], true); bcheck($central->mailCount() === 0, 'central handoff mail skipped'); bcheck($central->logStatuses() === $legacyLogs, 'central source log preserved'); bcheck(count(AlarmManagerProducerBridge::$calls) === 1, 'one submission per branch');
$input = AlarmManagerProducerBridge::$calls[0]['input']; bcheck($input['transition'] === 'raised' && $input['severity'] === 'major', 'normalized alert'); bcheck(array_keys($input['device']) === ['hostname', 'description', 'tags', 'id'], 'safe device allowlist');
$unselected = scenario(['lastread' => 95], ['script'], true); bcheck($unselected->mailCount() === 1, 'unselected mail retained');
$warn = scenario(['lastread' => 85], ['email'], true); bcheck($warn->mailCount() === 0, 'warning handoff'); bcheck(AlarmManagerProducerBridge::$calls[0]['input']['severity'] === 'warning', 'warning severity');
$recover = scenario(['lastread' => 50, 'thold_alert' => 1, 'thold_fail_count' => 2], ['email'], true); bcheck($recover->mailCount() === 0, 'recovery handoff'); bcheck(AlarmManagerProducerBridge::$calls[0]['input']['transition'] === 'recovered', 'recovery normalized'); bcheck(in_array(ST_NOTIFYRS, $recover->logStatuses(), true), 'recovery log retained');
$repeat = scenario(['lastread' => 95, 'thold_fail_count' => 1, 'repeat_alert' => 2], ['email'], true); bcheck(AlarmManagerProducerBridge::$calls[0]['input']['repeat'] === true, 'repeat retained');
$ack = scenario(['lastread' => 95, 'acknowledgment' => 'on'], ['email'], true); bcheck($ack->mailCount() === 0, 'source acknowledgment retained'); bcheck(AlarmManagerProducerBridge::$calls[0]['input']['suppressed'], 'ack metadata');
$sample = scenario(['lastread' => 95, 'lasttime' => 0], ['email'], true); bcheck($sample->mailCount() === 1 && !AlarmManagerProducerBridge::$calls, 'missing sample identity legacy fallback');
$disabledRecovery = scenario(['lastread' => 50, 'thold_alert' => 1, 'thold_fail_count' => 2, 'restored_alert' => 'on'], ['email'], true);
bcheck($disabledRecovery->mailCount() === 0, 'disabled recovery remains silent'); bcheck(AlarmManagerProducerBridge::$calls[0]['input']['transition'] === 'recovered' && AlarmManagerProducerBridge::$calls[0]['input']['suppressed'], 'disabled recovery closes central condition');
foreach ([['thold_type'=>1,'lastread'=>500,'bl_type'=>0,'bl_pct_up'=>10,'bl_pct_down'=>10,'bl_ref_time_range'=>3600,'bl_fail_trigger'=>1], ['thold_type'=>1,'lastread'=>100,'bl_alert'=>STAT_HI,'bl_fail_count'=>3,'bl_type'=>0,'bl_pct_up'=>10,'bl_pct_down'=>10,'bl_ref_time_range'=>3600,'bl_fail_trigger'=>1], ['thold_type'=>2,'lastread'=>95,'time_hi'=>90,'time_low'=>10,'time_fail_trigger'=>1]] as $data) {
 $before=scenario($data);$beforeCount=$before->mailCount();$beforeLogs=$before->logStatuses();$after=scenario($data,['email'],true);bcheck($beforeCount===1&&$after->mailCount()===0,'baseline/time handoff');bcheck($beforeLogs===$after->logStatuses(),'baseline/time source log parity');
}
foreach ([
 ['data'=>['lastread'=>85,'thold_alert'=>1,'thold_fail_count'=>1,'thold_warning_fail_count'=>1],'class'=>'alert2warn'],
 ['data'=>['lastread'=>50,'thold_alert'=>1,'thold_warning_fail_count'=>2],'class'=>'warn2normal'],
 ['data'=>['thold_type'=>2,'lastread'=>85,'time_hi'=>90,'time_low'=>10,'time_warning_hi'=>80,'time_warning_low'=>20,'time_warning_fail_trigger'=>1],'class'=>'warn'],
 ['data'=>['thold_type'=>2,'lastread'=>50,'thold_alert'=>1,'time_hi'=>90,'time_low'=>10,'time_warning_fail_trigger'=>0],'class'=>'warn2normal'],
 ['data'=>['thold_type'=>2,'lastread'=>50,'thold_alert'=>1,'time_hi'=>90,'time_low'=>10,'time_fail_trigger'=>0,'time_warning_fail_trigger'=>1],'class'=>'alert2normal']
] as $case) {
 scenario($case['data'],['email'],true);bcheck((AlarmManagerProducerBridge::$calls[0]['input']['rule_type']??null)===$case['class'],'warning transition/recovery branch '.$case['class']);
}
$scriptData=['lastread'=>95,'trigger_cmd_high'=>'/bin/echo fixture'];$scriptSettings=['thold_enable_scripts'=>'on','thold_notification_queue'=>'on'];
scenario($scriptData,[],false,$scriptSettings);$before=count(array_filter(CactiStubs::callsTo('db_execute_prepared'),fn($c)=>str_contains($c['sql'],'INSERT INTO notification_queue')));
scenario($scriptData,['script'],true,$scriptSettings);$after=count(array_filter(CactiStubs::callsTo('db_execute_prepared'),fn($c)=>str_contains($c['sql'],'INSERT INTO notification_queue')));
bcheck($before===$after+1,'selected source script skipped while mail queue retained');
// All patched send branches must reach handoff before any original side effects.
$text = file_get_contents($patched . '/includes/functions.php'); bcheck(substr_count($text, '$alarmmanager_delivery = alarmmanager_thold_begin(') === 11, 'all eleven source branches patched');
print "PASS: $checks pinned thold branch checks (Cacti doubles).\n";
