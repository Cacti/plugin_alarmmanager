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

### Provider setup

Selecting an integration opens its provider-specific fields and standard payload. Slack asks for a bot token, channel ID, and message. Twilio asks for account credentials, sender, recipient, and message. Telegram and PagerDuty keys use encrypted credentials rather than exported URLs or mappings. The selected provider is preserved through save/reopen, export/import, and collector synchronization.

Existing destinations remain custom integrations until explicitly converted. Changing providers asks before replacing message fields, disables the destination, and requires credentials for the new provider. Advanced message mappings remain available. Deploy matching plugin files to every delivering collector before using the new provider configuration.

### Twilio SMS

Choose the **Twilio SMS** destination preset. Enter your `AC...` **Account SID**. Choose **API Key SID and Secret** and enter the `SK...` key and secret, or choose **Account SID and Auth Token** and enter the Auth Token. The URL and Basic authentication header are generated automatically. Credentials use the existing encrypted storage and collector synchronization.

Set **Sender phone number** to your SMS-capable Twilio number and **Recipient phone number** to the recipient, both in E.164 format (for example, `+15551234567`). Edit **Message** for the alarm text, keeping it within 1,600 characters. Each destination sends to one recipient; use additional destinations for other recipients. Save the disabled draft, then enable it and use **Test destination**. Trial accounts require verified recipients, and sender registration and geographic permissions must allow the message.

The preset posts form-encoded fields and records Twilio's returned Message SID in delivery history. HTTP 201 confirms API acceptance; check Twilio Messaging logs for actual delivery. No delivery-status callback is installed. Long messages can incur multiple SMS segment charges. An uncertain response followed by a retry can send a duplicate SMS; the worker's idempotency header does not guarantee Twilio deduplication.

See the [Twilio Messages API](https://www.twilio.com/docs/messaging/api/message-resource) and [API key documentation](https://www.twilio.com/docs/iam/api-keys).

### Twilio recipients in alarm rules

In an alarm rule, select a Twilio destination and expand its destination section under **Message template**. Enter **Twilio recipients for this rule** as international numbers, one per line or separated by commas (up to 50). For example:

```text
+15551234567
+15557654321
```

The list replaces that destination's default recipient for this rule. Leave it blank to use the destination default. SMS and WhatsApp share the same number-list format; the WhatsApp prefix is applied automatically. Duplicate numbers are removed. Message customization is independent of the recipient list. If several rules match the same destination, the first matching rule by priority and ID supplies both the recipients and the message.

Each recipient has its own durable delivery record, retry status, and idempotency key. Successful recipients are not resent when a sibling fails. Removing a recipient from the matching rule cancels its queued send before transport execution. History shows the recipient alongside delivery results. An uncertain provider response can still cause a duplicate message to that recipient on retry.

Version 0.12.0 upgrades the delivery table to schema 13 without deleting history. Update all delivering collectors before using recipient lists. **Test destination** continues to use the destination default recipient; it does not evaluate a rule's recipient list.

### Twilio WhatsApp

Choose **Twilio WhatsApp** and enter the same Account SID and credential fields as SMS. Set **WhatsApp sender** to your approved WhatsApp sender and **WhatsApp recipient** to an opted-in recipient using `whatsapp:+15551234567` addresses. Set **Messaging Service SID** to the `MG...` service containing that sender and **Approved Template SID** to your approved `HX...` template.

Use a template such as `Cacti alarm: {{1}}.` with one variable. The **Combined text** mapping (`text`) fills variable `1`; JSON escaping is automatic and whitespace is collapsed to fit WhatsApp template-variable requirements. Do not add `Body` or `ContentVariables` mappings. Recovery events fill the same variable with the recovered alarm state. This template preset supports proactive alarms outside the 24-hour customer service window.

Save the disabled draft, enable it, then use **Test destination**. As with SMS, acceptance is not delivery confirmation; check Twilio Messaging logs. See [Twilio WhatsApp template setup](https://www.twilio.com/docs/whatsapp/tutorial/send-whatsapp-notification-messages-templates).

GPL-2.0-or-later. See [LICENSE](LICENSE) and [attribution](NOTICE.md).
