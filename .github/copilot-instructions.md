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
4. The `docs/` narrative documents were removed in October 2026 pending a
   rewrite — do not create or append to `docs/PROGRESS.md`, `docs/DECISIONS.md`
   or similar. Mark anything not proven by a command/test as UNVERIFIED rather
   than claiming it.
5. Do not add backward-compatibility shims, function_exists guards around
   Cacti helpers, or database migration paths — the project is greenfield
   (never released). Keep only Cacti-framework-required boilerplate.

## Internationalization (i18n)

- Translatable strings are managed with GNU gettext via
  `locales/build_gettext.sh`. `locales/po/alarmmanager.pot` is the source
  template; Weblate owns syncing the per-language `.po`/`.mo` files from it.
- **Never commit the per-language `.po` or compiled `.mo` files**
  (`locales/po/*.po`, `locales/LC_MESSAGES/*.mo`) in a plugin pull request.
  Weblate is the sole owner of those catalogs, and regenerating them here
  produces spurious diffs and merge conflicts. `locales/po/alarmmanager.pot`
  is the only translation artifact a pull request may add or modify.
- When a pull request adds or changes a string wrapped in
  `__()`/`__n()`/`__esc()`/`__x()`/`__xn()`/`__gettext()`, run
  `locales/build_gettext.sh` before pushing and stage
  `locales/po/alarmmanager.pot` only. The script also rewrites the
  `.po`/`.mo` files as a side effect; revert those before committing
  (`git checkout -- locales/po/*.po locales/LC_MESSAGES`), or run only the
  `xgettext` step that targets `alarmmanager.pot`.
