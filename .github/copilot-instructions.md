# Copilot instructions — Alarm Manager for Cacti

Start from **`CLAUDE.md`** at the repository root; it is the complete
agent guide (identity, conventions, quality gates, test rules). The
authoritative PHP style contract is `.github/instructions/php.instructions.md`
— follow it, never edit it.

Standing rules that most often get violated:

1. Identity is fixed: repository `bmfmancini/plugin_alarmmanager`, plugin
   directory `alarmmanager`, prefix `alarmmanager_` (Cacti lifecycle functions
   are `plugin_alarmmanager_*`), tables `plugin_alarmmanager_*`, hook
   `alarmmanager_submit`. Never push to or open PRs against a Cacti
   organization; the repo stays private unless the user changes it.
2. No secrets in logs, source or responses — log reason codes only, via
   `alarmmanager_log()`.
3. Run the quality gates before every commit (exact commands in
   `CLAUDE.md`): pest unit lane, `php -l`, php-cs-fixer dry-run, and phpstan
   from this directory with `CACTI_PATH` set. The phpstan baseline may only
   shrink.
4. Append a dated, evidence-backed entry to `docs/PROGRESS.md` for every
   substantive change; binding decisions go to `docs/DECISIONS.md`. Mark
   anything not proven by a command/test as UNVERIFIED rather than claiming it.
5. Do not add backward-compatibility shims, function_exists guards around
   Cacti helpers, or database migration paths — the project is greenfield
   (never released). Keep only Cacti-framework-required boilerplate.