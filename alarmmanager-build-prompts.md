# Alarm Manager — focused build prompts

Run these prompts in order, in the same project workspace. Paste the shared brief once, then run one phase at a time. Each phase must produce working code or concrete repository artifacts and verify its acceptance checks before moving on.

## Shared brief — paste once

You are implementing Alarm Manager, a Cacti plugin for centralized alarm history, lifecycle management, notification filtering, routing, and delivery. Cacti, thold, and syslog retain their detection logic and can opt into centralized delivery.

Project identity:
- GitHub owner: **bmfmancini**, never the Cacti organization.
- Repository: `bmfmancini/plugin_alarmmanager`.
- Display name: **Alarm Manager**.
- Installed plugin directory: `alarmmanager`.
- PHP functions, settings, and hook names: `alarmmanager_`.
- Submission hook: `alarmmanager_submit`.
- Database tables: `plugin_alarmmanager_`.
- Namespace classes and browser code explicitly to avoid collisions.

Source references:
- https://github.com/Cacti/cacti
- https://github.com/Cacti/plugin_thold
- https://github.com/Cacti/plugin_syslog
- https://github.com/Cacti/plugin_servcheck (credential storage and replication reference)

Non-negotiable requirements:
1. No CDN dependencies, externally hosted fonts/icons, or runtime dependency downloads. Dependencies must be shipped locally with their licenses and pinned versions, or reused from the supported Cacti installation. User-configured notification endpoints are intentional external calls and remain supported.
2. Follow Cacti's PHP, database, authentication, authorization, CSRF, theme, and plugin conventions. Read applicable repository instructions. Explicit user requirements take precedence where dependency packaging guidance conflicts.
3. Adapt the existing syslog dashboard layout rather than inventing another design. It must work without syslog being installed.
4. Existing email, scripts, and notification behaviour remain the default until a producer opts in. Centralized delivery must prevent duplicate notifications.
5. Producers durably enqueue and return; they never wait for external notification delivery. Processing uses bounded memory and multiple worker processes.
6. Reuse existing configuration and deployment patterns where they fit. Do not introduce Node.js as a runtime requirement or require containers.
7. Work in isolated local/lab checkouts. Do not change a production installation or publish changes to the Cacti organization. Keep upstream integrations in separate branches or patch files. Creating and pushing this new repository under bmfmancini is authorized; preserve an existing repository's visibility, or use private visibility for a new one until explicitly requested otherwise.
8. Do not claim unrun tests or production-scale throughput. Report concrete evidence and remaining limitations.
9. Follow plugin_servcheck's encrypted credential-record storage pattern and Cacti settings integration. Inspect its real code first; do not reuse its key or copy timestamp-derived key generation. Generate a cryptographically random, plugin-specific key during setup, preserve it across upgrades/restarts, and fail closed on missing or malformed keys. Document backup, rotation, and poller provisioning.
10. Support multiple destinations per alarm: a routing profile can fan out to a REST API, webhook, email, script or WebSocket endpoint together. Each endpoint has its own mapping, credentials, delivery job, retry state and outcome; one endpoint failure must not block or resend successful deliveries to other endpoints. REST API and webhook are separately configurable HTTP destination types.
11. Remote pollers must independently ingest, evaluate, route, and deliver alarms using their local database and worker pools while disconnected from the main poller. Central history synchronization must retain original event IDs and delivery ownership/results, and must not resend notifications already delivered remotely.

Maintain `docs/IMPLEMENTATION_PLAN.md`, `docs/DECISIONS.md`, and `docs/PROGRESS.md`. Every phase should read these documents, implement its assigned scope, run relevant checks, and update progress with changed files, test commands/results, and remaining work. Continue routine work autonomously; ask only for missing information that actually blocks implementation.

## Prompt 1 — repository and compatibility discovery

Read the shared brief and inspect the actual local Cacti, thold, syslog, and servcheck code before designing integration points. If necessary, obtain isolated checkouts from the source repositories. Read applicable AGENTS.md and development instructions.

Authenticate to GitHub, confirm the owner is bmfmancini, and create or reuse `bmfmancini/plugin_alarmmanager`. Never create it under Cacti. Preserve existing files and repository visibility; default a new repository to private. If remote creation is unavailable, prepare the local repository and report the precise blocker without claiming creation succeeded.

Determine the supported Cacti branch/version, PHP version, database requirements, and local test environment from real code. Inspect plugin lifecycle, realm registration, hook return behaviour, and remote collector handling. Trace core device status transitions, thold notification queues/actions, and syslog alert logging/suppression/delivery. Inspect syslog's actual dashboard PHP, CSS, and local chart assets. Pin inspected revisions in documentation; do not assume old filenames or hook names still apply.

Create the plugin skeleton, INFO metadata, license/attribution, development README, and implementation/decision/progress documents. Document the compatibility target and proposed file structure. Inspect servcheck_encrypt_credential(), servcheck_decrypt_credential(), key storage, and replication. Document how Alarm Manager follows the storage pattern with setup-time secure key generation. Discover the remote bootstrap/replication boundaries needed for independent local alarm processing. Register no automatic notification interception yet.

Acceptance: repository ownership is correct, discovery cites concrete functions/files, plugin metadata and lifecycle skeleton fit the target Cacti version, and subsequent phases have a reproducible test setup. Do not build queues or transports in this phase.

## Prompt 2 — event contract and durable ingestion

Implement the versioned alarm event contract and `alarmmanager_submit` hook. Preserve the request envelope so it works with Cacti's hook return semantics. No handler means `unhandled`; never infer acceptance from a truthy return or plugin installation alone.

Include stable event ID, source and source instance, source rule/resource ID, alarm correlation key, transition, severity, occurred/received times, collector identity, safe device metadata, summary/details, and source context. Separate alarm identity from event identity. Support raised, updated, reminder, recovered, and standalone occurrence events. Use explicit ordering/episode information so stale events cannot reverse newer state. Do not ingest whole host records containing credentials.

Implement idempotent schema migrations, indexed InnoDB tables, and transactional insertion of the event plus its processing job. Return `accepted` only after commit; distinguish `already_accepted`, `unhandled`, and `failed`. Handle duplicate IDs and database failures correctly. Observation mode records/evaluates without external delivery; ensure observation submissions cannot accidentally consume future centralized-delivery ownership.

Provide a CLI sample-event submitter and document the producer contract. Keep ingestion independent of network transports and expensive routing work.

Acceptance: tests cover unavailable/disabled plugin, invalid input, duplicate and concurrent submissions, rollback on partial failure, durable acceptance, and observation/centralized mode semantics. Install/upgrade are repeatable, and uninstall cannot silently destroy alarm history. Do not implement the daemon yet.

## Prompt 3 — alarm lifecycle, routing, and suppression

Implement event processing using the contract from phase 2. Separate immutable event history, current alarm/episode state, routing decisions, delivery jobs, and delivery attempts.

Apply raised/update/recovery transitions atomically per alarm stream. Standalone syslog occurrences must not create permanent active alarms. Separate condition state from acknowledgment and notification state. Handle stale/out-of-order events and recovery followed by a new outage without closing the wrong episode.

Build centrally managed routing profiles and ordered rules. Each matching profile can select multiple destinations (for example REST API plus webhook). Create one idempotent job per event/destination with independent outcomes; overlapping rules selecting the same destination must not duplicate delivery. Initially support source, severity, transition, device/collector identifiers, tags, and time schedules. Define whether rule processing stops or continues, what happens when no rule matches, and how multiple matching rules avoid duplicate delivery jobs. Define timezone handling explicitly.

Add notification suppression for maintenance, acknowledgment, cooldown, and duplicate policies, recording the rule/reason for every decision. Detection remains in thold/syslog. Source suppression and central suppression must have clear precedence; suppressed events are visible only when producers actually publish them. Observation mode previews decisions without creating dispatchable jobs.

Acceptance: tests cover raise/recover/new episode sequences, stale events, standalone occurrences, overlapping routing rules, no matching route, maintenance, acknowledgment, cooldown boundaries, and observation mode. Processing the same event again must not duplicate state changes or delivery jobs. Do not add network adapters here.

## Prompt 4 — multi-process daemon and queue recovery

Implement a CLI supervisor and configurable alarm-processing and delivery-worker pools. Provide systemd service examples and a bounded scheduled CLI/poller mode for installations that cannot run a resident daemon. Run the same supervisor and worker pools on each configured remote poller against its own local queue; main-poller connectivity must not be required for local processing/delivery. Do not fork with inherited live database connections; workers must establish their own connections.

Use atomic batch claiming appropriate to the supported MariaDB/MySQL versions. Do not use SELECT-then-UPDATE without transaction/locking protection. Jobs need ownership tokens, expiring leases, bounded batches, and crash recovery. Every completion must verify current ownership so an expired worker cannot finalize reclaimed work. Ensure leases cover processing and blocking transport time; do not let queued batch members expire before they start.

Implement worker heartbeats, supervisor respawn/backoff, graceful shutdown, controlled lease reclamation, delayed retries, bounded attempt counts, and terminal failed-job storage. Preserve per-alarm/per-endpoint raise/recovery order across retries. Apply priority with fairness and per-endpoint concurrency/rate limits across all workers, not just within one process. A broken endpoint must not monopolize capacity.

Expose queue depth, oldest pending age, worker health, throughput, and retry counts. Make configuration reload and plugin-disable behaviour explicit.

Acceptance: database-backed tests exercise competing workers, worker termination, lease expiry, stale completion, slow endpoints, retries, ordering, shutdown, and bounded memory. Include a reproducible synthetic outage benchmark with declared event volume and environment. Do not claim production capacity from it.

## Prompt 5 — endpoint mapping and delivery adapters

Implement endpoint-specific payload mappings plus independently configured REST API and webhook destinations, existing Cacti email, and controlled script adapters. REST API and webhook can share an HTTP transport while retaining endpoint-specific method, URL, headers, mapping and response-success criteria. Support simultaneous delivery to multiple selected endpoints. Dispatch only through the phase 4 delivery workers.

Expose safe normalized fields such as `device.id`, `device.hostname`, `alarm.severity`, and `alarm.summary`. Users must be able to map `device.id` to `hostname` for API 1 and `host` for API 2 without writing code. Support nested JSON destinations, constants, explicit value conversion, severity/status mappings, required fields, missing-value defaults, and different raise/recovery payloads. Reject ambiguous/conflicting mappings. Encode JSON structurally; never evaluate PHP or construct JSON through raw string substitution.

Store endpoint secrets as encrypted credential records following the inspected servcheck pattern. Generate plugin-specific keys at setup with a CSPRNG, not on first credential save. Use versioned authenticated ciphertext, strict decoding and fail-closed error handling. Never overwrite a valid existing key during setup/upgrade; do not create a replacement key when encrypted records exist and the key is missing. Keep keys out of source control, previews, responses and logs. Define backup/restore, explicit rotation and provisioning of usable credentials/keys to each authorized remote poller; replicating ciphertext alone does not make credentials usable.

HTTP endpoints need configurable method/path, authenticated headers, TLS verification, bounded timeouts, success criteria, retry classification, Retry-After handling, and stable idempotency keys. Prevent credential leakage through logs, redirects, previews, or error responses. Allow explicitly configured internal destinations rather than imposing a blanket block on private addresses.

Preserve email formatting, recipients, and attachments through adapters. Define script compatibility against actual existing wrapper behaviour; use structured arguments/environment, bounded runtime/output, and explicit administrator configuration. Scripts may have side effects and cannot be assumed idempotent.

Provide preview and explicit test-send actions; preview must never send traffic. Record attempts with redacted diagnostics and sanitized response summaries.

Acceptance: tests prove both requested field-name variations, types/nesting/defaults, escaping, HTTP success/failure/retry behaviour, simultaneous REST API plus webhook fan-out, independent retries without resending successful destinations, credential redaction, setup-time key generation, repeat setup preserving keys, missing/wrong/malformed keys, ciphertext tampering, backup/restore, and email/script compatibility. No delivery performs a dependency download or calls a CDN.

## Prompt 6 — management UI and syslog-style dashboard

Build the Cacti-native management UI for endpoints, mappings, routing profiles/rules, alarm history/current alarms, delivery attempts, worker/queue health, and authorized failed-job replay. Use Cacti realms, CSRF protection, input validation, escaping, and existing theme/navigation conventions. Restrict endpoint credentials, test sends, and configuration to administrators.

Adapt the actual `plugin_syslog` dashboard layout: shared time-range controls, refresh controls, responsive panel grid, card headers, and locally bundled chart conventions. Preserve license attribution. Reuse Cacti assets only where the supported version guarantees availability; otherwise package dependencies locally. Do not depend on syslog being installed. Do not silently reduce dashboard reuse to a few unrelated statistic cards; document which existing layout/components were adapted.

Display raised/recovered events, active conditions, severity/source breakdowns, delivery success/failure/retries, queue depth/age, suppression reasons, endpoint performance, and worker health. Distinguish unique alarm conditions, event occurrences, delivery jobs, and attempt counts. Current-state metrics should be labeled separately from historical time-window metrics. Clicking statistics should open matching filtered logs.

Use indexed, server-side filtered/paginated queries and bounded chart aggregation. Never pull the full history into PHP/browser memory. Enforce visibility permissions in dashboard aggregates as well as detailed logs; permission filtering cannot occur only after LIMIT.

Acceptance: verify visually in supported Cacti themes and narrow layouts, exercise admin/read-only permissions and escaping, and inspect browser network requests to confirm no external asset/CDN calls. Document the dashboard components reused and the measured query behaviour.

## Prompt 7 — opt-in thold and syslog integrations

Implement separate integration branches or reproducible patches against the revisions discovered in phase 1. Keep these outside unrelated upstream changes and store patch/application instructions with Alarm Manager. Do not push to the Cacti organization.

Expose integration controls when Alarm Manager is installed and enabled. Provide legacy (default), observe, and centralized modes with a plugin-wide default and per-threshold/template or syslog-rule overrides where appropriate. Allow selecting a routing profile. Preserve configured choices when Alarm Manager is unavailable and show their effective fallback state.

Publish normalized events before existing notification side effects, not through a post-delivery hook. In the thold path previously inspected, `thold_action` followed email/command execution: re-check the target code and do not assume that hook transfers ownership. For centralized mode, skip only the notification channels explicitly handed off, and only after durable acceptance or confirmed prior acceptance. Continue source state updates, local logging, and other required processing.

On unavailable/failed submission, preserve the documented legacy fallback. After confirmed acceptance, Alarm Manager owns retries; later delivery failure must not independently trigger source delivery. Preserve existing acknowledgment, maintenance, repeat, recipient/template/attachment, ticket, and script semantics or document explicit migrated ownership. Persist/reuse event IDs across submission retries. Publish suppressed source events with metadata if complete history is enabled.

Acceptance: compare legacy behaviour before/after; test all modes, missing/disabled plugin, ingestion failure, accepted-but-delivery-failed events, raise/recovery, and observation suppression. Prove each configured channel executes once through its chosen owner. No detection rewrite belongs in this phase.

## Prompt 8 — core device status and collector behaviour

Implement opt-in Cacti device down/recovery publication from confirmed status transitions, using failure/recovery counts already maintained by Cacti. Do not turn every failed raw ping into an alarm, add per-sample network work, or assume an existing hook fires without tracing it. Package required core changes as a separate branch/patch.

Define one owner for device status notifications: core Cacti or thold's existing device notifications. Keep threshold alarms owned by thold. Configuration and migration must prevent both paths notifying the same outage. Derive stable device/alarm/episode identity across collectors and collector reassignment.

Define deployment behaviour for remote collectors explicitly: local durable ingestion/outbox, original event IDs and order retained during replay, central transfer acknowledgments, duplicate handling, and a chosen delivery owner. Independent remote processing and delivery are required capabilities, enabled through explicit deployment configuration with clear destination ownership. Local ingestion, lifecycle evaluation, routing and endpoint delivery must work while the main poller is unavailable; reconnect must not deliver the same notification again centrally. Replicate configuration separately from poller-local operational state, provision usable endpoint credentials before disconnection, and protect local queues/keys from destructive replication. Central history reconciliation must record delivery decisions/results and original event identities rather than recreate dispatchable jobs. Validate the target Cacti remote-plugin capability and hook execution rules instead of assuming capabilities alone make a new hook work remotely.

Measure ingestion overhead on status transitions and avoid database/schema/config probes for every polled sample. Document what happens on local database failure and which failure boundaries the outbox can actually recover from.

Acceptance: tests cover confirmed down/up, transient failures, duplicate core/thold sources, independent remote ingestion/routing/delivery during main-poller outage, remote key/credential provisioning, remote disconnect/reconnect, duplicate replay, stale recovery/new outage, and collector identity. Do not claim durable handoff across collector boundaries until end-to-end transfer and acknowledgment tests pass.

## Prompt 9 — WebSocket support and outage policies

Implement the agreed WebSocket capability explicitly. Distinguish an outbound WebSocket notification endpoint from a browser subscription to the live alarm dashboard. Do not present server-sent events or periodic polling as completed WebSocket support.

Use the daemon/service layer for long-lived connections, with locally packaged dependencies and documented reverse-proxy/service setup. Define authentication, authorized subscriptions, TLS, reconnect backoff, heartbeat/liveness, bounded buffers, and slow-consumer backpressure. Use persisted event cursors for replay where applicable; reconnect cannot simply discard events.

For outbound WebSocket delivery, document the receiver's acknowledgment contract. Sending a frame does not prove durable acceptance. Define delivery completion, retries, idempotency, and receiver timeout behaviour; distinguish best-effort feeds from acknowledged notification endpoints. Integrate destinations with existing mapping profiles and centralized worker accounting.

Add optional outage grouping/digests and flapping suppression using deterministic policies. Preserve individual events and explain why each notification was grouped or suppressed. Do not introduce opaque automatic root-cause correlation that hides child alarms. Ensure these policies can be disabled and cannot silently suppress recoveries.

Acceptance: test disconnected/slow clients, authorization boundaries, reconnect/replay, bounded buffering, acknowledged versus best-effort delivery, and an outage burst alongside HTTP/email traffic. Document exact transport guarantees and verify all assets/dependencies remain local.

## Prompt 10 — end-to-end verification and release package

Complete a release-readiness review of the implemented plugin and integrations. Fix defects found by validation; do not substitute documentation for missing working features.

Run PHP syntax/style/static checks appropriate to the chosen compatibility target, unit tests, database-backed queue/concurrency tests, transport tests, and browser tests. Verify install, enable/disable, upgrade, restart, retention cleanup, failed-job replay, and uninstall/data preservation. Verify all request mutations require appropriate realms and CSRF protection, and all configuration/secrets are redacted from ordinary views/logs.

Run a reproducible outage exercise: publish a declared burst from Cacti/thold/syslog, make one endpoint slow or unavailable, kill workers mid-delivery, restore the endpoint, and verify queue recovery, event durability, per-stream ordering, suppression history, and duplicate effects. Report ingestion throughput, drain throughput, peak memory, queue age, database contention, and tested worker configuration. Separate synthetic results from production claims.

Scan code and browser requests for CDN/external asset references. Include pinned dependency licenses, installation instructions, service configuration, field-mapping examples, the producer hook contract, integration patches with tested revisions, migration/rollback guidance, backup/retention instructions, and an architecture diagram. Document test coverage and remaining limitations honestly.

Push reviewed commits and the release package to **bmfmancini/plugin_alarmmanager**, preserving the agreed visibility. Do not open or merge upstream Cacti PRs as part of this task.

Acceptance: provide repository/commit links, exact checks and results, a feature-completion matrix, and a lab rollout checklist. Mark untested integrations as unverified; do not label the project production-ready while required phases are incomplete.
