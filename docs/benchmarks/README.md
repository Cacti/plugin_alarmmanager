# Synthetic outage queue benchmark

outage-2000.json captures a real local MariaDB fixture run: PHP 8.3.6, MariaDB 10.11.14/InnoDB, 64 MiB buffer pool, four processing and four delivery workers. 2,000 distinct-stream events produced 4,000 destination jobs, all completed. Trusted in-process handlers sleep 1 ms; no REST API, webhook, Cacti main outage or external receiver was exercised. Reported throughput is fixture capacity, not production capacity.

Run tests/Integration/OutageBenchmark.php with PDO/mysql extensions and pcntl/posix, ALARMMANAGER_TEST_ALLOW_CREATE=1, ALARMMANAGER_TEST_DSN, ALARMMANAGER_TEST_USER and optional ALARMMANAGER_TEST_PASSWORD. Set ALARMMANAGER_BENCH_EVENTS=2000. The harness creates and drops a randomly named test database; it requires permission for that isolated database. Never point it at production. Each child opens its own connection after fork.

Measured ingestion took 0.986 seconds, processing 5.213 seconds and delivery 13.432 seconds. All four workers in each pool performed work; each reported a 2 MiB PHP peak (not total process RSS). One processing deadlock was retried inside its transaction; no job-level retries remained. Row lock waits totaled 32 ms. Remaining queue age was zero. Native AIO was unavailable in this lab. Use the JSON for exact counts and timings; reproduce against realistic stream contention and actual transport latency before sizing a deployment.


Phase 8: [device-transition.json](device-transition.json) records 40 real MariaDB confirmed transitions and a native-write baseline from RemoteTest. Source cache fixtures verify no per-sample configuration queries. This measures PDO helper fixtures, not a deployed Cacti poller or production throughput.
