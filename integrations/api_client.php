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
// Copied into producers. No Alarm Manager include, browser session, or shared secret table is required.
if (!class_exists('AlarmManagerApiClient', false)) {
	final class AlarmManagerApiClient {
		/**
		 * @return array<string, mixed>|null
		 */
		public static function configuration(string $source): ?array {
			if (!preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,31}\z/D', $source)) {
				throw new InvalidArgumentException('invalid_producer');
			}
			$directory = getenv('ALARMMANAGER_PRODUCER_API_DIR') ?: '/etc/cacti/alarmmanager-producers';
			$file      = $directory . '/' . $source . '.json';

			if (!is_file($file)) {
				return null;
			}

			if ((fileperms($file) & 0007) !== 0) {
				throw new RuntimeException('api_configuration_permissions');
			}
			$raw = file_get_contents($file, false, null, 0, 65537);

			if ($raw === false || strlen($raw) > 65536) {
				throw new RuntimeException('api_configuration_unavailable');
			}

			return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
		}
		/**
		 * @param  array<string, mixed> $settings
		 * @return array<string, mixed>
		 */
		public static function validate(#[\SensitiveParameter] array $settings): array {
			if (array_diff(array_keys($settings), ['url', 'token', 'ca_bundle', 'mode', 'profile_id', 'channels', 'complete_history'])) {
				throw new InvalidArgumentException('invalid_api_configuration');
			}
			$url   = $settings['url'] ?? '';
			$token = $settings['token'] ?? '';
			$parts = is_string($url) ? parse_url($url) : false;

			if (!is_array($parts) || strlen($url) > 2048 || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
				|| !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
				|| ($parts['scheme'] === 'http' && !in_array(strtolower($parts['host']), ['127.0.0.1', '[::1]', 'localhost'], true))
				|| !is_string($token) || !preg_match('/\Aamapi_[a-f0-9]{64}\z/D', $token)
				|| !in_array($settings['mode'] ?? null, ['observe', 'centralized'], true)) {
				throw new InvalidArgumentException('invalid_api_configuration');
			}
			$ca = $settings['ca_bundle'] ?? null;

			if ($ca !== null && (!is_string($ca) || !str_starts_with($ca, '/') || !is_file($ca) || !is_readable($ca))) {
				throw new InvalidArgumentException('invalid_api_configuration');
			}

			return $settings + ['ca_bundle' => null];
		}
		/**
		 * @param  array<string, mixed> $envelope
		 * @return array<string, mixed>
		 */
		public static function submit(array $envelope): array {
			$settings = self::configuration($envelope['event']['source']);

			if ($settings === null || !extension_loaded('curl')) {
				throw new RuntimeException('api_submission_pending');
			}
			global $config;

			if (!empty($config['DEBUG_SQL_FLOW'])) {
				throw new RuntimeException('api_submission_pending');
			}
			$body = json_encode(['contract_version' => 1, 'mode' => $envelope['mode'], 'event' => $envelope['event']], JSON_THROW_ON_ERROR);

			if (strlen($body) > 32768) {
				throw new RuntimeException('api_submission_pending');
			}
			$curl     = curl_init($settings['url']);
			$response = '';

			try {
				$options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['token']],
					CURLOPT_FOLLOWLOCATION  => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
						if (strlen($response) + strlen($chunk) > 65536) {
							return 0;
						}
						$response .= $chunk;

						return strlen($chunk);
					}];

				if ($settings['ca_bundle'] !== null) {
					$options[CURLOPT_CAINFO] = $settings['ca_bundle'];
				}
				curl_setopt_array($curl, $options);
				$ok     = curl_exec($curl);
				$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

				if ($ok === false || !in_array($status, [200, 202], true)) {
					throw new RuntimeException('api_submission_pending');
				}
				$ack = json_decode($response, true, 8, JSON_THROW_ON_ERROR);

				if (!is_array($ack) || !in_array($ack['status'] ?? '', ['accepted', 'already_accepted'], true)
					|| ($ack['event_id'] ?? null) !== $envelope['event']['event_id'] || ($ack['mode'] ?? null) !== $envelope['mode']
					|| ($ack['owned'] ?? null) !== ($envelope['mode'] === 'centralized') || !is_int($ack['event_row_id'] ?? null) || $ack['event_row_id'] < 1) {
					throw new RuntimeException('api_submission_pending');
				}

				return $ack;
			} finally {
				curl_close($curl);
			}
		}
	}
}
