# Alarm Manager

Cacti plugin under development for alarm lifecycle/history, routing to multiple destinations and independent remote-poller processing. Detection remains in Cacti, thold and syslog. Legacy notifications remain unchanged until explicitly opted in.

**Current state:** phase 1 skeleton only. No ingestion, alarm queue, transports, producer interception or working remote alarm processing exists yet.

Primary source target: pinned Cacti 1.2.x with 1.2.32 version metadata; PHP >=8.2 with OpenSSL. Initial local checks use PHP 8.3.6. First database qualification target is MariaDB 10.11/InnoDB. See [discovery](docs/DISCOVERY.md) for exact revisions and limitations.

## Lab installation

Use an isolated Cacti installation. Clone this repository into `<cacti-root>/plugins/alarmmanager`, not plugins/plugin_alarmmanager. Install and enable through Cacti's Plugin Management page, then grant the Alarm Manager View realm as appropriate. Setup generates a 256-bit plugin-specific key in settings_user under owner 1. The page displays an authenticated development placeholder. It does not display key material. Existing Cacti credentials are neither copied nor changed.

Disable Cacti DEBUG_SQL_FLOW tracing before installation or enablement: core tracing can expose returned keys. Back up the alarmmanager_key settings_user record securely. Uninstall preserves it. Upgrade/enable fails closed when it is missing or malformed; restore the original key rather than inventing one. Do not deploy this skeleton for notification ownership. Credential encryption/rotation and remote provisioning arrive in later phases.

## Local verification

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/Unit/Plugin/SetupTest.php
```

The standalone setup test uses in-memory framework/database doubles. It does not require Composer, containers, Node.js or a live database. It verifies key length/strict decoding, persistence, failed storage, lifecycle readiness and absence of producer hooks. Do not load its bootstrap into a running Cacti installation.

See [test setup](docs/TEST_SETUP.md), [implementation plan](docs/IMPLEMENTATION_PLAN.md), [decisions](docs/DECISIONS.md), [progress](docs/PROGRESS.md) and [focused prompts](alarmmanager-build-prompts.md). Dependencies/assets must be local and licensed; no CDN or runtime package download is permitted. REST API and webhook destinations will have independent mappings, credentials and retries, even when selected for the same alarm.

GPL-2.0-or-later. See LICENSE and [attribution](NOTICE.md).
