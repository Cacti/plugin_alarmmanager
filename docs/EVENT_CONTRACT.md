# Event contract v1

This is an internal Cacti functional hook, not a public HTTP endpoint. Producers keep their detection logic. They must publish before delivery side effects and retain stable IDs on retry. No upstream integration has been installed yet.

## Envelope and handoff

```php
$request = [
    'contract_version' => 1,
    'mode' => 'observe', // or 'centralized'
    'status' => 'unhandled',
    'event' => $event,
];
$response = api_plugin_hook_function('alarmmanager_submit', $request);
$owns_delivery = $request['mode'] === 'centralized'
    && is_array($response)
    && in_array($response['status'] ?? '', ['accepted', 'already_accepted'], true);
```

Always initialize status to unhandled. Cacti returns the original parameter if no handler is registered; a truthy array is not acknowledgment. The handler preserves the envelope, replacing status/error/event_row_id response fields. It rechecks plugin_config.status on each call, including when a long-lived process still has a cached hook. Missing/disabled installation returns unhandled; database failures return failed.

| Status | Meaning | Producer action |
| --- | --- | --- |
| unhandled | No active handler/installation | Retain documented legacy delivery |
| failed | Invalid contract, identity conflict, transaction unavailable, or unconfirmed storage | Retain documented legacy fallback and original event ID for retry |
| accepted | This event/mode and its processing job committed durably | Centralized mode hands off delivery; observe does not |
| already_accepted | Same event/mode/payload already committed; job is not reopened | Same ownership semantics as accepted |

On confirmed centralized acceptance, later delivery failure belongs to Alarm Manager retry policy. Do not independently send from the source. A lost commit acknowledgment may return failed even if the database committed; stable event IDs allow retry to resolve it. Exact-once external effects cannot be guaranteed without endpoint idempotency. Because opt-in producer integrations and complete deployment qualification remain pending, **do not enable centralized delivery in production with this build**.

## Event fields

See ../examples/event.json for a complete JSON fixture. Strings are UTF-8; IDs are case-sensitive ASCII tokens using letters, digits, `_ . : @ / -`.

| Field | Requirement |
| --- | --- |
| event_id | Stable occurrence/transition ID, max 128 bytes; reuse on retry |
| source | Producer name, max 32 bytes, e.g. cacti/thold/syslog |
| source_instance | Stable producer deployment identity, max 128 bytes; preserve through collector reassignment |
| resource_id | Producer resource/rule ID, max 128 bytes |
| alarm_key | Stable alarm stream identity, max 191 bytes; separate from event ID |
| episode_id | Outage/condition episode ID, max 128 bytes; occurrence events still need an explicit grouping token |
| sequence | Positive PHP integer; monotonically increasing across the alarm stream, including new episodes |
| transition | raised, updated, reminder, recovered, occurrence |
| severity | critical, major, minor, warning, info, clear |
| occurred_at | ISO 8601 timestamp with Z or explicit offset and up to six fractional digits; normalized to UTC; year 1000-9999 after conversion |
| collector_id | Original collector, positive integer <=2147483647; main=1 |
| summary | Required string, max 512 bytes |
| details | Optional string, max 16384 bytes |
| device | Optional safe metadata: integer id, hostname (255 bytes), description (512 bytes), tags |
| context | Optional allowlisted source context below |

Device IDs may be absent; raw host records are rejected. Tags/channels are lists of at most 32 strings of up to 128 bytes, sorted and deduplicated for stable hashing. Context permits rule_name, rule_type, metric, metric_value, threshold, suppression_reason (strings up to 512 bytes), source_suppressed (boolean), source_notification_channels (bounded string list), and optional routing_profile_id (positive integer). Metric/threshold values are strings in v1 to preserve formatting/precision. Unknown event/device/context fields are rejected. Producers must not put credentials in summary/details/context. Total encoded normalized payload limit: 32768 bytes; no truncation.

Episode/sequence information is persisted now. Workers now apply stale-state rejection and occurrence-versus-active-condition behaviour; ingestion itself does not change current alarm state.

## Durable identity, observation and local ownership

Unique identity is (source, source_instance, event_id, mode). The stored SHA-256 is over the normalized event JSON. An identical retry returns already_accepted; changed data under the same identity returns failed/event_identity_conflict. Observation and centralized submissions deliberately occupy separate rows/jobs. Observing an event therefore cannot consume later centralized delivery ownership. Reporting must distinguish modes to avoid counting the same observed/centralized event twice.

Each row records UTC occurred/received times and processing_poller_id from the local Cacti configuration, separately from original collector_id. Each mode gets one pending *processing* job. Observation jobs evaluate shadow state and record decisions without creating dispatchable delivery jobs. Centralized jobs route to independent destination jobs. Nothing calls an external endpoint during ingestion.

Ingestion uses an InnoDB transaction containing event insertion and job insertion. A uniqueness constraint and locking current read resolve concurrent retries; a random insertion token distinguishes the winning insert from duplicates without relying on connection-specific affected-row settings. Completed jobs remain completed on retry. Producers must invoke outside their own transaction; an already active transaction is refused without committing/rolling it back. If source state and ingestion need an atomic boundary, design a producer outbox rather than treating an uncommitted nested write as durable acceptance.

Remote ingestion uses the local DB. Local routing and queue processing are implemented with offline Cacti CLI bootstrap. HTTP/email/script adapters and explicit configuration/key provisioning are implemented; full Cacti remote qualification and central history reconciliation remain pending. Reconciliation must preserve original identity and remotely completed delivery ownership instead of redispatching these rows.

## CLI fixture

From an installed lab plugin directory:

```
php cli/submit_event.php --file=examples/event.json --mode=observe
```

Default mode is observe. The file is bounded to 64 KiB before parsing. The command uses the Cacti hook rather than bypassing disabled-plugin semantics, reports only status/error/row ID and exits 0 only on acceptance. Reusing the supplied fixture repeats the same event identity. Edit event_id/episode/sequence/time for a new event; do not change payload contents and keep the same ID unless deliberately testing a conflict.
