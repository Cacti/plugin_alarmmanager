<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', ['save:', 'file:', 'backup-dir:', 'rotate', 'restore:', 'key-file:', 'replace-local-credentials', 'help']);
if (isset($options['help'])) {
	print "Usage:\n  php cli/credentials.php --save=ENDPOINT_ID --file=/secure/credentials.json\n  php cli/credentials.php --backup-dir=/secure/new-backup\n  php cli/credentials.php --rotate\n  php cli/credentials.php --restore=/secure/credentials.json --key-file=/secure/key.txt --replace-local-credentials\nUse --file=php://stdin to avoid storing plaintext input. Restore also provisions an authorized remote poller, replacing its entire local key/credential set.\n";
	exit(0);
}
function alarmmanager_private_input(string $path, int $limit): string {
	if ($path !== 'php://stdin') {
		$stat = @lstat($path);
		if (!$stat || is_link($path) || !is_file($path) || ($stat['mode'] & 0077) !== 0 || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) { throw new RuntimeException('private_file_required'); }
	}
	$data = @file_get_contents($path, false, null, 0, $limit + 1);
	if ($data === false || strlen($data) > $limit) { throw new RuntimeException('invalid_input'); }
	return $data;
}
function alarmmanager_private_write(string $path, string $data): void {
	$stream = @fopen($path, 'xb');
	if (!$stream) { throw new RuntimeException('backup_write_failed'); }
	try { if (fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !fsync($stream)) { throw new RuntimeException('backup_write_failed'); } } finally { fclose($stream); }
}
try {
	$actions = array_intersect(['save', 'backup-dir', 'rotate', 'restore'], array_keys($options));
	if (count($actions) !== 1) { throw new RuntimeException('invalid_options'); }
	$action = array_values($actions)[0];
	if ($action === 'save') {
		$id = filter_var($options['save'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
		if (!$id || !is_string($options['file'] ?? null)) { throw new RuntimeException('invalid_options'); }
		$record = json_decode(alarmmanager_private_input($options['file'], 65536), true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($record)) { throw new RuntimeException('invalid_input'); }
	} elseif ($action === 'restore') {
		if (!isset($options['replace-local-credentials']) || !is_string($options['restore']) || !is_string($options['key-file'] ?? null)) { throw new RuntimeException('invalid_options'); }
		$bundle = json_decode(alarmmanager_private_input($options['restore'], 90000000), true, 8, JSON_THROW_ON_ERROR);
		$key = trim(alarmmanager_private_input($options['key-file'], 128));
		if (!is_array($bundle)) { throw new RuntimeException('invalid_input'); }
	} elseif ($action === 'backup-dir' && (!is_string($options['backup-dir']) || !str_starts_with($options['backup-dir'], '/') || file_exists($options['backup-dir']) || is_link($options['backup-dir']))) { throw new RuntimeException('new_backup_directory_required'); }
} catch (Throwable $error) { fwrite(STDERR, "Invalid credential options or private input file (owner-only permissions required).\n"); exit(2); }
$conn_mode = 'offline';
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/credential_store.php');
try {
	if ($action === 'save') { alarmmanager_save_credential($id, $record); }
	elseif ($action === 'rotate') { alarmmanager_rotate_key(); }
	elseif ($action === 'restore') { alarmmanager_credential_restore($bundle, $key); }
	else {
		$backup = alarmmanager_credential_backup(); $previous = umask(0077);
		try {
			if (!mkdir($options['backup-dir'], 0700)) { throw new RuntimeException('backup_directory_failed'); }
			alarmmanager_private_write($options['backup-dir'] . '/credentials.json', json_encode($backup['bundle'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			alarmmanager_private_write($options['backup-dir'] . '/key.txt', $backup['key'] . "\n");
		} finally { umask($previous); }
	}
	print "Credential action completed; no secret material displayed.\n";
} catch (Throwable $error) { fwrite(STDERR, "Credential action failed; existing database changes were rolled back where transactional. Verify backup completeness before use.\n"); exit(1); }
