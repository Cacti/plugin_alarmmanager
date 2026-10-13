<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1' || !getenv('ALARMMANAGER_TEST_DSN')) { exit(2); }
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/processor.php');
require_once(__DIR__ . '/../../includes/queue.php');
require_once(__DIR__ . '/../../integrations/producer_bridge.php');
$checks = 0; $hook_mode = 'normal'; $config = ['poller_id' => 2];
function producer_assert(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function api_plugin_is_enabled($name) { return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [$name], '', false) === 1; }
function api_plugin_hook_function($name, $envelope) {
	global $hook_mode;
	if ($hook_mode === 'no_handler') { return $envelope; }
	if ($hook_mode === 'fake_accepted') { $envelope['status'] = 'accepted'; return $envelope; }
	if ($hook_mode === 'throw_before') { throw new RuntimeException('fixture'); }
	$result = alarmmanager_submit($envelope);
	if ($hook_mode === 'throw_after') { throw new RuntimeException('fixture'); }
	return $result;
}
function producer_policy(string $source, string $scope, int $id, string $mode, array $channels = ['email', 'script'], bool $history = false): void {
	$definition = AlarmManagerProducerBridge::validate(['mode' => $mode, 'profile_id' => 1, 'channels' => $channels, 'complete_history' => $history]);
	alarmmanager_db_write('INSERT INTO plugin_alarmmanager_producer_config (source, scope, resource_id, definition) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE definition = VALUES(definition)', [$source, $scope, $id, json_encode($definition)]);
}
function producer_publish(string $identity, string $transition = 'raised', bool $suppressed = false, string $source = 'thold', int $resource = 1): array {
	return AlarmManagerProducerBridge::publish($source, $resource, 10, $identity, ['transition' => $transition, 'severity' => $transition === 'recovered' ? 'clear' : 'major', 'occurred_at' => '2026-10-13T12:00:00Z', 'summary' => 'Fixture "alarm"', 'device' => ['id' => 5, 'hostname' => 'router.test'], 'suppressed' => $suppressed, 'suppression_reason' => $suppressed ? 'source_maintenance' : '']);
}
$alarmmanager_test_db = alarmmanager_test_connect(); $database = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4"); $alarmmanager_test_db->exec("USE `$database`");
try {
	$alarmmanager_test_db->exec('CREATE TABLE settings_user (user_id INT NOT NULL, name VARCHAR(255), value TEXT, PRIMARY KEY (user_id, name)) ENGINE=InnoDB');
	$alarmmanager_test_db->exec('CREATE TABLE plugin_config (directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.6.0', 1)");
	producer_assert(AlarmManagerProducerBridge::text(str_repeat('é', 300), 511) === str_repeat('é', 255), 'UTF-8 truncation retains complete characters');
	producer_assert(plugin_alarmmanager_install(), 'install'); producer_assert(plugin_alarmmanager_install(), 'repeat install');
	producer_assert((int) db_fetch_cell_prepared('SELECT version FROM plugin_alarmmanager_schema') === 6, 'schema v6');
	producer_assert(!producer_publish('legacy-default')['owned'], 'legacy default');
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === 0, 'legacy no events');
	producer_policy('thold', 'default', 0, 'observe');
	$observed = producer_publish('observe'); producer_assert(!$observed['owned'] && $observed['status'] === 'observed', 'observe retains legacy');
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE mode = ?', ['observe']) === 1, 'observation durable');
	producer_policy('thold', 'template', 10, 'centralized');
	producer_assert(AlarmManagerProducerBridge::configuration('thold', 1, 10)['mode'] === 'centralized', 'template precedence');
	producer_policy('thold', 'resource', 1, 'legacy');
	producer_assert(!producer_publish('resource-legacy')['owned'], 'resource precedence');
	producer_policy('thold', 'resource', 1, 'centralized', ['email']);
	$first = producer_publish('raised');
	producer_assert($first['owned'] && AlarmManagerProducerBridge::owns($first, 'email'), 'accepted email ownership');
	producer_assert(!AlarmManagerProducerBridge::owns($first, 'script'), 'unselected scripts retained');
	$count = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
	producer_assert(producer_publish('raised')['owned'], 'duplicate owns');
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === $count, 'duplicate no events');
	$alarmmanager_test_db->exec("UPDATE plugin_config SET status = 4");
	producer_assert(producer_publish('raised')['owned'], 'accepted ownership persists disabled');
	producer_assert(!producer_publish('new-disabled')['owned'], 'new disabled fallback');
	producer_assert(AlarmManagerProducerBridge::configuration('thold', 1, 10)['mode'] === 'centralized', 'saved mode persists');
	$alarmmanager_test_db->exec('DELETE FROM plugin_config');
	producer_assert(producer_publish('raised')['owned'], 'accepted ownership persists uninstall');
	producer_assert(!producer_publish('new-uninstalled')['owned'], 'missing fallback');
	$alarmmanager_test_db->exec("INSERT INTO plugin_config VALUES ('alarmmanager', '0.7.0', 1)");
	foreach (['no_handler', 'fake_accepted', 'throw_before'] as $hook_mode) {
		producer_assert(!producer_publish($hook_mode)['owned'], $hook_mode . ' fallback');
		producer_assert(db_fetch_cell_prepared('SELECT status FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [hash('sha256', 'thold|2|1|' . $hook_mode)]) === 'legacy', 'fallback decision persisted');
		$saved_mode = $hook_mode; $hook_mode = 'normal';
		producer_assert(!producer_publish($saved_mode)['owned'], 'failed handoff never redispatched');
	}
	$hook_mode = 'throw_after'; producer_assert(producer_publish('lost-response')['owned'], 'lost hook response recovered from receipt'); $hook_mode = 'normal';
	$alarmmanager_test_db->exec('RENAME TABLE plugin_alarmmanager_jobs TO jobs_unavailable');
	producer_assert(!producer_publish('failed-ingestion')['owned'], 'failed ingestion fallback');
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = ?', [hash('sha256', 'thold|2|1|failed-ingestion')]) === 0, 'event receipt rollback together');
	$alarmmanager_test_db->exec('RENAME TABLE jobs_unavailable TO plugin_alarmmanager_jobs');
	producer_assert(!producer_publish('failed-ingestion')['owned'], 'legacy failure receipt remains legacy');
	producer_assert(AlarmManagerProducerBridge::retry(hash('sha256', 'thold|2|1|failed-ingestion')) === 'legacy', 'recovery never reopens legacy receipt');
	$legacyEnvelope = json_decode(db_fetch_cell_prepared('SELECT envelope FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [hash('sha256', 'thold|2|1|failed-ingestion')]), true);
	producer_assert(alarmmanager_submit($legacyEnvelope)['status'] === 'failed', 'late direct handoff cannot consume legacy receipt');
	// A durable pending envelope represents an interrupted source process, before legacy fallback.
	$pending = db_fetch_assoc_prepared('SELECT * FROM plugin_alarmmanager_producer_receipts WHERE receipt = ?', [hash('sha256', 'thold|2|1|fake_accepted')])[0];
	$pendingEnvelope = json_decode($pending['envelope'], true); $pendingId = hash('sha256', 'pending-crash');
	$pendingEnvelope['producer_receipt'] = $pendingId; $pendingEnvelope['event']['event_id'] = $pendingId;
	$pendingEnvelope['event']['sequence'] += 100; $pendingEnvelope['event']['alarm_key'] = 'crash-fixture';
	alarmmanager_db_write("INSERT INTO plugin_alarmmanager_producer_receipts (receipt, source, stream_key, status, envelope, channels, created_at, updated_at) VALUES (?, 'thold', ?, 'pending', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$pendingId, $pending['stream_key'], json_encode($pendingEnvelope), $pending['channels']]);
	$hook_mode = 'no_handler'; producer_assert(AlarmManagerProducerBridge::retry($pendingId) === 'pending', 'recovery unavailable preserves pending');
	$hook_mode = 'normal'; producer_assert(AlarmManagerProducerBridge::retry($pendingId) === 'accepted', 'crash receipt retried durably');
	$countBefore = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events');
	producer_assert(AlarmManagerProducerBridge::retry($pendingId) === 'accepted', 'recovery accepted receipt remains accepted');
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events') === $countBefore, 'recovery no duplicate');

	producer_assert(!producer_publish('suppressed-disabled-history', 'raised', true)['owned'], 'suppressed history disabled');
	producer_policy('thold', 'resource', 1, 'centralized', ['email'], true);
	producer_assert(producer_publish('suppressed', 'raised', true)['owned'], 'suppressed durable publication');
	producer_assert(producer_publish('recovered', 'recovered')['owned'], 'recovery accepted');
	producer_assert(producer_publish('new-outage')['owned'], 'new outage');
	$rows = db_fetch_assoc_prepared('SELECT episode_id, transition, sequence FROM plugin_alarmmanager_events WHERE mode = ? AND source = ? AND alarm_key = ? ORDER BY sequence', ['centralized', 'thold', 'thold:1:']);
	producer_assert(count(array_unique(array_column($rows, 'sequence'))) === count($rows), 'source ordering unique');
	$recovery = array_values(array_filter($rows, fn($r) => $r['transition'] === 'recovered'))[0];
	producer_assert(end($rows)['episode_id'] !== $recovery['episode_id'], 'new episode after recovery');
	producer_policy('syslog', 'default', 0, 'centralized', ['email', 'ticket', 'script'], true);
	producer_assert(producer_publish('syslog-occurrence', 'occurrence', false, 'syslog')['owned'], 'syslog occurrence');
	$fixture = json_decode(file_get_contents(__DIR__ . '/../../examples/routing-delivery.json'), true, 24, JSON_THROW_ON_ERROR);
	foreach ($fixture['endpoints'] as &$endpoint) { unset($endpoint['transport']); } unset($endpoint);
	foreach ($fixture['rules'] as &$rule) { $rule['policy'] = []; } unset($rule);
	alarmmanager_apply_configuration($fixture);
	while ($claim = alarmmanager_claim_processing(30)) { alarmmanager_process_job($claim); }
	producer_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries d JOIN plugin_alarmmanager_events e ON e.id = d.event_row_id WHERE e.mode = 'observe'") === 0, 'observe no jobs');
	producer_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_deliveries d JOIN plugin_alarmmanager_events e ON e.id = d.event_row_id WHERE JSON_EXTRACT(e.payload, '$.context.source_suppressed') = true") === 0, 'source suppression no jobs');
	producer_assert((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_states s JOIN plugin_alarmmanager_events e ON e.id = s.last_event_id WHERE e.source = 'syslog' AND s.active = 1") === 0, 'syslog not permanently active');
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries') > 1, 'centralized fanout');
	// Competing retry processes retain one identity and one owner.
	$alarmmanager_test_db = null; $children = [];
	for ($i = 0; $i < 4; $i++) {
		$pid = pcntl_fork(); if ($pid < 0) { throw new RuntimeException('fork'); }
		if ($pid === 0) { $alarmmanager_test_db = alarmmanager_test_connect(); $alarmmanager_test_db->exec("USE `$database`"); exit(producer_publish('concurrent')['owned'] ? 0 : 1); }
		$children[] = $pid;
	}
	foreach ($children as $pid) { pcntl_waitpid($pid, $status); producer_assert(pcntl_wexitstatus($status) === 0, 'concurrent owns'); }
	$alarmmanager_test_db = alarmmanager_test_connect(); $alarmmanager_test_db->exec("USE `$database`");
	producer_assert((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_events WHERE event_id = ?', [hash('sha256', 'thold|2|1|concurrent')]) === 1, 'concurrent single identity');
	$alarmmanager_test_db->beginTransaction(); producer_assert(producer_publish('raised')['owned'], 'accepted ownership survives caller transaction'); producer_assert(!producer_publish('outer')['owned'], 'outer transaction fallback'); producer_assert($alarmmanager_test_db->inTransaction(), 'outer preserved'); $alarmmanager_test_db->rollBack();
	print "PASS: $checks real database producer checks.\n";
} finally { if (!$alarmmanager_test_db) { $alarmmanager_test_db = alarmmanager_test_connect(); } $alarmmanager_test_db->exec("DROP DATABASE IF EXISTS `$database`"); }
