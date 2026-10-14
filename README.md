# Alarm Manager

Cacti plugin under development for reusable webhook/REST and script notification integrations, with alarm lifecycle/history, routing to multiple destinations and independent remote-poller processing. Detection and native email remain in Cacti, thold and syslog.

**Project direction (2026-10-14):** provide functionality inspired by Zabbix media types, custom alert scripts and webhooks: configurable integrations, transition-specific payloads, preview/test sends and independent queued delivery. Email is outside the target scope because Cacti already supports it. The integration workflow is implemented locally; the current build retains the existing email adapter and email handoff logic. Email removal is deferred.

**Current state:** development build 0.10.0. Reusable integration templates and validated per-episode external ticket/message references are implemented. Durable ingestion feeds ordered episode/state processing, routing and independent destination queues. Bounded workers deliver through REST API, webhook, Cacti email and controlled scripts. Endpoint-specific mappings and authenticated encrypted credentials support setup-key backup/rotation and explicit local-poller provisioning. Cacti pages provide a syslog-style operational dashboard, permission-scoped logs and protected configuration/credential/test/replay actions. Opt-in thold/syslog producer integrations and remote collector reconciliation ship as bridge code under `integrations/`; the corresponding code changes are implemented directly in the source plugins, not shipped as patches from this repository. WebSocket delivery and outage policies (spec phase 9) and the release package (spec phase 10) are not built. Do not hand off production notification ownership before qualification is complete.

Primary source target: pinned Cacti 1.2.x with 1.2.32 version metadata; PHP >=8.2 with OpenSSL. Initial local checks use PHP 8.3.6. This build requires MariaDB >=10.11/InnoDB; MySQL is refused pending qualification. CLI workers require pcntl and posix; HTTP needs cURL; email/scripts require Linux /usr/bin/setsid. Cacti supplies its own local PHPMailer.

## Lab installation

Use an isolated Cacti installation. Clone this repository into `<cacti-root>/plugins/alarmmanager`, not plugins/plugin_alarmmanager. Install and enable through Cacti's Plugin Management page, then grant the Alarm Manager View realm as appropriate. Setup generates a 256-bit plugin-specific key in settings_user under owner 1. Grant Alarm Manager View for readers; administration also requires Alarm Manager Administration and Cacti realm 1. Installation creates the current schema version (v9 at 0.10.0) and upgrades add routing, lifecycle, worker and encrypted credential/transport tables plus permission/report indexes with repeatable forward migrations. It does not display key material. Existing Cacti credentials are neither copied nor changed.

Disable Cacti DEBUG_SQL_FLOW tracing before installation or enablement: core tracing can expose returned keys. Back up the alarmmanager_key settings_user record securely. Uninstall preserves it and alarm event/job history. Upgrade/enable fails closed when it is missing or malformed; restore the original key rather than inventing one. Do not deploy this skeleton for notification ownership.

## Default integrations

Administration → Alarm Manager → Endpoints can start a new integration from a provider preset as well as the generic templates. A preselection creates a **disabled draft endpoint** with the provider's URL, message configuration, response semantics and — where applicable — a suggested authentication type. Presets never carry secrets: replace every `REPLACE-…` placeholder before enabling, and attach credentials through the editor's authentication controls. Severity `critical/major/minor/warning/info/clear` maps to each provider's own scale where the provider has one.

- **Slack message** — posts to the Slack Web API `chat.postMessage` as a bot. Create a Slack app with the bot scope `chat:write`, invite the bot to the target channel, replace the channel constant, and enter the bot token (`xoxb-…`) as a Bearer credential after saving. Delivery is successful only when the response body reports `ok: true`.
- **Telegram bot** — calls the Telegram Bot API `sendMessage` with the bot token from @BotFather in the URL and the target chat ID as a mapped constant; no credential record is used. Delivery requires the response body to report `ok: true`.
- **PagerDuty Events v2** — enqueues to `events.pagerduty.com/v2/enqueue`; paste the Events API integration (routing) key over the constant. Raise events send `trigger`, recovery events send `resolve`, and updates re-trigger the same incident — all sharing the alarm episode as the dedup key, so grouping and auto-resolve come from PagerDuty. Severity maps to `critical/error/warning/info`.
- **ServiceNow incidents** — creates an incident through the Table API (`/api/now/table/incident`) on raise events and updates or resolves it on recovery and update events by PATCHing `/incident/{{integration.id}}`, where the recorded `sys_id` from the create response (`result.sys_id`) is substituted automatically. Set the instance URL, enter Basic credentials after saving, and review the incident constants — the preset resolves with state 6 (`Solved (Permanently)`), which ServiceNow expects to be adjusted to your incident-state model. The account needs the `rest_api_explorer` and `sn_incident_write` roles; delivery retries while the creating event has not yet completed, so recovery updates wait for the incident to exist.
- **ServiceNow events** — posts Event Management records to `/api/now/table/em_event` with `source`, `node`, `type`, `resource`, `metric_name`, `message_key`, `description` and severity 1–5 (0 for clear). Each transition creates an event; recovery sends severity 0 with the same episode message key so Event Management can correlate and clear the alert. This preset uses no incident fields or PATCH requests. Replace the instance URL, enter Basic credentials authorized to create `em_event` records, and review your Event Management rules and table ACLs. Field definitions: [ServiceNow event documentation](https://www.servicenow.com/docs/r/it-operations-management/event-management/send-events-via-web-service.html).

A preset that still contains a placeholder is a hint to edit it: unresolved placeholders make the endpoint draft appear with its example values until you replace them. Delivery failures from provider-specific bodies (`ok: false`, missing routing keys, missing incident references) are recorded per delivery with reason codes; the endpoint remains configurable and testable through the preview/test sends before it is enabled.

## Local verification

Tests are PHPUnit/Pest based; they run through a sibling Cacti checkout's
`include/vendor/bin/*` (the plugin intentionally ships no composer.json). See
[CLAUDE.md](.github/CLAUDE.md) for the exact commands, the phpstan
working-directory/CACTI_PATH requirements and the optional MariaDB-backed
integration lane:

```sh
QA=<cacti-checkout>/include/vendor/bin
$QA/pest -c phpunit.xml                                     # unit lane
find . -name '*.php' -not -path './tests/*' -exec php -l {} \;
```

The unit lane uses in-memory framework/database doubles and does not require
Composer in this repository, containers, Node.js or a live database. The
DB-backed integration lane (web pages, deliveries, remote synchronization) is
DSN-gated and skips without a dedicated loopback-only test database.

Dependencies/assets must be local and licensed; no CDN or runtime package download is permitted. REST API and webhook destinations have independent transports, mappings, credentials, jobs and retries, with self-signed certificate/private-CA support through endpoint ca_bundle files, preview and explicit worker-owned test sends.

Measured benchmark fixtures (PHP 8.3.6/MariaDB 10.11.14) are kept as data under `docs/benchmarks/`; they are not production performance guarantees. External non-idempotent effects still need receiver idempotency; local ownership and signed ACK do not establish end-to-end exactly-once delivery.

GPL-2.0-or-later. See LICENSE and [attribution](NOTICE.md).

## Plugin layout

Shared runtime PHP lives in flat lowercase files under `includes/`, following `plugin_thold`. Plugin metadata, setup and web entry points live at the root; assets use `css/`, `js/` and `locales/`. Development, integration and deployment resources remain in their dedicated folders. New runtime modules must use `includes/` rather than adding a plugin-local `lib/` hierarchy.

`cli/` is reserved for supported administrator command-line entry points: credentials, endpoint preview/test, producer recovery, remote synchronization (peer provisioning, configuration application, credential bundles, signed sync), status, and event submission. Each provides `--help` and rejects web execution. Administration → Daemon controls worker enablement and processing/delivery pool sizes after the service is installed. Runtime execution supports exactly two modes and no cron: the resident daemon (`alarmmanager_daemon.php`) supervises every worker pool, th-style, behind one systemd unit (`service/alarmmanager.service`), or the plugin registers Cacti's `poller_bottom` hook and drains bounded processing/delivery work inline at the tail of each poller cycle. Internal subprocess helpers, including the email child process and `includes/worker.php`, live under `includes/` with the runtime code that invokes them. Normal Cacti plugin install/upgrade creates the schema and setup key — there is no separate local-setup command. Every application operation the web pages perform — replay, acknowledgment, preview, test sends, configuration and credential saves — calls the same shared functions in `includes/` that these scripts call; the scripts exist for automation, protected secret handling and out-of-web recovery/diagnostics, not as an alternate application path. Normal management runs through Cacti's Plugin Management page and the Alarm Manager pages; the CLI-only operations that remain are remote-poller provisioning (`remote.php`), secret backup/rotation (secret material must never transit a browser), bounded pending-receipt recovery, status diagnostics and the sample-event submitter.