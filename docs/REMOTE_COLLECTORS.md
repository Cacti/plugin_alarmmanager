# Device transitions and remote collectors

Version 0.8.0/schema v6 adds explicit device ownership, local remote-poller provisioning and authenticated history reconciliation. Install and qualify in an isolated Cacti lab. This implementation has passed real MariaDB and loopback TLS fixtures; it has not been qualified through a full deployed Cacti scheduler/installer lane.

## Source patches

| Source | Exact base revision | Separate patch |
| --- | --- | --- |
| Cacti 1.2.x | d6d230f712c0af1a0391646f65364950ebf131af | integrations/patches/cacti-device.patch |
| thold develop | 6bbfb58492b1e8ce2aeee4690659e54ceb4d1226 | integrations/patches/thold-device.patch |

On clean pinned checkouts run git apply --check and git apply with absolute patch paths. The Cacti patch changes lib/functions.php and lib/poller.php and installs lib/alarmmanager_device_bridge.php. The thold device patch changes includes/polling.php and composes with the separate phase-7 threshold patch. Regenerate using python3 integrations/build_device_patches.py --cacti=/path/to/cacti --thold=/path/to/thold. Reverse with git apply -R --check and git apply -R only after disabling new centralized handoffs and resolving accepted queues. Restart long-lived pollers when installing or updating source/configuration.

Cacti retains its configured failure/recovery counts: transient ping errors are not device alarms. Only confirmed down/recovered edges enter the atomic host-state/event/processing-job/receipt transaction. Safe host fields exclude SNMP credentials. Durable counters disambiguate same-second transitions. One configured owner, core or thold, publishes a canonical device stream; thold's selected device email/script channels consult the same committed ownership. Logs, source hooks and tracking remain source-owned. Missing configuration defaults to legacy. Observation records state without dispatching central jobs and retains source delivery.

Managed centralized devices use a bounded grant cache (30 seconds, maximum 4096 devices per collector), without per-sample configuration queries. Inactive or expired grants pause managed status transitions and selected notification delivery. An accepted core receipt continues fencing thold even when the plugin hook is disabled; keep the copied core bridge installed while accepted jobs exist. Database loss before durable acceptance cannot preserve an unrecorded sample. Core queue failure rolls back confirmed host state so later polling can retry detection.

## Ownership grants

Create a private JSON file outside the plugin tree using examples/device-grant.json as a shape, with actual device/poller/profile IDs, a deployment UUID shared by this deployment, and future UTC lease dates. Both email and script require explicit migration. Configured central endpoints replace source recipient/template/environment behavior as described in PRODUCER_INTEGRATIONS.md. Verify those replacement endpoints before opting in.

On the main poller:

```sh
php cli/remote.php --grant-file=/secure/device-grant.json
```

The lease is not an instantaneous revocation mechanism for disconnected collectors. Keep NTP within 300 seconds and renew/provision leases before expiry. Deliveries check enough remaining validity for the configured transport timeout plus five seconds. Collector reassignment or changing core/thold ownership requires a higher epoch and a new valid_from at least **735 seconds after the prior valid_until** (two 300-second clock bounds, 120-second transport bound, 15-second margin). Same-epoch renewals cannot shrink the lease, change valid_from, change owner/poller or change deployment UUID.

A higher epoch requires seed_event containing the exact normalized last confirmed event acknowledged in the main archive (or main local history). Reconcile the old collector first; an unavailable/unacknowledged history prevents safe handoff. The seed must match the previous epoch. Assign/provision the device host on the new collector first. Import seeds host dates and condition state without creating new event/processing/delivery jobs, retaining an open outage's episode for its later recovery. Old-epoch queued device deliveries are fenced; old pending jobs are not transferred to the new collector. Reassignment during prolonged disconnection requires operator recovery of the old local database/history, rather than guessing its last state.

## Provisioning

Install schema v6 independently on main and each remote, including TRIGGER privilege for eight transactional outbox triggers. Preserve each installation's setup encryption key and generated node UUID. Never copy operational tables/node identities between independent installations. Schema v6 refuses silently generating a new missing node identity. Back up database, setup key and private peer keys together; use CREDENTIALS.md for encryption-key recovery/rotation.

Create routing profiles/endpoints and usable credentials on main. Explicitly export selected profiles:

```sh
php cli/remote.php --export-for=2 --profiles=1,2 --output=/secure/poller-2.json
# Securely transfer the owner-private file to collector 2, then run there:
php cli/remote.php --provision=/secure/poller-2.json
```

Export contains selected routes, applicable producer policies/device grants and plaintext credential records. Files must be absolute, outside the plugin directory, owner-private and nonsymlinks; new files are exclusive mode 0600. Transfer securely and remove plaintext exports after import. Import is atomic and re-encrypts credentials using the remote's existing setup key. It preserves identity, queues, history and existing encryption key. Provisioning merges included producer policies/grants; omitted existing policies/grants are not revoked. Review local configuration before enabling workers.

Workers and producer retry commands use their offline/local Cacti bootstrap. They do not call main to ingest, route or deliver. Local delivery remains independent during main outage within its provisioned ownership lease and with local credentials/CA/attachment/script files available.

Normal Cacti replication must exclude Alarm Manager operational tables, node identity, setup/peer keys, queues and delivery receipts. The plugin adds no replicate_out hook. For enrolled collectors the core host replication patch upserts configuration while preserving local status/counters/dates, marks removed assignments deleted, avoids TRUNCATE, and refuses host-schema mismatch rather than dropping the table. Unenrolled peers retain native behavior. Upgrade matching host schemas before replication.

## Signed history and health

Each node prints its durable identity with php cli/remote.php --identity. On main create one unique private peer key per collector using --new-key-file=/secure/poller-2.key. Securely transfer that key to collector 2. Register both ends with their actual node identities:

```sh
# Main: collector 2's identity
php cli/remote.php --peer=2 --instance=COLLECTOR_UUID --key-file=/secure/poller-2.key
# Collector 2: main's identity and HTTPS receiver
php cli/remote.php --peer=1 --instance=MAIN_UUID --key-file=/secure/poller-2.key --url=https://main.example/plugins/alarmmanager/alarmmanager_sync.php --ca-bundle=/secure/main-ca.pem
# Collector 2: bounded sender; run separately from local delivery workers
php cli/remote.php --send --limit=100
```

Run the sender on a schedule on each collector; it is a bounded batch that exits after one pass:

```cron
# /etc/cron.d/alarmmanager-sync-send (collector 2) — per-minute cadence
* * * * * cacti /usr/bin/php /var/www/html/cacti/plugins/alarmmanager/cli/remote.php --send --limit=100
```

The bounded batch exits after one pass, so a per-minute cron entry cannot overlap harmfully beyond a still-running previous batch (the queue is bounded and idempotent); cron is the only scheduler shipped for the sender.

The receiver is main-only, POST-only and HMAC-authenticated, independent of browser permissions. HTTPS peer/hostname verification is always enabled; private/self-signed certificates require a trust file, not disabling verification. Requests cannot redirect. Peer keys are random 32-byte secrets stored only in owner-private files; run HTTP receiver and sender under an account that can securely read its key. Key paths are metadata, never keys in database/output. Rotate by provisioning matching new files and peer records on both ends; pending packets remain available for retry.

Signed packets bind source poller/node, main node audience, body and timestamp; request and acknowledgment use separate signature domains with a 300-second timestamp bound. The sender retains its exact pending packet until matching signed acknowledgment. The exporter locks a committed outbox prefix under READ COMMITTED to prevent AUTO_INCREMENT commit-order gaps and reads entity data/revision in one read view. Requests are bounded to 500 records/1 MiB. Lost acknowledgment retries are idempotent. Heartbeats run even without history. Run the bounded sender at least once per minute in the lab and monitor failures; Administration → Collectors labels peers offline after 90 seconds without contact.

Imported event/processing/decision/delivery/attempt records live in a separate archive with original owner IDs and revisions. Reconciliation never submits those events or creates central delivery jobs. The existing dashboard remains local; the admin-only Collectors view filters the latest 50 archived records and shows the first 100 grants. Reader device permissions are not extended to remote records. Archive and outbox retention/pruning are phase-10 qualification work; retain source records until acknowledged.

Existing pre-v6 history requires explicit bounded seeding on each node using --seed=event|processing|decision|delivery|attempt --after=ID --limit=100, separately per entity and continuing from the returned after value. Triggers capture future changes automatically. No payload, network diagnostic or secret is printed on sender failure.

## Verification and limits

RemoteTest uses three real disposable MariaDB databases. RemoteHttpTest runs the public receiver with an offline bootstrap fixture behind actual TLS and local HTTPS notification fixtures: main downtime does not block local routing/fan-out, lost ACK replays safely, and reconnect does not resend successful notifications. The notification fixture adapter does not requalify production cURL transports. Source tests execute pinned patched core transitions with framework/publication doubles and characterize configuration replication/cache query behavior. Installed thold device branch behavior, actual remote CLI bootstrap, deployment cron/accounts, full Cacti install/upgrade, PHP 8.2, production capacity/receiver idempotency and MySQL remain unqualified.

The 40-transition measurement is in benchmarks/device-transition.json: 1.79 ms median, 2.70 ms p95, 16 helper queries per confirmed transition, versus 0.083 ms mean native status write and 2 MiB PHP peak in this fixture. It is not a production performance guarantee. External non-idempotent effects still need receiver idempotency; local ownership and signed ACK do not establish end-to-end exactly-once delivery.
