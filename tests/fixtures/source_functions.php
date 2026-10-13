<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Development fixture only: extract a named function from a pinned, trusted source checkout.
function alarmmanager_test_source_function(string $file, string $name): string {
	$tokens=token_get_all(file_get_contents($file));$collect=false;$opened=false;$depth=0;$code='';
	for($i=0;$i<count($tokens);$i++){$token=$tokens[$i];if(!$collect&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(is_array($tokens[$j])&&$tokens[$j][1]===$name)$collect=true;}if($collect){$code.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}if($token==='}'){$depth--;if($opened&&$depth===0)return $code;}}}
	throw new RuntimeException('source_function_missing');
}
