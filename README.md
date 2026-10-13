# Alarm Manager

Cacti plugin under development for alarm lifecycle/history, routing to multiple destinations and independent remote-poller processing. Detection remains in Cacti, thold and syslog. Legacy notifications remain unchanged until explicitly opted in.

**Current state:** phases 3–4 development build (0.4.0). Durable ingestion now feeds ordered episode/state processing, routing and independent destination queues. Bounded workers support leases, retries, crash recovery and graceful shutdown. Production transport adapters, producer integrations, administration UI and remote reconciliation remain later work. Do not hand off production notification ownership to this build.

Primary source target: pinned Cacti 1.2.x with 1.2.32 version metadata; PHP >=8.2 with OpenSSL. Initial local checks use PHP 8.3.6. This build requires MariaDB >=10.11/InnoDB; MySQL is refused pending qualification. CLI workers require pcntl and posix. See [discovery](docs/DISCOVERY.md) for exact revisions and limitations.

## Lab installation

Use an isolated Cacti installation. Clone this repository into `<cacti-root>/plugins/alarmmanager`, not plugins/plugin_alarmmanager. Install and enable through Cacti's Plugin Management page, then grant the Alarm Manager View realm as appropriate. Setup generates a 256-bit plugin-specific key in settings_user under owner 1. The page displays an authenticated development placeholder. Installation creates schema v2; upgrades add routing, lifecycle and worker tables with repeatable forward migrations. It does not display key material. Existing Cacti credentials are neither copied nor changed.

Disable Cacti DEBUG_SQL_FLOW tracing before installation or enablement: core tracing can expose returned keys. Back up the alarmmanager_key settings_user record securely. Uninstall preserves it and alarm event/job history. Upgrade/enable fails closed when it is missing or malformed; restore the original key rather than inventing one. Do not deploy this skeleton for notification ownership. Credential encryption/rotation and remote provisioning arrive in later phases.

## Local verification

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/Unit/Plugin/SetupTest.php
php tests/Unit/Ingestion/ContractTest.php
php tests/Unit/Lifecycle/LifecycleTest.php
php tests/Unit/Plugin/SupervisorTest.php
```

The standalone setup test uses in-memory framework/database doubles. It does not require Composer, containers, Node.js or a live database. It verifies key length/strict decoding, persistence, failed storage, lifecycle readiness and absence of automatic producer interception. The explicitly invoked ingestion hook is registered; producers remain unchanged. Do not load its bootstrap into a running Cacti installation.

See the [event contract](docs/EVENT_CONTRACT.md) and [schema](docs/SCHEMA.md) for acceptance/duplicate/observation semantics and CLI usage.

See [test setup](docs/TEST_SETUP.md), [implementation plan](docs/IMPLEMENTATION_PLAN.md), [decisions](docs/DECISIONS.md), [progress](docs/PROGRESS.md) and [focused prompts](alarmmanager-build-prompts.md). Dependencies/assets must be local and licensed; no CDN or runtime package download is permitted. REST API and webhook destinations have independent jobs and retries. Their actual transport, mapping and credential adapters arrive in phase 5. See [lifecycle/routing](docs/LIFECYCLE_ROUTING.md), [workers](docs/WORKERS.md) and [fixture benchmark](docs/benchmarks/README.md).

GPL-2.0-or-later. See LICENSE and [attribution](NOTICE.md).
