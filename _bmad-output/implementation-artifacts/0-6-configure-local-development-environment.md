# Story 0.6: Configure Local Development Environment

Status: done

## Story

As a developer,
I want local services and environment examples configured,
so that future stories can run consistently on developer machines.

## Acceptance Criteria

1. Given frontend and API starters exist, when local development config is added, then root `docker-compose.yml` defines PostgreSQL and optional Mercure service placeholders.
2. `frontend/.env.example` and `api/.env.example` document required environment variables without secrets.
3. Database connection defaults are development-safe.
4. Setup instructions describe how to start frontend, API, and local services.
5. No production credentials or real API secrets are committed.

## Tasks / Subtasks

- [x] Confirm local services baseline (AC: 1, 3, 5)
  - [x] Confirm root `docker-compose.yml` defines PostgreSQL.
  - [x] Confirm root `docker-compose.yml` defines optional Mercure under the `realtime` profile.
  - [x] Confirm service defaults are dev-only placeholders and contain no production secrets.
  - [x] Confirm generated `api/compose*.yaml` files are not reintroduced.
- [x] Add application environment examples (AC: 2, 3, 5)
  - [x] Create `frontend/.env.example`.
  - [x] Create `api/.env.example`.
  - [x] Use development-safe PostgreSQL defaults aligned with root Docker Compose.
  - [x] Document Mercure and JWT placeholders without committing keys or secrets.
- [x] Document local startup flow (AC: 4, 5)
  - [x] Update root README with local setup sequence.
  - [x] Include commands for Docker services, frontend dev server, Symfony API server, and optional Mercure.
  - [x] Include notes for copying `.env.example` files.
- [x] Validate scope and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run `docker compose config`.
  - [x] Run frontend quality commands.
  - [x] Run backend quality commands.
  - [x] Confirm no real secrets are present in committed examples.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story configures local development only. It must not introduce production credentials, deployment automation, business endpoints, database migrations, real JWT keys, HelloAsso credentials, Twitch credentials, SMTP credentials, or ArchiLAN product behavior.

### Environment Strategy

- Root `.env.example` documents Docker Compose service defaults.
- `frontend/.env.example` documents browser/server variables consumed by Next.js.
- `api/.env.example` documents Symfony API variables.
- Local uncommitted files should be `.env`, `frontend/.env.local`, and `api/.env.local`.
- `api/.env.test` is committed and contains only test-safe CI values from Story 0.5 review corrections.

### Local Ports

- Frontend: `http://localhost:3000`
- Symfony API: `http://localhost:8000`
- PostgreSQL: `localhost:5432`
- Mercure: `http://localhost:3001` when started with `--profile realtime`

### Required Validation

- `docker compose config` parses successfully.
- `pnpm lint`, `pnpm typecheck`, and `pnpm build` pass.
- `composer validate`, `composer phpstan`, `composer cs-fixer`, and `composer test` pass.
- Secret scan over example files finds placeholder values only.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-0.6-Configure-Local-Development-Environment]
- [Source: _bmad-output/implementation-artifacts/0-1-initialize-monorepo-baseline.md]
- [Source: _bmad-output/implementation-artifacts/0-2-initialize-nextjs-frontend-starter.md]
- [Source: _bmad-output/implementation-artifacts/0-3-initialize-symfony-api-starter.md]
- [Source: _bmad-output/implementation-artifacts/0-5-configure-quality-gates-and-ci.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- `docker compose config` parsed successfully and included the expected PostgreSQL service. Docker emitted the known local warning: `Error loading config file: open C:\Users\maste\.docker\config.json: Acces refuse.`
- `git check-ignore -v` confirmed `frontend/.env.example` and `api/.env.example` are commit-eligible while `.env`, `frontend/.env.local`, and `api/.env.local` remain ignored.
- `api/compose.yaml` and `api/compose.override.yaml` remain absent, preserving root Docker Compose as the only local service source of truth.
- Secret-oriented scan over `.env.example`, `frontend/.env.example`, `api/.env.example`, and `api/.env.test` found only documented placeholder/test-safe values.

### Completion Notes List

- Added `frontend/.env.example` with local app URL, API base URL, and optional Mercure public URL.
- Added `api/.env.example` with local Symfony, PostgreSQL, Messenger, Mailer, Lexik JWT placeholder, and Mercure placeholder values.
- Updated root README with local environment copy commands, Docker service startup, frontend dev startup, and Symfony API startup.
- Updated root README Epic 0 status to complete through Story 0.6.
- Confirmed root `docker-compose.yml` provides PostgreSQL and optional Mercure via the `realtime` profile.
- No production credentials, generated JWT key files, external API secrets, SMTP credentials, business endpoints, migrations, or product behavior were introduced.

### Validation Results

- `docker compose config` - passed with known user Docker config access warning outside the repository.
- `git check-ignore -v frontend\.env.example api\.env.example frontend\.env.local api\.env.local .env` - examples are unignored; local override files are ignored.
- `Test-Path api\compose.yaml; Test-Path api\compose.override.yaml` - `False`, `False`.
- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- `composer validate` - passed.
- `composer phpstan` - passed: no errors.
- `composer cs-fixer` - passed dry-run; local PHP `8.4.12` is newer than project minimum `>=8.3`, so PHP CS Fixer emitted its expected warning.
- `composer test` - passed: `OK (1 test, 1 assertion)`.
- `Test-Path frontend\.env.example; Test-Path api\.env.example; Test-Path docker-compose.yml` - `True`, `True`, `True`.

### File List

- `frontend/.env.example`
- `api/.env.example`
- `README.md`
- `_bmad-output/implementation-artifacts/0-6-configure-local-development-environment.md`

### Change Log

- 2026-04-25: Added frontend/API environment examples, documented local startup flow, verified Docker Compose config, and validated frontend/backend quality gates.
