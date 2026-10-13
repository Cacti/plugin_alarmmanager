# Implementation plan

Read this file, DECISIONS.md and PROGRESS.md before each phase. The detailed acceptance contract is in ../alarmmanager-build-prompts.md.

| Phase | Scope | Gate |
| --- | --- | --- |
| 1 | Pinned discovery, metadata/lifecycle skeleton, setup key, docs | Local syntax/setup checks; live install qualification pending |
| 2 | Event envelope, indexed InnoDB schema, durable idempotent ingestion | Implemented; 30 real MariaDB checks plus offline validation pass; full Cacti/MySQL/remote qualification pending |
| 3 | Episodes, routing, suppression, multiple destinations | Implemented; lifecycle, suppression, fan-out and ordering tests pass; live Cacti qualification pending |
| 4 | Supervisor and bounded local workers on main and remote | Implemented; real DB lease/fencing, crash/retry/fairness/shutdown tests pass; full remote Cacti outage qualification pending |
| 5 | REST API/webhook/email/script mappings and credentials | Authenticated encryption, backup/rotation, remote key provisioning, per-endpoint fan-out/retry evidence |
| 6 | Management UI and adapted syslog dashboard | Realms/CSRF, browser theme/accessibility/network/query checks |
| 7 | Separate opt-in thold/syslog patches | Legacy parity, acceptance ownership and source-state preservation |
| 8 | Confirmed core transitions and autonomous remote operation | Main outage, independent routing/delivery, reconnect without redispatch, collector reassignment fencing |
| 9 | WebSocket and outage policies | Authorized replay/backpressure/receiver acknowledgment and bounded load |
| 10 | Release/lab rollout qualification | Install/upgrade/retention/restart/outage exercise and honest feature matrix |

Proposed modules: includes/ for Cacti boundaries, lib/AlarmManager/ for domain/queue/transport classes, cli/ for submitter/supervisor/admin tasks, css/ and js/ for local UI, docs/ for contracts/configuration, integrations/ for revision-pinned upstream patches, tests/Unit/ and tests/Integration/ for behaviour. Create these only when their implementation lands.

Open qualification work: actual lab PHP/database versions, MySQL lane, live plugin install/enable/disable/uninstall, remote settings/key provisioning policy, persistent tables outside destructive framework cleanup, safe event identity across collectors and user-visible delivery ownership configuration.
