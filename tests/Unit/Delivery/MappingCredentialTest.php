<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once(__DIR__ . '/../../../includes/mapping.php');
require_once(__DIR__ . '/../../../includes/credential_store.php');
$checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function rejects(callable $action, string $label): void { try { $action(); } catch (Throwable $error) { check(true, $label); return; } throw new RuntimeException($label); }
$event = alarmmanager_validate_event(json_decode(file_get_contents(__DIR__ . '/../../../examples/event.json'), true));
$event['severity'] = 'critical';
$mapping = ['default' => [
 ['to' => 'hostname', 'source' => 'device.id', 'type' => 'string', 'required' => true],
 ['to' => 'alarm.level', 'source' => 'alarm.severity', 'values' => ['critical' => 5]],
 ['to' => 'defaulted', 'source' => 'context.rule_type', 'default' => 'unset'],
 ['to' => 'nested.enabled', 'constant' => 'false', 'type' => 'boolean'],
 ['to' => 'nested.count', 'constant' => '42', 'type' => 'integer'],
 ['to' => 'nested.ratio', 'constant' => '1.25', 'type' => 'number'],
 ['to' => 'escaped', 'constant' => '"\\ <script>&', 'type' => 'html'],
 ['to' => 'literal', 'constant' => '"\\ <script>&'],
 ['to' => 'list', 'source' => 'device.tags']
], 'recovered' => [['to' => 'status', 'constant' => 'resolved']]];
$out = alarmmanager_map_payload($mapping, $event);
check($out->hostname === (string) $event['device']['id'], 'API hostname mapping');
$mapping2 = ['default' => [['to' => 'host', 'source' => 'device.id']]];
check(alarmmanager_map_payload($mapping2, $event)->host === $event['device']['id'], 'API host mapping');
check($out->nested->enabled === false && $out->nested->count === 42 && $out->nested->ratio === 1.25, 'Explicit conversions');
check($out->defaulted === 'unset' && $out->alarm->level === 5, 'Defaults/severity values');
check(json_decode(json_encode($out))->literal === '"\\ <script>&' && !str_contains($out->escaped, '<script>'), 'Structural escaping');
check(is_array($out->list), 'List preserved');
$event['transition'] = 'recovered';
check(alarmmanager_map_payload($mapping, $event)->status === 'resolved', 'Recovery payload');
check(json_encode(alarmmanager_map_payload([], $event)) === '{}', 'Empty object');
foreach ([
 ['to' => 'a', 'source' => 'device.id', 'constant' => 1],
 ['to' => 'a', 'source' => 'device.password'],
 ['to' => 'a', 'source' => null],
 ['to' => 'a.0', 'source' => 'device.id'],
 ['to' => 'a', 'constant' => 1, 'type' => 'eval']
] as $bad) { rejects(fn() => alarmmanager_mapping_validate(['default' => [$bad]]), 'Invalid mapping rejected'); }
rejects(fn() => alarmmanager_mapping_validate(['default' => [['to' => 'a', 'constant' => 1], ['to' => 'a.b', 'constant' => 2]]]), 'Prefix conflict');
rejects(fn() => alarmmanager_mapping_validate(['default' => [['to' => 'a', 'constant' => 1], ['to' => 'a', 'constant' => 2]]]), 'Duplicate conflict');
rejects(fn() => alarmmanager_map_payload(['default' => [['to' => 'a', 'source' => 'context.rule_type', 'required' => true]]], $event), 'Required missing');
foreach (['integer' => '1.5', 'number' => 'INF', 'boolean' => 'truthy'] as $type => $value) { rejects(fn() => alarmmanager_mapping_convert($value, $type), 'Invalid conversion'); }
$key = random_bytes(32); $record = ['headers' => ['Authorization' => 'Bearer fixture-token']];
$encrypted = alarmmanager_encrypt_credential($record, 1, $key);
check(alarmmanager_decrypt_credential($encrypted, 1, $key) === $record, 'Authenticated roundtrip');
check(alarmmanager_encrypt_credential($record, 1, $key) !== $encrypted, 'Fresh nonce');
check(!str_contains($encrypted, 'fixture-token'), 'No plaintext stored');
rejects(fn() => alarmmanager_decrypt_credential($encrypted, 2, $key), 'Endpoint binding');
rejects(fn() => alarmmanager_decrypt_credential($encrypted, 1, random_bytes(32)), 'Wrong key');
rejects(fn() => alarmmanager_decrypt_credential($encrypted, 1, ''), 'Missing key');
foreach (['am2:' . substr($encrypted, 4), $encrypted . ' ', 'am1:!!', 'am1:' . base64_encode(str_repeat('x', 27))] as $bad) { rejects(fn() => alarmmanager_decrypt_credential($bad, 1, $key), 'Malformed ciphertext'); }
$binary = base64_decode(substr($encrypted, 4));
foreach ([0, 12, 28] as $position) { $tampered = $binary; $tampered[$position] = chr(ord($tampered[$position]) ^ 1); rejects(fn() => alarmmanager_decrypt_credential('am1:' . base64_encode($tampered), 1, $key), 'Tampering detected'); }
foreach ([['headers' => ['Authorization' => "x\r\ny"]], ['headers' => ['Host' => 'evil']], ['headers' => ['X-Test' => 'a', 'x-test' => 'b']], ['basic' => ['username' => 'a:b', 'password' => 'c']]] as $bad) { rejects(fn() => alarmmanager_encrypt_credential($bad, 1, $key), 'Invalid credentials'); }
print "PASS: $checks mapping/credential checks.\n";
