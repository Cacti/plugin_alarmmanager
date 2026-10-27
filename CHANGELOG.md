## ChangeLog

* Events: merge the Routing decisions tab into Events. Each event shows a Routing column, for example "Queued → Slack", "Cooldown → 2 destinations" or "No matching rule", including decisions mirrored from remote collectors. Routing decision, rule ID and destination ID are Events search conditions, dashboard suppression links open Events filtered by reason, and old `tab=decisions` links redirect to Events. VERIFIED locally with unit tests (`EventRoutingTest`, `ChangedPathsTest`); the browser lane remains UNVERIFIED.

* Schema 14: drop tables left behind by retired features, `plugin_alarmmanager_api_clients`, `plugin_alarmmanager_audit` and `plugin_alarmmanager_sync_batches`, which no current code reads or writes. VERIFIED locally with unit tests; the upgrade test in `SchemaIdentityTest` remains UNVERIFIED without a test database.

* Add SNMP trap and syslog destinations. SNMP traps (v2c and v3, trap or INFORM) are sent with Cacti's configured `snmptrap`; secrets stay in encrypted credentials and reach `snmptrap` only through a private temporary `snmp.conf`. The new `CACTI-ALARMMANAGER-MIB` defines `amAlarmRaise` and `amAlarmClear` under `{ cacti 6 }` (1.3.6.1.4.1.23925.6). That sub-arc assignment still needs Cacti Group confirmation. Syslog sends RFC 5424 with `[alarm@23925 …]` structured data, or RFC 3164, over UDP, TCP or certificate-verified TLS. VERIFIED locally: `SnmpSyslogTest` passed against real net-snmp 5.9.4 `snmptrap` (v2c community taken from the config file and absent from argv), UDP/TCP sockets and a Python TLS listener, and the MIB resolved with `snmptranslate`. Integration-lane and live deployment remain UNVERIFIED.

* Require an enabled matching Alarm Rule for notifications. Remove automatic routing to all integrations when no rules exist, and recheck queued alarms before delivery. Unrouted alarms remain captured with a no-route decision.

* Alarm Rules: use the shared AND/OR/NOT/group condition builder for Match alarms, with named source, severity, alarm change, device and collector choices, summary text and exact device tags. Applies to all alarm sources hides the builder and clears alarm conditions on save.

* Alarm Rules: add Applies to all devices to hide device conditions and save rules without a device filter.

* Add configurable Offline summary, Recovering summary and Online summary fields under Configuration, synchronized to collectors. Use "Device Offline" and "Device Online" as the summaries for newly captured Cacti device down and recovery alarms. Status 2 emits a recovering update in the same outage, keeping the condition active until status 3.

* Alarm Rules: add a menu-driven device-condition builder for Cacti location, poller, site, device template, hostname, description and SNMP system location. Support grouped AND/OR conditions, named ID choices and multiple integration destinations. Match current Cacti host data through the parameterized filter engine; device conditions remain part of replicated rule policy. Live deployment remains UNVERIFIED.

* Consolidate delivery jobs, processing and attempt screens into Events. Add per-event integration outcome summaries and scoped workflow details with failed-job replay. Schema v12 removes routing profiles; rules store integration IDs directly, including configuration replication and private exports. VERIFIED locally with focused MariaDB, native Cacti web fixtures, browser checks and PHPStan; live deployment remains UNVERIFIED.

--- main (development) ---

* Reconcile locally raised device outages after reassignment to another collector: a confirmed-UP host closes the old local episode without changing current poller ownership or capturing new remote-owned outages.

* Clear tracked device conditions automatically when Cacti confirms the device UP, even when its recovery timestamp is stale or missing. Use the host status as the recovery authority and revisit stranded open episodes on the next poller cycle; timestamps are event history metadata only. Preserve acknowledgment, history, ownership and Cacti host data.

* After successful activation on main, repeat Cacti's native plugin-state replication at request shutdown so collectors receive the final enabled status. This handles Cacti 1.2's hook-before-status enable ordering within Alarm Manager, without core patches or per-collector activation.

* 0.11.0: Use Cacti's existing collector-to-main database connection for history transfer. Remove separate HTTP endpoints, shared-secret provisioning and CA controls. Retain durable pending batches, transactional mirror imports and retry-safe acknowledgments; configuration continues through Cacti's `replicate_out` hook.

* 0.10.2: Replicate main-poller destinations, mappings, profiles, rules, credentials, daemon worker configuration and Alarm Queue pause/resume through Cacti's native full remote synchronization. Main saves request synchronization; remote credentials use each collector's own key. Updates are atomic, disable removed destinations, remove retired credentials, and preserve queued alarms, leases and history. Remote daemon settings apply on the next poller cycle, with poller fallback when no daemon heartbeat is available. VERIFIED locally with `ConfigurationReplicationTest` against three disposable MariaDB node databases, including rollback and independent-key checks. Live multi-server deployment and systemd reload remain UNVERIFIED.

* Rules and suppression: replace mandatory policy JSON with labeled alarm filters, named device/collector pickers, routing profiles, notification schedules, maintenance windows and repeat-suppression controls. Preserve the stored policy format and return to the appropriate editor after saving rules or profiles.

* Events: show the captured device name beside its ID, falling back to the hostname when the description is empty.

* Alarm submission: remove the inbound HTTP endpoint, bearer-token clients and API-client administration/provisioning. Cacti plugins submit events through the registered `alarmmanager_submit` hook. Remove Alarm Manager's Syslog/Thold adapters; source plugins own event mapping. Outbound REST/webhook delivery remains supported. Existing database rows are preserved.

* Endpoint setup: add default integration presets (Slack, Telegram, PagerDuty Events v2, ServiceNow incidents) to the template dropdown; selecting a preset populates the new-endpoint editor immediately. Each preselects an authentication type when relevant and fills a validated payload mapping for the provider; drafts stay disabled and no credentials or endpoint IDs are copied. New endpoints may save with the preselected authentication type while every credential field is still empty — the credential record is attached afterwards — while editing an existing authenticated endpoint still requires replaced or kept authentication.

* HTTP delivery: add optional per-transition URL/method overrides (`transition_overrides`) so one endpoint can create an incident on raise events and update or resolve it on recovery events, keyed by the recorded external reference (for example ServiceNow sys_id) through an {{integration.id}} URL placeholder. Deliveries waiting on a not-yet-recorded reference retry until the creating delivery completes.

* Poller mode: log elapsed time and processing/delivery job counts on every successful cycle, including empty queues.

* Administration: add a Daemon tab with enable/disable, processing and delivery worker counts, and live supervisor status. The standby controller applies changes automatically; poller-integrated processing pauses in daemon mode.

* Endpoint setup: remove the concurrency control, use the automatic default for new endpoints, and preserve existing destination limits on edits. Daemon worker pools remain centrally configured.

* HTTP alarm delivery: remove DELETE from endpoint settings and reject it in transport validation; supported methods are POST, PUT and PATCH.

* 0.8.1 navigation: add a realm-controlled Alarm Manager top-level tab beside Syslog, move report pages out of the console header, and provide administrator-only return navigation between reports and administration. Register both Cacti header-tab hooks.

* Schema v7: database-assigned endpoint/profile/rule IDs, with existing identities preserved on upgrade. Removed visible record-ID controls; creation no longer accepts arbitrary IDs. Producer routing profiles now use a dropdown.

* Authentication editor: select None, Bearer, Basic or custom headers from a dropdown; show and submit only the relevant inputs, with an explicit Keep saved authentication option for existing authenticated endpoints.

* Endpoint authentication: add bearer-token, Basic username/password and authentication-header inputs to the endpoint editor, saving encrypted credentials and configuration atomically; blank endpoint inputs preserve saved credentials.

* Credentials page: explain why no credential forms appear before a REST API/webhook endpoint is saved and provide a Create endpoint link.

* Fixed administration/report tab navigation and collector-history filters returning misleading permission errors when explicit query parameters were present; corrected FILTER_CALLBACK options passed to Cacti request helpers.

* Includes consolidation: merged five satellite modules into administration, credentials, delivery and transports; reduced includes/ from 33 PHP files to 28 while preserving all shared functions and subprocess boundaries.

* Navigation cleanup: the report pages expose their seven tabs (Dashboard, Active conditions, Events, Delivery jobs, Attempts, Routing decisions, Processing) and the Administration page moved out of the view tab strip into its own console menu item — a top-level "Alarm Manager Settings" group placed like the syslog plugin's "Syslog Settings" — with the administration breadcrumb promoted to a first-level console page. The endpoint editor's HTTP-method control was renamed to `http_method` (a `method` form control shadows `form.method` and crashes `csrf-magic.js`).
* 0.8.0: Alarm event contract and `alarmmanager_submit` producer hook; durable ingestion with idempotent schema; alarm lifecycle, routing profiles and suppression; multi-process supervisors and delivery workers with lease recovery; endpoint mapping plus REST API, webhook, email and script transports; syslog-style dashboard and administration UI; opt-in thold and syslog producer integrations; device status publication and remote collector reconciliation with signed history sync.
* Standards: repository conformance work — Cacti license headers, request validation and Cacti UI helpers, PHPStan/PHP-CS-Fixer/PHPUnit toolchain, hardened sync endpoint, simplified setup, Cacti logging and gettext conventions, rewritten CI workflow, agent documentation, and the specification audit (docs/SPEC_AUDIT.md) with a dated evidence matrix.
* CLI architecture review: verified the web/producer-to-worker call paths have no CLI coupling; Administration → Workers and Audit pages now render shared-metrics Cacti tables instead of raw JSON; consolidated the daemon deployment shape into a single service unit (`alarmmanager_daemon.php` supervising every worker pool, thold-style) with cron-documented scheduled mode, removed the per-worker and sync-sender unit/timer pairs; documented every retained CLI command and its reason.
* Deployment-mode consolidation: two supported runtime modes and no cron — poller-integrated (`poller_bottom` hook draining bounded work inline per cycle, new `includes/polling.php`) or the resident daemon; the worker entry point moved to `includes/` as an internal subprocess helper, `cli/setup_local.php` removed as an install/upgrade duplicate, and config-only application folded into `cli/remote.php --configure` under the owner-private file policy.
* Structure consolidation: all schema DDL merged into one `includes/schema.php` (plugin_syslog pattern) with producer-receipt acceptance moved to `includes/ingestion.php`; Cacti Group license headers applied across all files.
