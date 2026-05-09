# Story 0.1: Initialize Monorepo Baseline

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer,
I want the repository baseline configured,
so that frontend and API setup can proceed in a predictable project structure.

## Acceptance Criteria

1. Given the existing repository contains BMAD planning artifacts, when the baseline setup is applied, then the repository contains root-level `README.md`, `.editorconfig`, `.gitignore`, `.env.example`, and `docker-compose.yml` placeholders aligned with the architecture.
2. No business-domain code is introduced.
3. Existing `_bmad`, `_bmad-output`, `.agents`, and `.claude` content is preserved.

## Tasks / Subtasks

- [x] Create root repository documentation and baseline config (AC: 1, 2, 3)
  - [x] Add `README.md` describing project purpose, monorepo layout, and current setup phase.
  - [x] Add `.editorconfig` for consistent whitespace, final newline, charset, and indentation across PHP, TS, YAML, JSON, Markdown, and CSS files.
  - [x] Add `.env.example` with root-level non-secret placeholders and pointers to future `frontend/.env.example` and `api/.env.example`.
- [x] Add safe ignore rules (AC: 2, 3)
  - [x] Add `.gitignore` covering OS/editor noise, dependency folders, build outputs, logs, env files, Symfony cache/log directories, Next.js build directories, and Docker local artifacts.
  - [x] Ensure `_bmad`, `_bmad-output`, `.agents`, and `.claude` are not ignored by this story.
- [x] Add local service orchestration placeholder (AC: 1, 2)
  - [x] Add root `docker-compose.yml` as a placeholder for future local services.
  - [x] Include at least PostgreSQL service shape or clearly documented TODO placeholders for PostgreSQL and optional Mercure without real secrets.
  - [x] Keep credentials development-only and non-secret.
- [x] Verify baseline safety (AC: 2, 3)
  - [x] Confirm no `frontend/` or `api/` starter code is introduced in this story.
  - [x] Confirm no business domain folders, entities, controllers, pages, or feature components are introduced.
  - [x] Confirm existing BMAD/Claude/Codex artifact directories remain untouched except for this story file.

## Dev Notes

This story is repository baseline only. It prepares the root of the monorepo so Story 0.2 can initialize `frontend/` and Story 0.3 can initialize `api/` cleanly.

### Scope Boundaries

Do:
- create root-level baseline files only;
- document the intended monorepo structure;
- add safe placeholders for future services and env variables;
- preserve all planning artifacts.

Do not:
- run `pnpm create next-app`;
- run `symfony new`;
- install dependencies;
- create application source files;
- create DDD bounded context folders;
- create GitHub Actions workflows;
- add business-domain code.

### Project Structure Notes

The architecture defines the monorepo target:

```text
archilan.fr/
├── frontend/
├── api/
├── _bmad-output/
└── .claude/
```

This story should only add root scaffolding needed before that target structure is initialized. `frontend/` and `api/` are created by later stories.

Expected files after this story:

```text
archilan.fr/
├── README.md
├── .editorconfig
├── .gitignore
├── .env.example
├── docker-compose.yml
├── .agents/
├── .claude/
├── _bmad/
└── _bmad-output/
```

### Architecture Compliance

- Preserve the monorepo direction: `frontend/` and `api/` will be independently buildable and deployable later.
- Keep this story implementation-free. Epic 0 explicitly requires no business-domain code before starters and quality gates are operational.
- Use ASCII-safe root config where practical.
- Do not ignore planning artifacts. Claude and Codex rely on them for handoff.
- Keep root `.env.example` non-secret. Real secrets must never be committed.

### File-Specific Guidance

`README.md` should include:
- short project description: ArchiLAN public community hub + internal ERP;
- current status: planning artifacts complete, implementation starting at Epic 0;
- intended structure with `frontend/` and `api/`;
- links to key planning artifacts:
  - `_bmad-output/planning-artifacts/prd.md`
  - `_bmad-output/planning-artifacts/architecture.md`
  - `_bmad-output/planning-artifacts/ux-design-specification.md`
  - `_bmad-output/planning-artifacts/epics.md`
- note that business implementation starts only after setup/quality gates.

`.editorconfig` should cover:
- UTF-8;
- LF line endings;
- final newline;
- 2 spaces for `*.yml`, `*.yaml`, `*.json`, `*.md`, `*.ts`, `*.tsx`, `*.css`;
- 4 spaces for `*.php`;
- trim trailing whitespace except Markdown if preserving intentional line breaks is desired.

`.gitignore` should include:
- `.env`, `.env.*`, but keep `.env.example`;
- `node_modules/`, `.next/`, `out/`, `coverage/`, `dist/`, `build/`;
- `vendor/`, `var/cache/`, `var/log/`;
- logs and temporary files;
- IDE/OS files such as `.DS_Store`, `Thumbs.db`;
- local Docker volume artifacts if any are created under the repo.

`docker-compose.yml` should be valid YAML. Prefer current Docker Compose syntax without legacy top-level `version`. Docker's current documentation treats `compose.yaml` as canonical, but this repository architecture explicitly names `docker-compose.yml`; using `docker-compose.yml` is acceptable for compatibility.

`.env.example` should document root-level defaults only, such as:
- `COMPOSE_PROJECT_NAME=archilan`
- `POSTGRES_DB=archilan`
- `POSTGRES_USER=archilan`
- `POSTGRES_PASSWORD=archilan_dev_password`
- comments pointing to future `frontend/.env.example` and `api/.env.example`.

### Testing Requirements

Manual validation is sufficient for this baseline story:
- `git status --short` shows only intentional new/modified files.
- Root files exist: `README.md`, `.editorconfig`, `.gitignore`, `.env.example`, `docker-compose.yml`.
- `docker compose config` may be run if Docker Compose is available; if unavailable, document that validation was not run.
- No `frontend/` or `api/` starter directories are created.
- Existing `_bmad`, `_bmad-output`, `.agents`, `.claude` directories still exist.

No unit tests are required because no executable application code is introduced.

### Previous Story Intelligence

No previous implementation story exists. The Git repository has no commits yet, so there are no established code patterns from committed history.

### Latest Technical Information

- GitHub Actions workflows must live under `.github/workflows` and use YAML workflow files. That matters for Story 0.5, not this story.
- Docker Compose current docs prefer `compose.yaml`, while the existing architecture specifies `docker-compose.yml`. Keep `docker-compose.yml` for project consistency.
- Docker Compose V2 supports the Compose Specification; avoid legacy top-level `version`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-0.1-Initialize-Monorepo-Baseline]
- [Source: _bmad-output/planning-artifacts/architecture.md#Project-Structure--Boundaries]
- [Source: _bmad-output/planning-artifacts/architecture.md#Architecture-Completion--Handoff]
- [Source: _bmad-output/planning-artifacts/prd.md#Technical-Success]
- [Source: Docker Compose docs](https://docs.docker.com/compose/intro/compose-application-model/)
- [Source: Docker Compose file reference](https://docs.docker.com/reference/compose-file/)
- [Source: GitHub Actions workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- `docker compose config` parsed `docker-compose.yml` successfully.
- Docker emitted local warning: `Error loading config file: open C:\Users\maste\.docker\config.json: Accès refusé.` This warning is outside the repository and did not prevent Compose config validation.
- `Test-Path frontend` and `Test-Path api` returned `False`.
- `Test-Path _bmad`, `_bmad-output`, `.agents`, and `.claude` returned `True`.

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- Added repository baseline files only; no frontend/API starter or business-domain code was introduced.
- Added root documentation, editor configuration, safe ignore rules, root environment example, and Docker Compose local service placeholders.
- Validated required files exist, protected artifact directories remain present, and no `frontend/` or `api/` directories were created.
- No unit tests were added because this story introduces no executable application code.

### File List

- `README.md`
- `.editorconfig`
- `.gitignore`
- `.env.example`
- `docker-compose.yml`
- `_bmad-output/implementation-artifacts/0-1-initialize-monorepo-baseline.md`

### Change Log

- 2026-04-24: Implemented repository baseline configuration for Story 0.1.
