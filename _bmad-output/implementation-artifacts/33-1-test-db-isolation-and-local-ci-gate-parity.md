# Story 33.1: Test DB Isolation & Local/CI Gate Parity

Status: done

## Story

As a developer (human or agent) working on the archilan.fr monorepo,
I want the full test suite to run reliably on an isolated database and a single command that runs the exact CI gate set,
so that local gate runs are trustworthy (no shared-DB flake), reproduce CI pass/fail exactly, and every subsequent Epic 33 story can be verified safely.

## Acceptance Criteria

1. **AC1 - Scripted isolated test run.** A documented, scripted way to run the full `php bin/phpunit` suite on an isolated Postgres database exists, built on the existing `TEST_TOKEN` `dbname_suffix` hook and `scripts/setup-worktree.sh`. It works locally (Docker `archilan-postgres`) and in CI (CI's per-run service container is already isolated; the default empty `TEST_TOKEN` path must keep working there unchanged).
2. **AC2 - Flake closed on the isolated path.** The full `php bin/phpunit` suite runs **10 times consecutively** against an isolated DB with **zero** schema-setup failures (no `relation "..." does not exist` in `FunctionalTestCase::setUp`). The 10-run evidence (loop command + summary) is recorded in the Dev Agent Record.
3. **AC3 - One-command gates, identical locally and in CI.** A `composer gates` (api) and a `pnpm gates` (frontend) command runs the exact CI gate set: api = phpstan (src+tests), php-cs-fixer over the **full dist config (src + tests)**, `app:architecture:ddd`, `php bin/phpunit`; frontend = `typecheck`, `lint`, `test` (jest), `build`. Running the command locally reproduces CI pass/fail for those gates. CI invokes the same underlying scripts (no drift possible).
4. **AC4 - Worktree requirement documented.** A documented note states that parallel agents/sessions **must** use a `git worktree` (the flake's root cause is shared working trees racing one schema, not the code), linking the existing worktree workflow (root `CLAUDE.md` "Sessions parallèles" + `scripts/setup-worktree.sh`).
5. **AC5 - All gates green.** api: phpstan 0 errors, cs-fixer 0 violations, phpunit green with 0 notices/deprecations/warnings, `app:architecture:ddd` exit 0. frontend: typecheck 0, lint 0/0, jest green, build clean.

## Tasks / Subtasks

- [x] Task 1: `composer gates` - api one-command gate runner (AC: 3)
  - [x] 1.1 Add a `"gates"` composite script to `api/composer.json` chaining the existing scripts in CI order: `["@phpstan", "@cs-fixer", "@arch", "@test"]`. Do NOT redefine the commands - reuse the existing `phpstan` / `cs-fixer` / `arch` / `test` script entries so CI (which already calls `composer phpstan`, `composer cs-fixer`, `composer arch`, `composer test`) and local runs share one definition.
  - [x] 1.2 Guard against Composer's 300 s default process timeout killing the phpunit leg: add `"process-timeout": 600` under `config` in `api/composer.json` (scoped rationale: full suite + schema rebuild per test class can exceed 300 s on slower machines; CI passes today but local parity must not die on a timeout).
  - [x] 1.3 Confirmed fact (checked during story creation): `.php-cs-fixer.dist.php` Finder is `->in(__DIR__)` excluding only `var`/`vendor`, so `composer cs-fixer` already covers `src` **and** `tests`. No config change needed - the parity gap is only in the Stop hook (Task 3), which passes `src` as an explicit path argument.
- [x] Task 2: `pnpm gates` - frontend one-command gate runner (AC: 3)
  - [x] 2.1 Add `"gates": "pnpm typecheck && pnpm lint && pnpm test && pnpm build"` to `frontend/package.json` scripts (CI gate set incl. jest; CI order is lint→typecheck→test→build, order is not significant, set is).
  - [x] 2.2 Confirm `pnpm gates` passes locally: `build`/`test` need `NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN` (CI injects `test-channel`; locally it comes from `frontend/.env.local`). If a fresh clone would fail, document the required var next to the script (README or AGENTS.md gates section) - do not hardcode a value in `package.json`. → `pnpm gates` passed locally (2026-07-04); the var requirement is documented in `frontend/AGENTS.md` gates section (present in `.env.example`, CI injects `test-channel`).
- [x] Task 3: Align the local Stop-hook gate runner with CI (AC: 3)
  - [x] 3.1 In `.claude/quality-gates.sh`, replace `vendor/bin/php-cs-fixer check src` with `vendor/bin/php-cs-fixer check` (full dist config, src+tests) so the hook can no longer pass on a test-file violation that CI rejects.
  - [x] 3.2 Add the missing frontend legs to the hook (`pnpm test`, `pnpm build`) **or** explicitly document in the hook header that it is a fast subset and `pnpm gates` / `composer gates` is the authoritative pre-PR command. Choose the documented-subset option if full build makes the Stop hook unacceptably slow (>2-3 min); state the choice in the PR. → **Documented-subset option chosen**: hook header now states it is a fast subset and `composer gates` / `pnpm gates` are the authoritative pre-PR commands (full `next build` in a Stop hook is too slow).
  - [x] 3.3 (added during dev) Isolate the hook's phpunit leg on `archilan_test_stophook` (`TEST_TOKEN=_stophook` + `doctrine:database:create --if-not-exists`). Rationale: the Stop hook fires whenever a session ends, possibly while a foreground/background suite is running on `archilan_test` - observed live during Task 7 (see Completion Notes), reproducing the exact AC2 flake. The hook must never share the default test DB.
- [x] Task 4: Scripted isolated test run in the main tree (AC: 1, 2)
  - [x] 4.1 Create `api/scripts/test-isolated.sh` (bash, Git-Bash compatible - the team is on Windows): takes an optional token name (default e.g. `local`), normalises it like `setup-worktree.sh` does (`[a-z0-9-]`, `-`→`_`, prefixed `_`), then: `php bin/console doctrine:database:create --env=test --if-not-exists` with `TEST_TOKEN` exported, then `php bin/phpunit "$@"` with the same `TEST_TOKEN`. The `env(default::TEST_TOKEN)` processor in `api/config/packages/doctrine.yaml:100` reads real environment variables, so an exported var works without touching `.env.test.local`.
  - [x] 4.2 Do NOT modify `tests/Functional/FunctionalTestCase.php` - the epic's locked decision is that the flake is a process problem (shared trees racing `DROP SCHEMA public CASCADE`), not a code problem. Isolation is delivered by DB name, not by changing the wipe strategy. → No change made to `FunctionalTestCase.php`.
  - [x] 4.3 Confirm no change needed for CI: CI's service container creates `archilan_test` per run and runs a single phpunit process; empty `TEST_TOKEN` default yields `archilan` + `_test` + `` = `archilan_test`. Assert this by reading `.github/workflows/backend.yml` (POSTGRES_DB + DATABASE_URL) - no workflow edit expected for AC1. → **Confirmed**: `.github/workflows/backend.yml` sets `POSTGRES_DB: archilan_test` on the per-run service container and never sets `TEST_TOKEN`, so the empty-default path (`archilan_test`) is untouched. No workflow edit.
- [x] Task 5: 10-consecutive-runs flake verification (AC: 2)
  - [x] 5.1 Run `for i in $(seq 1 10); do ./scripts/test-isolated.sh || exit 1; done` (from `api/`, Git Bash) against the isolated DB; capture pass/fail per iteration. → 10/10 PASS.
  - [x] 5.2 Record the loop command, iteration results, and total wall time in the Dev Agent Record / Completion Notes. Any schema-setup failure = AC2 red, investigate before proceeding. → Recorded below; zero schema-setup failures.
- [x] Task 6: Documentation (AC: 1, 3, 4)
  - [x] 6.1 Root `CLAUDE.md` quality-gates block: replace the four raw api commands + frontend commands with `composer gates` / `pnpm gates` as the canonical invocation (keep the enumerated set for reference; scope only the gate-command lines - the full docs reconciliation is story 33.2).
  - [x] 6.2 `api/CLAUDE.md` quality-gates block: same substitution; keep the per-gate breakdown below it. (`frontend/AGENTS.md` gates block updated the same way.)
  - [x] 6.3 Add/confirm the worktree note (AC4): parallel agents MUST use `scripts/setup-worktree.sh` worktrees; a shared tree is the flake's root cause. Root `CLAUDE.md` "Sessions parallèles" and `api/CLAUDE.md` "Parallel sessions" already exist - extend them with the isolated-run script (`api/scripts/test-isolated.sh`) for single-tree isolated runs and cross-link both. → "Pourquoi c'est obligatoire" paragraph added to root `CLAUDE.md`; both files now reference `api/scripts/test-isolated.sh`.
  - [x] 6.4 Document `test-isolated.sh` usage in its own header (usage, token rules, DB created, cleanup command `doctrine:database:drop --env=test --force` with `TEST_TOKEN` set).
- [x] Task 7: Final gate run (AC: 5)
  - [x] 7.1 `composer gates` green in `api/`; `pnpm gates` green in `frontend/`. → api: phpstan 0 errors, cs-fixer 0 violations, arch OK, phpunit `OK (1440 tests, 10214 assertions)`. frontend: exit 0 (typecheck, lint, jest, build).
  - [x] 7.2 No behaviour change: `git diff` touches only `composer.json`, `package.json`, `.claude/quality-gates.sh`, new script, docs. Zero `src/` changes expected; if a gate forces a `src/` fix, it is a pre-existing red gate (exempt per BMAD rules) - fix it in a separate commit with its own message. → Confirmed: diff = `.claude/quality-gates.sh`, `CLAUDE.md`, `api/CLAUDE.md`, `api/composer.json`, `frontend/AGENTS.md`, `frontend/package.json`, `api/scripts/test-isolated.sh` (new), story file. Zero `src/` changes.

## Dev Notes

### Why this story exists (observed failures)

- **Shared test-DB flake:** local full `php bin/phpunit` intermittently mass-fails at schema setup (`relation "..." does not exist`) because parallel processes race the same `archilan_test` schema. `FunctionalTestCase::setUp` (`api/tests/Functional/FunctionalTestCase.php:26-54`) does `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` + `SchemaTool::createSchema` per test - two processes on one DB destroy each other mid-run. Confirmed work-around: isolated DB via `TEST_TOKEN`. [Source: epics/epic-33-cleanup-and-standards-hardening.md#Known-issues]
- **Local cs-fixer narrower than CI:** the Stop hook runs `php-cs-fixer check src` (`.claude/quality-gates.sh`); CI runs `composer cs-fixer` = `php-cs-fixer fix --dry-run --diff` over the full dist config (src + tests). A snake_case PHPUnit method + missing EOF newline passed locally and failed CI during story 7.7.

### Existing mechanisms - REUSE, do not reinvent

- **TEST_TOKEN hook (already wired):** `api/config/packages/doctrine.yaml:96-100` - `when@test` → `dbname_suffix: '_test%env(default::TEST_TOKEN)%'`. Base DB in `api/.env.test` `DATABASE_URL` is `archilan`; effective test DB = `archilan_test<TOKEN>`. Empty token (default) → `archilan_test`.
- **Worktree script (already isolates):** `scripts/setup-worktree.sh` writes `api/.env.test.local` with `TEST_TOKEN=_<name>` (gitignored via `/.env.*.local`) and creates the DB with `doctrine:database:create --env=test --if-not-exists`. Follow its token normalisation (`[a-z0-9-]`, `-`→`_`, `_` prefix) and its DB-creation approach in the new script.
- **Composer scripts (already the CI entry points):** `api/composer.json` scripts: `test` = `php bin/phpunit`, `phpstan` = `php vendor/bin/phpstan analyse src tests`, `cs-fixer` = `php vendor/bin/php-cs-fixer fix --dry-run --diff`, `arch` = `php bin/console app:architecture:ddd`. CI (`.github/workflows/backend.yml:112-122`) calls exactly these. `composer gates` = composite of these four - that is the whole parity trick.
- **Frontend scripts:** `frontend/package.json`: `typecheck` = `tsc --noEmit`, `lint` = `eslint`, `test` = `jest`, `build` = `next build`. CI (`.github/workflows/frontend.yml:80-94`) calls `pnpm lint`, `pnpm typecheck`, `pnpm run --if-present test`, `pnpm build` with `NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN=test-channel` on test/build.
- **Postgres:** local = Docker container `archilan-postgres` (postgres:17-alpine, user `archilan`, docker-compose.yml). CI = per-run service container (postgres:17-alpine, `POSTGRES_DB: archilan_test`).

### Guardrails (locked epic decisions that bind this story)

- **Zero product behaviour change.** This story touches tooling, scripts, and docs only. `src/` must not change.
- **Do not "fix" the wipe strategy in `FunctionalTestCase`** (per-test full schema rebuild is a deliberate design - see its own comment block). The flake fix is DB isolation, full stop.
- **CI-only extras stay CI-only:** `composer validate`, `composer audit` (warn-only), the coverage run/floor, and bundle analysis are NOT part of the gate set and must not go into `composer gates` / `pnpm gates`. The epic enumerates the gate set explicitly.
- **Do not rename or restructure the CI jobs.** `Backend` / `Frontend` are required status checks with the changes-filter + always-run gate pattern (PR #276). Only the step commands may be touched, and only if needed for parity (expected: no workflow edit at all).
- **[human] flag:** creating/granting an isolated test DB needs Postgres `CREATE DATABASE` privilege. Locally the `archilan` user owns the docker instance, so `doctrine:database:create` works (proven by `setup-worktree.sh`). If a non-docker Postgres refuses, surface it to Jean rather than working around it.
- **Windows host:** Jean develops on Windows; shell scripts run under Git Bash. Keep the new script POSIX-y like `setup-worktree.sh` (`set -euo pipefail`, no bashisms beyond what that script already uses). Path handling: run it from `api/` and use relative paths.

### Project Structure Notes

- New script location: `api/scripts/test-isolated.sh` (api-scoped tooling; repo-level `scripts/` holds cross-cutting scripts like `setup-worktree.sh` - either location is defensible, prefer `api/scripts/` since it only touches the api suite; create the directory, it does not exist yet).
- `api/.env.test` is committed and non-secret; `api/.env.test.local` is gitignored - the script must NOT write `.env.test.local` (that is the worktree script's job); it exports `TEST_TOKEN` for the process only, so the main tree's default behaviour is untouched afterwards.
- `phpunit.xml.dist` sets `failOnDeprecation/failOnNotice/failOnWarning = true` - "OK, but there were issues!" is a red gate. Capture details with `php bin/phpunit --log-events-text php://stdout` if it trips.

### Testing standards summary

- This story adds no PHPUnit tests (tooling/docs story - exempt category per root `CLAUDE.md`: fixing quality-gate infrastructure). The "test" is AC2's 10-run loop plus AC5's full gate pass.
- Do not use `--filter` for the AC2 loop - the flake only manifests on the full suite (many functional classes rebuilding schema).

### Cross-story context (Epic 33)

- 33.2 (docs reconciliation) depends on this story's final gate definition - keep the `composer gates` / `pnpm gates` names exactly as the epic states, and keep doc edits here minimal (gate-command lines + worktree note only) so 33.2 has a clean scope.
- Sequencing: 33.1 is first in the epic precisely so every later story verifies against a trustworthy one-command gate. Nothing in this story depends on other 33.x stories.
- Epic 32 constraint does not apply here (no `Sessions` code touched).

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md#Proposed-stories - story 33.1 AC]
- [Source: _bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md#The-gates - exact gate enumeration]
- [Source: api/config/packages/doctrine.yaml:96-100 - TEST_TOKEN dbname_suffix]
- [Source: scripts/setup-worktree.sh - token normalisation + DB creation pattern]
- [Source: api/tests/Functional/FunctionalTestCase.php:26-54 - schema wipe/rebuild (do not modify)]
- [Source: .github/workflows/backend.yml / .github/workflows/frontend.yml - CI gate steps]
- [Source: .claude/quality-gates.sh - Stop-hook runner to align]
- [Source: api/CLAUDE.md#Testing-standards - "Parallel sessions" note to extend]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

### Completion Notes List

- **AC2 evidence - 10 consecutive isolated full-suite runs (2026-07-04, Git Bash, Windows host, Docker `archilan-postgres`):**
  - Loop command (from `api/`): `for i in $(seq 1 10); do ./scripts/test-isolated.sh || exit 1; done` (each iteration logged separately; DB `archilan_test_local`).
  - Results: **10/10 PASS** - every run `OK (1440 tests, 10214 assertions)`, zero notices/deprecations/warnings.
  - Per-run wall time: 400s / 392s / 360s / 358s / 354s / 380s / 375s / 349s / 360s / 360s. Total wall time: **3688s (~61.5 min)**.
  - Zero schema-setup failures: `grep 'does not exist'` over all 10 logs returned nothing.
  - Note: runs 1-5 overlapped with a concurrent `pnpm gates` (frontend) on the same host - no impact, confirming isolation is robust under load. The frontend gates do not touch Postgres.
- **AC3/AC5 - frontend gates:** `pnpm gates` (typecheck, lint, jest, build) green locally (2026-07-04, exit 0).
- **Live reproduction of the flake during Task 7 (root cause confirmed):** the first `composer gates` run mass-failed its phpunit leg (567 errors, `relation "..." does not exist` from ~21% of the suite onward) while phpstan/cs-fixer/arch were green. Cause: the Claude Stop hook (`.claude/quality-gates.sh`) fired at session end and launched its own `php bin/phpunit` on the shared `archilan_test` while `composer gates` was mid-suite - two schema-rebuilding suites on one DB. This is exactly the AC2 flake, process-level as the epic states. Fix: hook's phpunit leg now runs on `archilan_test_stophook` (Task 3.3); `composer gates` re-run after the fix: **green** - `OK (1440 tests, 10214 assertions)`, phpstan/cs-fixer/arch all clean (2026-07-04).

### File List

- `api/scripts/test-isolated.sh` (new) - isolated full-suite runner via process-scoped `TEST_TOKEN`
- `api/composer.json` - `gates` composite script + `process-timeout: 600`
- `frontend/package.json` - `gates` script
- `.claude/quality-gates.sh` - cs-fixer full dist config (src + tests) + documented-subset header
- `CLAUDE.md` - canonical `composer gates` / `pnpm gates` invocation + worktree/isolation rationale
- `api/CLAUDE.md` - gates block substitution + `test-isolated.sh` cross-link
- `frontend/AGENTS.md` - gates block + `NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN` requirement