# Phases 3–4: interrupted workspace checkpoint

This branch contains a status report and captured benchmark evidence, **not the phase 3–4 implementation**. Main remains at phase 2 commit 8ed2b5afaf2b897b5158412598895ca083bb1012.

The implementation was written and tested in the active workspace, but the execution environment disconnected before source capture, final documentation, final regression verification and commit. Do not deploy this branch or treat the work as merged/released. Reconnect the original workspace before completing the steps below.

## Implemented in the workspace

- Schema v2 and upgrade/backfill of existing event/job rows; InnoDB durable tables remain outside Cacti's destructive plugin_db_changes registry.
- Current condition state, episode history, acknowledgment independent of condition state, stale/equal sequence rejection, old recovery rejection, occurrence events without permanent active episodes.
- Shadow observation state/decisions, without delivery jobs or changes to live conditions.
- Bounded routing configuration with multiple destination profiles (REST API plus webhook), ordered rules, first-endpoint policy ownership, explicit stop/continue and no-route outcomes.
- Source/maintenance/acknowledgment/cooldown/content-duplicate suppression with recorded reasons; recovery exemptions for acknowledgment/cooldown/duplicate policies.
- Immutable local configuration snapshots referenced by routing decisions (added immediately before disconnect; final regression rerun still required).
- Per-endpoint jobs, stable idempotency keys, independent retry outcomes and stream/destination ordering.
- Transactional SKIP LOCKED claims, one immediately started job per worker, random ownership tokens, expiring leases, fencing, bounded reclamation, retries and terminal failure storage.
- Terminal processing failures block only their own later stream events; unrelated streams continue.
- Shared endpoint concurrency/rate accounting across workers using one local queue; priority with aging and fair endpoint rotation.
- Processing and delivery worker loops, heartbeats and CLI queue/worker status.
- Supervisor starts fresh PHP processes without an inherited DB connection; bounded pools, crash backoff, valid/invalid SIGHUP reload, graceful shutdown and forced cleanup.
- Worker, configure, status, submit and local-setup CLIs force Cacti conn_mode=offline before bootstrap to avoid main-poller DB contact.
- systemd service and bounded scheduled CLI examples.
- MariaDB >=10.11 is the supported backend for this build; unqualified MySQL/older MariaDB are refused at setup.

## Last observed successful checks

PHP 8.3.6, MariaDB 10.11.14, isolated test databases and PDO-backed Cacti-compatible helpers:

- 22 setup checks.
- 29 contract checks at the last observed output; one additional routing-profile type test was subsequently added and needs rerun.
- 26 lifecycle/routing contract checks.
- 11 real-process supervisor checks: fresh children, crash backoff, reload, invalid reload, graceful shutdown and bounded escalation.
- 30 database ingestion regression checks before the final phase 3–4 refinements; rerun against the final source.
- 60 real database lifecycle/queue checks: fan-out, overlapping rules, stale episodes, suppression, observation, stream ordering, independent endpoint retries, rate/concurrency bounds, four competing claim processes, worker kill, reclamation, stale completion, timeout, graceful in-flight shutdown, aging, bounded transaction contention retry and blocked-stream health.
- A configuration-snapshot assertion was added after that 60-check result; its final run could not be retrieved after disconnect.
- A 2,000-event synthetic burst completed 2,000 processing jobs and 4,000 fixture delivery jobs through four processes in each pool. Per-worker PHP peak memory was 2 MiB; one DB deadlock was handled by bounded transaction retry, with zero processing-job retries and no remaining queue age. See benchmarks/outage-2000.json.

Benchmarks use distinct alarm streams and two successful in-code fixtures with a 1-ms artificial delay. No network, email, scripts or upstream detectors ran. DB buffer pool was 64 MiB; native AIO was unavailable. PHP memory excludes the DB and OS cache. These results do not establish production throughput or full Cacti/remote-poller correctness.

## Workspace files to recover

The project directory is plugin_alarmmanager. New or changed modules include:

- includes/runtime.php, schema_runtime.php, lifecycle.php, routing.php, processor.php, queue.php, delivery.php, workers.php, supervisor.php
- lib/AlarmManager/Exceptions.php
- cli/worker.php, supervisor.php, configure.php, status.php, setup_local.php
- examples/routing.json and workers.json; service/alarmmanager.service
- tests/Unit/Lifecycle/LifecycleTest.php and tests/Unit/Plugin/SupervisorTest.php
- tests/Integration/RuntimeTest.php and OutageBenchmark.php; tests/fixtures/supervisor_worker.php
- existing schema.php, ingestion.php, events.php, INFO, authenticated placeholder, translation template and test bootstraps

The final documentation write failed to start when the environment disconnected. README, plan/decisions/progress, event/schema contracts and operating instructions therefore still need review/update.

## Remaining steps

1. Reconnect the original workspace and confirm the uncommitted source is still present. If it was lost, reconstruct and rerun tests; do not claim that a reconstructed build is the tested snapshot.
2. Finish lifecycle/routing and worker/deployment documentation, update README, plan/decisions/progress, and preserve this benchmark with its declared fixture environment.
3. Run PHP syntax checks, SetupTest, ContractTest, LifecycleTest, SupervisorTest, IngestionTest and RuntimeTest on the final source. Rerun the benchmark after material changes.
4. Review source and repository head for intervening work; commit the phase 3–4 implementation to bmfmancini/plugin_alarmmanager with visibility unchanged.
5. Retain the limitations: production transport registry is empty until phase 5, real Cacti/service/remote bootstrap and MySQL qualification remain pending, and upstream integrations/central history reconciliation remain phases 7–8.

Unsupported destination jobs remain pending and consume no attempts. Do not enable production notification handoff before real adapters and integration qualification exist.
