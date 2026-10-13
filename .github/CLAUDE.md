# CLAUDE.md — Alarm Manager for Cacti

Guidance for AI agents (and humans) working in this repository. For the full
standing decisions, read `docs/DECISIONS.md`; for the chronological phase log,
read `docs/PROGRESS.md`. The authoritative Cacti PHP style contract lives in
`.github/instructions/php.instructions.md` — never edit that file; follow it.

## Identity — never drift from these

- GitHub owner is **bmfmancini**, repository **bmfmancini/plugin_alarmmanager**.
  Nothing here is Cacti Group property. Never push to a Cacti organization.
- The plugin install directory is `alarmmanager` (no `plugin_` prefix). The
  function/settings/hook prefix is `alarmmanager_`; the one Cacti-controlled
  hook registered by this plugin is `alarmmanager_submit`; lifecycle functions
  Cacti itself names use the `plugin_alarmmanager_*` prefix.
- Every database table is `plugin_alarmmanager_*`.
- Supported platform (pinned in docs/DISCOVERY.md): Cacti 1.2.x, PHP >= 8.2,
  MariaDB >= 10.11 / InnoDB (MySQL refused until a lane is qualified).
- The repository is private by default. Do not change visibility or push
  anywhere without an explicit user instruction.
- Keep keys, secrets, ciphertext and credentials out of logs, source, commit
  messages and API responses — including the Cacti log lines written by
  `alarmmanager_log()`. Log reason codes only.

## Conventions (enforced by CI; keep them green)

- Cacti style: tab indentation, same-line braces, single quotes, short arrays.
  Always run php-cs-fixer with the repo `.php-cs-fixer.php` before committing.
- Web pages bootstrap through `include/auth.php` and use Cacti's helpers:
  `top_header()`/`bottom_footer()`, `html_start_box()`/`html_end_box()` and the
  form/table helpers, `html_escape()` and `__()`/`__esc()` (text domain
  `'alarmmanager'` is always the LAST argument). Never emit HTML without
  escaping, never invent UI helpers.
- Request variables go through `get_filter_request_var()`, `get_request_var()`,
  `get_nfilter_request_var()` and `validate_store_request_vars()`. Never read
  `$_GET`/`$_POST`/`$_REQUEST` directly (the one sanctioned exception: header
  and transport metadata in `alarmmanager_sync.php`).
- Errors and operational events are reported through `alarmmanager_log()` in
  `includes/runtime.php` (`cacti_log('[alarmmanager] …', false,
  'alarmmanager')`). The supervisor parent process (`includes/supervisor.php`,
  `alarmmanager_daemon.php`) never bootstraps Cacti — keep it stderr-only.
- All user-visible strings are wrapped in `__()`/`__esc()`. After string
  changes run `locales/build_gettext.sh` so `locales/po/alarmmanager.pot`
  stays fresh (CI fails on a stale pot).
- One ASCII GPL license box header per file, copyright `The Cacti Group`
  (the standard Cacti header used by thold/syslog — same style on PHP,
  shell and workflow files). Never re-introduce per-file copyright drift.

## Quality gates — run before every commit

QA tooling has **no composer.json by design** (thold convention): run tools
from a sibling Cacti checkout's `include/vendor/bin/*`, with the plugin
checkout placed beside it as `cacti/plugins/alarmmanager` or the paths below
adjusted.

Local QA environment on this machine: sibling develop-clone at
`~/Documents/GitHub/cacti-qa` with `plugins/alarmmanager` symlinked to this
repository. From this repository root:

```sh
QA=/home/sean/Documents/GitHub/cacti-qa/include/vendor/bin

# Unit lane (fast, stubbed)
$QA/pest -c phpunit.xml

# Integration lane (real MariaDB; skipped without a DSN)
ALARMMANAGER_TEST_DSN='mysql:host=…;dbname=…' \
ALARMMANAGER_TEST_USER=… ALARMMANAGER_TEST_PASSWORD=… \
ALARMMANAGER_TEST_ALLOW_CREATE=1 \
  $QA/phpunit -c phpunit-integration.xml

# Static analysis — MUST run from this directory (includes/baseline resolve
# against the process CWD) and MUST have CACTI_PATH pointing at a Cacti tree
env CACTI_PATH=/home/sean/Documents/GitHub/cacti-qa \
  $QA/phpstan analyse --configuration=.phpstan.neon --no-progress

# Coding standards (write: drop --dry-run)
$QA/php-cs-fixer fix --dry-run \
  --config=/home/sean/Documents/GitHub/plugin_alarmmanager/.php-cs-fixer.php

# Syntax over every shipped PHP file
find . -name '*.php' -not -path './tests/*' -exec php -l {} \;
```

Notes:
- `phpstan-baseline.neon` exists so level-6 can stay strict on a green tree.
  It may only shrink; do not extend it for new code.
- The integration lane is DSN-gated and skips (never fails) without a
  dedicated loopback-only test database. Never point it at a production
  database; fixtures create and drop schemas.
- `tests/fixtures/cacti_web_functions.php` is the pinned-Cacti web-lane
  fixture; it is executed as a script and refuses non-test invocation.

## Tests

- Ported to PHPUnit/Pest: `tests/Unit` (phpunit.xml), `tests/Integration`
  (phpunit-integration.xml, `#[Group('db')]`). Doubles live in
  `tests/Helpers/CactiStubs.php` (`function_exists`-guarded so a real Cacti
  tree wins). Cacti-signature stubs for static analysis are in
  `tests/Helpers/phpstan-stubs.php`, wired from `.phpstan.neon`.
- Do not re-add `function_exists()` guards around Cacti core helpers in
  shipping code — production paths always run inside `auth.php`/`cli_check.php`.
- Web markup claims must be verified through the DB-gated lane
  (`tests/Integration/WebTest.php`), which loads real Cacti helpers.

## Documentation discipline

- Every substantive change appends one timestamped entry to `docs/PROGRESS.md`
  (what changed + validation evidence) before the commit.
- Architecture decisions that bind later work go to `docs/DECISIONS.md`.
- `docs/PROGRESS.md` and `docs/CHANGELOG.md` distinguish VERIFIED (command +
  evidence) from UNVERIFIED claims. Never claim a Cacti behavior as verified
  unless a test or a cited Cacti source path confirms it.