# Opt-in thold and syslog integration

Alarm Manager 0.7.0/schema v5 provides separate, reproducible patches. It does not automatically intercept an installed producer. Apply the patches only in an isolated Cacti lab before rolling them out. No upstream Cacti repository changes are required or pushed.

| Producer | Exact base revision | Patch | Delivery boundary |
| --- | --- | --- | --- |
| plugin_thold develop | 6bbfb58492b1e8ce2aeee4690659e54ceb4d1226 | integrations/patches/thold.patch | Eleven threshold notification branches, before mail, commands, traps and thold_action |
| plugin_syslog develop | be1a8151c21ff81684d0e8ca0f9f0a78a8d48f27 | integrations/patches/syslog.patch | Traditional and threshold alerts, before email/ticket/command delivery |

Core and thold device-status notifications remain outside these patches (phase 8). Syslog reports are also unchanged. Existing detection, threshold counts, local logging, source hooks, traps, environment setup and suppression bookkeeping remain producer-owned.

## Apply and reverse

In the isolated producer checkout, verify its HEAD matches the table above, and verify the checkout is clean. Then use absolute paths to the appropriate patch:

```sh
git rev-parse HEAD
git status --short
git apply --check /path/to/alarmmanager/integrations/patches/thold.patch
git apply /path/to/alarmmanager/integrations/patches/thold.patch
```

Use syslog.patch in the syslog checkout. Each patch modifies includes/functions.php and adds includes/alarmmanager_bridge.php and includes/alarmmanager_adapter.php. Install/upgrade Alarm Manager to schema v5 first. Deploy both producers' bridge files from the same build. Restart long-lived pollers after changing plugin hooks or source files. Do not patch a moving branch without reviewing its differences from the pinned base.

To regenerate the exact patches from clean pinned checkouts:

```sh
python3 integrations/build_patches.py --thold=/path/to/plugin_thold --syslog=/path/to/plugin_syslog
```

The builder refuses an unexpected HEAD or a modified functions.php. Reversal is git apply -R --check followed by git apply -R. Before reversing, set affected policies to legacy for new events and allow accepted queues to drain. Reversal removes interception, not Alarm Manager's accepted jobs/history; replaying an old source notification manually can duplicate an already accepted notification.

## Configuration and ownership

Administrators use Administration → Producer integrations. Controls require the existing Alarm Manager Administration and core administrator realms, POST and valid Cacti CSRF. Policies are stored locally and guarded against stale edits.

A policy identifies thold or syslog, a scope and ID, a mode, a routing profile, channel selections and optional complete history. Default scope uses ID 0. Resource scope uses thold_data.id or syslog_alert.id. Template scope uses thold_data.thold_template_id; syslog has no template scope. A complete resource policy overrides its template policy, which overrides the plugin default. Removing an override restores inheritance. No policy means legacy.

| Mode | Event publication | Existing producer delivery |
| --- | --- | --- |
| legacy | No new submission | Unchanged |
| observe | Durable observation, lifecycle/routing preview | Unchanged; no external Alarm Manager jobs |
| centralized | Durable centralized ingestion and worker routing | Only explicitly selected channels are skipped after a committed acceptance receipt |

thold selections are email and script. Syslog selections are email, script and ticket. Empty selections allow additional configured API/webhook destinations while retaining all source channels. Centralized mode requires a selected enabled routing profile and an enabled rule for that profile. Rules still apply their configured matches, maintenance, acknowledgment and cooldown; profile selection does not bypass filtering. A misconfigured or later-disabled route can intentionally produce no delivery. Verify routing previews and endpoint test results first.

Unavailability, disabled hooks, an unchanged no-handler envelope, invalid input or a failed ingestion preserves legacy fallback for new submissions. A truthy return or a forged accepted status cannot claim ownership: the committed receipt is authoritative. Existing accepted identities stay centralized even if the plugin is subsequently disabled or uninstalled, policies change, a worker fails or a destination exhausts retries. Receipts are not deleted on uninstall. Source delivery never retries a durably accepted centralized notification.

### Explicit migration of existing email and scripts

Channel handoff replaces that channel's source-specific configuration with the selected profile's endpoints. It does **not** automatically import dynamic thold notification-list recipients/BCC, source mail templates/graphs, syslog SMS recipients, or arbitrary THOLD_*/ALERT_* shell environments into event payloads. These remain unchanged on legacy/observe and every unselected channel.

Before selecting a channel, configure the replacement endpoints' recipients, formatting, attachments and mappings; provision graph/attachment files on the delivery owner when needed. Use separate resource/template profiles where recipient policies differ. Map alarm.summary/details to email subject/body using HTML conversion for untrusted text. Configure a reviewed executable wrapper for scripts using the phase-5 structured stdin/argv/environment contract; keep legacy shell commands and ticket wrappers unselected until migrated. The admin confirmation explicitly records acceptance of this replacement behavior. Selected scripts also follow the central source-acknowledgment suppression policy; legacy scripts keep their original behavior.

Normalized syslog severity follows the pinned producer: 0 Notice → info, 1 Warning → warning, 2 Critical → critical. context.metric_value preserves the numeric source severity as a string for mappings/wrappers. A syslog host ID is not assumed to be a Cacti device ID. Syslog occurrences without a verified Cacti device association remain administrator-visible in Alarm Manager; no fabricated device permission is granted.

## Identity, lifecycle and interrupted submissions

The bridge derives a notification identity from source, local poller, resource and source-owned sample/batch identity. thold uses its recorded sample timestamp, branch and counters; absent sample identity falls back to legacy. Syslog uses incoming sequence plus logtime, batch count and hostname, with an order-independent constant-memory fingerprint. Identical retry inputs reuse their saved event ID and envelope, including original time, mode, episode and channel selection. Collector reassignment/cross-poller identity fencing remains phase 8.

A local source stream allocates monotonically increasing sequence numbers under transaction and advisory stream locking. Alarm episodes persist across notifications; repeats become reminders, severity transitions update the active episode, and recovery closes it. A fresh same-severity raise starts a new episode even if the source previously disabled its recovery notification. Syslog publications are occurrences and never permanent active conditions.

Before submission, a bounded receipt and envelope are committed locally. Ingestion commits event, processing job and receipt ownership in the same transaction. Failed/legacy receipts cannot later be converted into centralized delivery by a retry. A lost hook response is resolved from the receipt. Receipt finalization rechecks the committed owner so concurrent recovery cannot race legacy fallback.

If the database becomes unreadable after reserving a receipt, the bridge holds the selected centralized channels rather than guessing ownership, retains unselected legacy channels, and logs a sanitized ownership_unknown error. A competing source evaluation of the same stream waits up to two seconds; a lock timeout avoids a duplicate source effect. These holds require operator recovery and are not evidence of end-to-end exactly-once delivery.

Retry only pending receipts after database recovery:

```sh
php cli/producers.php --retry-pending --limit=100
```

The offline/local CLI uses original envelopes, queues no duplicate accepted receipt, never invokes legacy delivery, and never reopens accepted/observed/legacy receipts. Schedule this bounded command on every opted-in producer as part of lab deployment; it can run alongside workers. Check its exit status and the pending receipt count on the administration page. Pending records remain pending when ingestion is unavailable. Receipt/stream state is local operational data; do not overwrite it during configuration replication.

A total source database failure before the receipt commits cannot durably record legacy fallback. Existing legacy fallback also cannot prove exactly-once execution of a non-idempotent script if the source crashes mid-effect. Receiver idempotency is still required at external delivery boundaries. Do not claim those failure boundaries are solved by an event ID.

## Source suppression

Central suppression never re-enables a source-suppressed notification. Complete history optionally records syslog repeat/cooldown/duplicate events and thold notification transitions that actually reach source logging. It cannot invent events from early source exits, unavailable samples, disabled detection or branches that never publish/log.

A confirmed thold recovery with no source notification still emits a suppressed recovery for condition closure, even when complete history is off. It creates no external delivery. Normal source-selected recovery delivery remains subject to the original source guards, followed by central policy. The local source logs and counters are retained regardless of the notification owner.

## Verified scope and remaining qualification

Unit branch tests execute the patched producer code with recorded Cacti/delivery doubles. Database tests use real MariaDB with Cacti-compatible PDO helpers, including concurrent submitters, atomic rollback, saved ownership, source suppression, observations and recovery. Administration tests exercise actual pinned Cacti policy functions and protected mutations in the existing fixture.

This does not qualify the full installed thold/syslog/Cacti scheduler, SMTP/graphs, deployment-specific scripts, or remote disconnect/reconnect reconciliation. Apply and exercise both patches on an installed lab before production use. Remote configuration provisioning, collector ownership changes and history reconciliation are phase 8. No upstream integration was deployed by this implementation.
