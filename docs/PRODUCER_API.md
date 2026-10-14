# Producer alarm API

Alarm Manager 0.9.0 exposes `POST /cacti/plugins/alarmmanager/alarmmanager_api.php`. Any plugin or monitoring system can publish the same versioned JSON event contract. Alarm Manager has no source-specific API handlers: translation, event identity, source counters, and retry queues belong to the producer.

## Register a client

In Administration → API clients, enter a name, source name, source instance and collector. Select allowed devices, or explicitly grant all devices and/or events without a mapped device. Start in Observe mode; centralized ownership requires an enabled routing profile and an enabled rule for that profile. The producer's mode must match the client. The account's profile always overrides producer-supplied routing context.

Saving a new client generates a random bearer token, displayed once. Copy it to the producer's private configuration. Only its SHA-256 hash is stored; it is excluded from configuration histories and ordinary UI reads. Edit the client without rotating to preserve its token, use Generate a replacement API token to rotate, or disable the client to revoke access. Source and instance names must match the event exactly. `cacti_device` remains reserved for the existing leased device-ownership contract.

Use HTTPS with certificate verification for network requests. Plain HTTP is accepted only from a loopback peer (`127.0.0.1` or `::1`); forwarding headers do not grant a loopback/TLS exception. If TLS terminates at a trusted reverse proxy, configure the server's HTTPS indication and Authorization forwarding correctly. Cacti browser cookies and passwords are not API credentials.

## Submit a standard event

Headers:

```text
Authorization: Bearer <issued token>
Content-Type: application/json
```

Body:

```json
{
  "contract_version": 1,
  "mode": "observe",
  "event": {
    "event_id": "alarm-42-sample-1001",
    "source": "monitor",
    "source_instance": "cacti-poller-1",
    "resource_id": "42",
    "alarm_key": "monitor:42:temperature",
    "episode_id": "outage-42-1001",
    "sequence": 1,
    "transition": "raised",
    "severity": "major",
    "occurred_at": "2026-10-14T12:00:00Z",
    "collector_id": 1,
    "device": {"id": 42, "hostname": "router.example.test"},
    "summary": "Temperature above threshold",
    "details": "Measured 85 degrees; threshold 80.",
    "context": {"rule_name": "Temperature", "source_suppressed": false}
  }
}
```

Use `raised`, `updated`, `reminder`, `recovered`, or `occurrence` for transitions, and `critical`, `major`, `minor`, `warning`, `info`, or `clear` for severity. Event IDs must be stable across retries; preserve the original timestamp and entire event. Sequence numbers increase per alarm stream and episode IDs identify the active episode. The event contract and UTF-8/text/JSON limits are validated by the existing ingestion engine; an HTTP body is limited to 32 KiB. Extra fields, including a caller-supplied internal producer receipt, are rejected.

Acceptance follows the database commit containing the event and processing job. First acceptance returns HTTP 202; an identical retry returns HTTP 200:

```json
{"status":"accepted","event_id":"alarm-42-sample-1001","event_row_id":123,"mode":"observe","owned":false}
```

`already_accepted` uses the same event-row ID and creates no additional processing job. Reusing an identity with different content returns 409. Other responses are 400 (invalid contract), 401 (missing/invalid/disabled credential), 403 (transport or scope denied), 405 (method), 413 (body too large), 415 (content type), and 503 (unavailable/unconfirmed storage). No SQL, credentials, request bodies or internal diagnostics are returned.

## Notification ownership and retries

Observe acceptance records alarms while the producer retains delivery. Centralized acceptance reports `owned:true`; Alarm Manager owns downstream routing/delivery, subject to normal rule matching and suppression. Acceptance does not promise that a notification has already been delivered. Changing a client's profile/mode while events are pending can invalidate retries: drain the old outbox first, then change both ends together. Routing or suppression changes affect delivery as they do for other ingestion paths.

The producer must reserve an event durably BEFORE HTTP submission. A timeout can mean the receiver committed but the acknowledgement was lost. Keep the original event pending and retry it; never send a second legacy notification after an uncertain centralized submission. Authentication, contract, or scope errors also remain pending for operator correction; do not change event IDs automatically. The source outbox relies on the producer database: source storage failures before reservation retain the existing legacy fallback, and failures after reservation retain pending ownership.

## Syslog and Thold migration packages

The revision-pinned patches in `integrations/patches/` still intercept each producer's real notification branches and retain its normalization/counters/logging. The installed producer contains the generic bridge, HTTP client, and `cli/alarmmanager_api.php`; the Alarm Manager server does not execute those adapters.

1. Upgrade Alarm Manager and remote collectors to schema v8 before using the new API or v2 provisioning bundles. Keep existing source notification ownership in place.
2. Register a client for the source and collector. The supplied bridges identify their instance as `cacti-poller-<collector ID>`. For Syslog without a verified device association, explicitly permit unmapped events; a syslog host ID is not treated as a Cacti device ID.
3. Review/apply the patch against its documented exact producer revision. Dirty/newer producer versions need a reviewed port; do not overwrite unrelated source changes. Upgrade the bridge files in all producers sharing one PHP process before enabling API submission, so an older shared bridge class cannot take precedence.
4. Run the producer's `php cli/alarmmanager_api.php --install`. This idempotent source-side setup creates its outbox/stream tables. It does not depend on Alarm Manager being installed on the sending server.
5. Store `/etc/cacti/alarmmanager-producers/<source>.json`, outside the document root and unreadable by other users (0600, or 0640 for the source worker group). `ALARMMANAGER_PRODUCER_API_DIR` can specify another private directory.

```json
{
  "url": "http://127.0.0.1/cacti/plugins/alarmmanager/alarmmanager_api.php",
  "token": "<issued token>",
  "mode": "observe",
  "profile_id": 0,
  "channels": [],
  "complete_history": true
}
```

For centralized handoff, use the same `mode` and positive `profile_id` as the API client and explicitly list the source channels to replace (`email`, `script`, and Syslog `ticket`). Verify replacement recipients, message fields, scripts and attachments before switching; existing source-specific recipient/template behavior is not automatically imported. An optional absolute `ca_bundle` supports private HTTPS certificate authorities.

6. Schedule the producer's bounded recovery command on every opted-in collector:

```sh
php cli/alarmmanager_api.php --retry-pending --source=syslog --limit=100
php cli/alarmmanager_api.php --retry-pending --source=thold --limit=100
```

Nonzero exit indicates pending work or unavailable recovery. Pending source receipts retain their original identity/mode/channels; retrying never invokes source delivery. Drain legacy pending receipts before installing an API policy; do not reinterpret an old receipt as a new HTTP handoff. Historical producer policy tables and already accepted receipts remain intact, but the manager's old producer-policy editor/save action is retired.

## Remote collectors

Main-poller configuration export produces a v2 provisioning bundle containing only API accounts scoped to the requested collector. It copies token hashes, not raw tokens. Clients whose routing profile is excluded are provisioned disabled, preventing stale admission after a route is removed from the export. Import preserves local keys and queues and rejects foreign collector scopes or colliding source identities. Existing v1 bundles remain supported.

Provision a collector-local API receiver and source configuration before disconnecting main. The source posts to its local loopback/HTTPS receiver and retries its local outbox independently. Existing history reconciliation carries accepted events/outcomes back to main without redispatch. Re-provision after token rotation or disabling an account; a disconnected collector keeps its last provisioned policy until it is updated. Provision source credential files privately on each collector; do not replicate producer outboxes or place raw tokens in public Cacti resource replication.
