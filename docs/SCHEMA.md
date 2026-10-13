# Schema v2

Install/upgrade creates indexed InnoDB tables directly through prepared Cacti DB helpers, then records schema version 2. CREATE IF NOT EXISTS is repeatable; engine checks refuse non-transactional existing tables. No external migration runner or runtime dependency download is needed. Later schema revisions need explicit forward migrations; this version does not repair arbitrarily malformed preexisting tables.

| Table | Purpose | Key indexes |
| --- | --- | --- |
| plugin_alarmmanager_schema | Migration version | Primary id=1 |
| plugin_alarmmanager_events | Immutable normalized event and source/mode identity, ordering, receipt and local owner | Unique source/instance/event/mode; received time; alarm stream/episode/sequence; collector/time; severity/time |
| plugin_alarmmanager_jobs | Exactly one processing job per event row | Unique event_row_id; status/available_at/id; event foreign key |

Processing jobs are distinct from destination deliveries. Schema v2 adds leases, bounded queues and indexed per-stream ordering. Event payloads are bounded JSON text rather than an unbounded collection of raw device fields. Timestamp values are UTC DATETIME(6), independent of server session timezone.

These durable tables are intentionally **not** registered through api_plugin_db_table_create/db_table_create helpers that register plugin_db_changes. Core api_plugin_uninstall() calls api_plugin_db_changes_remove(), which would drop registered tables even if our uninstall hook did nothing. The plugin uninstall preserves event/job/history/key records; Cacti removes only framework registrations. There is no purge command in this build. A later purge action must be explicit, authorized and documented with backup implications. Remote table creation/upgrade must be explicit because framework registry replication is not used for these operational tables.

Credential records are not present yet. Setup checks the future plugin_alarmmanager_credentials table before generating a missing key, and refuses creation if encrypted records are present or the check fails. SQL flow tracing is refused where it could expose sensitive return values or payloads. Upgrade cannot recreate missing keys.

MariaDB 10.11.14 was tested with actual InnoDB transactions and competing processes. MySQL, Cacti runtime installation, remote schema bootstrap and browser authorization have not been qualified. The database harness uses real PDO storage with Cacti-compatible helper signatures; it does not bootstrap the full Cacti runtime.

Host-crash durability also depends on the database/storage configuration: use innodb_flush_log_at_trx_commit=1, and sync_binlog=1 when relying on binary logs, with storage that honors fsync. The plugin does not change global database durability settings. A successful transaction commit is the application handoff boundary; these tests do not simulate power loss.

## Version 2 additions

| Table suffix (plugin_alarmmanager_) | Purpose |
| --- | --- |
| states, episodes | Mode-separated stream state and immutable episode identity/closure ledger |
| endpoints, profiles, rules | Bounded routing configuration and per-endpoint queue limits |
| configuration_history | Immutable normalized configuration snapshots by revision |
| decisions | Per-event routing/suppression outcomes pinned to a configuration revision |
| notification_state | Per-stream/endpoint cooldown and duplicate policy state |
| deliveries, attempts | Independent endpoint jobs, stable idempotency keys, fenced attempts |
| failed_jobs | Terminal processing/delivery failure records |
| workers | Local worker heartbeat and current-job diagnostics |

Events gain stream_key; jobs gain lease token/expiry, attempts, priority, error and update timestamps. Migration backfills stream hashes, adds named indexes idempotently and stamps v2 only after successful completion. The schema row also stores configuration revision. Versions newer than this implementation are refused. MariaDB >=10.11 is required for the qualified SKIP LOCKED claim path; MySQL and older MariaDB are refused. Upgrades preserve the setup key and event history. Endpoint/profile/rule IDs omitted from replacement configuration are disabled rather than deleted.
