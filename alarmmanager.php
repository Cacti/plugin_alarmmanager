<?php
// SPDX-License-Identifier: GPL-2.0-or-later

require_once(__DIR__ . '/../../include/auth.php');
require_once(__DIR__ . '/setup.php');

top_header();
html_start_box(__('Alarm Manager', 'alarmmanager'), '100%', false, 3, 'center', '');
print '<tr><td>' . html_escape(__('Development build: alarm processing and worker queues are available; notification transports are not implemented yet.', 'alarmmanager')) . '</td></tr>';
html_end_box();
bottom_footer();
