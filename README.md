# Alarm Manager

Cacti plugin under development for alarm lifecycle/history, routing to multiple destinations and independent remote-poller processing. Detection remains in Cacti, thold and syslog. Legacy notifications remain unchanged until explicitly opted in.

**Current state:** phase 6 development build (0.6.0). Durable ingestion feeds ordered episode/state processing, routing and independent destination queues. Bounded workers now deliver through REST API, webhook, Cacti email and controlled scripts. Endpoint-specific mappings and authenticated encrypted credentials support setup-key backup/rotation and explicit local-poller provisioning. Cacti pages now provide a syslog-style operational dashboard, permission-scoped logs and protected configuration/credential/test/replay actions. Producer integrations and remote reconciliation remain later work. Do not hand off production notification ownership before those integrations and qualification are complete.

Primary source target: pinned Cacti 1.2.x with 1.2.32 version metadata; PHP >=8.2 with OpenSSL. Initial local checks use PHP 8.3.6. This build requires MariaDB >=10.11/InnoDB; MySQL is refused pending qualification. CLI workers require pcntl and posix; HTTP needs cURL; email/scripts require Linux /usr/bin/setsid. Cacti supplies its own local PHPMailer. See [discovery](docs/DISCOVERY.md) for exact revisions and limitations.

## Lab installation

Use an isolated Cacti installation. Clone this repository into `<cacti-root>/plugins/alarmmanager`, not plugins/plugin_alarmmanager. Install and enable through Cacti's Plugin Management page, then grant the Alarm Manager View realm as appropriate. Setup generates a 256-bit plugin-specific key in settings_user under owner 1. Grant Alarm Manager View for readers; administration also requires Alarm Manager Administration and Cacti realm 1. See [administration and dashboard](docs/ADMINISTRATION.md). Installation creates schema v4; upgrades add routing, lifecycle, worker and encrypted credential/transport tables plus permission/report indexes and audit records with repeatable forward migrations. It does not display key material. Existing Cacti credentials are neither copied nor changed.

Disable Cacti DEBUG_SQL_FLOW tracing before installation or enablement: core tracing can expose returned keys. Back up the alarmmanager_key settings_user record securely. Uninstall preserves it and alarm event/job history. Upgrade/enable fails closed when it is missing or malformed; restore the original key rather than inventing one. Do not deploy this skeleton for notification ownership. See [credential backup, rotation and authorized poller provisioning](docs/CREDENTIALS.md).

## Local verification

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/Unit/Plugin/SetupTest.php
php tests/Unit/Ingestion/ContractTest.php
php tests/Unit/Lifecycle/LifecycleTest.php
php tests/Unit/Plugin/SupervisorTest.php
php tests/Unit/Delivery/MappingCredentialTest.php
```

The standalone setup test uses in-memory framework/database doubles. It does not require Composer, containers, Node.js or a live database. It verifies key length/strict decoding, persistence, failed storage, lifecycle readiness and absence of automatic producer interception. The explicitly invoked ingestion hook is registered; producers remain unchanged. Do not load its bootstrap into a running Cacti installation.

See the [event contract](docs/EVENT_CONTRACT.md) and [schema](docs/SCHEMA.md) for acceptance/duplicate/observation semantics and CLI usage.

See [test setup](docs/TEST_SETUP.md), [implementation plan](docs/IMPLEMENTATION_PLAN.md), [decisions](docs/DECISIONS.md), [progress](docs/PROGRESS.md) and [focused prompts](alarmmanager-build-prompts.md). Dependencies/assets must be local and licensed; no CDN or runtime package download is permitted. REST API and webhook destinations have independent transports, mappings, credentials, jobs and retries. [Delivery configuration](docs/DELIVERY.md) includes self-signed certificate/private-CA support through endpoint ca_bundle files, preview and explicit worker-owned test sends. See [lifecycle/routing](docs/LIFECYCLE_ROUTING.md), [workers](docs/WORKERS.md) and [fixture benchmark](docs/benchmarks/README.md).

GPL-2.0-or-later. See LICENSE and [attribution](NOTICE.md).


## Opt-in producer integrations (phase 7)

Version 0.7.0 includes revision-pinned thold/syslog patches, durable notification ownership receipts and protected source policy controls. Apply the patches separately in a lab; source delivery remains legacy by default. See [PRODUCER_INTEGRATIONS.md](docs/PRODUCER_INTEGRATIONS.md) for exact revisions, policy inheritance, explicit email/script migration, pending receipt recovery and remaining installed/remote qualification. Device-status integration remains phase 8.
