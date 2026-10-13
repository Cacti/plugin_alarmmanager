# Reproducible verification

## Offline skeleton checks

Use PHP 8.2 or 8.3 CLI with OpenSSL. From the repository root:

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/Unit/Plugin/SetupTest.php
```

The test is a separate PHP process with in-memory database doubles. It is not evidence of DB concurrency, Cacti authentication or remote replication. No network/dependency downloads occur at plugin runtime.

## Pinned lab reference setup

Clone Cacti/cacti separately and check out d6d230f712c0af1a0391646f65364950ebf131af. Clone plugin_thold, plugin_syslog and plugin_servcheck separately at the revisions in DISCOVERY.md. Retain immutable commits, not floating develop references. Follow Cacti's installation requirements for PHP, web server, RRDTool, DB timezone access and poller cron. Use an isolated MariaDB 10.11 instance with InnoDB and utf8mb4 and a separate test database. Do not point fixtures/migrations at production.

Place Alarm Manager at plugins/alarmmanager. Install, enable, grant realm, inspect the read-only page, disable and uninstall through the authenticated Cacti UI. Verify key persistence and redaction, initial setup idempotency and lack of unexpected producer hooks. Record exact runtime and DB versions. Repeat relevant qualification against the forward-reference Cacti develop revision before declaring support.

## Remote lab lane (required before feature completion)

Use Cacti's supported remote-poller deployment with POLLER_ID >1 and a separate local DB. Provision configuration and credentials before disconnecting the main poller. Once phases 2-8 exist, send local events to both REST API and webhook fixture endpoints, disconnect main, confirm independent processing/delivery, then reconnect and verify central history has the original IDs/outcomes without new dispatch jobs. A failed endpoint must not block or resend successful destinations. Test key restoration/rotation, local DB failure, ownership changes and competing workers. Inspect core online/offline/recovery hook handling and operational-table replication boundaries.

No live Cacti, MariaDB, remote poller or browser lane has been run in phase 1. The private Wordpress lab address from unrelated work is not treated as a Cacti test server.
