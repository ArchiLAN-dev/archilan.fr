# Story 33.12: Mutation Testing (Infection) (api/)

Status: ready-for-review

## Story

As a maintainer relying on the api test suite as the refactor safety net,
I want mutation testing (Infection) measuring whether the tests actually catch regressions, with a baseline MSI and an advisory CI gate,
so that "green suite" means "tests assert meaningfully", not just "code ran".

## Context

CI already enforces a line-coverage floor (`% statements covered`). Coverage measures how much code runs;
it does not measure whether a test would FAIL if the code were wrong. After the epic's large refactors
(~378 files in 33.10/33.11), that distinction matters. Infection mutates the code (flip `<`, drop a `return`,
negate a condition) and checks a test fails; the Mutation Score Indicator (MSI) is the signal.

**Environment note:** the local PHP (8.4.12) has no pcov/xdebug, so Infection cannot run bare-metal locally.
It runs in CI (pcov present) and in a throwaway pcov Docker image for a local baseline.

## Acceptance Criteria

1. **AC1 - Infection installed + configured.** `infection/infection` added (dev); `infection.json5` at api root
   with source `src/`, phpunit adapter, sensible mutators (default set), `threads: max`, per-mutant timeout,
   `.gitignore` for `infection.log`/`.infection.cache`.
2. **AC2 - Baseline MSI recorded.** A baseline MSI (and covered-code MSI) captured on a bounded, representative
   slice (a mid-size context, e.g. `Community` or `Identity`) via the pcov Docker run, recorded in the Dev Agent Record.
   Full-repo mutation is not run in this story (too slow for one pass); the CI gate runs diff-scoped.
3. **AC3 - Advisory CI gate, diff-scoped.** A CI step runs Infection with `--git-diff-lines --git-diff-base=origin/develop`
   + `--min-msi`/`--min-covered-msi` set to a low advisory floor (non-blocking first, `continue-on-error`), mirroring
   the existing coverage-floor step; pcov already available in the backend workflow. The floor is raised over time.
4. **AC4 - Hotspots recorded, not all fixed.** The lowest-MSI findings from the baseline slice are listed as targeted
   test-hardening follow-ups; this story delivers the tooling + baseline, not a suite-wide MSI lift.
5. **AC5 - Existing gates unaffected.** `composer gates` + `pnpm gates` unchanged and green (Infection is additive).

## Tasks / Subtasks

- [x] Task 1: `infection/infection ^0.34` (dev) installed, plugin allowed, artifacts gitignored (`var/infection/`, `.infection.cache`).
- [x] Task 2: `infection.json5` (source `src/`, excludes Kernel/Console/Double, `@default` mutators, phpunit adapter targeting the new `unit` suite, timeout 30s). `composer infection` script added. **phpunit.xml.dist split into `unit`/`functional` testsuites** so mutation runs the fast DB-free unit suite (full `bin/phpunit` unchanged: 1463 tests; `--testsuite unit` = 565).
- [x] Task 3: pcov Docker image (php:8.4-alpine + pcov + zip/intl/pdo_pgsql) + tmpfs for `var/` (Windows bind-mount could not re-read the coverage-xml). **Baseline (Community, unit-suite): Mutation Code Coverage 100%, Covered-Code MSI 72%** (141 timeouts at 30s - a hotspot to investigate, see AC4).
- [x] Task 4: advisory `continue-on-error` Infection step in `backend.yml` (git-diff-lines vs origin/develop, `--logger-github`, pcov reused). Non-blocking until the floor is tuned.
- [x] Task 5: `composer validate` OK, cs-fixer 0, arch green, unit+full suites green; PR opened.

## Dev Notes

- Do NOT run full-repo Infection (mutation runs the suite once per mutant - hours). Scope to a context for the
  baseline and to the diff in CI.
- pcov Docker: `php:8.4-cli-alpine` + `pecl install pcov` + `docker-php-ext-enable pcov`, mount the repo, host
  vendor is pure-PHP so it mounts fine; run `vendor/bin/infection --filter=src/Community ...` or `--only-covered`.
- CI backend workflow already sets `coverage: pcov` on setup-php; reuse it. Infection reads the phpunit coverage.
- Infection's `--test-framework=phpunit`, `--skip-initial-tests` if a coverage clover/xml is already produced by the
  existing coverage step (reuse it to avoid double-running the suite).
- Windows/exec lessons still apply for any scripting (memory: [[feedback-windows-shell-replaces]]).

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M).

### Debug Log References

- Local PHP has no pcov/xdebug -> baseline via a throwaway pcov Docker image; two image gaps found
  and fixed (missing `zip`, then Windows bind-mount could not re-read Infection's coverage-xml ->
  `var/` moved to a container tmpfs).
- Baseline (Community, unit suite, 4 threads): Mutation Code Coverage 100%, **Covered-Code MSI 72%**,
  9m16s, 141 timeouts (30s each) - the timeouts concentrate the runtime and are the first hotspot.

### Completion Notes List

- Infection installed + configured; the durable structural win is the **phpunit `unit`/`functional`
  testsuite split** (enables fast DB-free mutation and `--testsuite unit` runs generally). Full
  `composer test` behaviour unchanged (both suites = 1463 tests, no overlap).
- Baseline recorded (Community 72% covered-MSI). Not a suite-wide number by design - mutation of the
  whole suite (incl. functional) is a separate, slower run; CI runs it diff-scoped and advisory.
- AC4 hotspots for follow-up test-hardening stories: (1) the 141 timeouts (likely loop/`sleep`-shaped
  mutants or a too-tight 30s bound - investigate before making the gate blocking); (2) run baselines
  on the other unit-heavy contexts (Identity, WeeklyRuns, Membership) to set a repo-wide floor.
- Existing gates untouched (Infection is additive, dev-only); `composer validate` OK.

### File List

- `api/composer.json` + `api/composer.lock` (infection dev dep, plugin allow, `infection` script)
- `api/infection.json5` (new), `api/.gitignore` (infection artifacts)
- `api/phpunit.xml.dist` (unit/functional testsuite split)
- `.github/workflows/backend.yml` (advisory diff-scoped Infection step)
- `_bmad-output/implementation-artifacts/33-12-mutation-testing-infection.md` (this story)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-06 | Story created (epic-33 follow-up 33.12). Infection for mutation testing; baseline on a bounded slice via pcov Docker (local PHP has no coverage driver); advisory diff-scoped CI gate. Status: ready-for-dev. |
