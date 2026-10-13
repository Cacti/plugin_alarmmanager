<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/alarmmanager_bridge.php');
function alarmmanager_syslog_begin(array $alert, array $records, string $hostname, string $message, bool $send, bool $repeat_suppressed): array {
	if (!$records) { return ['owned' => false, 'channels' => [], 'status' => 'no_records']; }
	$digest = str_repeat(chr(0), 32); $max = 0; $time = '';
	foreach ($records as $record) {
		$seq = (int) ($record['seq'] ?? 0);
		if ($seq < 1) { return ['owned' => false, 'channels' => [], 'status' => 'missing_record_identity']; }
		$digest ^= hash('sha256', $seq . ':' . ($record['logtime'] ?? ''), true);
		if ($seq > $max) { $max = $seq; $time = (string) ($record['logtime'] ?? ''); }
	}
	if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?\z/D', $time)) { return ['owned' => false, 'channels' => [], 'status' => 'invalid_record_time']; }
	try { $occurred = (new DateTimeImmutable($time, new DateTimeZone(date_default_timezone_get())))->format('Y-m-d\TH:i:sP'); }
	catch (Throwable $error) { return ['owned' => false, 'channels' => [], 'status' => 'invalid_record_time']; }
	$severity = match ((int) $alert['severity']) { 0 => 'info', 1 => 'warning', 2 => 'critical', default => 'info' };
	return AlarmManagerProducerBridge::publish('syslog', (int) $alert['id'], 0, bin2hex($digest) . ':' . count($records) . ':' . $hostname,
		['transition' => 'occurrence', 'severity' => $severity, 'metric_value' => (string) $alert['severity'], 'occurred_at' => $occurred,
			'stream_suffix' => hash('sha256', $hostname), 'device' => ['hostname' => $hostname ?: (string) ($records[0]['host'] ?? ''), 'description' => '', 'tags' => []],
			'summary' => 'Syslog alert: ' . $alert['name'], 'details' => $message, 'rule_name' => (string) $alert['name'],
			'rule_type' => 'syslog-' . $alert['method'], 'suppressed' => !$send,
			'suppression_reason' => !$send ? ($repeat_suppressed ? 'source_repeat' : 'source_cooldown_or_duplicate') : '']);
}
