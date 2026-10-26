# Alarm Manager

Alarm Manager is a Cacti plugin for routing alarms to notification services and scripts. It tracks alarm state, queues deliveries, and records notification results in Cacti. Source plugins such as thold and syslog submit alarms through Cacti's in-process `alarmmanager_submit` hook; Alarm Manager also captures confirmed Cacti device down and recovery states after each poller cycle.

## Features

- **Notification integrations:** REST APIs, webhooks, Cacti email, and executable scripts, with presets for Slack, Microsoft Teams Workflows, Telegram, PagerDuty, and ServiceNow incidents and events.
- **Alarm rules:** Route alarms to multiple destinations using device, collector, source, severity, and other alarm filters. Notification hours, maintenance windows, acknowledgments, cooldowns, and duplicate controls help manage repeated notifications.
- **Custom messages:** Combine text with alarm and device fields, configure messages for different alarm states, and override destination templates for individual rules.
- **Queued delivery:** Independent destination queues with retries, ordered alarm processing, and configurable processing and delivery workers.
- **Dashboard and history:** View active conditions, event history, routing decisions, delivery attempts, external ticket references, and worker status. Search with filters and grouped conditions, and replay failed jobs.
- **Queue controls:** Inspect waiting messages, pause and resume delivery, or clear waiting deliveries with confirmation. Already claimed deliveries may finish after pausing.
- **Distributed collectors:** Each collector processes and delivers its own alarms. Configuration is managed on the main poller and distributed through Cacti's native replication; remote history returns to the main poller while local delivery continues during a main-poller outage.
- **Administration:** Encrypted credentials, configuration import/export, message previews, explicit test sends, and permission-scoped access.

## Installation

### Requirements

- Cacti 1.2.x (plugin minimum: 1.2.29).
- PHP 8.2 or later with OpenSSL; CLI workers also require `pcntl` and `posix`.
- MariaDB 10.11 or later with InnoDB. MySQL is not supported.
- PHP cURL for HTTP integrations.
- Linux with `/usr/bin/setsid` for email and script delivery.

### Install and enable

1. Clone the repository into your Cacti installation, using `alarmmanager` as the plugin directory name:

   ```sh
   cd <cacti-root>/plugins
   git clone https://github.com/bmfmancini/plugin_alarmmanager.git alarmmanager
   ```

2. Disable Cacti's `DEBUG_SQL_FLOW` tracing before installation or enablement, as it can expose encryption-key material.
3. Install and enable **Alarm Manager** through Cacti's **Plugin Management** page. Installation creates the database schema and a plugin-specific encryption key.
4. Grant **Alarm Manager View** to readers. Administrators also need **Alarm Manager Administration** and Cacti realm 1.
5. Securely back up the `alarmmanager_key` record in Cacti's `settings_user` table (owner 1). Preserve the original key: encrypted credentials require it, and enablement or upgrades fail if it is missing or malformed.

### Configure notifications

1. Open **Administration → Alarm Manager → Endpoints** and choose a provider preset or a generic integration. Presets start as disabled drafts.
2. Replace placeholder values, configure authentication and message fields, and use the preview and test controls to check the destination before enabling it.
3. Open **Alarm Rules → Create a rule**, select one or more destinations, configure the filters you need, and enable the rule. **Notifications require an enabled, matching rule; an enabled endpoint alone does not send alarms.**
4. Review **Events** for processing and delivery results, and **Alarm Queue** for waiting messages and pause controls.

For custom source-plugin integrations, see the [sample event](examples/event.json), [examples](examples/), and [Cacti admin-alert hook guide](docs/cacti-admin-alert-hook.md).

### Workers and remote collectors

By default, Alarm Manager processes bounded batches at the end of each Cacti poller cycle; no separate cron job is needed. For resident workers, install the supplied [systemd service](service/alarmmanager.service), adjusting its user, group, Cacti path, and PHP executable for your installation. Configure worker enablement and pool sizes under **Administration → Configuration**.

For distributed installations, deploy the plugin files on the main poller and collectors, and enable the plugin through the main poller's Plugin Management page. Manage destinations, rules, credentials, worker settings, and delivery pause/resume on poller 1. Cacti synchronizes the configuration to remote collectors. Install the systemd service on each machine that will run resident workers, and ensure configured script executables and custom CA files exist at the same paths on the relevant collectors.

GPL-2.0-or-later. See [LICENSE](LICENSE) and [attribution](NOTICE.md).
