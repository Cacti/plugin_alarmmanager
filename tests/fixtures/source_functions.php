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
// Development fixture only: extract a named function from a pinned, trusted source checkout.
function alarmmanager_test_source_function(string $file, string $name): string {
	$tokens  = token_get_all(file_get_contents($file));
	$collect = false;
	$opened  = false;
	$depth   = 0;
	$code    = '';

	for ($i = 0; $i < count($tokens); $i++) {
		$token = $tokens[$i];

		if (!$collect && is_array($token) && $token[0] === T_FUNCTION) {
			$j = $i + 1;

			while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}

			if (is_array($tokens[$j]) && $tokens[$j][1] === $name) {
				$collect = true;
			}
		}

		if ($collect) {
			$code .= is_array($token) ? $token[1] : $token;

			if ($token === '{') {
				$opened = true;
				$depth++;
			}

			if ($token === '}') {
				$depth--;

				if ($opened && $depth === 0) {
					return $code;
				}
			}
		}
	}

	throw new RuntimeException('source_function_missing');
}
