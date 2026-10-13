<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once(__DIR__ . '/../../../includes/lifecycle.php');
require_once(__DIR__ . '/../../../includes/routing.php');
$checks = 0;
function am_lifecycle_assert(bool $value): void { global $checks; if (!$value) { throw new RuntimeException('Lifecycle assertion failed.'); } $checks++; }
$state = ['episode_id' => '', 'last_sequence' => 0, 'active' => 0, 'acknowledged' => 0, 'severity' => 'info', 'summary' => ''];
$event = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR));
[$raised, $reason] = alarmmanager_transition($state, $event);
am_lifecycle_assert($reason === 'applied' && $raised['active'] === 1);
[$again, $reason] = alarmmanager_transition($raised, $event);
am_lifecycle_assert($reason === 'stale' && $again === $raised);
$event['sequence'] = 2; $event['transition'] = 'recovered';
[$recovered, $reason] = alarmmanager_transition($raised, $event);
am_lifecycle_assert($reason === 'applied' && $recovered['active'] === 0);
$event['sequence'] = 3; $event['transition'] = 'raised';
[$closed, $reason] = alarmmanager_transition($recovered, $event);
am_lifecycle_assert($reason === 'closed_episode' && $closed === $recovered);
$event['episode_id'] = 'episode-2'; $recovered['acknowledged'] = 1;
[$new, $reason] = alarmmanager_transition($recovered, $event);
am_lifecycle_assert($new['active'] === 1 && $new['acknowledged'] === 0);
$event['sequence'] = 4; $event['transition'] = 'recovered'; $event['episode_id'] = 'old-episode';
[$unchanged, $reason] = alarmmanager_transition($new, $event);
am_lifecycle_assert($reason === 'ignored_episode' && $unchanged === $new);
$event['transition'] = 'occurrence';
[$occurrence, $reason] = alarmmanager_transition($state, $event);
am_lifecycle_assert($reason === 'occurrence' && $occurrence['active'] === 0 && $occurrence['episode_id'] === '');
[$with_condition, $reason] = alarmmanager_transition($new, $event);
am_lifecycle_assert($with_condition['active'] === 1 && $with_condition['episode_id'] === 'episode-2');
$schedule = alarmmanager_validate_schedule(['timezone' => 'America/Toronto', 'days' => [1], 'start' => '22:00', 'end' => '02:00']);
am_lifecycle_assert(alarmmanager_schedule_matches($schedule, '2026-10-13T03:00:00Z'));
am_lifecycle_assert(alarmmanager_schedule_matches($schedule, '2026-10-13T05:59:00Z'));
am_lifecycle_assert(!alarmmanager_schedule_matches($schedule, '2026-10-13T06:00:00Z'));
am_lifecycle_assert(!alarmmanager_schedule_matches($schedule, '2026-10-14T03:00:00Z'));
$dst = alarmmanager_validate_schedule(['timezone' => 'America/Toronto', 'days' => [7], 'start' => '01:00', 'end' => '02:00']);
am_lifecycle_assert(alarmmanager_schedule_matches($dst, '2026-11-01T05:30:00Z'));
am_lifecycle_assert(alarmmanager_schedule_matches($dst, '2026-11-01T06:30:00Z'));
$event = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR));
$policy = alarmmanager_validate_rule_definition(['match' => ['sources' => ['sample'], 'severities' => ['major'], 'transitions' => ['raised'], 'device_ids' => [42], 'collector_ids' => [1], 'tags' => ['network']], 'schedule' => ['timezone' => 'UTC', 'days' => [2], 'start' => '01:00', 'end' => '04:00']]);
am_lifecycle_assert(alarmmanager_rule_matches($policy, $event));
$event['device']['id'] = 99; am_lifecycle_assert(!alarmmanager_rule_matches($policy, $event));
$event['device']['id'] = 42; $event['device']['tags'] = []; am_lifecycle_assert(!alarmmanager_rule_matches($policy, $event));
$config = json_decode(file_get_contents(__DIR__ . '/../../../examples/routing.json'), true, 32, JSON_THROW_ON_ERROR);
$validated = alarmmanager_configuration($config);
am_lifecycle_assert($validated['profiles'][1]['endpoints'] === [1, 2]);
foreach ([['timezone' => 'Bad/Zone', 'days' => [1], 'start' => '00:00', 'end' => '01:00'], ['timezone' => 'UTC', 'days' => [8], 'start' => '00:00', 'end' => '01:00'], ['timezone' => 'UTC', 'days' => [1], 'start' => '24:00', 'end' => '01:00']] as $invalid) {
	$failed = false; try { alarmmanager_validate_schedule($invalid); } catch (InvalidArgumentException $error) { $failed = true; }
	am_lifecycle_assert($failed);
}
foreach (['missing_endpoint', 'duplicate_id', 'invalid_type', 'invalid_bool', 'ambiguous_fields'] as $kind) {
	$bad = $config;
	if ($kind === 'missing_endpoint') { $bad['profiles'][0]['endpoints'][] = 99; }
	elseif ($kind === 'duplicate_id') { $bad['endpoints'][] = $bad['endpoints'][0]; }
	elseif ($kind === 'invalid_type') { $bad['endpoints'][0]['type'] = 'eval'; }
	elseif ($kind === 'invalid_bool') { $bad['endpoints'][0]['enabled'] = 'yes'; }
	else { $bad['rules'][0]['policy']['php'] = 'callback'; }
	$failed = false; try { alarmmanager_configuration($bad); } catch (InvalidArgumentException $error) { $failed = true; }
	am_lifecycle_assert($failed);
}
print "PASS: $checks lifecycle/routing contract checks.\n";
