# Progress

## Phase 1 — 2026-10-13 UTC

Implemented: INFO/lifecycle skeleton, authenticated placeholder page and view realm, plugin-specific CSPRNG setup key using explicit settings_user owner 1, strict decoding and persistent-key readiness, GPL license/attribution, translation template, pinned integration discovery, implementation/decision/test-setup documents, and updated focused prompts.

New requirements recorded: servcheck-style encrypted credential storage with setup-time keys; independent remote alarm processing and delivery; simultaneous REST API and webhook destinations with independent delivery/retry state.

Validation actually run on PHP 8.3.6 CLI:

- PHP syntax check: all five PHP files pass.
- `php -n tests/Unit/Plugin/SetupTest.php`: PASS, 21 checks using in-memory database/framework doubles.
- Reviewed remote repository identity and visibility through GitHub: bmfmancini/plugin_alarmmanager, private.
- Inspected pinned Cacti 1.2.x/develop, thold, syslog and servcheck code/instructions. No upstream writes.

Key checks cover first/repeated setup, 32-byte length, strict malformed-input rejection, upgrade/uninstall preservation, missing/malformed key readiness failure, failed storage, separate-installation randomness and absence of producer hooks. SQL flow tracing blocks secret reads/setup to avoid core debug output disclosure.

Acceptance status: discovery and skeleton complete; offline checks pass. Live Cacti install/enable/disable/uninstall, browser/auth behaviour, actual DB concurrency, PHP 8.2 execution and remote qualification remain unverified. A reproducible lab checklist is in TEST_SETUP.md. Phase 1 is not a working alarm system or production release. No event schema, queue, transport or producer interception was added.

Next phase: versioned event contract and transactional durable ingestion. Before persistent credential tables land, add missing-key-over-existing-data guards and framework-uninstall history preservation. Subsequent worker and replication phases must establish real offline delivery and reconciliation guarantees; INFO flags alone are not proof.

## Phase 2 — 2026-10-13 UTC

Implemented version 0.2.0: contract validation/normalization, functional alarmmanager_submit envelope handler, schema v1, transactional event/job ingestion, explicit durable acknowledgment, conflicting-ID rejection, mode-separated observation identity, local processing-owner metadata, bounded sample JSON CLI, automatic version-gated phase 1 upgrade, preserved uninstall data and missing-key-over-encrypted-record safeguards. No producer integration, delivery transport or processing daemon was added.

Validation actually run on PHP 8.3.6:

- Syntax: all 12 PHP files pass.
- Setup: 22 checks pass with in-memory framework/database doubles.
- Contract: 29 checks pass, including allowed transitions, identifiers/limits, credential-field rejection, timezone/date validity and canonical tag ordering.
- MariaDB 10.11.14/InnoDB: 30 database checks pass using real PDO storage with Cacti-compatible helper adapters. Eight separate processes submitting one identity result in exactly one accepted insert, seven already_accepted responses, one event and one job.
- Real missing-job-table failure rolls back the event; injected unconfirmed commit returns failed and rolls back; a producer-owned transaction remains open. Observation then centralized submission creates independent identities. Disabled/missing installation returns unhandled; database failure returns failed. Duplicate retries do not reopen completed jobs. Plugin uninstall preserves events and key.
- CLI --help runs successfully outside Cacti; actual installed CLI dispatch remains unverified.

Remaining qualification: full Cacti lifecycle/hook dispatch/browser permissions, PHP 8.2, MySQL, remote bootstrap/replication, daemon crash durability/ordering and endpoint delivery. No external delivery guarantees or production capacity are claimed. Core source no-handler semantics were inspected, not exercised through a running Cacti installation.

Next phase: apply episodes and ordered state, build routing/suppression and idempotent per-destination delivery jobs (REST API plus webhook fan-out), keeping observe jobs non-dispatchable.

## Phases 3–4 — 2026-10-13 UTC

Implemented version 0.4.0: schema v2 migration; ordered stream/episode state; acknowledgment-aware suppression; bounded versioned routing configuration and history; immutable decisions; independent per-endpoint delivery jobs; fenced queue leases, retries and attempts; local offline CLI bootstrap; fresh-process supervisor, reload and graceful shutdown; diagnostics and lab service example. Production adapters remain phase 5; no external messages were sent. Upstream producer code is unchanged.

Final verification after recovering the disconnected workspace: all PHP syntax checks passed; 22 setup, 30 event contract, 26 lifecycle/routing and 11 supervisor checks passed. Real MariaDB checks: 30 ingestion (including eight competing submitters), 61 lifecycle/queue (including configuration history, actual worker termination, stale fencing, independent retries, deadline, graceful completion, concurrency, aging, deadlock retry and failed-stream isolation). These database tests use Cacti-compatible PDO adapters, not a full Cacti bootstrap. The captured 2,000-event/4,000-job fixture benchmark is documented in benchmarks/README.md.

Remaining qualification: full Cacti installation/upgrade/browser behavior, PHP 8.2 execution, live remote main-server outage and local configuration provisioning, production transports and endpoint idempotency, MySQL compatibility and reconciliation without redispatch. Main outage infrastructure is implemented locally; complete autonomous remote delivery is not yet demonstrated. Next phase: real transport/mapping and encrypted credential adapters with setup-key backup/rotation/provisioning.

## Phase 5 — 2026-10-13 UTC

Implemented version 0.5.0/schema v3: bounded structural endpoint mappings with nested JSON, constants/defaults, conversions, value maps and transition-specific payloads; separate REST API/webhook HTTP configuration; AES-256-GCM endpoint-bound credential records using the existing setup key; explicit private-file/stdin save, backup, transactional key rotation and restore/provisioning CLI; TLS-verified HTTP with self-signed/private-CA trust files, status/JSON success criteria, bounded response/deadline, Retry-After and no redirect/proxy/netrc credential forwarding; controlled script argv/stdin/environment/group cleanup; Cacti email in a bounded fresh offline child; traffic-free redacted preview and explicitly queued single-destination test sends. Workers refuse starting external effects when the claim no longer has a usable lease. No producer interception or external production destination was exercised.

Verification actually run on PHP 8.3.6, cURL 8.5.0 and MariaDB 10.11.14: syntax passed for all 47 PHP files and Python fixtures; all JSON files parse and the delivery example validates; user CLI help checks passed. Test assertions: 22 setup, 30 contract, 26 lifecycle/routing, 36 mapping/credential, 11 supervisor, 30 real DB ingestion, 61 real DB lifecycle/queue, 73 real DB/HTTP/TLS/script/email-boundary and 7 pinned Cacti mailer/loopback SMTP/deadline checks — 296 total.

Transport checks include authenticated self-signed HTTPS, untrusted and wrong-host rejection, POST/PUT/PATCH/DELETE, response/status criteria, redirect/body/time limits, field-name variations, fan-out and independent actual HTTP retry without sibling resend, stable idempotency, worker-only test requests, expired-worker no-send, wrong/missing/tampered credentials, transaction rollback on failed rotation, matching backup restore, v2 migration preserving key, ciphertext-only remote failure and usable credentials in a separate local database. Script tests exercise literal argv/stdin, filtered environment, compatibility arguments, retry/permanent exits, output limits and ordinary descendant termination. Actual pinned Cacti send_mail/bundled PHPMailer preserves multiple recipients, HTML and attachment content through loopback SMTP; a fixture offline bootstrap is used, not the full installer.

Remaining qualification: PHP 8.2, deployed Cacti plugin lifecycle/browser behavior, real SMTP/sendmail deployment, real remote poller/main outage/reconnect and reconciliation, MySQL and production receiver idempotency/load. No automated cross-poller credential replication is added. Scripts use the documented structured contract; legacy thold/syslog shell-template and source-specific numeric severity/context parity require reviewed phase-7 producer wrappers. Next phase: Cacti-native administration and dashboard, realms/CSRF, configuration/test actions and failure replay.
