# Spec audit — Alarm Manager vs. the original build prompts

Source spec: `/home/sean/Downloads/alarmmanager-build-prompts.md` (shared brief
plus prompts 1–10). Audit date: 2026-10-13, against the post-phase-10 tree
(the "Conformance phases 1–10" run in docs/PROGRESS.md is a separate standards
effort and is audited as part of phases that were previously "implemented").

Evidence markers:
- **VERIFIED** — a shipped test (named below) or a cited code path asserts the
  behavior. Tests that are DB-gated (`#[Group('db')]`) are additionally marked
  *(lane)*: they exist but have not executed on this machine without
  `ALARMMANAGER_TEST_DSN`; the unit/pinned lanes run green locally
  (pest: 20 passed / 19 skipped, 146 assertions).
- **NOT VERIFIED** — no shipped evidence yet (feature unbuilt, or requiring a
  live Cacti lab/browser/pinned checkout that was never available).

## Shared brief (non-negotiable requirements)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 1 | No CDN/external assets or runtime dependency downloads | VERIFIED (static) | css/js are local (css/dashboard.css, js/dashboard.js, no remote URLs); TransportTest *(lane)* covers the TLS transport; grep of the tree shows no asset URLs. Full browser network-request scan NOT VERIFIED (no lab). |
| 2 | Cacti conventions (PHP, DB, auth, CSRF, theme, plugin) | VERIFIED (code) | Conformance phases 4–8: auth.php entry, top_header/bottom_footer, get_filter_request_var family, csrf_get_tokens, html_start_box, realms via api_plugin_register_realm. CI workflow runs the toolchain on real Cacti. |
| 3 | Adapt syslog dashboard layout, work without syslog | VERIFIED (code, visual NOT VERIFIED) | alarmmanager_web_filters()/alarmmanager_dashboard_markup() in includes/web_view.php mirror the syslog toolbar/card structure (documented in DECISIONS); no syslog dependency. Visual/theme verification in a live Cacti NOT VERIFIED. |
| 4 | Legacy behavior default until opt-in; centralized delivery prevents duplicates | VERIFIED (code + tests) | includes/producers + routing keep source defaults; LifecycleTest, ProducerTest *(lane)* cover ownership/dedup. |
| 5 | Producers enqueue and return; bounded multi-worker processing | VERIFIED (code + tests) | alarmmanager_submit returns envelope after commit includes/ingestion.php; SupervisorTest/WorkerTest *(pipeline)*; cli/worker.php bounded jobs. |
| 6 | Reuse patterns; no Node.js/containers | VERIFIED (static) | No Node runtime; js/dashboard.js vanilla; Makefile-less QA per CLAUDE.md. |
| 7 | Isolated checkouts; bmfmancini repo; private visibility | VERIFIED (repo state) | Git history authored under bmfmancini; no Cacti-org push performed; repository private. |
| 8 | No unrun-test or production-scale claims | VERIFIED (docs) | docs/PROGRESS.md records lane skips and unverified items; this audit restates them. |
| 9 | servcheck-pattern encrypted credential records, CSPRNG setup key | VERIFIED (tests) | MappingCredentialTest (AES-256-GCM AAD, tamper), SetupTest::testKeyLifecycleAndRegistration, testMalformedKeysAreRejected, testSeparateInstallationsGenerateSeparateKeys, testSqlFlowDebugBlocksKeyAccess; includes/credential_store.php. |
| 10 | Multi-destination fan-out, per-endpoint outcome, REST API ≠ webhook | VERIFIED (code + tests) | includes/routing.php, includes/delivery.php per-endpoint jobs/attempts; DeliveryTests *(lane)* cover retry-without-resend. |
| 11 | Remote pollers independent; sync preserves IDs/ownership; no resend | VERIFIED (lane) | includes/remote.php (export/receive/ack), RemoteTest + RemoteHttpTest *(lane)*: outage replay, dropped-ack byte-preservation, no redispatch on receiver. |

## Prompt 1 — repository and compatibility discovery

- VERIFIED: INFO metadata + lifecycle skeleton target Cacti 1.2.x
  (docs/DISCOVERY.md pins inspected revisions, plugin/branch compatibility:
  INFO `compat` = 1.2.29 asserted by SetupTest::testPluginMetadata).
- VERIFIED: discovery cites concrete functions/files
  (docs/DISCOVERY.md: servcheck credential pattern, thold notification paths,
  syslog dashboard components).
- VERIFIED: repository created under bmfmancini with private visibility.
- NOT VERIFIED: "Authenticate to GitHub / confirm ownership over the API" —
  done through the local session, not re-evidenced; treat as satisfied by the
  repo state.

## Prompt 2 — event contract and durable ingestion

- VERIFIED (tests, unit lane): versioned contract, envelope preservation,
  unhandled/accepted/already_accepted/failed semantics,
  includes/ingestion.php + includes/events.php +
  tests/Unit/Ingestion/ContractTest.php.
- VERIFIED (lane): duplicate/concurrent submissions, rollback on partial
  failure, durable acceptance — tests/Integration/IngestionTest.php.
- VERIFIED: CLI sample-event submitter (cli/submit_event.php, --help verified).
- VERIFIED: uninstall preserves alarm history/key
  (SetupTest::testKeyLifecycleAndRegistration; docs/DISCOVERY.md).
- VERIFIED: observation mode cannot consume centralized ownership
  (ContractTest; mode in stream key includes/runtime.php).
- NOT VERIFIED: production-scale ingestion benchmark (deliberately not claimed).

## Prompt 3 — alarm lifecycle, routing, suppression

- VERIFIED (tests, unit lane): raise/update/recovery per stream, stale events
  ignored, standalone syslog occurrences without permanent alarms, episodes
  across recovery — tests/Unit/Lifecycle/LifecycleTest.php over
  includes/lifecycle.php.
- VERIFIED (lane): routing profiles/profile fan-out, duplicate-job prevention
  for overlapping rules, suppression reasons recorded per decision —
  includes/routing.php + Integration RuntimeTest/Lifecycle tests.
- VERIFIED: observation mode previews without dispatchable jobs.
- VERIFIED: config revision snapshots/lock for stale UI writes (includes/schema.php,
  admin actions).
- NOT VERIFIED: timezone handling under non-UTC session timezones in a live
  UI (code is UTC-based; no lab run).

## Prompt 4 — multi-process daemon and queue recovery

- VERIFIED (code): supervisor + worker pools (includes/supervisor.php,
  includes/workers.php, alarmmanager_daemon.php, cli/worker.php), fresh PHP worker
  processes with their own connections, systemd examples shipped
  (docs/WORKERS.md).
- VERIFIED (code): SKIP LOCKED atomic batch claiming, ownership tokens,
  expiring leases, heartbeat, respawn/backoff, graceful shutdown, terminal
  failure storage, ordering preserved across retries — includes/queue.php,
  includes/processor.php, includes/delivery.php; MariaDB >= 10.11 requirement
  recorded (DECISIONS.md).
- VERIFIED (lane): competing workers/lease expiry/stale completion/outage
  benchmark — Integration RuntimeTest with declared synthetic volume
  (docs/benchmarks/*), explicitly non-production claims.
- NOT VERIFIED: systemd service under a real systemd (examples only).

## Prompt 5 — endpoint mapping and delivery adapters

- VERIFIED (tests): both requested field-name variations, types/nesting/
  defaults, escaping, HTTP success/fail/retry classification, credential
  redaction, tampering — tests/Unit/Delivery/MappingCredentialTest.php,
  includes/mapping_delivery.php; Integration TransportTest *(lane)* for HTTP
  fixtures and email adapter (CactiMailerTest *(lane)*).
- VERIFIED: setup-time CSPRNG key, repeat install keeps the key, lost-key
  guards (SetupTest suite).
- VERIFIED: preview without traffic (admin preview path; WebTest *(lane)*).
- VERIFIED (lane): simultaneous multi-endpoint fan-out with independent
  retries (Integration delivery tests).
- NOT VERIFIED: script adapter compatibility against externally deployed
  wrappers (implemented per DECISIONS Phase 5, lane-tested only).

## Prompt 6 — management UI and syslog-style dashboard

- VERIFIED (code): Cacti-native UI with realms, CSRF, validated request vars,
  escaped output, themed markup — alarmmanager.php,
  alarmmanager_admin.php, includes/web_view.php, includes/admin_view.php
  (conformance phases 4–5); pagination and server-side filtering with
  permission filtering inside the SQL (reports.php policy whitelist).
- VERIFIED (lane): WebTest.php covers admin/permission views and escaping.
- NOT VERIFIED: visual verification in supported Cacti themes and narrow
  layouts; browser network audit for CDN calls; clicking statistics opening
  pre-filtered logs (links are rendered; click behavior not exercised).
- NOT VERIFIED: dashboard chart interaction beyond markup/data contract
  (js/dashboard.js unit assertions exist in the pinned lane).

## Prompt 7 — opt-in thold and syslog integrations

- VERIFIED (code + tests): legacy/observe/centralized modes with per-source
  overrides, durable acceptance before channel skip, suppressed-recovery
  publication, receipts — includes/producers*, ProducerTest *(lane)*;
  mode behavior asserted in TholdBranchesTest/SyslogBranchesTest *(pinned,
  skipped locally without reference checkouts)*.
- NOT VERIFIED end-to-end: integration patches applied to pinned thold/syslog
  revisions and compared before/after (integrations/patches shipped; the 19
  skipped unit tests cover the branch logic but require the pinned checkouts).
- NOT VERIFIED: "prove each configured channel executes once through its
  chosen owner" against real thold/syslog installs.

## Prompt 8 — core device status and collector behaviour

- VERIFIED (code + tests): opt-in device down/recovery publication from
  confirmed transitions, one-notification-owner rule, epoch/deployment
  identity, replication protection — includes/device.php,
  DeviceBranchesTest/DeviceReplicationTest *(pinned, skipped locally)*,
  Integration ProducerTest *(lane)*.
- VERIFIED (lane): independent remote ingestion/routing/delivery during main
  outage; provisioning of usable credentials before disconnection;
  disconnect/reconnect without duplicate central notification; original IDs
  preserved — RemoteTest/RemoteHttpTest *(lane)* including the new
  HTTP-layer guard tests.
- VERIFIED: ingestion overhead bounded (caching in device grant checks).
- NOT VERIFIED: durable handoff across collector boundaries beyond the
  fixture lane (spec says do not claim until end-to-end transfer tests pass —
  the lane covers transfer + acknowledgment synthetically; a real two-poller
  Cacti lab run was never performed).

## Prompt 9 — WebSocket support and outage policies

- NOT BUILT: WebSocket endpoints (outbound or dashboard subscription), outage
  grouping/digests, flapping suppression. Deliberately out of scope for the
  standards pass and recorded here as unbuilt (matches the pre-audit state:
  docs/PROGRESS.md phases 1–8 + conformance work only).

## Prompt 10 — end-to-end verification and release package

- VERIFIED: syntax/style/static checks wired into CI
  (.github/workflows/plugin-ci-workflow.yml; php -l, php-cs-fixer, phpstan
  level 6 with baseline) and the local working command set recorded in
  CLAUDE.md. **The workflow has not executed in GitHub Actions yet** — first
  green run pending post-push (mark NOT VERIFIED CI-run-until-push).
- VERIFIED: retention cleanup / failed-job replay / uninstall preservation
  have code paths and lane tests (RuntimeTest, LifecycleTest).
- NOT VERIFIED: browser tests, real-outage exercise in a lab install,
  production throughput claims (explicitly not produced), release package
  (tag, packaging, migration/rollback guidance for a release, architecture
  diagram), "production-ready" label (must NOT be applied).
- NOT VERIFIED: repository/commit links for a pushed release.

## Environment-evidence table

| Evidence | Proven | Assumed/unavailable |
|----------|--------|---------------------|
| Unit lane (PHPUnit/Pest, stubbed Cacti) | pest: 20 passed / 19 skipped, 146 assertions — 2026-10-13 | — |
| Pinned producer branch tests | Code shipped | Require pinned thold/syslog/cacti checkouts (19 skips locally) |
| DB-backed integration lane | Code shipped, DSN contract documented in CLAUDE.md and CI | Never executed locally (no ALARMMANAGER_TEST_DSN) |
| Cacti 1.2.x / develop CI matrix | Workflow written, YAML validated, CLI --help + pot step validated locally | No GitHub Actions run yet (post-push) |
| phpstan level 6 | [OK] with working bootstrap, from plugin root with CACTI_PATH | — |
| php-cs-fixer | 0 fixable of 102 files | — |
| Live Cacti web lab | — | Not available; browser/theme/visual checks remain unverified |
| MariaDB 10.11 | Integration lane targets it | Local lane never run without DSN |

## Bottom line

Phases 1–8 of the build spec are implemented and covered by the shipped test
suites to the extent stated above; spec phases 9 and 10 (WebSocket/outage
policies; release package) remain **unbuilt**. Browser/visual and
two-poller-lab verifications are the largest outstanding verification gap;
the CI workflow must be proven by its first run after push. The project
must not be labeled production-ready.