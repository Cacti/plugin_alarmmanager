<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 Sean Mancini                                    |
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
 | Alarm Manager: centralized alarm history, routing and delivery for Cacti |
 +-------------------------------------------------------------------------+
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/

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

	return alarmmanager_registration_setup();
}

function plugin_alarmmanager_check_config(): bool {
	return PHP_VERSION_ID >= 80200 && extension_loaded('openssl') && alarmmanager_key_ready() && plugin_alarmmanager_upgrade();
}

function plugin_alarmmanager_upgrade(): bool {
	$version = plugin_alarmmanager_version()['version'];
	$stored  = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false);

	if (is_string($stored) && version_compare($stored, $version, '>=')) {
		return alarmmanager_key_ready() && alarmmanager_schema_ready();
	}

	if (!alarmmanager_key_ready() || !alarmmanager_schema_install()) {
		return false;
	}

	if (!alarmmanager_registration_setup((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false) === 1)) {
		return false;
	}

	return (bool) db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$version, 'alarmmanager'], false);
}

function alarmmanager_registration_setup(bool $enable = false): bool {
	// Cacti registration helpers return void on success. Verify persisted metadata.
	foreach (['config_arrays' => ['alarmmanager_config_arrays', 'setup.php'], 'draw_navigation_text' => ['alarmmanager_draw_navigation_text', 'setup.php'], 'alarmmanager_submit' => ['alarmmanager_submit', 'includes/ingestion.php']] as $hook => [$callback, $file]) {
		api_plugin_register_hook('alarmmanager', $hook, $callback, $file, $enable);
		$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hooks WHERE name = ? AND hook = ? AND `function` = ? AND file = ?', ['alarmmanager', $hook, $callback, $file], '', false);

		if ((int) $count !== 1) {
			return false;
		}
	}

	foreach (['alarmmanager.php' => 'Alarm Manager View', 'alarmmanager_admin.php' => 'Alarm Manager Administration'] as $file => $label) {
		api_plugin_register_realm('alarmmanager', $file, __($label, 'alarmmanager'), 1);

		if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ? AND file = ?', ['alarmmanager', $file], '', false) !== 1) {
			return false;
		}
	}

	return true;
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
	$navigation['alarmmanager_admin.php:'] = ['title' => __('Alarm Manager Administration', 'alarmmanager'), 'mapping' => 'index.php:,alarmmanager.php:', 'url' => 'alarmmanager_admin.php', 'level' => '2'];
	$navigation['alarmmanager.php:']       = [
		'title'   => __('Alarm Manager', 'alarmmanager'),
		'mapping' => 'index.php:',
		'url'     => 'alarmmanager.php',
		'level'   => '1'
	];

	return $navigation;
}
