# Epic 0: Project Foundation & Quality Gates

Set up the monorepo, Next.js frontend, Symfony API, dependencies, CI, and quality gates so implementation can proceed in a coherent, repeatable state.

## Story 0.1: Initialize Monorepo Baseline

As a developer,
I want the repository baseline configured,
So that frontend and API setup can proceed in a predictable project structure.

**Acceptance Criteria:**

**Given** the existing repository contains BMAD planning artifacts
**When** the baseline setup is applied
**Then** the repository contains root-level `README.md`, `.editorconfig`, `.gitignore`, `.env.example`, and `docker-compose.yml` placeholders aligned with the architecture
**And** no business-domain code is introduced
**And** existing `_bmad`, `_bmad-output`, `.agents`, and `.claude` content is preserved

## Story 0.2: Initialize Next.js Frontend Starter

As a developer,
I want a Next.js frontend initialized in `frontend/`,
So that public pages and application UI can be built on the approved stack.

**Acceptance Criteria:**

**Given** the repository baseline exists
**When** the frontend starter is initialized
**Then** `frontend/` contains a Next.js App Router project with TypeScript, Tailwind, ESLint, `src/`, and `@/*` import alias
**And** shadcn/ui initialization is ready through `components.json`
**And** `next-themes` and `@tanstack/react-query` are installed
**And** `pnpm build`, lint, and type-check commands are available
**And** no public product UI beyond starter-safe placeholders is implemented

## Story 0.3: Initialize Symfony API Starter

As a developer,
I want a Symfony LTS API initialized in `api/`,
So that backend use cases can be implemented on the approved DDD/N-Tier stack.

**Acceptance Criteria:**

**Given** the repository baseline exists
**When** the Symfony API starter is initialized
**Then** `api/` contains a Symfony 7.4 LTS skeleton project
**And** required bundles are installed: ORM pack, security bundle, Lexik JWT auth bundle, serializer pack, Messenger, Mailer, PHPStan, PHP CS Fixer, and test pack
**And** `composer validate`, PHPUnit, PHPStan, and CS Fixer commands are available
**And** no business-domain code beyond starter-safe framework files is implemented

## Story 0.4: Establish Project Structure and DDD Boundaries

As a developer,
I want the approved frontend and backend directories created,
So that future stories place code consistently.

**Acceptance Criteria:**

**Given** frontend and API starters exist
**When** project structure is established
**Then** `frontend/src/app`, `frontend/src/features`, `frontend/src/components`, `frontend/src/lib`, `frontend/src/providers`, and `frontend/src/types` exist
**And** `api/src/Shared`, `Identity`, `Events`, `Registrations`, `GameSelection`, `Content`, `Payments`, `Realtime`, `Communications`, and `Legal` exist with intended DDD subdirectories
**And** placeholder files do not introduce business behavior
**And** architecture boundaries are documented in the local README or equivalent developer notes

## Story 0.5: Configure Quality Gates and CI

As a developer,
I want automated quality gates configured,
So that every implementation batch can be verified before handoff.

**Acceptance Criteria:**

**Given** frontend and API starters exist
**When** quality gates are configured
**Then** frontend CI runs install, lint, type-check, tests where available, and build
**And** backend CI runs Composer validation, PHPStan, PHP CS Fixer dry-run, and PHPUnit
**And** CI workflow files exist under `.github/workflows/`
**And** commands are documented for local execution
**And** the initial CI-equivalent local checks pass or any unavailable checks are explicitly documented

## Story 0.6: Configure Local Development Environment

As a developer,
I want local services and environment examples configured,
So that future stories can run consistently on developer machines.

**Acceptance Criteria:**

**Given** frontend and API starters exist
**When** local development config is added
**Then** root `docker-compose.yml` defines PostgreSQL and optional Mercure service placeholders
**And** `frontend/.env.example` and `api/.env.example` document required environment variables without secrets
**And** database connection defaults are development-safe
**And** setup instructions describe how to start frontend, API, and local services
**And** no production credentials or real API secrets are committed
