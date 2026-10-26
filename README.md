# Alarm Manager

Alarm Manager is a Cacti plugin for routing alarms to notification services and scripts. It tracks alarm state, queues deliveries, and records notification results in Cacti. Source plugins such as thold and syslog submit alarms through Cacti's in-process `alarmmanager_submit` hook; Alarm Manager also captures confirmed Cacti device down and recovery states after each poller cycle.

## Features

- **Notification integrations:** REST APIs, webhooks, Cacti email, and executable scripts, with presets for Slack, Microsoft Teams Workflows, Telegram, Twilio SMS and WhatsApp, PagerDuty, and ServiceNow incidents and events.
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
   git clone https://github.com/Cacti/plugin_alarmmanager.git alarmmanager
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

### Twilio SMS

Choose the **Twilio SMS** destination preset. Replace the Account SID placeholder in the URL with your `AC...` Account SID. In **Authentication → Basic authentication**, enter an API Key SID (`SK...`) as Username and its secret as Password; Account SID/Auth Token credentials also work. Credentials use the existing encrypted storage and collector synchronization.

In the message mapping, replace **From** with your SMS-capable Twilio number and **To** with the recipient, both in E.164 format (for example, `+15551234567`). Edit **Body** for the alarm text, keeping it within 1,600 characters. Each destination sends to one recipient; use additional destinations for other recipients. Save the disabled draft, then enable it and use **Test destination**. Trial accounts require verified recipients, and sender registration and geographic permissions must allow the message.

The preset posts form-encoded fields and records Twilio's returned Message SID in delivery history. HTTP 201 confirms API acceptance; check Twilio Messaging logs for actual delivery. No delivery-status callback is installed. Long messages can incur multiple SMS segment charges. An uncertain response followed by a retry can send a duplicate SMS; the worker's idempotency header does not guarantee Twilio deduplication.

See the [Twilio Messages API](https://www.twilio.com/docs/messaging/api/message-resource) and [API key documentation](https://www.twilio.com/docs/iam/api-keys).

### Twilio WhatsApp

Choose **Twilio WhatsApp** and configure the same Account SID URL and encrypted Basic credentials as SMS. Set **From** to your approved WhatsApp sender and **To** to an opted-in recipient using `whatsapp:+15551234567` addresses. Set **MessagingServiceSid** to the `MG...` service containing that sender and **ContentSid** to your approved `HX...` template.

Use a template such as `Cacti alarm: {{1}}.` with one variable. The **Combined text** mapping (`text`) fills variable `1`; JSON escaping is automatic and whitespace is collapsed to fit WhatsApp template-variable requirements. Do not add `Body` or `ContentVariables` mappings. Recovery events fill the same variable with the recovered alarm state. This template preset supports proactive alarms outside the 24-hour customer service window.

Save the disabled draft, enable it, then use **Test destination**. As with SMS, acceptance is not delivery confirmation; check Twilio Messaging logs. See [Twilio WhatsApp template setup](https://www.twilio.com/docs/whatsapp/tutorial/send-whatsapp-notification-messages-templates).

GPL-2.0-or-later. See [LICENSE](LICENSE) and [attribution](NOTICE.md).
