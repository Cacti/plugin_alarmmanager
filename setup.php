<?php
// SPDX-License-Identifier: GPL-2.0-or-later

require_once(__DIR__ . '/includes/credentials.php');

function plugin_alarmmanager_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);
	return is_array($info) && isset($info['info']) ? $info['info'] : [];
}

function plugin_alarmmanager_install(): bool {
	if (PHP_VERSION_ID < 80200 || !extension_loaded('openssl')) {
		return false;
	}

	alarmmanager_setup_key();
	$menu = api_plugin_register_hook('alarmmanager', 'config_arrays', 'alarmmanager_config_arrays', 'setup.php');
	$navigation = api_plugin_register_hook('alarmmanager', 'draw_navigation_text', 'alarmmanager_draw_navigation_text', 'setup.php');
	$realm = api_plugin_register_realm('alarmmanager', 'alarmmanager.php', __('Alarm Manager View', 'alarmmanager'), 1);
	return $menu && $navigation && $realm;
}

function plugin_alarmmanager_check_config(): bool {
	return PHP_VERSION_ID >= 80200 && extension_loaded('openssl') && alarmmanager_key_ready();
}

function plugin_alarmmanager_upgrade(): bool {
	// No schema exists yet. Never repair a missing key by silently replacing it.
	return plugin_alarmmanager_check_config();
}

function plugin_alarmmanager_uninstall(): void {
	// Keep installation key and, in later phases, alarm history for recovery.
	// Cacti removes framework hook/realm registrations itself.
}

function alarmmanager_config_arrays(): void {
	global $menu;
	$menu[__('Management')]['plugins/alarmmanager/alarmmanager.php'] = __('Alarm Manager', 'alarmmanager');
}

function alarmmanager_draw_navigation_text($navigation): array {
	$navigation['alarmmanager.php:'] = [
		'title' => __('Alarm Manager', 'alarmmanager'),
		'mapping' => 'index.php:',
		'url' => 'alarmmanager.php',
		'level' => '1'
	];
	return $navigation;
}
