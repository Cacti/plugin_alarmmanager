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
	$ready            = PHP_VERSION_ID >= 80200 && extension_loaded('openssl') && alarmmanager_key_ready() && plugin_alarmmanager_upgrade();
	static $scheduled = false;

	if ($ready && !$scheduled && alarmmanager_local_poller() === 1) {
		$scheduled = true;
		// Cacti 1.2 enables hooks before saving status; replicate after the enable request finishes.
		register_shutdown_function(static function (): void {
			try {
				if (alarmmanager_runtime_enabled()) {
					api_plugin_replicate_config();
				}
			} catch (Throwable $error) {
				alarmmanager_log('plugin activation replication failed; retry Cacti synchronization from main');
			}
		});
	}

	return $ready;
}

function plugin_alarmmanager_upgrade(): bool {
	$version = plugin_alarmmanager_version()['version'];
	$stored  = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false);

	if (is_string($stored) && version_compare($stored, $version, '>=') && alarmmanager_schema_ready()) {
		if (!alarmmanager_key_ready()) {
			return false;
		}
		// Pulling new files at the same version must not leave the admin hook missing.
		$registered = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hooks WHERE name = ? AND hook = ? AND function = ? AND file = ?',
			['alarmmanager', 'admin_alert', 'alarmmanager_admin_alert', 'includes/admin_alert.php'], '', false);

		return $registered === 1 || alarmmanager_registration_setup((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', ['alarmmanager'], '', false) === 1);
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
	// Cacti registration helpers return void: register everything, then verify
	// the persisted metadata once instead of after every individual call.
	foreach ([['config_arrays', 'alarmmanager_config_arrays', 'setup.php'], ['draw_navigation_text', 'alarmmanager_draw_navigation_text', 'setup.php'], ['alarmmanager_submit', 'alarmmanager_submit', 'includes/ingestion.php'], ['admin_alert', 'alarmmanager_admin_alert', 'includes/admin_alert.php'], ['poller_bottom', 'alarmmanager_poller_bottom', 'includes/polling.php'], ['replicate_out', 'alarmmanager_replicate_out', 'includes/replication.php'], ['top_header_tabs', 'alarmmanager_show_tab', 'setup.php'], ['top_graph_header_tabs', 'alarmmanager_show_tab', 'setup.php']] as [$hook, $callback, $file]) {
		api_plugin_register_hook('alarmmanager', $hook, $callback, $file, $enable);
	}

	api_plugin_register_realm('alarmmanager', 'alarmmanager.php', __('Alarm Manager View', 'alarmmanager'), 1);
	api_plugin_register_realm('alarmmanager', 'alarmmanager_admin.php', __('Alarm Manager Administration', 'alarmmanager'), 1);

	$hooks  = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hooks WHERE name = ?', ['alarmmanager'], '', false);
	$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', ['alarmmanager'], '', false);

	return $hooks === 8 && $realms === 2;
}

function plugin_alarmmanager_uninstall(): void {
	// Keep installation key and, in later phases, alarm history for recovery.
	// Cacti removes framework hook/realm registrations itself.
}

/**
 * Shared labels for Cacti's console menu and the administration page heading.
 * @return array<string, string>
 */
function alarmmanager_admin_sections(): array {
	return ['endpoints' => 'Alarm Destinations', 'rules' => 'Alarm Rules', 'credentials' => 'Credentials', 'configuration' => 'Configuration', 'queue' => 'Alarm Queue', 'health' => 'Workers and failed processing'];
}

function alarmmanager_config_arrays(): void {
	global $menu, $menu_glyphs;

	// Cacti core deliberately never disables the config_arrays hook itself
	// (so a disabled plugin can still reach its settings); gate the menu
	// here instead, or it stays visible for a plugin that can't run.
	if (!api_plugin_is_enabled('alarmmanager')) {
		return;
	}

	// Like the syslog plugin's Syslog Settings group, administration lives in
	// its own console group instead of under the view page in Management.
	$extended = [];

	foreach ($menu as $header => $items) {
		$extended[$header] = $items;

		if ($header === __('Import/Export')) {
			foreach (alarmmanager_admin_sections() as $section => $label) {
				$extended[__('Alarm Manager Settings', 'alarmmanager')]['plugins/alarmmanager/alarmmanager_admin.php?action=' . $section . '&section=' . $section] = __($label, 'alarmmanager');
			}
		}
	}

	$menu                                                        = $extended;
	$menu_glyphs[__('Alarm Manager Settings', 'alarmmanager')]   = 'fa fa-bell';
}

function alarmmanager_draw_navigation_text($navigation): array {
	global $config;

	$navigation['alarmmanager_admin.php:'] = ['title' => __('Alarm Manager Settings', 'alarmmanager'), 'mapping' => 'index.php:', 'url' => 'alarmmanager_admin.php', 'level' => '1'];
	$navigation['alarmmanager.php:']       = [
		'title'   => __('Alarm Manager', 'alarmmanager'),
		'mapping' => '',
		'url'     => $config['url_path'] . 'plugins/alarmmanager/alarmmanager.php',
		'level'   => '1'
	];

	return $navigation;
}

function alarmmanager_show_tab(): void {
	global $config;

	if (!api_user_realm_auth('alarmmanager.php')) {
		return;
	}
	$selected = get_current_page()   === 'alarmmanager.php';
	$image    = get_selected_theme() === 'classic' ? get_classic_tabimage(__('Alarm Manager', 'alarmmanager'), $selected) : '#';
	print '<a id="alarmmanager" href="' . html_escape($config['url_path'] . 'plugins/alarmmanager/alarmmanager.php') . '"><img src="' . html_escape($image) . '" alt="' . __esc('Alarm Manager', 'alarmmanager') . '"></a>';
}
