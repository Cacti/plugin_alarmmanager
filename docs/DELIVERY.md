# Mapping and delivery adapters

Version 0.5.0 adds worker-owned REST API, webhook, Cacti email and controlled script delivery. WebSocket remains phase 9. HTTP requires PHP cURL. Email/script children require Linux /usr/bin/setsid and posix in addition to worker pcntl. All code/dependencies are local: email uses Cacti's bundled PHPMailer; no dependency download or CDN occurs at runtime.

## Endpoint configuration

Add a transport object to each endpoint in the complete routing configuration. Apply examples/routing-delivery.json in an isolated lab after changing URLs and local certificate paths. REST API and webhook have separate methods, URLs, mappings, credentials, success criteria, jobs and retries. Configuration and IDs are administrator-controlled; no event can select executable code, a URL or credentials. Omitted endpoint IDs are disabled; old endpoints without transport configuration cannot send.

Transport definitions are non-secret versioned routing configuration. Credential material belongs in encrypted records. Never place passwords/tokens in mapping constants, URLs, event text or routing JSON. URL userinfo, query parameters and fragments are refused; use authenticated headers. Anonymous HTTP requires explicit allow_http=true and cannot use stored credentials. Internal/private hosts are supported. Ambient proxies and netrc are disabled; redirects are refused, including redirects to the same host. No Authorization header is forwarded to a redirect target.

Queued jobs use the endpoint's current transport definition and credentials at attempt time. A configuration revision in the original routing decision records the selection policy; it does not freeze the destination URL or secret for retries. Disable and drain/review an endpoint before reusing its ID for a different receiver. A credential update cannot recall an in-flight request. Endpoint idempotency keys remain stable through retries. Receivers must deduplicate that key to avoid repeat external effects after a successful send with a lost completion acknowledgment. Email and scripts do not provide receiver-level exactly-once semantics.

## Self-signed certificates and private CAs

Use the absolute local PEM file path in transport.ca_bundle to trust a self-signed server certificate or a private CA:

```json
{
  "url": "https://alarms.internal.example/api/alarms",
  "method": "POST",
  "ca_bundle": "/etc/cacti/alarmmanager/internal-ca.pem",
  "credential": true,
  "mapping": {"default": [{"to": "hostname", "source": "device.id", "type": "string"}]}
}
```

Install that certificate/CA file on every authorized poller and make it readable by the worker account. Use a server certificate with a subject alternative name matching the URL hostname (or IP address). Certificate chain and hostname verification stay enabled. Null/omitted ca_bundle uses the system trust store. Tests generate a self-signed certificate, confirm authenticated delivery with that trust file, and reject it without the file. Expired, mismatched or otherwise invalid certificates remain failures; no blanket verification bypass is implemented.

## Mapping contract

Mapping has default and optional raised/updated/reminder/recovered/occurrence lists. A transition-specific list replaces default; it is not merged. Each item has to (a dotted destination object path), exactly one source or constant, optional default, required, type and values. Prefix/duplicate paths conflict, numeric path segments are refused, and JSON is encoded structurally. At most 64 items per transition, eight nested object levels and 64 KiB encoded output are allowed. Required rejects a missing/null value; empty strings are valid values. Defaults apply only to missing/null input. Explicit null can be emitted for optional fields.

Sources: event.id/source/source_instance/collector_id/occurred_at; alarm.resource_id/key/episode_id/sequence/transition/severity/summary/details; device.id/hostname/description/tags; allowlisted context rule/metric/threshold/suppression fields. No raw device or credential fields are exposed. Types: identity, string, integer, number, boolean and html. Integer/number/boolean conversions reject ambiguous inputs; html escapes scalar text for HTML email. Values maps exact scalar source values, e.g. major to 2 or recovered to resolved; an unmapped value fails instead of silently guessing. Constants may contain structured JSON. Examples map device.id to hostname for REST API and host for webhook, with independent recovered payloads.

HTTP accepts POST/PUT/PATCH/DELETE. Default success is any 2xx, configurable to selected 2xx codes. Optional response_match.path/equals checks a bounded JSON response field with strict type equality. 408/425/429 and 5xx retry; other status failures, redirects, certificate errors, oversized response and failed success criteria are terminal. Retry-After accepts seconds or HTTP date, capped at one hour, and is combined with queue backoff. HTTP body is limited to 64 KiB and response headers to 16 KiB. cURL connect and total deadlines complement the worker alarm. Attempts record sanitized codes/duration, never response bodies, header values, URLs, stderr or credential errors.

## Email and scripts

Email maps to root subject and body strings, with configured to/from, html and up to eight Cacti attachment descriptors. Recipient strings and arrays are forwarded using the native send_mail signature; tested multiple recipients use [{"email":"one@example.test","name":"One"}]. From may be empty to use Cacti's configured sender. Subject/header controls are rejected. Use type=html when mapping untrusted text into an HTML body. Attachments preserve Cacti attachment/filename/mime_type/inline/encoding and graph descriptor fields; constants are bounded, readable absolute files are limited to 1 MiB each. Provision attachments on each poller. Core SMTP settings and its existing mail logging remain Cacti-owned; it can log recipient/subject metadata. Do not put secrets in those fields. A fresh offline Cacti child runs the mailer; the parent enforces the endpoint deadline and kills the process group on timeout. Main ini is passed when present; install the required modules in normal CLI PHP configuration. Tests exercised the actual pinned Cacti send_mail and bundled PHPMailer against loopback SMTP, HTML and attachment content.

Script requires an explicitly configured absolute executable and up to 32 constant structured arguments. Payload JSON arrives on stdin; ALARM_IDEMPOTENCY_KEY and ALARM_TRANSITION are supplied in a clean environment. There is no shell evaluation or raw command-template substitution. Default success is exit 0, exit 75 retries, and other exits fail; retry_exit_codes can override that list. Runtime is bounded by timeout_seconds and combined stdout/stderr by 64 KiB; output is discarded from diagnostics. Ordinary descendants are killed with the process group. A deliberately escaping/admin-authored script is not sandboxed; use a restricted worker OS account.

compatibility=json is the default script contract. compatibility=syslog_ticket appends --alert-name, --severity, --hostlist and --message as separate argv entries using normalized safe fields, following the inspected syslog ticket wrapper. It does not claim numeric syslog severity/host aggregation parity: phase-7 producers must preserve their source-specific context. Existing thold/syslog shell templates or THOLD_*/ALERT_* environment contracts are not imported automatically; migrate them to a reviewed executable wrapper or retain legacy delivery until producer integration. Script effects may repeat after timeout/crash; receiver/wrapper idempotency is required.

## Preview and explicit test delivery

From the installed plugin directory:

```sh
php cli/endpoint.php --endpoint=1 --file=examples/event.json --preview
php cli/endpoint.php --endpoint=1 --file=examples/event.json --test-send
```

Preview validates/maps the event without loading secrets or sending any traffic. It displays only endpoint ID/type, mapped payload and a credential-redaction marker. Test-send writes a separate synthetic source/stream and exactly one selected endpoint delivery; it bypasses production routing/suppression deliberately and records explicit_test_send. The normal delivery worker performs the send and records attempts. Test requests do not change real alarm conditions. These are administrator OS CLI actions; browser actions/realms/CSRF arrive in phase 6.
