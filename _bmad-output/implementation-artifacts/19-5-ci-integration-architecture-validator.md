# Story 19.5: CI Integration - `app:architecture:ddd` in Quality Gates

## Story

**As a** developer,
**I want** the architecture validator to run in CI and in local quality gates,
**So that** no future PR can introduce a CQRS or DDD layer violation undetected.

## Status

review

## Acceptance Criteria

**AC1:** `composer.json` exposes an `arch` script: `php bin/console app:architecture:ddd`. Running `composer arch` in `api/` on the current codebase exits 0.

**AC2:** `.github/workflows/backend.yml` includes an **Architecture** step that runs `composer arch` after PHP CS Fixer and before PHPUnit. The step fails the workflow if the command exits non-zero.

**AC3:** The root `CLAUDE.md` quality-gates table already lists `php bin/console app:architecture:ddd → exit 0`. No change needed - verify it is present.

**AC4:** The `api/CLAUDE.md` quality-gates section already lists `php bin/console app:architecture:ddd → exit 0`. No change needed - verify it is present.

**AC5:** PHPStan level max: 0 errors on modified files. CS Fixer @Symfony: 0 violations. All existing tests pass.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Add `arch` composer script to `api/composer.json`
- [x] Task 3: Add Architecture step to `.github/workflows/backend.yml`
- [x] Task 4: Verify quality gate documentation in CLAUDE.md files
- [x] Task 5: Quality gates

## Dev Notes

### Current state

- `ValidateDddArchitectureCommand` already returns `Command::FAILURE` (exit 1) on violations and lists them with file paths.
- `app:architecture:ddd` already passes (0 violations) on the current codebase after stories 19.1–19.4.
- CI pipeline (`backend.yml`) runs: PHPStan → CS Fixer → PHPUnit. Missing: Architecture check.
- `composer.json` scripts: `phpstan`, `cs-fixer`, `test`. Missing: `arch`.

### Architecture step placement in CI

Place the Architecture step **after** CS Fixer and **before** PHPUnit - it is a fast static check (no DB needed).

### No validator changes needed

The validator already:
- Exits 1 on any violation
- Lists violations with file paths (`src/{context}/{layer}/{file}.php`)
- Covers all bounded contexts (14 registered)
- Checks CQRS, Domain purity, services.yaml, Doctrine mappings

## File List

- `api/composer.json` - modified (add `arch` script)
- `.github/workflows/backend.yml` - modified (add Architecture step)
- `_bmad-output/implementation-artifacts/19-5-ci-integration-architecture-validator.md` - this file

## Dev Agent Record

### Completion Notes

- `api/composer.json`: added `"arch": "php bin/console app:architecture:ddd"` to scripts section
- `.github/workflows/backend.yml`: added Architecture step after PHP CS Fixer, before PHPUnit
- `app:architecture:ddd` → exit 0 (0 violations, confirmed after stories 19.1–19.4)
- CS Fixer @Symfony: 0 violations on modified files (`composer.json`, `backend.yml` are non-PHP - no CS Fixer scope)
- PHPStan: 0 errors on Story 19.5 modified files (no PHP files modified); pre-existing 192 errors are unrelated to this story
- PHPUnit: 726 tests - pre-existing 45 errors + 6 failures unchanged, 0 regressions introduced
- AC3/AC4: `php bin/console app:architecture:ddd → exit 0` already present in root `CLAUDE.md` (line 23) and `api/CLAUDE.md` (line 9) - no changes needed

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implemented |
