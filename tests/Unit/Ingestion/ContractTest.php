<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
require_once(__DIR__ . '/../../../includes/events.php');
$sample = json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true, 32, JSON_THROW_ON_ERROR);
$checks = 0;
function alarmmanager_contract_assert(bool $ok): void {
	global $checks;
	if (!$ok) {
		throw new RuntimeException('Contract assertion failed.');
	}
	$checks++;
}
$normalized = alarmmanager_validate_event($sample);
alarmmanager_contract_assert($normalized['occurred_at'] === '2026-10-13T03:00:00.000000Z');
$reordered = $sample;
$reordered['device']['tags'] = ['lab', 'network', 'lab'];
alarmmanager_contract_assert(alarmmanager_validate_event($reordered) === $normalized);
alarmmanager_contract_assert(alarmmanager_event_time('2026-10-12T23:00:00-04:00') === $normalized['occurred_at']);
foreach (['raised', 'updated', 'reminder', 'recovered', 'occurrence'] as $transition) {
	$event = $sample;
	$event['transition'] = $transition;
	alarmmanager_contract_assert(alarmmanager_validate_event($event)['transition'] === $transition);
}
$changes = [
	['sequence', 0], ['sequence', '1'], ['collector_id', 0], ['collector_id', 2147483648],
	['source', ''], ['event_id', str_repeat('x', 129)], ['episode_id', ''],
	['summary', str_repeat('x', 513)], ['details', str_repeat('x', 16385)],
	['severity', 'unknown'], ['transition', 'down'], ['occurred_at', '2026-02-30T03:00:00Z'],
	['occurred_at', '0999-10-13T03:00:00Z'], ['occurred_at', '2026-10-13 03:00:00'], ['occurred_at', '2026-10-13T03:00:00+14:01'],
	['device', ['hostname' => 'lab', 'snmp_community' => 'secret']],
	['device', ['id' => '42']], ['device', ['tags' => array_fill(0, 33, 'tag')]],
	['context', ['routing_profile_id' => '1']], ['context', ['password' => 'secret']], ['context', ['source_suppressed' => 'yes']], ['raw_host', []]
];
foreach ($changes as [$field, $value]) {
	$event = $sample;
	$event[$field] = $value;
	$failed = false;
	try {
		alarmmanager_validate_event($event);
	} catch (InvalidArgumentException $error) {
		$failed = true;
	}
	alarmmanager_contract_assert($failed);
}
print "PASS: $checks contract checks.\n";
