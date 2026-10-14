# Decisions

1. Owner is bmfmancini/plugin_alarmmanager; private visibility was confirmed via GitHub and is preserved. Installed directory is alarmmanager, without plugin_ prefix. Functions/hooks/settings use alarmmanager_; tables will use plugin_alarmmanager_. Cacti-mandated lifecycle function names remain plugin_alarmmanager_*.
2. Primary source target is pinned Cacti 1.2.x / 1.2.32 metadata, PHP >=8.2; PHP 8.3.6 is the initial validation runtime. Develop is a forward reference, not declared compatible yet. MariaDB 10.11 is the first DB test lane; MySQL 8.0 is a later qualification lane.
3. Servcheck-style encrypted credential records and settings_user key storage are required. Generate a plugin-specific CSPRNG key during setup. Use explicit owner 1, never current-session ownership; preserve it across setup/upgrade/uninstall. Add authenticated ciphertext, lost-key guards and rotation before introducing credential records. Do not reuse servcheck's key, timestamp-derived generator or unauthenticated format verbatim.
4. Remote pollers must process and deliver independently against local state during disconnection. Central reconciliation is history synchronization, not redispatch. Separate replicated configuration from poller-local queues/keys. Provision usable credentials per authorized poller; decide shared-key versus per-poller re-encryption with threat and operational tradeoffs before implementing replication.
5. A profile can route one event to multiple destinations. REST API and webhook are separate configurations sharing an HTTP transport. Each endpoint gets its own mapping, secret references, idempotent job, result and retry stream. Deduplicate the same endpoint selected by overlapping rules. Do not resend successful endpoints because another endpoint failed.
6. Legacy producers retain delivery by default; observe and centralized modes require explicit handoff semantics. thold_action is unsuitable for interception. There is no interception in the skeleton.
7. Adapt syslog's actual dashboard toolbar, grid/cards and billboard conventions later. Namespace copied code and preserve source notices; do not require syslog installation. Ship pinned dependencies locally or reuse guaranteed Cacti assets. No Node.js runtime or container requirement.
8. Phase 1 creates no event tables, queues or transports. Its setup checks use a standalone database double and cannot establish live installation/replication correctness.

9. Contract v1 uses explicit status in a functional hook envelope. Event uniqueness includes mode so observation cannot own delivery. Normalized payload conflicts fail closed. Persist episode and monotonic stream sequence; state application is phase 3.
10. Ingestion refuses an already active producer transaction. Atomic event plus job commit is required before acceptance. Direct DDL keeps history outside Cacti plugin_db_changes destructive cleanup; no purge action exists yet.
11. Setup guards lost keys over future encrypted credential records. Key/ingestion paths refuse SQL flow tracing because core can dump returned values.

## Phases 3–4 additions

- Require MariaDB >=10.11 for the qualified SKIP LOCKED queue path; refuse MySQL until a separate lane is qualified.
- Fence claims and completion with random lease tokens and expiry; perform external calls outside transactions. Queue retries and receiver idempotency address separate failure boundaries.
- Preserve processing stream order across retries and terminal processing failures; isolate blocked streams. Allow later recovery after terminal destination failure.
- Use mode-separated observation state and immutable configuration revision snapshots for reviewable routing outcomes.
- Force offline/local Cacti CLI bootstrap and spawn fresh PHP worker processes; local endpoint limits apply to one queue database, not all pollers. Leave production handlers empty until phase 5.

## Phase 5 additions

- Follow servcheck's encrypted JSON/settings_user ownership pattern with plugin-specific setup keys, versioned AES-256-GCM and endpoint-bound additional authenticated data. Do not use servcheck's CBC/lazy timestamp-key behavior.
- Credentials are separate from configuration/history/previews; explicit local CLI backup/rotation/restore provisions matching keys and records. No automatic cross-poller secret replication.
- Trust self-signed/private-CA certificates through an endpoint ca_bundle PEM path while retaining certificate/hostname checks. Refuse redirects, ambient proxies/netrc and credential-bearing URL/HTTP configurations.
- Encode mapping payloads structurally with typed conversions and bounded safe field names. Endpoint current transport/credential resolution at attempt time is explicit; original routing revisions remain in decisions.
- Use Cacti's existing mailer in a deadline-controlled fresh offline child; preserve its recipient/HTML/attachment contract. Scripts receive structured argv/stdin and a filtered environment, with bounded output and ordinary descendant group cleanup.

## Phase 6 additions

- Scope all viewer queries/aggregates to explicit Cacti policy SQL before grouping/pagination; reserve unmapped/global events, workers and configuration for administrators. Administrative actions require both plugin Administration and core realm 1, excluding guests/auth-disabled use.
- Resolve plugin realm IDs by stable filenames rather than translated display labels. Cacti registration helpers return void on success; verify persisted hook/realm metadata instead of treating a missing return as failure.
- Adapt the actual syslog shared controls, responsive panels and local Billboard chart conventions with attribution. Keep user labels in escaped/text-only elements and disable chart HTML tooltips.
- Use UTC DATETIME arithmetic for buckets independent of session timezone; keep current condition/queue counts separate from historical action windows and mode-separated observations.
- Bound report rows, windows/categories and per-statement execution; record measured fixture queries and remaining large-installation qualification honestly.
- Lock configuration revision to reject stale UI writes, audit safe metadata atomically, and preserve prior delivery attempts/idempotency while granting a fresh explicit replay retry budget.


## Phase 7 additions

- Ship separate revision-pinned thold/syslog patches; default legacy, functional observation and explicit per-channel centralized handoff. Manage source defaults, thold template/resource overrides and syslog rule overrides in the existing protected administration page.
- Persist source envelopes/sequence/episode state and atomic ownership receipts separately from event/delivery attempts. Ingestion commits event/job/receipt together; committed receipts override disabled hooks or later transport failure. Never reopen legacy receipts.
- Keep source email/template/BCC/graph/script/ticket behavior unchanged until that channel is explicitly migrated. Central handoff replaces those settings with administrator-configured endpoint contracts; require migration acknowledgment and document wrapper/recipient parity limits.
- Preserve source suppression and local state/logging. Emit suppressed confirmed recoveries when a source recovery notification is disabled so central conditions close without sending traffic. Syslog remains an occurrence producer.
- Hold selected channels when ownership cannot be read after durable reservation; recover pending saved envelopes using the bounded offline producer CLI. Receiver/script idempotency and source DB-loss boundaries remain explicit limitations.
- Keep operational receipts/streams local and preserve them across uninstall. Full installed producer and remote reconciliation qualification remains separate from PDO/framework fixtures.

## Phase 8 additions

- Preserve native confirmed-device detection; commit core host state, event, job and receipt together. Use deployment/device/epoch/durable-counter identity and one core/thold notification owner.
- Provision selected local configuration and re-encrypt usable credentials under each remote setup key, without overwriting operational state. Keep private exports/peer keys outside the plugin tree.
- Use bounded leases, higher ownership epochs, a 735-second clock/transport handoff margin and acknowledged last-state seeds; pause expired managed devices and fence old deliveries. Do not promise instantaneous disconnected revocation.
- Protect enrolled remote host state from destructive native replication. Cache grant checks rather than probing configuration for each sample.
- Archive signed local history separately from processing/delivery; keep exact pending packets until signed ACK. Lock outbox prefixes under READ COMMITTED and read revisions/data atomically to handle reverse transaction commit order.
- Use TLS-verified HTTPS POST with HMAC peer/audience/domain binding. Cacti cacti_http supports GET and global insecure-TLS settings, so it is unsuitable for this authenticated POST boundary.
- Expose remote archive only to administrators; retain the local dashboard semantics. Installed remote Cacti qualification and retention remain separate gates.


## Plugin layout correction — 2026-10-13

Use plugin_thold (reference revision 6bbfb58492b1e8ce2aeee4690659e54ceb4d1226) as the folder-layout reference. Shared PHP belongs in flat lowercase includes/ files, matching thold's includes/functions.php, database.php and polling.php organization. Move the exception classes from lib/AlarmManager/Exceptions.php to includes/exceptions.php and update the runtime loader; preserve their namespace and behavior. Keep setup.php, INFO and web entry points at the plugin root, with assets in their conventional folders. Future implementation phases must follow this layout rather than adding a separate plugin-local library tree. References to Cacti core's lib/ directory and third-party source paths are upstream integration paths, not directories to recreate inside this plugin.

The cli/ directory is reserved for administrator commands and worker entry points that can be run directly from a terminal; the daemon itself lives at the plugin root like thold's thold_daemon.php. The email child is an internal stdin-driven subprocess, so it lives at includes/mail_child.php; the email transport invokes that path. Keep its CLI-only execution guard, offline bootstrap and bounded parent-process deadline. All ten public entry points (nine in cli/ plus alarmmanager_daemon.php) provide --help.

## Sync endpoint hardening — 2026-10-13

Keep alarmmanager_sync.php a standalone HMAC peer endpoint rather than migrating it onto Cacti's remote_agent hook: remote_agent binds to the Cacti session/remote-auth model, which is the wrong trust boundary for machine-to-machine history reconciliation, and migration would re-key every peer with no security gain. Structural transport guards (method, Content-Length, content type, declared-vs-actual size) run before the Cacti bootstrap so malformed traffic never touches the database. After bootstrap, the endpoint refuses to receive unless the plugin is enabled, the sync schema is provisioned and the node is the main poller. Every rejection is recorded with cacti_log('[ALARMMANAGER] …', false, 'alarmmanager') using the reason code and the claimed poller id only; signatures, HMAC keys and request bodies are never logged, and a logging failure never changes the 403 response.

## CLI architecture review — 2026-10-13

Re-verified against HEAD that no frontend-to-CLI coupling exists: the view, administration and preview/test-send paths call shared `includes/` functions directly (alarmmanager_apply_configuration, alarmmanager_delivery_preview, alarmmanager_enqueue_test, alarmmanager_save_credential, alarmmanager_rotate_key, alarmmanager_admin_replay), producers publish through api_plugin_hook_function('alarmmanager_submit') and durably enqueue in the same transaction, and the only spawned processes are the supervisor→worker children (phase-4 daemon design) and the stdin-driven mail child. The web/CLI split is duplicated thin entry points over shared functions, not alternate application paths.

Retained all ten entry points, each with a required capability: alarmmanager_daemon.php/worker (phase-4 daemon plus scheduled bounded mode), status (diagnostics sharing alarmmanager_metrics() with the web health page — now the single implementation for both), configure (configuration-as-code/provisioning over the same apply function the web uses), endpoint (preview/test-send automation over the same functions the web uses), credentials (backup/rotate/restore/provision by design: key and plaintext material must never transit the browser; the web keeps status/save/rotation), producers (bounded recovery of pending saved envelopes — an out-of-web recovery boundary, surfaced as a count on the producers page), submit_event (spec-phase-2 sample submitter for the producer contract), setup_local and remote (remote-poller provisioning, peer keys, bounded sender and seeding operate against the local offline poller database before/while the main poller may be unreachable, which the Cacti web install lifecycle does not do). Consolidation opportunities the review found instead in the web layer: the Administration → Workers and Audit pages rendered raw JSON <pre> blocks and a private worker query; both now render Cacti tables, and the health page calls the shared alarmmanager_metrics() implementation rather than its own query.

The daemon (`alarmmanager_daemon.php`, the phase-4 supervisor) keeps a bounded worker-pool configuration JSON and retains the Cacti-free parent: it deliberately never bootstraps Cacti or opens a database connection (fresh worker spawns, no inherited connections, SIGHUP reload without storage), so Cacti settings are not readable by design. This is not a request for a web editor; the phase-4 spec's "bounded scheduled CLI/poller mode" is the scheduled worker invocation documented in docs/WORKERS.md (cron only). The phase-4 spec's "systemd service examples" are met by the single `service/alarmmanager.service` unit — the thold `thold_daemon.service` deployment shape: one service, the daemon owns every worker pool. Per-worker and remote-sender unit/timer pairs were removed; the bounded sync sender runs from the documented cron entry.

## Deployment-shape and schema consolidation — 2026-10-13

Following the Cacti plugin references: schema DDL lives in ONE file, `includes/schema.php` (the plugin_syslog pattern), keeping the previous phase order (v2 runtime, v3 delivery, v4 ui, v5 producers, v6 remote) as documented functions in that file; `alarmmanager_accept_producer_receipt()` is ingestion runtime logic and moved to `includes/ingestion.php`; the satellite schema files were deleted. The daemon entry point is `alarmmanager_daemon.php` (renamed from cli/supervisor.php) at the plugin root, the same place thold keeps thold_daemon.php and thold_process.php and Cacti keeps poller.php/cmd.php — core process scripts live in the plugin main folder, and cli/ keeps only the administrator/worker commands. with `service/alarmmanager.service` as the single shipped systemd unit; scheduled bounded mode and the signed-history sender are documented cron invocations only, without unit/timer pairs.

## Deployment-mode audit and CLI consolidation — 2026-10-13

Runtime deployment supports exactly two modes and no cron, thold-style: (1) poller-integrated — the plugin registers Cacti's `poller_bottom` hook (`setup.php` now registers four hooks, `includes/polling.php`) and drains bounded processing/delivery work inline at the tail of each poller cycle (500 jobs / 60 seconds per role; leases make coexistence with a resident daemon safe; each poller heartbeats one deterministic worker identity per role so `plugin_alarmmanager_workers` accretes one row per role instead of one per cycle, since the table has no pruning); (2) the resident daemon (`alarmmanager_daemon.php` + `service/alarmmanager.service`). This supersedes every earlier "scheduled bounded mode / cron invocation" statement in entries above: the scheduled-cron mode was removed because its only entry point would be a directly-invoked worker script, which contradicts this repository's own includes/ rule for internal subprocess helpers.

Consequently `cli/worker.php` moved to `includes/worker.php` (and `includes/supervisor.php` spawns it from there) — it is a runtime subprocess, like `includes/mail_child.php`, not an administrator command. Audit outcomes for the remaining CLI scripts: `cli/setup_local.php` deleted — it was a strict subset of `plugin_alarmmanager_install()`/`plugin_alarmmanager_upgrade()` (same key + schema calls) that skipped hook/realm registration; remote pollers are modeled by the integration suite (`tests/Integration/RemoteTest.php`) through the normal install/upgrade path, and `--provision`/`--configure` presuppose that installed schema/key rather than creating them. `cli/configure.php` deleted — config-only application is now `php cli/remote.php --configure=FILE` over the same `alarmmanager_apply_configuration()` the web uses, read through `alarmmanager_remote_private_read()` so it enforces the same owner-private file policy as every other remote.php input (the former script accepted any readable file including group/world-readable JSON carrying endpoint URLs).

This corrects the "Retained all ten entry points" rationale above: the remaining cli/ surface is six administrator commands (status, remote, submit_event, credentials, producers, endpoint) plus the root daemon, and README/WORKERS now describe the poller-integrated and daemon modes with no cron anywhere.
