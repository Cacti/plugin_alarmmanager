# Local workers

Requirements: PHP >=8.2 with OpenSSL, pcntl and posix; MariaDB >=10.11/InnoDB. Tests used PHP 8.3.6 and MariaDB 10.11.14. MySQL is not qualified and setup refuses it. HTTP needs cURL; email/scripts require /usr/bin/setsid. Run only in an isolated Cacti lab until producer integration and full deployment qualification are complete.

All database CLI entry points set Cacti conn_mode=offline before cli_check.php. They use the poller's local database and do not bootstrap a main-server database connection. The supervisor opens no database connection; it spawns fresh PHP children. Local HTTP delivery and separate-database credential provisioning have fixture coverage; a complete Cacti remote outage deployment and reconciliation remain unqualified.

From the installed plugins/alarmmanager directory, after Cacti plugin installation:

```sh
php cli/setup_local.php --local
php cli/configure.php --file=examples/routing.json
php cli/status.php
php cli/supervisor.php --config=examples/workers.json
```

Local setup creates that poller's schema and random setup key; it preserves installed enabled/disabled status. Configuration must be provisioned to each local database. Encrypted credential save/backup/rotation/provisioning is described in CREDENTIALS.md; history reconciliation remains phase 8. Disabled plugins pause worker claims. Source collector identity is distinct from local processing owner.

The default supervisor starts two processing and four delivery workers. Settings bound each pool to 64, processing minimum one; delivery may be zero. SIGTERM/SIGINT finish in-flight work within the shutdown budget, then forcibly terminate remaining children. SIGHUP validates replacement JSON and resizes/restarts pools; invalid reload keeps prior settings. Crashed workers restart with bounded exponential backoff. Adapt paths, account and configuration in service/alarmmanager.service before installation; its stop budget includes the default grace period.

For scheduled bounded execution:

```sh
php cli/worker.php --role=processing --once --max-jobs=100 --max-seconds=30
php cli/worker.php --role=delivery --once --max-jobs=100 --max-seconds=30
```

Installations that cannot run the resident supervisor schedule those two commands with cron or a systemd timer. Each run is bounded; it exits after its job/time budget. Cacti's crontab pattern applies (the poller user, full PHP path):

```cron
# /etc/cron.d/alarmmanager-workers — scheduled bounded mode
* * * * * cacti /usr/bin/php /var/www/html/cacti/plugins/alarmmanager/cli/worker.php --role=processing --once --max-jobs=100 --max-seconds=30
* * * * * cacti /usr/bin/php /var/www/html/cacti/plugins/alarmmanager/cli/worker.php --role=delivery  --once --max-jobs=100 --max-seconds=30
```

A systemd timer example equivalent to this supervisor deployment ships as `service/alarmmanager-worker.timer`, or use the cron entries above. Use either the supervisor or the scheduled mode on one poller, not both: the queue lease/fencing makes interleaved claims safe, but two pools only double idle wake-ups. `cli/status.php` prints the same local queue/worker metrics the Administration → Workers page renders (`alarmmanager_metrics()` in `includes/workers.php` is the single implementation for both).

Time budgets are checked between jobs; in-flight work can extend to its configured deadline. Claims take row locks with SKIP LOCKED, commit immediately and use random lease tokens. Processing waits behind earlier pending, leased or terminally failed jobs in its stream. A failed processing predecessor blocks only that stream and needs operator repair; no repair UI is implemented. Destination retries preserve stream order, but a terminal delivery failure does not prevent a later recovery. Priority ages to prevent starvation.

Endpoint row locks enforce concurrency and minimum start interval across workers sharing a local database. Limits are per local queue, not fleet-wide. Reapers bound work to 100 rows, reclaim expired leases and record abandoned attempts or terminal failures. Finalization checks both token and unexpired lease; stale workers cannot commit completion. Transient deadlock/lock-timeout transactions retry up to three times after rollback. Handler calls run outside transactions with a PCNTL deadline; phase-5 adapters must also configure native I/O deadlines. REST API, webhook, email and script handlers are registered. WebSocket remains unsupported and queued. Endpoints without a valid transport definition cannot send.

Status reports queue counts, oldest age, retries, recent completion, worker heartbeat, unsupported destinations and streams blocked behind failed processing. Errors are sanitized codes. Loopback fixtures exercise real HTTP/TLS/script delivery and the pinned Cacti mailer over SMTP. No production destination was contacted.
