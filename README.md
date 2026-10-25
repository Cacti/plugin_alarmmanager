# Alarm Manager

Cacti plugin under development for reusable webhook/REST and script notification integrations, with alarm lifecycle/history, routing to multiple destinations and independent remote-poller processing. Detection and native email remain in Cacti, thold and syslog.

**Project direction (2026-10-14):** provide functionality inspired by Zabbix media types, custom alert scripts and webhooks: configurable integrations, transition-specific payloads, preview/test sends and independent queued delivery. Email is outside the target scope because Cacti already supports it. The integration workflow is implemented locally; the current build retains the existing email adapter and email handoff logic. Email removal is deferred.

**Current state:** development build 0.10.2. Reusable integration templates and validated per-episode external ticket/message references are implemented. Durable ingestion feeds ordered episode/state processing, routing and independent destination queues. Bounded workers deliver through REST API, webhook, Cacti email and controlled scripts. Endpoint-specific mappings and authenticated encrypted credentials support setup-key backup/rotation and explicit local-poller provisioning. Cacti pages provide a syslog-style operational dashboard, permission-scoped logs and protected configuration/credential/test/replay actions. Source plugins such as thold and syslog own alert detection and event mapping, and submit event envelopes through the registered `alarmmanager_submit` hook. Generic producer and device bridge code remains under `integrations/`; source-specific adapters belong in their respective plugin codebases. WebSocket delivery and outage policies (spec phase 9) and the release package (spec phase 10) are not built. Do not hand off production notification ownership before qualification is complete.

Primary source target: pinned Cacti 1.2.x with 1.2.32 version metadata; PHP >=8.2 with OpenSSL. Initial local checks use PHP 8.3.6. This build requires MariaDB >=10.11/InnoDB; MySQL is refused pending qualification. CLI workers require pcntl and posix; HTTP needs cURL; email/scripts require Linux /usr/bin/setsid. Cacti supplies its own local PHPMailer.

## Lab installation

Use an isolated Cacti installation. Clone this repository into `<cacti-root>/plugins/alarmmanager`, not plugins/plugin_alarmmanager. Install and enable through Cacti's Plugin Management page, then grant the Alarm Manager View realm as appropriate. Setup generates a 256-bit plugin-specific key in settings_user under owner 1. Grant Alarm Manager View for readers; administration also requires Alarm Manager Administration and Cacti realm 1. Installation creates the current schema version (v10 at 0.10.2) and upgrades add routing, lifecycle, worker and encrypted credential/transport tables plus permission/report indexes with repeatable forward migrations. It does not display key material. Existing Cacti credentials are neither copied nor changed.

Disable Cacti DEBUG_SQL_FLOW tracing before installation or enablement: core tracing can expose returned keys. Back up the alarmmanager_key settings_user record securely. Uninstall preserves it and alarm event/job history. Upgrade/enable fails closed when it is missing or malformed; restore the original key rather than inventing one. Do not deploy this skeleton for notification ownership.

## Default integrations

Administration → Alarm Manager → Endpoints can start a new integration from a provider preset as well as the generic templates. A preselection creates a **disabled draft endpoint** with the provider's URL, message configuration, response semantics and — where applicable — a suggested authentication type. Presets never carry secrets: replace every `REPLACE-…` placeholder before enabling, and attach credentials through the editor's authentication controls. Severity `critical/major/minor/warning/info/clear` maps to each provider's own scale where the provider has one.

- **Slack message** — posts to the Slack Web API `chat.postMessage` as a bot. Create a Slack app with the bot scope `chat:write`, invite the bot to the target channel, replace the channel constant, and enter the bot token (`xoxb-…`) as a Bearer credential after saving. Delivery is successful only when the response body reports `ok: true`.
- **Telegram bot** — calls the Telegram Bot API `sendMessage` with the bot token from @BotFather in the URL and the target chat ID as a mapped constant; no credential record is used. Delivery requires the response body to report `ok: true`.
- **PagerDuty Events v2** — enqueues to `events.pagerduty.com/v2/enqueue`; paste the Events API integration (routing) key over the constant. Raise events send `trigger`, recovery events send `resolve`, and updates re-trigger the same incident — all sharing the alarm episode as the dedup key, so grouping and auto-resolve come from PagerDuty. Severity maps to `critical/error/warning/info`.
Select **ServiceNow** in the template picker, then choose **Incident** or **Event** using **ServiceNow record type** inside the endpoint form. Changing the type fills the matching destination, fields and recovery behavior, retaining the instance and credentials.

- **ServiceNow incidents** — creates an incident through the Table API (`/api/now/table/incident`) on raise events and updates or resolves it on recovery and update events by PATCHing `/incident/{{integration.id}}`, where the recorded `sys_id` from the create response (`result.sys_id`) is substituted automatically. Set the instance URL, enter Basic credentials after saving, and review the incident constants — the preset resolves with state 6 (`Solved (Permanently)`), which ServiceNow expects to be adjusted to your incident-state model. The account needs the `rest_api_explorer` and `sn_incident_write` roles; delivery retries while the creating event has not yet completed, so recovery updates wait for the incident to exist.
- **ServiceNow events** — posts Event Management records to `/api/now/table/em_event` with `source`, `node`, `type`, `resource`, `metric_name`, `message_key`, `description` and severity 1–5 (0 for clear). Each transition creates an event; recovery sends severity 0 with the same episode message key so Event Management can correlate and clear the alert. This preset uses no incident fields or PATCH requests. Replace the instance URL, enter Basic credentials authorized to create `em_event` records, and review your Event Management rules and table ACLs. Field definitions: [ServiceNow event documentation](https://www.servicenow.com/docs/r/it-operations-management/event-management/send-events-via-web-service.html).

A preset that still contains a placeholder is a hint to edit it: unresolved placeholders make the endpoint draft appear with its example values until you replace them. Delivery failures from provider-specific bodies (`ok: false`, missing routing keys, missing incident references) are recorded per delivery with reason codes; the endpoint remains configurable and testable through the preview/test sends before it is enabled.

For any message field, choose **Combined text** and mix literal text with variables using **Insert field**, for example `Device {{device.id}} with description {{device.description}} went down at {{event.occurred_at}}`. Search for a field by its friendly name to insert a chip at the cursor. Select a chip and press Delete or Backspace to remove it. The sample preview uses example values, not live device data. Existing template syntax remains available under **Template syntax**. This works for API/webhook fields, email subjects/messages and script payloads, including per-state mappings. `event.occurred_at` is the current event time, not a calculated downtime duration. Only listed variables are accepted; a missing variable uses the field's configured fallback (or yields null, failing delivery if required), and list/object values cannot be embedded in text. Custom values remain literal.

## Submitting alarms from another Cacti plugin

Alarm submission uses Cacti's in-process `alarmmanager_submit` plugin hook. There is no inbound HTTP API, API token or HTTP client. Outbound REST/webhook destinations are notification transports.

The source plugin builds the event using the fields in [examples/event.json](examples/event.json), with stable event/episode IDs and increasing sequence numbers, then submits it after its own database transaction has finished:

```php
$result = api_plugin_hook_function('alarmmanager_submit', [
    'contract_version' => 1,
    'status' => 'unhandled',
    'event' => $event,
]);
```

`accepted` and `already_accepted` mean the event is durably stored; delivery happens through Alarm Manager's queues and configured routing. `unhandled` or `failed` means the caller must handle submission failure. Retry the same event with its original identity. Every collector processes and delivers its own alarms locally. Alarm submissions do not select an operating mode. Detection and source-specific event mapping belong to the calling plugin. The generic producer bridge is optional and manages local producer receipts for notification ownership and retries.

## Distributed delivery and central visibility

Enable Alarm Manager on main through Cacti's Plugin Management page. At the end of a successful activation request, Alarm Manager uses Cacti's native plugin replication API to send the final enabled status and hooks to online collectors. No Cacti core patch or per-collector enable command is required; offline collectors receive plugin state through Cacti's normal full synchronization.


Each collector, including main poller **1**, processes its own events and sends its own notifications using local routing and credentials. Events must identify the local collector. There is no Observe/Centralized choice.

Remote collectors automatically send up to 500 queued history changes to poller **1** at the end of every Cacti poller cycle, including when daemon workers handle delivery. Events, processing results, routing decisions, deliveries and attempts are mirrored for **Administration → Collectors and remote history**. The receiver never creates delivery jobs from mirrored history.

Collector history returns automatically through Cacti's existing main database connection (`remote_db_cnn_id`) from the `poller_bottom` hook. No separate URL, shared secret, CA bundle or collector pairing is required. Cacti controls connection credentials and TLS. If main is unavailable, local delivery continues and pending history stays queued; the collector advances its cursor only after main commits. The Collectors page shows synchronization status and history. Sync always targets poller **1**. Upgrade main and collectors together: the retired HTTP history endpoint returns 410, and queued batches remain available for the new database path. Legacy peer settings are retained in storage but are no longer used.

Configure destinations, mappings, rules, credentials, daemon worker settings and the Alarm Queue pause/resume setting on **poller 1**. Main-poller saves flag enabled remote pollers for Cacti's next synchronization cycle. The documented Cacti [`replicate_out` plugin hook](https://github.com/Cacti/documentation/blob/develop/Plugin-Hook-API-Ref.md#replicate_out), registered by `setup.php`, uses Cacti's supplied `rcnn_id` connection and applies settings in one remote database transaction and re-encrypts authentication using the collector's existing key. Updates include disabled and removed destinations and removed credentials. Unchanged snapshots do not create extra configuration history. Failed updates roll back, leaving the previous local configuration usable.

Daemon settings are staged in each collector's database and written to its local `cache/alarmmanager/workers.json` on its next poller cycle. The normal resident service notices that file automatically. Install the service on each machine that should use daemon workers; a remote without a fresh daemon heartbeat continues through poller-integrated processing. Script executables and custom CA files must exist at the configured paths on each collector. Alarm Queue **clear**, jobs, attempts, leases, alarm history, node identity and local encryption keys are operational/local data and are not copied from main. History transfer uses Cacti's own collector connection settings.

Existing history, stream identities and delivery keys are preserved. Old observation records remain historical and are not sent retroactively. Old callers specifying `mode=centralized` remain compatible; observation submissions and the CLI `--mode` option are rejected to prevent old dry-run commands from unexpectedly sending notifications. Saved producer policies and device grants now use local delivery for new events, regardless of their former mode.

## Event history

Events shows one row per alarm event with an integration status summary. Open an event to see processing errors, integration results, external references, delivery attempts, and routing decisions. Administrators can replay failed jobs from those details. Quick filters select integration, processing, or attempt status; dashboard and queue links open the matching Events view. Active conditions remains separate.

Schema v12 stores integration IDs directly on rules and removes the routing-profile table and profile references. This greenfield configuration format requires rules with an `endpoints` list; old profile-based configurations and retired report URLs are not supported. Processing, delivery, and attempt tables remain necessary for workers, retries, and history. Remote file exports select `--endpoints`, while normal collectors use Cacti replication.

## Notification rules

Notifications require an enabled, matching Alarm Rule. Having an enabled integration alone does not send alarms.

Open **Administration → Alarm Rules → Create a rule**. Enter a name, select one or more integrations directly, and enable the filters you need. Leave filters off to match all alarms. Device and collector pickers show names alongside IDs; sources use the names submitted by the calling plugins, such as `syslog` or `thold`.

The **Match devices** builder filters the current Cacti `host` record by location, collector (`poller_id`), Device Site Association (`site_id`), Device Template (`host_template_id`), hostname, description, and SNMP system location (`snmp_sysLocation`). Use equals, contains, AND/OR connectors, and nested groups; collectors, sites, and templates have named choices. Device conditions also have to satisfy the alarm filters below. A device missing from Cacti cannot match a device condition. For example, choose **Device Template equals Linux (#1)** and select both Slack and another integration under **Alarm Destination**. No device conditions means all devices can match.

**Filter by alarm source** offers a multiselect for sources found in local events and imported collector history. Saved source selections remain available after history expires. Sources appear after their first alarm; no internal source names need to be typed.

Optional notification hours limit when a rule matches. Maintenance windows pause notifications, while acknowledgment, cooldown and duplicate controls reduce repeated notifications. Recovery bypasses acknowledgment/cooldown/duplicate suppression but still follows notification hours and maintenance windows. Lower priorities run first; **Stop checking later rules** ends rule evaluation even when the matched rule suppresses delivery. Save with **Create rule** or **Save rule**; no policy JSON is required.

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

`cli/` is reserved for supported administrator command-line entry points: credentials, endpoint preview/test, producer recovery, remote synchronization (configuration application, credential bundles, history transfer), status, and event submission. Each provides `--help` and rejects web execution. Administration → Configuration controls worker enablement and processing/delivery pool sizes after the service is installed. Runtime execution supports exactly two modes and no cron: the resident daemon (`alarmmanager_daemon.php`) supervises every worker pool, th-style, behind one systemd unit (`service/alarmmanager.service`), or the plugin registers Cacti's `poller_bottom` hook and drains bounded processing/delivery work inline at the tail of each poller cycle. Internal subprocess helpers, including the email child process and `includes/worker.php`, live under `includes/` with the runtime code that invokes them. Normal Cacti plugin install/upgrade creates the schema and setup key — there is no separate local-setup command. Every application operation the web pages perform — replay, acknowledgment, preview, test sends, configuration and credential saves — calls the same shared functions in `includes/` that these scripts call; the scripts exist for automation, protected secret handling and out-of-web recovery/diagnostics, not as an alternate application path. Normal management runs through Cacti's Plugin Management page and the Alarm Manager pages; the CLI-only operations that remain are optional offline recovery bundles (`remote.php`), secret backup/rotation (secret material must never transit a browser), bounded pending-receipt recovery, status diagnostics and the sample-event submitter.
Report searches use a visual query builder with AND, OR, NOT and nested groups. Choose Summary, Details, Hostname, Source, Severity, Alarm state, IDs, or the selected report's status/error/endpoint fields. Existing quick filters remain available; conditions are retained in pagination and dashboard drill-down links. Search values are parameterized and LIKE wildcards are treated literally. Device permissions and historical time windows are applied outside the search expression.

Confirmed Cacti device down/recovery transitions register automatically when Alarm Manager is enabled; per-device enrollment is not required. After each poller cycle, Alarm Manager compares `host` with its own `plugin_alarmmanager_device_status` table. This works with Spine and the PHP poller without changing either or installing database triggers, including when daemon workers handle delivery. A first observation of an up device establishes a baseline; an already-down device starts a tracked episode. Recovering devices stay down until Cacti confirms them up. The comparison and durable event enqueue commit together, and existing PHP device-bridge receipts prevent duplicate events. The optional bridge under `<cacti>/lib/alarmmanager_device_bridge.php` can remain connected at `update_host_status()` for synchronous capture. Changes entirely between comparisons can be missed; this is poller-cycle state tracking, as in thold, rather than a complete transition journal. Notifications require an enabled, matching Alarm Rule with selected integrations. Without one, alarms remain in history and Active conditions but no notification is sent. Queued deliveries are checked against current matching rules before sending. Disabled drafts do not send, and capture continues even when no endpoint is enabled. Explicit ownership grants remain available for collector handoff and advanced ownership control.

Saved endpoints can be deleted directly from their editor, including enabled endpoints. The separate delete form requires confirmation and removes transport settings and credentials atomically. Notification rule references and pending or leased deliveries protect an endpoint from deletion. Completed and failed deliveries remain in history with the deleted endpoint ID.

Device message formatting is shared across all delivery services. Generic REST API, webhook and JSON script presets include individual device fields and a combined `message`; Slack and Telegram use the same message template for `text`, and new email bodies use it too. In **Integrations and mappings**, use **Use device message** on any field to insert an editable message with hostname, description, location, contact, system name, severity, state and event time. Rename the destination field as required by the receiving service. **Combined text** and **Insert field** let you arrange these values for any provider, including custom services and per-state messages.

Device and Thold producers supply `device.location`, `device.contact` and `device.sysname` from Cacti's cached SNMP device fields; unavailable values are empty. Existing saved mappings are preserved: edit their message field to use the richer format. Previously queued events retain their captured device information.

Additional insertable fields: `device.cacti_location` (configured Cacti location, separate from SNMP `device.location`), `device.collector_name` (originating collector), `device.external_id` (Cacti asset identifier, separate from the receiving service's `integration.id`), `device.uptime` (last known SNMP uptime as days/hours/minutes/seconds), `device.snmp_sysUpTimeInstance` (raw hundredths of a second), `device.status_fail_date`, `device.status_rec_date` (last restored time), and `device.status_last_error`. Transition dates are UTC ISO 8601; unknown dates and uptime are empty. These values are captured when the event is created; existing queued events are not backfilled.

### Maintenance Alarm Queue

Open **Administration → Alarm Queue** to see the waiting-message count,
the first 100 waiting messages, and links to the searchable delivery reports.
**Pause delivery** stops new notification claims in daemon and poller mode;
alarm ingestion, state processing, and routing continue to queue notifications.
**Resume delivery** drains that backlog using the normal retry and ordering rules.
Messages already claimed may finish. Controls apply to the local poller's database;
manage distributed pollers separately.

**Clear queue** requires confirmation and discards waiting deliveries, including
scheduled retries. Alarm state, running deliveries, and history are preserved.
Discarded deliveries appear as failed with reason `queue_cleared` and can be
explicitly replayed from delivery history. New alarms can still add messages;
pause before clearing for maintenance. The dashboard shows waiting notifications
and delivery pause status; its count follows the current visibility and filters.

### Microsoft Teams (Workflows)

Select **Microsoft Teams (Workflows)** in Integrations → Add Integration. This creates a disabled draft. It uses the supported **When a Teams webhook request is received** trigger, not the retired Office 365 connector.

1. In Teams, open the destination channel's **Workflows**, select **Send webhook alerts to a channel**, sign in to the Teams connection, and select the team/channel. Use **Anyone** trigger authentication. Save and copy the generated webhook URL. The workflow must post the received Adaptive Card attachments to the channel.
2. Assign a continuing owner and at least one co-owner. Workflows belong to users, not channels; an absent owner can orphan a workflow. Co-owners must maintain valid connections and channel access. Verify that your tenant allows Workflows, Power Automate, the Teams connector, and the Anyone trigger. This preset does not acquire OAuth tokens for tenant/user-restricted triggers. Do not send an Authorization header with Anyone authentication.
3. In Alarm Manager leave the Destination URL at `https://teams.workflow.invalid` (a nonsecret marker). Under **Authentication → Teams Workflow URL**, paste the complete generated URL, including its signature. Save the disabled draft. Saved URLs are encrypted with the existing endpoint credential mechanism and are never filled back into the editor or ordinary configuration exports. Keep saved authentication when editing. To rotate, select Teams Workflow URL again and paste the replacement.
4. Review the editable `text` Combined text mapping and Preview. It includes summary, severity, transition, source, hostname, description, location, collector, event time, alarm key and episode ID. Missing optional device values appear blank. Each transition (raised, updated, reminder, occurrence, recovered) posts a new Adaptive Card; recovery carries the same episode ID and alarm key. No message editing or acknowledgment callback is implemented. Event time and captured uptime are not outage duration.
5. Enable and **Test destination**, then check both workflow run history and the actual channel. Select the endpoint in the desired notification rules. Enabling through the editor requires a saved or newly supplied workflow URL. Test destination uses the existing queue and requires an enabled endpoint.

The existing mapping system has no automatic Cacti base-URL field. If useful, add a Markdown link in Combined text using your installation's fixed HTTPS base URL and `{{device.id}}`, for example `[Device](https://cacti.example.test/host.php?action=edit&id={{device.id}})` for device-backed alarms. Retain the episode ID for alarm correlation; no external Teams message ID is recorded.

HTTP 2xx is recorded as `teams_workflow_accepted`: acceptance by the workflow, **not confirmed Teams channel delivery**. A later action can fail after HTTP 202. HTTP 401/403 and other permanent 4xx fail; 408/425/429 and 5xx use existing retry/backoff and Retry-After handling. Response bodies and signed URLs are not included in results. Delivery retries/replays can create duplicate Teams posts if a request was accepted before a timeout; Workflows do not promise Idempotency-Key deduplication. Cards over 28,000 encoded bytes are rejected; shorten the mapping if needed.

Each endpoint retains its independent worker queue, attempt history, replay, timeout and rate limit settings. Routing, episode processing, maintenance, acknowledgment suppression and cooldown policies remain shared. Remote collectors use the existing central configuration/credential replication and local encryption key; they deliver locally without contacting the main poller per notification. Permit outbound DNS and TCP 443 from every delivering collector to the hostname in the generated URL (commercial cloud `*.logic.azure.com` or `*.environment.api.powerplatform.com`). Other cloud hostname formats are rejected by this preset. Microsoft-side workflow connections must also be permitted by tenant policy. TLS hostname/certificate verification stays enabled; install any configured private CA PEM on each collector. Ordinary configuration exports exclude the signed URL, but explicit credential backups and protected collector-provisioning bundles contain secrets and must remain private.

Microsoft states that the webhook templates and Teams webhook trigger do not require a premium license. Users still need access to Teams/Workflows and a permitted connection; extra premium actions can have additional licensing requirements. Flow performance-profile and Teams connector throttling limits apply. Channel type and posting-identity support vary; verify private/shared-channel support for the chosen workflow before enabling production notifications.

Official references (reviewed October 21, 2026):

- [Create webhooks using Teams Workflows; ownership and channel limitations](https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook)
- [Teams connector: webhook authentication, Adaptive Card format and throttling](https://learn.microsoft.com/en-us/connectors/teams/)
- [Microsoft Support: webhook templates, setup and licensing](https://support.microsoft.com/en-us/workflows/send-messages-in-teams-using-incoming-webhooks)
- [Power Automate: asynchronous HTTP 202 acceptance](https://learn.microsoft.com/en-us/power-automate/guidance/coding-guidelines/asychronous-flow-pattern)

Live Teams delivery remains unverified without a workflow URL and channel access.
