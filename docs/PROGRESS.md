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
