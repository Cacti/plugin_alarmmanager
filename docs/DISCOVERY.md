# Phase 1 compatibility and integration discovery

Inspected 2026-10-13 UTC in isolated source checkouts. No Cacti organization writes or live installation changes were made.

## Pinned source revisions

| Source | Branch | Revision | Observed version |
| --- | --- | --- | --- |
| Cacti primary target | 1.2.x | d6d230f712c0af1a0391646f65364950ebf131af | include/cacti_version: 1.2.32 |
| Cacti forward reference | develop | ce6ab6e0b3c43b37d00bcfcf50bf61d46131f86e | 1.3.0.-1; unreleased development |
| thold | develop | 6bbfb58492b1e8ce2aeee4690659e54ceb4d1226 | INFO: 1.8.3, compat 1.2.29 |
| syslog | develop | be1a8151c21ff81684d0e8ca0f9f0a78a8d48f27 | INFO: 4.11, compat 1.2.23 |
| servcheck | main | 1622c88c416cbc199cae9a5756734903b487ddf6 | INFO: 0.4, compat 1.2.29 |

Use `https://github.com/Cacti/<repository>/blob/<revision>/<path>` for immutable source links. Plugin repository names are `plugin_thold`, `plugin_syslog`, and `plugin_servcheck`.

Primary compatibility target is the pinned Cacti 1.2.x code, not a claim that 1.2.32 has been released. Plugin PHP floor: 8.2; local validation: PHP 8.3.6. Cacti 1.2.x CLAUDE.md states PHP >=8.2, although its older utility_php_recommends() still reports 5.4; do not copy that stale value. Forward develop composer.json explicitly requires ^8.3. Development database target: MariaDB 10.11/InnoDB, with a second MySQL 8.0 lane before claiming MySQL support. These are chosen test targets, not a verified statement about Cacti's minimum database version. No live DB is available here.

## Framework lifecycle, permissions and hook returns

Cacti `lib/plugins.php`: api_plugin_install() reads plugin_alarmmanager_version(), invokes install, then check_config; strict false/exception fails installation. api_plugin_enable() invokes check_config before activating hooks. Disable removes active hook execution; uninstall removes hook/realm/config metadata. Our install registers only config_arrays and draw_navigation_text plus an administrator-assigned view realm. There is no producer, poller or delivery hook yet.

api_plugin_register_realm() establishes per-page access, enforced by `include/auth.php`. `alarmmanager.php` is an authenticated read-only placeholder using top_header(), html_start_box(), html_escape(), and bottom_footer(). It contains no mutations or secrets. Future configuration/test-send endpoints need separate admin realms and CSRF checks.

api_plugin_hook_function() initializes its return with the caller's parameter; no handler returns that parameter unchanged. api_plugin_hook() passes positional argument arrays and does not propagate a handler's functional return. Therefore phase 2 must use a versioned envelope with explicit `unhandled` initial status through api_plugin_hook_function('alarmmanager_submit', envelope). A truthy envelope is not acceptance. Keep envelope shape across hook chaining and restart long-lived processes after hook-registration changes because core caches handlers.

The framework's api_plugin_uninstall() also invokes api_plugin_db_changes_remove() when table deletion is enabled. A no-op plugin uninstall alone cannot protect history if future tables are registered in that cleanup registry. Phase 2 must explicitly preserve durable tables against framework cleanup and provide a separate reviewed purge command.

## Core confirmed device status

`cmd.php` calls `lib/functions.php::update_host_status()` with raw reachability results. The function applies ping_failure_count/ping_recovery_count and status_event_count, changes HOST_UP/DOWN/RECOVERING, logs confirmed events, then persists host state. Inspection found no actual `api_plugin_hook('update_host_status', ...)` call in this function despite the hook name appearing in remote capability maps. Do not assume it fires. Phase 8 needs a separate core patch or an evidenced alternative for confirmed transitions. Select safe fields; the function fetches a complete host record containing SNMP credentials that must never become an event payload. Failure/recovery transition state and enqueue atomicity need design before adding hooks. thold already sends device notifications in includes/polling.php, so one path must own each device outage.

## thold delivery boundary

`includes/functions.php::thold_check_threshold()` applies evaluation context, acknowledgment, maintenance, repeat and severity behaviour. In the alert path around lines 3530-3572, thold_mail() and thold_command_execution() execute before api_plugin_hook_function('thold_action', $save). Other branches also invoke thold_action; every branch must be traced during integration. It is a post-delivery hook and cannot be used to intercept centralized ownership.

`thold_mail()` (same file, around 8307) reads thold_notification_queue and builds recipients/graph attachments. `notify_queue.php::notify_queue()` presents queue state; it is not an Alarm Manager queue API. Preserve queue/template/attachment behaviour during opt-in migration. `includes/polling.php` has separate device down/up email and exec paths. Future patches publish before chosen side effects and skip only durably accepted channels, retaining source state/local history.

## syslog suppression and delivery

`includes/functions.php::syslog_process_alerts()` selects rules; syslog_process_alert() handles matching records, repeat detection and syslog_alert_suppression_check(). It logs via syslog_log_alert(), sends via syslog_sendemail(), and runs syslog_execute_ticket_command()/syslog_execute_alert_command(). Traditional and threshold branches have different email/log ordering. Publish normalized occurrences before delivery side effects; preserve source suppression metadata, local logs and counters. A normal matched log occurrence does not become an indefinitely active condition.

`includes/processing.php` and setup.php register poller_bottom and replicate_out. Phase 8 must verify local syslog collector behaviour end to end rather than assume INFO capability flags prove autonomous operation.

## Dashboard reuse plan

Actual files: `lib/syslog_dashboard.php::syslog_dashboard()` builds the toolbar/panel bootstrap; syslog_dashboard_chart_data() supplies bounded chart data; syslog_dashboard_can_view()/can_edit() enforce access. `js/dashboard.js` builds cards, refresh controls and bb.generate() charts; `css/dashboard.css` provides the panel grid. Reuse toolbar, refresh, panel widths/heights, card actions and chart conventions under alarmmanager names. Cacti 1.2.x `lib/html.php` loads local `include/js/d3.js`, `include/js/billboard.js`, and theme billboard.css. No dashboard source/assets are copied in phase 1. Verify availability at the compatibility pin and retain GPL attribution when adapting later. No runtime syslog dependency or CDN is allowed.

## servcheck credentials and keys

`includes/functions.php::servcheck_encrypt_credential()` JSON-encodes credential fields, uses AES-256-CBC from includes/arrays.php, prefixes a fresh random IV and stores base64 ciphertext in plugin_servcheck_credential.data. servcheck_decrypt_credential() reads that blob. The key comes from settings_user through read_user_setting('servcheck_key', null, true, 1). Current creation is lazy and uses a timestamp hash; set_user_setting() does not explicitly specify user 1, although reads do. Do not copy either detail.

Alarm Manager follows the encrypted credential-record/settings pattern, with its own alarmmanager_key in settings_user for explicit owner 1. Initial setup generates random_bytes(32), inserts without overwriting an existing key, reads back and validates strict canonical base64/32-byte length. Enable/upgrade never generate keys. Uninstall preserves the key. Ordinary error SQL logging is disabled for these calls; setup/readiness fail closed when DEBUG_SQL_FLOW is enabled because core can print query return values including keys. No credential records, encrypt/decrypt adapter or rotation implementation exists yet; phase 5 will use a versioned authenticated ciphertext envelope and fail closed. A database-stored key does not protect against a full database compromise; it follows the requested Cacti storage integration rather than pretending to be an external vault.

`servcheck_replicate_out()` copies credential tables alongside test/proxy/CA configuration. That alone does not demonstrate that a remote can decrypt them: the relevant key must also be provisioned consistently. Explicit owner selection avoids current-session/CLI differences. Phase 2 must prevent a fresh install from regenerating a missing key if encrypted records already exist; phase 5 must implement rotation, credential/key backup restoration and remote provisioning.

## Remote independent processing

`lib/plugins.php::api_plugin_run_plugin_hook()` maps poller_bottom to remote_poller and data-collection hooks to remote_collect. api_plugin_run_plugin_hook_function() has different fallback behaviour for custom hooks. INFO declares remote_collect and remote_poller; offline_view allows a read-only offline page, offline_mgmt is deliberately disabled. These flags are scaffolding, not completed remote processing.

Required architecture: each configured poller has local durable ingestion, alarm state, routing snapshot, worker pools, endpoint credentials and destination-owned delivery jobs. Main-poller unavailability must not block local REST API/webhook/email delivery. Provision each authorized poller with a usable key/credential pair before disconnecting. Never overwrite local operational queues or keys through bulk config replication. Synchronize immutable event IDs and decisions/attempt outcomes centrally; synchronization of a remotely delivered event must not enqueue another delivery. Poller reassignment needs ownership epochs/fencing; endpoint idempotency remains required at crash boundaries. Validate online/offline/recovery and custom-hook dispatch on both core pins.

## Repository instructions

Read Cacti develop AGENTS.md, .github/copilot-instructions.md and CLAUDE.md, Cacti 1.2.x CLAUDE.md, and syslog AGENTS.md. Syslog's graphify-out graph is absent, so its conditional graph query does not apply. No reference repository was modified. Cacti's upstream no-vendoring rule conflicts with the explicit plugin packaging requirement; the user's locally shipped dependency requirement governs this plugin. No dependency was introduced in phase 1.


## Phase 8 source boundaries

At the pinned Cacti 1.2 revision, lib/functions.php update_host_status applies ping_failure_count/ping_recovery_count and commits host status after confirmed detection. The separate device patch wraps that final status write with atomic local publication, preserves native counters, and copies only safe host metadata. The custom hook dispatcher in lib/plugins.php was exercised for offline/recovery/online contexts with framework doubles. No new assumption that INFO flags alone enable remote hooks is made; source device publication calls the copied local bridge directly.

lib/poller.php's remote host replication invokes replicate_out_table for host configuration. Enrolled peers use an explicit upsert boundary preserving local status/counters/dates instead of destructive replacement; schema mismatch fails safely. No Alarm Manager replicate_out hook is registered. thold includes/polling.php thold_update_host_status has separate down/recover hooks before device mail and script execution; the device patch gates only selected notification effects there. Threshold integration remains a separate patch. Installed scheduler/remote bootstrap qualification is still pending.
