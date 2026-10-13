# Reproducible verification

## Offline skeleton checks

Use PHP 8.2 or 8.3 CLI with OpenSSL. From the repository root:

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/Unit/Plugin/SetupTest.php
php tests/Unit/Ingestion/ContractTest.php
php tests/Unit/Lifecycle/LifecycleTest.php
php tests/Unit/Plugin/SupervisorTest.php
php tests/Unit/Delivery/MappingCredentialTest.php
```

The test is a separate PHP process with in-memory database doubles. It is not evidence of DB concurrency, Cacti authentication or remote replication. No network/dependency downloads occur at plugin runtime.

## Pinned lab reference setup

Clone Cacti/cacti separately and check out d6d230f712c0af1a0391646f65364950ebf131af. Clone plugin_thold, plugin_syslog and plugin_servcheck separately at the revisions in DISCOVERY.md. Retain immutable commits, not floating develop references. Follow Cacti's installation requirements for PHP, web server, RRDTool, DB timezone access and poller cron. Use an isolated MariaDB 10.11 instance with InnoDB and utf8mb4 and a separate test database. Do not point fixtures/migrations at production.

Place Alarm Manager at plugins/alarmmanager. Install, enable, grant realm, inspect the read-only page, disable and uninstall through the authenticated Cacti UI. Verify key persistence and redaction, initial setup idempotency and lack of unexpected producer hooks. Record exact runtime and DB versions. Repeat relevant qualification against the forward-reference Cacti develop revision before declaring support.

## Remote lab lane (required before feature completion)

Use Cacti's supported remote-poller deployment with POLLER_ID >1 and a separate local DB. Provision configuration and credentials before disconnecting the main poller. Once phases 2-8 exist, send local events to both REST API and webhook fixture endpoints, disconnect main, confirm independent processing/delivery, then reconnect and verify central history has the original IDs/outcomes without new dispatch jobs. A failed endpoint must not block or resend successful destinations. Test key restoration/rotation, local DB failure, ownership changes and competing workers. Inspect core online/offline/recovery hook handling and operational-table replication boundaries.

Phase 1 had no live database lane. Phase 2 passed a real isolated MariaDB 10.11.14 lane with PDO-backed Cacti helper adapters. Live Cacti, remote poller, MySQL and browser lanes remain unverified. The private Wordpress lab address from unrelated work is not treated as a Cacti test server.

## Database ingestion harness

Use a dedicated disposable MariaDB server. PHP needs PDO/pdo_mysql, OpenSSL and pcntl (for eight concurrent processes). Set the server DSN **without an existing database**; the test generates its own alarmmanager_test_<random> database and drops only that database in cleanup. The test account needs CREATE/DROP DATABASE on this test server. Do not use a production server.

```
export ALARMMANAGER_TEST_ALLOW_CREATE=1
export ALARMMANAGER_TEST_DSN='mysql:host=127.0.0.1;port=3306'
export ALARMMANAGER_TEST_USER=alarmmanager_test
# Supply the test password through your local environment.
php tests/Integration/IngestionTest.php
```

This actually exercises repeat schema installation/upgrade, local owner identity, committed event/job acceptance, duplicate/conflicting submissions, independent observe/centralized identities, disabled/missing installation, database availability failure, invalid UTF-8, rollback after a real missing-job-table error, an injected unconfirmed commit, caller-owned transaction preservation, eight competing submissions and uninstall preservation. It is not an end-to-end Cacti install or a throughput benchmark.

## Transport and credential lanes

Use the same disposable MariaDB DSN/create opt-in for tests/Integration/RuntimeTest.php and tests/Integration/TransportTest.php. The transport test also requires PHP cURL/pcntl/posix, Python 3, openssl CLI and /usr/bin/setsid. It creates its own disposable databases, self-signed TLS fixture files and loopback HTTP/HTTPS servers. It exercises field mappings, methods, trusted self-signed certificates/hostname checks, redirect rejection, response/timeout bounds, status criteria, Retry-After, worker-only test sends, simultaneous REST API/webhook retries and stable receiver keys. A separate local database demonstrates key-plus-ciphertext provisioning; it is not a full Cacti remote install. Script fixtures verify literal argv, stdin, filtered environment, exit policies and group cleanup. Credential fixtures cover wrong/missing/tampered keys/records, setup preservation, rollback, backup/restore and rotation.

For the actual Cacti email boundary, set ALARMMANAGER_CACTI_ROOT to the pinned Cacti reference directory and run tests/Integration/CactiMailerTest.php with Cacti's required CLI PHP modules (including mbstring) available through its normal php.ini. It creates a minimal disposable offline bootstrap pointing at the pinned Cacti functions and bundled PHPMailer, sends only synthetic loopback SMTP messages, checks recipients/HTML/attachments/idempotency header and kills a stalled child at its deadline. It does not exercise the full Cacti installer, authenticated UI or real SMTP deployment. All temporary fixtures are removed and no certificate/private key is committed.

## Administration/query/browser lane

Run tests/Integration/WebTest.php against the disposable MariaDB DSN/create opt-in with ALARMMANAGER_CACTI_ROOT pointing at the pinned Cacti source. This lane loads real Cacti policy/realm functions, seeds Cacti-shaped users/groups/hosts and verifies visibility before aggregates/pagination, current-versus-historical semantics, mode separation, native endpoint edits, stale revisions, audit, preview/credential redaction, replay history and report execution deadlines. ALARMMANAGER_WEB_MEASUREMENT optionally writes an exact JSON timing/EXPLAIN artifact; it is a small fixture measurement, not a production sizing result.

Browser verification is optional development tooling (Node + Playwright/Chrome, not a plugin runtime dependency). Set ALARMMANAGER_TEST_WEB=1 and a new absolute ALARMMANAGER_WEB_ROOT, then run tests/fixtures/web_seed.php with the disposable DB variables and pinned Cacti root. The seed creates a randomly named test database and minimal copied local UI fixture root, with private session/CSRF files. Start PHP CLI with Cacti's required modules, including ctype for the real csrf-magic validator:

```
php -d session.save_path="$ALARMMANAGER_WEB_ROOT/sessions" \
  -S 127.0.0.1:33078 -t "$ALARMMANAGER_WEB_ROOT" tests/fixtures/web_router.php
```

Run tests/Browser/WebTest.cjs with ALARMMANAGER_BROWSER_URL=http://127.0.0.1:33078 and optionally ALARMMANAGER_BROWSER_BINARY pointing at a local Chrome headless shell. ALARMMANAGER_PLAYWRIGHT_MODULE may point at an installed Playwright package; ALARMMANAGER_BROWSER_SCREENSHOTS optionally saves review images. The fixture server and browser must share the local network namespace. Tests cover real local chart/theme assets, modern/dark/classic, desktop/390px, valid/invalid Cacti CSRF, direct read-only denial, no-code mapping edits, chart-to-log links, escaping and zero non-loopback page/asset requests. Fixture scripts refuse normal PHP-FPM/web execution; enable flags apply only to the disposable CLI server. Stop the server, drop only the regex-validated database named in the private database.txt file, and remove the disposable fixture/session files after testing. Never expose this fixture listener beyond loopback.

Full Cacti install/login/LDAP/admin upgrade and real collector deployment remain a separate qualification lane. The minimal fixture uses Cacti's actual policy/CSRF/chart sources and a test authentication/header shell; it does not prove the full installer or production authentication boundary.
