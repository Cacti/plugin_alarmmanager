<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/** Called inside the disposable Cacti child. Preserve the native mailer arguments. */
function alarmmanager_email_send(array $message): array {
	if (!function_exists('send_mail')) { return ['outcome' => 'failure', 'code' => 'cacti_mailer_unavailable']; }
	$error = send_mail($message['to'], $message['from'], $message['subject'], $message['body'], $message['attachments'], ['X-AlarmManager-Idempotency-Key' => $message['idempotency']], $message['html']);
	return ['outcome' => $error === '' ? 'success' : 'retry', 'code' => $error === '' ? 'email_sent' : 'email_failed'];
}
