# Notification integrations

Alarm Manager 0.10.0/schema v9 adds reusable generic webhook, REST API and JSON script templates, persistent external references, and visible browser preview/test results. Existing email behavior remains unchanged.

## Configure, test and route

1. Open Administration → Integrations and mappings. Choose Generic webhook, Generic REST API or JSON script and click Prepare new integration. This read-only action fills a new disabled draft; it does not save, overwrite an endpoint or copy credentials.
2. Replace the example HTTPS URL or local executable path. Give the integration a name, configure authentication separately, and choose the alarm fields and field names expected by your receiver. Standard fields cover every transition; a transition-specific mapping replaces the standard mapping. Scripts receive mapped JSON on stdin and literal configured arguments, without shell evaluation.
3. Save the endpoint. Preview a complete sample event under Preview and test; the resulting payload appears after redirect without sending traffic or loading saved secrets. Include realistic device, episode and transition values. Preview can read a previously completed reference for that exact endpoint/episode.
4. Enable the endpoint when ready. Confirm Queue explicit test send to enqueue one synthetic event for that destination. The displayed event row identifies the test; View delivery results opens existing job history. Processing/delivery workers perform the send independently of the browser. Pending means queued, leased means running, completed means succeeded and failed means terminal failure; attempts show individual retries. Tests deliberately bypass routing/suppression and do not alter real alarm conditions.
5. Select the endpoint in a routing profile and use that profile in a rule. Each destination keeps its own jobs, attempts and idempotency key. Failure of one integration does not resend another integration's success. Pool sizing stays in Daemon administration.

Remote pollers require explicit configuration/credential provisioning and any local executable/CA files. Templates do not replicate secrets or overwrite operational state. Receiver/wrapper deduplication is still required after a successful external action followed by a lost completion acknowledgment.

## External ticket or message references

For a receiver that creates a ticket, configure Response JSON path for external ID and optionally Response JSON path for HTTPS link. Paths use bounded dotted object keys (for example `ticket.id` and `ticket.url`), not JSONPath expressions. The transport configuration is:

```json
"response_reference": {"id": "ticket.id", "url": "ticket.url"}
```

A successful HTTP response, or stdout from a successful script, can return:

```json
{"ticket": {"id": "CASE-42", "url": "https://tickets.example.invalid/cases/CASE-42"}}
```

Only the selected ID and link are stored; raw response/stdout and unrelated fields are discarded. IDs accept integers or 1–128 ASCII letters, digits, underscores, periods, colons and hyphens, starting with a letter or digit. A link may be null; non-null links must be HTTPS, at most 2048 bytes, without embedded credentials, query strings, fragments or control characters. Missing or invalid configured fields produce terminal `external_reference_invalid`; inspect the remote system before replay because its external action may already have succeeded.

Updates, reminders and recovery can map `integration.id` and `integration.url` into their outgoing payload. Values come from the latest earlier completed delivery for the same endpoint, alarm stream and episode. Producer event context cannot supply them. For example:

```json
{"to": "ticket_id", "source": "integration.id", "required": true}
```

A required reference rejects delivery when no earlier reference exists; optional fields map to null or a configured default. A new episode or different destination never inherits the old ticket. Standard queue ordering is retained. Completion writes the reference with the delivery outcome under the same unexpired lease transaction; stale workers cannot overwrite it. Delivery history displays the ID and safe clickable link. Remote signed history carries references into the main archive without creating main-poller delivery jobs.

Generic HTTP integrations keep an administrator-configured URL/method; their receiver uses the supplied external ID to update or resolve a ticket. Use a reviewed local script for providers requiring multi-step calls or URLs constructed from returned IDs. There is no embedded JavaScript engine or Zabbix macro/template importer.

## Upgrade and qualification

Run the normal Cacti plugin upgrade on the main poller before updating remotes. Schema v9 adds a nullable reference column to deliveries, preserving existing jobs, history and keys. The updated main receiver accepts older delivery history without that field; an older main receiver cannot accept the new field from upgraded remotes. Do not reset the schema stamp to run old code against new tables; rollback requires a compatible code/database pair.

Browser and database fixture coverage is documented in TEST_SETUP.md. Those checks do not qualify an installed remote deployment or a specific ticket provider. WebSocket and email removal remain deferred.
