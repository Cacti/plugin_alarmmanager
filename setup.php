<?php
// SPDX-License-Identifier: GPL-2.0-or-later

require_once(__DIR__ . '/includes/credentials.php');
require_once(__DIR__ . '/includes/schema.php');

function plugin_alarmmanager_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);
	return is_array($info) && isset($info['info']) ? $info['info'] : [];
}

function plugin_alarmmanager_install(): bool {
	if (PHP_VERSION_ID < 80200 || !extension_loaded('openssl')) {
		return false;
	}

	alarmmanager_setup_key();
	if (!alarmmanager_schema_install()) {
		return false;
	}
	$menu = api_plugin_register_hook('alarmmanager', 'config_arrays', 'alarmmanager_config_arrays', 'setup.php');
	$navigation = api_plugin_register_hook('alarmmanager', 'draw_navigation_text', 'alarmmanager_draw_navigation_text', 'setup.php');
	$realm = api_plugin_register_realm('alarmmanager', 'alarmmanager.php', __('Alarm Manager View', 'alarmmanager'), 1);
	$submission = api_plugin_register_hook('alarmmanager', 'alarmmanager_submit', 'alarmmanager_submit', 'includes/ingestion.php');
	return $menu && $navigation && $realm && $submission;
}

function plugin_alarmmanager_check_config(): bool {
	return PHP_VERSION_ID >= 80200 && extension_loaded('openssl') && alarmmanager_key_ready() && plugin_alarmmanager_upgrade();
}

function plugin_alarmmanager_upgrade(): bool {
	$version = plugin_alarmmanager_version()['version'];
	$stored = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false);
	if (is_string($stored) && version_compare($stored, $version, '>=')) {
		return alarmmanager_key_ready() && alarmmanager_schema_ready();
	}
	if (!alarmmanager_key_ready() || !alarmmanager_schema_install()) {
		return false;
	}
	if (!api_plugin_register_hook('alarmmanager', 'alarmmanager_submit', 'alarmmanager_submit', 'includes/ingestion.php', (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false) === 1)) {
		return false;
	}
	return (bool) db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$version, 'alarmmanager'], false);
}

function plugin_alarmmanager_uninstall(): void {
	// Keep installation key and, in later phases, alarm history for recovery.
	// Cacti removes framework hook/realm registrations itself.
}

function alarmmanager_config_arrays(): void {
	global $menu;
	$menu[__('Management')]['plugins/alarmmanager/alarmmanager.php'] = __('Alarm Manager', 'alarmmanager');
	if (in_array(get_current_page(), ['index.php', 'plugins.php', 'alarmmanager.php'], true)) {
		plugin_alarmmanager_check_config();
	}
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
