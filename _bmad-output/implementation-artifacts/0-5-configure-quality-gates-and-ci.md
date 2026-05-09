# Story 0.5: Configure Quality Gates and CI

Status: done

## Story

As a developer,
I want automated quality gates configured,
so that every implementation batch can be verified before handoff.

## Acceptance Criteria

1. Given frontend and API starters exist, when quality gates are configured, then frontend CI runs install, lint, type-check, tests where available, and build.
2. Backend CI runs Composer validation, PHPStan, PHP CS Fixer dry-run, and PHPUnit.
3. CI workflow files exist under `.github/workflows/`.
4. Commands are documented for local execution.
5. The initial CI-equivalent local checks pass or any unavailable checks are explicitly documented.

## Tasks / Subtasks

- [x] Configure frontend CI quality gate (AC: 1, 3, 5)
  - [x] Create `.github/workflows/frontend.yml`.
  - [x] Install pnpm dependencies with frozen lockfile.
  - [x] Run `pnpm lint`.
  - [x] Run `pnpm typecheck`.
  - [x] Run frontend tests only when a test script exists.
  - [x] Run `pnpm build`.
- [x] Configure backend CI quality gate (AC: 2, 3, 5)
  - [x] Create `.github/workflows/backend.yml`.
  - [x] Install Composer dependencies from lockfile.
  - [x] Run `composer validate`.
  - [x] Run `composer phpstan`.
  - [x] Run `composer cs-fixer`.
  - [x] Run `composer test`.
- [x] Document local quality commands (AC: 4, 5)
  - [x] Document frontend commands.
  - [x] Document backend commands.
  - [x] Document that frontend tests are skipped until a test script exists.
- [x] Validate scope and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run frontend CI-equivalent local commands.
  - [x] Run backend CI-equivalent local commands.
  - [x] Confirm workflows are present under `.github/workflows/`.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story configures automation only. It must not introduce product code, business tests, deployment automation, environment secrets, database migration execution, or production credentials.

### Frontend CI Requirements

Run from `frontend/`:

```bash
pnpm install --frozen-lockfile
pnpm lint
pnpm typecheck
pnpm build
```

No frontend test framework exists yet. CI should check for a `test` script and skip with an explicit message when unavailable.

### Backend CI Requirements

Run from `api/`:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer validate
composer phpstan
composer cs-fixer
composer test
```

### Tooling Notes

- Use Node.js 22 for frontend CI because local development and the generated Next.js starter use Node 22.
- Use PHP 8.3 for backend CI because project minimum is `>=8.3`; this avoids PHP CS Fixer warnings caused by running on newer PHP versions.
- CI should use read-only repository permissions.

### Latest Technical Information

- `actions/checkout@v6` is the current major version as of 2026-04-25.
- `actions/setup-node@v6` is the current major version as of 2026-04-25.
- `shivammathur/setup-php@v2` remains the documented stable major for PHP setup.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-0.5-Configure-Quality-Gates-and-CI]
- [Source: _bmad-output/planning-artifacts/architecture.md#Development-Workflow-Integration]
- [Source: _bmad-output/implementation-artifacts/0-2-initialize-nextjs-frontend-starter.md]
- [Source: _bmad-output/implementation-artifacts/0-3-initialize-symfony-api-starter.md]
- [Source: _bmad-output/implementation-artifacts/0-4-establish-project-structure-and-ddd-boundaries.md]
- [Source: actions/checkout releases](https://github.com/actions/checkout/releases)
- [Source: actions/setup-node repository](https://github.com/actions/setup-node)
- [Source: shivammathur/setup-php repository](https://github.com/shivammathur/setup-php)

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- `pnpm install --frozen-lockfile` failed in sandbox because pnpm needed to purge/recreate `node_modules` without a TTY.
- Retried with `CI=true`; sandbox run timed out while recreating `node_modules`.
- Retried `CI=true pnpm install --frozen-lockfile` with escalation; install completed from the pnpm store with lockfile unchanged.
- Removed `setup-node` implicit pnpm cache from the workflow because pnpm is enabled after Node setup through Corepack. This keeps the initial CI simpler and avoids cache setup ordering issues.
- `composer install --no-interaction --prefer-dist --no-progress` passed locally; Composer warned the user cache directory was not writable in sandbox but did not require network or package changes.
- `actionlint` is not installed locally, so workflow syntax was reviewed by inspection and local command equivalence rather than actionlint execution.

### Completion Notes List

- Added `.github/workflows/frontend.yml` with checkout, Node 22 setup, Corepack pnpm enablement, frozen pnpm install, lint, type-check, conditional test step, and build.
- Added `.github/workflows/backend.yml` with checkout, PHP 8.3 setup, Composer install, Composer validation, PHPStan, PHP CS Fixer dry-run, and PHPUnit.
- Added root README quality gate documentation for frontend and backend local commands.
- Documented that frontend tests are skipped until a future story adds a `test` script.
- CI uses read-only `contents: read` permissions.
- No product code, deployment secrets, migrations, or environment credentials were introduced.

### Validation Results

- `CI=true pnpm install --frozen-lockfile` - passed with escalation after sandbox timeout.
- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- Frontend test detection command - skipped as expected: no frontend test script configured yet.
- `pnpm build` - passed.
- `composer install --no-interaction --prefer-dist --no-progress` - passed.
- `composer validate` - passed.
- `composer phpstan` - passed: no errors.
- `composer cs-fixer` - passed dry-run; local PHP `8.4.12` is newer than project minimum `>=8.3`, so PHP CS Fixer emitted its expected warning.
- `composer test` - passed: `OK (1 test, 1 assertion)`.
- Workflow files confirmed present: `.github/workflows/frontend.yml` and `.github/workflows/backend.yml`.

### File List

- `.github/workflows/frontend.yml`
- `.github/workflows/backend.yml`
- `README.md`
- `_bmad-output/implementation-artifacts/0-5-configure-quality-gates-and-ci.md`

### Change Log

- 2026-04-25: Added frontend and backend GitHub Actions quality workflows, documented local quality gates, and validated CI-equivalent local commands.
