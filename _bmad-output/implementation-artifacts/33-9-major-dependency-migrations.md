# Story 33.9: Major Dependency Migrations (api/ + frontend/)

Status: done

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

- [x] Task 1: Plan of record committed → `d0ea4bf`.
- [x] Task 2: M1 TypeScript 6 → PR #290 merged (`ea4c065`), #42 closed. One mechanical fix: TS 6 dropped the automatic `node_modules/@types` inclusion → explicit `"types": ["node", "jest"]` in tsconfig. Gates green locally + CI.
- [x] Task 3: M2 Node 26 → PR #291 merged (`e1c9cdf`), #37 closed. Two mechanical fixes: Node >= 25 removed corepack (base stage reinstalls it via npm so the pnpm `packageManager` pin keeps working); new `frontend/.dockerignore` (local builds copied host node_modules over the deps stage - latent gap exposed by the verification build). Verified: gates on @types/node 26, docker build green, container smoke HTTP 200, CI on node 26.
- [x] Task 4: M3 PHP 8.5 → PR #292. Two mechanical fixes: `docker-php-ext-enable opcache` dropped (statically compiled into the 8.5 image; loaded by default on both, behaviour unchanged); new `api/.dockerignore` (mirror of M2's fix). Verified: docker build green on 8.5.7 (amqp 2.2.0 compiles, Symfony cache:warmup prod boots), PR CI = full backend suite on PHP 8.5. #39 closed after merge.
- [x] Task 5: Story record (this update, rides the M3 PR).

## Dev Notes

- `composer.json` requires `"php": "^8.4"` - allows 8.5, no manifest change needed; check for a `config.platform` pin before assuming.
- Docker is available locally (archilan-postgres etc.); build images with `docker build` but do NOT push (GHCR publishing happens on develop merge via Docker Publish workflow).
- Local Node is v22.14.0 (nvm) - M2's `pnpm gates` runs on node 22, which stays valid (code is engine-agnostic; the types + image + CI are what move); the PR CI on node 26 is the runtime check.
- 33.4 precedent: @types/node majors track the runtime major (PR #41 closed for exactly this reason); `@dependabot ignore` may need lifting for the 26.x types - just install the version directly.
- Merge-on-green authorized in-session (standing); each PR body carries its rollback pin per AC1.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Debug Log References

- M1: gates red on first TS 6 run (jest globals unresolved) → tsconfig `types` field; green after.
- M2: docker build failed twice before green - (1) corepack absent from node:26-alpine (exit 127), (2) `COPY . .` clobbered the deps-stage node_modules with the host's (no .dockerignore existed; CI checkouts are clean, which had masked it). Container smoke: HTTP 200 on Next 16.2.10.
- M3: pre-tested via a temp Dockerfile BEFORE branching - caught `docker-php-ext-enable opcache` erroring on 8.5 (statically compiled); isolated repro confirmed pecl amqp 2.2.0 builds fine; `php -m` parity check (OPcache loaded by default on 8.4 AND 8.5).
- Each migration merged on green CI before the next started; each PR carries its rollback pin.

### Completion Notes List

- 3/3 majors landed, none needed the split-on-friction clause - every fix was mechanical (a config field, a package reinstall, an obsolete enable line, two missing .dockerignore files).
- Runtime alignment (AC3) delivered: CI now tests node 26 and PHP 8.5 - the exact runtimes the images ship.
- Dependabot queue fully drained: #42, #37, #39 closed with supersession comments; zero open Dependabot PRs.
- Collateral hardening: both Dockerfiles gained .dockerignore files (local-build correctness + slimmer contexts + no host artifacts in images).

### File List

- `_bmad-output/implementation-artifacts/33-9-major-dependency-migrations.md` (this story)
- M1: `frontend/package.json`, `frontend/pnpm-lock.yaml`, `frontend/tsconfig.json`
- M2: `frontend/Dockerfile`, `frontend/.dockerignore` (new), `frontend/package.json`, `frontend/pnpm-lock.yaml`, `.github/workflows/frontend.yml`
- M3: `api/Dockerfile`, `api/.dockerignore` (new), `.github/workflows/backend.yml`

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created: 3-migration plan of record with per-major scope/verification/rollback, runtime-alignment hardening (CI must test the new runtime before the image ships), split-on-friction clause. Status: ready-for-dev. |
| 2026-07-05 | Executed: M1 TS 6 (#290), M2 Node 26 (#291), M3 PHP 8.5 (#292) - sequenced, each verified locally (gates/docker builds/smoke) then merged on green CI; Dependabot majors queue drained (#42/#37/#39 closed). Status → ready-for-review. |
