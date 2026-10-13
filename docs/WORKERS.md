# Local workers

Requirements: PHP >=8.2 with OpenSSL, pcntl and posix; MariaDB >=10.11/InnoDB. Tests used PHP 8.3.6 and MariaDB 10.11.14. MySQL is not qualified and setup refuses it. Run only in an isolated Cacti lab until transport and integration phases are complete.

All database CLI entry points set Cacti conn_mode=offline before cli_check.php. They use the poller's local database and do not bootstrap a main-server database connection. The supervisor opens no database connection; it spawns fresh PHP children. This is local queue infrastructure, not proof of a complete Cacti remote outage deployment or reconciliation.

From the installed plugins/alarmmanager directory, after Cacti plugin installation:

```sh
php cli/setup_local.php --local
php cli/configure.php --file=examples/routing.json
php cli/status.php
php cli/supervisor.php --config=examples/workers.json
```

Local setup creates that poller's schema and random setup key; it preserves installed enabled/disabled status. Configuration must be provisioned to each local database. Key sharing/encrypted credentials arrive in phase 5; history reconciliation in phase 8. Disabled plugins pause worker claims. Source collector identity is distinct from local processing owner.

The default supervisor starts two processing and four delivery workers. Settings bound each pool to 64, processing minimum one; delivery may be zero. SIGTERM/SIGINT finish in-flight work within the shutdown budget, then forcibly terminate remaining children. SIGHUP validates replacement JSON and resizes/restarts pools; invalid reload keeps prior settings. Crashed workers restart with bounded exponential backoff. Adapt paths, account and configuration in service/alarmmanager.service before installation; its stop budget includes the default grace period.

For scheduled bounded execution:

```sh
php cli/worker.php --role=processing --once --max-jobs=100 --max-seconds=30
php cli/worker.php --role=delivery --once --max-jobs=100 --max-seconds=30
```

Time budgets are checked between jobs; in-flight work can extend to its configured deadline. Claims take row locks with SKIP LOCKED, commit immediately and use random lease tokens. Processing waits behind earlier pending, leased or terminally failed jobs in its stream. A failed processing predecessor blocks only that stream and needs operator repair; no repair UI is implemented. Destination retries preserve stream order, but a terminal delivery failure does not prevent a later recovery. Priority ages to prevent starvation.

Endpoint row locks enforce concurrency and minimum start interval across workers sharing a local database. Limits are per local queue, not fleet-wide. Reapers bound work to 100 rows, reclaim expired leases and record abandoned attempts or terminal failures. Finalization checks both token and unexpired lease; stale workers cannot commit completion. Transient deadlock/lock-timeout transactions retry up to three times after rollback. Handler calls run outside transactions with a PCNTL deadline; phase-5 adapters must also configure native I/O deadlines. No production transport handlers are registered yet, so unsupported endpoint types remain queued and appear in diagnostics.

Status reports queue counts, oldest age, retries, recent completion, worker heartbeat, unsupported destinations and streams blocked behind failed processing. Errors are sanitized codes. Fixtures exercise trusted handlers without network delivery.
