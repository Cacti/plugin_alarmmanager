<?php
// SPDX-License-Identifier: GPL-2.0-or-later

// Compare executable Clover lines with the added lines in a zero-context Git diff.
if ($argc !== 3) {
	fwrite(STDERR, "Usage: php tests/bin/patch-coverage.php coverage.xml patch.diff\n");
	exit(2);
}
$coverage = simplexml_load_file($argv[1]);
$patch    = file($argv[2], FILE_IGNORE_NEW_LINES);

if ($coverage === false || $patch === false) {
	fwrite(STDERR, "Cannot read coverage report or patch.\n");
	exit(2);
}
$root       = dirname(__DIR__, 2) . '/';
$statements = [];

foreach ($coverage->xpath('//file') as $file) {
	$name = (string) $file['name'];

	if (!str_starts_with($name, $root)) {
		continue;
	}
	$name = substr($name, strlen($root));

	foreach ($file->line as $line) {
		if ((string) $line['type'] === 'stmt') {
			$statements[$name][(int) $line['num']] = (int) $line['count'];
		}
	}
}

if (!$statements) {
	fwrite(STDERR, "Coverage report contains no executable plugin lines.\n");
	exit(2);
}
$changed = [];
$name    = '';
$number  = 0;

foreach ($patch as $line) {
	if (str_starts_with($line, '+++ b/')) {
		$name = substr($line, 6);
	} elseif (preg_match('/^@@ .* \+(\d+)(?:,\d+)? @@/', $line, $match)) {
		$number = (int) $match[1];
	} elseif (str_starts_with($line, '+')) {
		$changed[$name][$number++] = true;
	} elseif ($line !== '' && ($line[0] === ' ')) {
		$number++;
	}
}
$total   = 0;
$covered = 0;

foreach ($changed as $name => $lines) {
	foreach ($lines as $number => $_) {
		if (!isset($statements[$name][$number])) {
			continue;
		}
		$total++;

		if ($statements[$name][$number] > 0) {
			$covered++;
		} else {
			fwrite(STDERR, "Uncovered: $name:$number\n");
		}
	}
}
printf("Changed executable PHP lines: %d/%d covered (%.2f%%)\n", $covered, $total, $total === 0 ? 100 : 100 * $covered / $total);
exit($covered === $total ? 0 : 1);
