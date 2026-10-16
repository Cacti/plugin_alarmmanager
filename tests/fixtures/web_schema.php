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
if (PHP_SAPI !== 'cli' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') {
	http_response_code(403);
	exit;
}
function alarmmanager_test_web_schema(PDO $db): void {
	$db->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB');
	$db->exec('CREATE TABLE settings_user (user_id INT, name VARCHAR(255), value TEXT, PRIMARY KEY(user_id, name)) ENGINE=InnoDB');
	$db->exec('CREATE TABLE plugin_config (id INT AUTO_INCREMENT UNIQUE, directory VARCHAR(64) PRIMARY KEY, version VARCHAR(32), status INT) ENGINE=InnoDB');
	$db->exec("INSERT INTO plugin_config (directory, version, status) VALUES ('alarmmanager', '0.5.0', 1)");
	$db->exec('CREATE TABLE user_auth (id INT PRIMARY KEY, username VARCHAR(64), enabled VARCHAR(4), reset_perms INT DEFAULT 0, policy_hosts INT DEFAULT 2, policy_graphs INT DEFAULT 2, policy_graph_templates INT DEFAULT 2, policy_trees INT DEFAULT 2) ENGINE=InnoDB');
	$db->exec("INSERT INTO user_auth (id, username, enabled) VALUES (1, 'admin', 'on'), (2, 'reader', 'on'), (3, 'denied', 'on')");
	$db->exec('CREATE TABLE user_auth_realm (user_id INT, realm_id INT, PRIMARY KEY(user_id,realm_id)) ENGINE=InnoDB');
	$db->exec('CREATE TABLE user_auth_group (id INT PRIMARY KEY, name VARCHAR(64), enabled VARCHAR(4), policy_hosts INT DEFAULT 2, policy_graphs INT DEFAULT 2, policy_graph_templates INT DEFAULT 2, policy_trees INT DEFAULT 2) ENGINE=InnoDB');
	$db->exec('CREATE TABLE user_auth_group_members (user_id INT, group_id INT, PRIMARY KEY(user_id,group_id)) ENGINE=InnoDB');
	$db->exec('CREATE TABLE user_auth_group_realm (group_id INT, realm_id INT, PRIMARY KEY(group_id,realm_id)) ENGINE=InnoDB');
	$db->exec('CREATE TABLE user_auth_perms (user_id INT, type INT, item_id INT, PRIMARY KEY(user_id,type,item_id)) ENGINE=InnoDB');
	$db->exec('CREATE TABLE user_auth_group_perms (group_id INT, type INT, item_id INT, PRIMARY KEY(group_id,type,item_id)) ENGINE=InnoDB');
	$db->exec("CREATE TABLE host (id INT PRIMARY KEY, hostname VARCHAR(128), description VARCHAR(128), disabled VARCHAR(4) DEFAULT '', deleted VARCHAR(4) DEFAULT '', host_template_id INT DEFAULT 1) ENGINE=InnoDB");
	$db->exec("INSERT INTO host (id,hostname,description) VALUES (42, 'visible.example', 'Visible'), (43, 'hidden.example', 'Hidden')");
	$db->exec('CREATE TABLE poller (id INT PRIMARY KEY, name VARCHAR(128), requires_sync VARCHAR(3) DEFAULT \'\', disabled VARCHAR(2) DEFAULT \'\') ENGINE=InnoDB');
	$db->exec("INSERT INTO poller VALUES (1, 'Main collector'), (2, 'Remote collector')");
	$db->exec('CREATE TABLE host_template (id INT PRIMARY KEY) ENGINE=InnoDB');
	$db->exec('INSERT INTO host_template VALUES (1)');
	$db->exec('CREATE TABLE graph_local (id INT PRIMARY KEY, host_id INT, graph_template_id INT) ENGINE=InnoDB');
	$db->exec('CREATE TABLE graph_templates (id INT PRIMARY KEY) ENGINE=InnoDB');
	$db->exec('INSERT INTO user_auth_perms VALUES (2,3,42)');
}
function alarmmanager_test_web_grants(PDO $db): void {
	$db->exec('INSERT INTO user_auth_realm SELECT 1, id + 100 FROM plugin_realms');
	$db->exec('INSERT INTO user_auth_realm VALUES (1,1)');
	$db->exec("INSERT INTO user_auth_realm SELECT 2, id + 100 FROM plugin_realms WHERE file='alarmmanager.php'");
}
