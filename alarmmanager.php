<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once(__DIR__ . '/../../include/auth.php');
require_once(__DIR__ . '/includes/web_view.php');
header('Cache-Control: no-store');
try {
 $scope = alarmmanager_web_scope();
 if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { throw new RuntimeException('permission_denied'); }
 $filters = alarmmanager_report_filters($_GET);
 if (($_GET['action'] ?? '') === 'dashboard_data') { header('Content-Type: application/json'); print json_encode(alarmmanager_dashboard_data($scope, $filters), JSON_THROW_ON_ERROR); exit; }
 $tab = $_GET['tab'] ?? 'dashboard';
 if (!is_string($tab) || !in_array($tab, ['dashboard', 'conditions', 'events', 'deliveries', 'attempts', 'decisions', 'processing'], true)) { throw new InvalidArgumentException('invalid_report'); }
 $data = $tab !== 'dashboard' ? alarmmanager_report_list($tab, $scope, $filters) : null;
 $detail = $tab === 'events' ? alarmmanager_event_detail($scope, $filters) : null;
} catch (Throwable $error) {
 http_response_code($error instanceof InvalidArgumentException ? 400 : ($error instanceof \AlarmManager\StorageException ? 503 : 403));
 if (($_GET['action'] ?? '') === 'dashboard_data') { header('Content-Type: application/json'); print '{"error":"Report unavailable or permission denied."}'; }
 else { print alarmmanager_label('Report unavailable or permission denied.'); }
 exit;
}
top_header(); alarmmanager_web_assets(); alarmmanager_web_open($scope, $tab); alarmmanager_web_filters($filters, $tab, $tab === 'dashboard');
if ($tab === 'dashboard') { alarmmanager_dashboard_markup(); } else { if ($detail !== null) { alarmmanager_event_detail_markup($detail); } alarmmanager_report_table($tab, $data, $scope, $filters); }
print '</main>'; bottom_footer();
