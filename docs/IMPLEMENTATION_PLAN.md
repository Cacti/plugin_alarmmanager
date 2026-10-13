# Implementation plan

Read this file, DECISIONS.md and PROGRESS.md before each phase. The detailed acceptance contract is in ../alarmmanager-build-prompts.md.

| Phase | Scope | Gate |
| --- | --- | --- |
| 1 | Pinned discovery, metadata/lifecycle skeleton, setup key, docs | Local syntax/setup checks; live install qualification pending |
| 2 | Event envelope, indexed InnoDB schema, durable idempotent ingestion | Implemented; 30 real MariaDB checks plus offline validation pass; full Cacti/MySQL/remote qualification pending |
| 3 | Episodes, routing, suppression, multiple destinations | Implemented; lifecycle, suppression, fan-out and ordering tests pass; live Cacti qualification pending |
| 4 | Supervisor and bounded local workers on main and remote | Implemented; real DB lease/fencing, crash/retry/fairness/shutdown tests pass; full remote Cacti outage qualification pending |
| 5 | REST API/webhook/email/script mappings and credentials | Implemented; mapping/AEAD/backup/rotation/provisioning, real HTTP/TLS/script and pinned Cacti SMTP checks pass; full deployed Cacti qualification pending |
| 6 | Management UI and adapted syslog dashboard | Implemented; scoped real DB/Cacti policies and browser modern/dark/classic/narrow/CSRF/escaping/network checks pass; full installed Cacti qualification pending |
| 7 | Separate opt-in thold/syslog patches | Implemented; pinned source branch tests, durable receipt/fallback/concurrency and protected admin checks pass; full installed producer/remote qualification pending |
| 8 | Confirmed core transitions and autonomous remote operation | Implemented; three-database and real TLS outage/reconnect/ACK replay, source transition/cache and reassignment fencing fixtures pass; installed remote Cacti qualification pending |
| 9 | WebSocket and outage policies | Authorized replay/backpressure/receiver acknowledgment and bounded load |
| 10 | Release/lab rollout qualification | Install/upgrade/retention/restart/outage exercise and honest feature matrix |

Follow plugin_thold's layout for shared plugin PHP: use flat, lowercase files in includes/ for Cacti boundaries, domain logic, queues, transports and exception classes. Keep plugin entry pages, setup.php and INFO at the root; css/, js/ and locales/ hold their respective assets. Do not introduce a plugin-local lib/ or lib/AlarmManager/ tree. Alarm Manager's cli/, docs/, examples/, integrations/, service/ and tests/ directories group its existing command-line tools, documentation, fixtures and deployment assets; these are project-specific additions, not replacements for Cacti's plugin runtime layout. See docs/DECISIONS.md for the reference and layout decision.

Open qualification work: actual lab PHP/database versions, MySQL lane, live plugin install/enable/disable/uninstall, remote settings/key provisioning policy, persistent tables outside destructive framework cleanup, safe event identity across collectors and user-visible delivery ownership configuration.

