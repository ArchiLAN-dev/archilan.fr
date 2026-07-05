# Story 33.9: Major Dependency Migrations (api/ + frontend/)

Status: ready-for-dev

## Story

As a maintainer of the monorepo's toolchain,
I want the three pending major bumps (TypeScript 6, PHP 8.5 image, Node 26 image) executed as sequenced, independently-revertable migrations,
so that the platform stays current without ever merging an untested runtime.

## Acceptance Criteria

1. **AC1 - Per major:** the branch builds with full gates green; any code/type changes the major requires are made; a rollback note (previous pin) is recorded in the PR body.
2. **AC2 - No forcing:** no major merges on red gates; a major needing more than mechanical fixes is split into its own recorded follow-up instead of forced.
3. **AC3 - Runtime alignment (hardening beyond the Dependabot diffs):** a base-image major is never merged with CI still testing the OLD runtime - the Node migration bundles CI `setup-node` + `@types/node` (33.4 decision: types track the runtime major); the PHP migration bundles CI `php-version` so the full backend suite runs on 8.5 BEFORE the image ships.
4. **AC4 - Dependabot hygiene:** each superseded Dependabot PR (#42, #37, #39) is closed with a comment referencing the landed migration.

## Migration plan (current pins verified at develop, 2026-07-05)

| # | Major | Current | Target | Scope | Verification | Rollback |
|---|-------|---------|--------|-------|--------------|----------|
| M1 | TypeScript 6 (#42) | `"typescript": "^5"` (5.9.3) | `"^6"` (6.0.x) | `frontend/package.json` + lockfile (+ any mechanical type fixes) | `pnpm gates` locally (tsc IS the toolchain - fully verifiable); watch for typescript-eslint supported-range friction | repin `"^5"`, `pnpm install` |
| M2 | Node 26 image (#37) | `node:22-alpine`, CI `node-version: '22'`, `@types/node ^22` | `node:26-alpine`, CI 26, `@types/node ^26` | `frontend/Dockerfile`, `.github/workflows/frontend.yml`, `frontend/package.json` | `pnpm gates` (types) + local `docker build` of the frontend image + PR CI on node 26 | revert the 3 pins |
| M3 | PHP 8.5 image (#39) | `php:8.4-cli-alpine`, CI `php-version: '8.4'` | `php:8.5-cli-alpine`, CI 8.5 | `api/Dockerfile`, `.github/workflows/backend.yml` | PR CI runs the ENTIRE backend gate set on PHP 8.5 (the authoritative check - local runtime is 8.4); local `docker build` of the api image | revert the 2 pins |

Sequenced M1 → M2 → M3, each merged on green before the next starts. Split-on-friction: if any major surfaces non-mechanical breakage, revert, record the findings as its own story, move to the next major.

## Tasks / Subtasks

- [ ] Task 1: Commit this story (plan of record) (AC: all)
- [ ] Task 2: M1 TypeScript 6 - bump, install, gates, fix mechanical type errors if any, PR with rollback note, merge on green, close #42 (AC: 1, 2, 4)
- [ ] Task 3: M2 Node 26 - Dockerfile + CI + @types/node, gates + docker build, PR with rollback note, merge on green, close #37 (AC: 1, 2, 3, 4)
- [ ] Task 4: M3 PHP 8.5 - Dockerfile + CI php-version, local docker build, PR (CI = full suite on 8.5) with rollback note, merge on green, close #39 (AC: 1, 2, 3, 4)
- [ ] Task 5: Story record + epic status note (AC: all)

## Dev Notes

- `composer.json` requires `"php": "^8.4"` - allows 8.5, no manifest change needed; check for a `config.platform` pin before assuming.
- Docker is available locally (archilan-postgres etc.); build images with `docker build` but do NOT push (GHCR publishing happens on develop merge via Docker Publish workflow).
- Local Node is v22.14.0 (nvm) - M2's `pnpm gates` runs on node 22, which stays valid (code is engine-agnostic; the types + image + CI are what move); the PR CI on node 26 is the runtime check.
- 33.4 precedent: @types/node majors track the runtime major (PR #41 closed for exactly this reason); `@dependabot ignore` may need lifting for the 26.x types - just install the version directly.
- Merge-on-green authorized in-session (standing); each PR body carries its rollback pin per AC1.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created: 3-migration plan of record with per-major scope/verification/rollback, runtime-alignment hardening (CI must test the new runtime before the image ships), split-on-friction clause. Status: ready-for-dev. |
