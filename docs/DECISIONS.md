# Decisions

1. Owner is bmfmancini/plugin_alarmmanager; private visibility was confirmed via GitHub and is preserved. Installed directory is alarmmanager, without plugin_ prefix. Functions/hooks/settings use alarmmanager_; tables will use plugin_alarmmanager_. Cacti-mandated lifecycle function names remain plugin_alarmmanager_*.
2. Primary source target is pinned Cacti 1.2.x / 1.2.32 metadata, PHP >=8.2; PHP 8.3.6 is the initial validation runtime. Develop is a forward reference, not declared compatible yet. MariaDB 10.11 is the first DB test lane; MySQL 8.0 is a later qualification lane.
3. Servcheck-style encrypted credential records and settings_user key storage are required. Generate a plugin-specific CSPRNG key during setup. Use explicit owner 1, never current-session ownership; preserve it across setup/upgrade/uninstall. Add authenticated ciphertext, lost-key guards and rotation before introducing credential records. Do not reuse servcheck's key, timestamp-derived generator or unauthenticated format verbatim.
4. Remote pollers must process and deliver independently against local state during disconnection. Central reconciliation is history synchronization, not redispatch. Separate replicated configuration from poller-local queues/keys. Provision usable credentials per authorized poller; decide shared-key versus per-poller re-encryption with threat and operational tradeoffs before implementing replication.
5. A profile can route one event to multiple destinations. REST API and webhook are separate configurations sharing an HTTP transport. Each endpoint gets its own mapping, secret references, idempotent job, result and retry stream. Deduplicate the same endpoint selected by overlapping rules. Do not resend successful endpoints because another endpoint failed.
6. Legacy producers retain delivery by default; observe and centralized modes require explicit handoff semantics. thold_action is unsuitable for interception. There is no interception in the skeleton.
7. Adapt syslog's actual dashboard toolbar, grid/cards and billboard conventions later. Namespace copied code and preserve source notices; do not require syslog installation. Ship pinned dependencies locally or reuse guaranteed Cacti assets. No Node.js runtime or container requirement.
8. Phase 1 creates no event tables, queues or transports. Its setup checks use a standalone database double and cannot establish live installation/replication correctness.

9. Contract v1 uses explicit status in a functional hook envelope. Event uniqueness includes mode so observation cannot own delivery. Normalized payload conflicts fail closed. Persist episode and monotonic stream sequence; state application is phase 3.
10. Ingestion refuses an already active producer transaction. Atomic event plus job commit is required before acceptance. Direct DDL keeps history outside Cacti plugin_db_changes destructive cleanup; no purge action exists yet.
11. Setup guards lost keys over future encrypted credential records. Key/ingestion paths refuse SQL flow tracing because core can dump returned values.

## Phases 3–4 additions

- Require MariaDB >=10.11 for the qualified SKIP LOCKED queue path; refuse MySQL until a separate lane is qualified.
- Fence claims and completion with random lease tokens and expiry; perform external calls outside transactions. Queue retries and receiver idempotency address separate failure boundaries.
- Preserve processing stream order across retries and terminal processing failures; isolate blocked streams. Allow later recovery after terminal destination failure.
- Use mode-separated observation state and immutable configuration revision snapshots for reviewable routing outcomes.
- Force offline/local Cacti CLI bootstrap and spawn fresh PHP worker processes; local endpoint limits apply to one queue database, not all pollers. Leave production handlers empty until phase 5.

## Phase 5 additions

- Follow servcheck's encrypted JSON/settings_user ownership pattern with plugin-specific setup keys, versioned AES-256-GCM and endpoint-bound additional authenticated data. Do not use servcheck's CBC/lazy timestamp-key behavior.
- Credentials are separate from configuration/history/previews; explicit local CLI backup/rotation/restore provisions matching keys and records. No automatic cross-poller secret replication.
- Trust self-signed/private-CA certificates through an endpoint ca_bundle PEM path while retaining certificate/hostname checks. Refuse redirects, ambient proxies/netrc and credential-bearing URL/HTTP configurations.
- Encode mapping payloads structurally with typed conversions and bounded safe field names. Endpoint current transport/credential resolution at attempt time is explicit; original routing revisions remain in decisions.
- Use Cacti's existing mailer in a deadline-controlled fresh offline child; preserve its recipient/HTML/attachment contract. Scripts receive structured argv/stdin and a filtered environment, with bounded output and ordinary descendant group cleanup.
