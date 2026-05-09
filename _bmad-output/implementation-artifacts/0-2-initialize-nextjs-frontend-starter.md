# Story 0.2: Initialize Next.js Frontend Starter

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer,
I want a Next.js frontend initialized in `frontend/`,
so that public pages and application UI can be built on the approved stack.

## Acceptance Criteria

1. Given the repository baseline exists, when the frontend starter is initialized, then `frontend/` contains a Next.js App Router project with TypeScript, Tailwind, ESLint, `src/`, and `@/*` import alias.
2. shadcn/ui initialization is ready through `components.json`.
3. `next-themes` and `@tanstack/react-query` are installed.
4. `pnpm build`, lint, and type-check commands are available.
5. No public product UI beyond starter-safe placeholders is implemented.

## Tasks / Subtasks

- [x] Initialize the approved Next.js starter (AC: 1, 5)
  - [x] Confirm Story 0.1 baseline is present before starting.
  - [x] Create `frontend/` using `pnpm create next-app@latest` with TypeScript, Tailwind, ESLint, App Router, `src/`, and `@/*`.
  - [x] Ensure the generated app uses pnpm and does not introduce product-specific UI.
- [x] Initialize shadcn/ui for the existing frontend app (AC: 2, 5)
  - [x] Run shadcn init from `frontend/`.
  - [x] Commit the generated `components.json` and shadcn utility/config changes.
  - [x] Do not add feature components or public product UI in this story.
- [x] Add approved frontend dependencies (AC: 3)
  - [x] Install `next-themes`.
  - [x] Install `@tanstack/react-query`.
  - [x] Do not add unapproved state-management or UI libraries.
- [x] Ensure frontend quality commands exist (AC: 4)
  - [x] Verify `pnpm build` is available and succeeds.
  - [x] Verify lint command is available and succeeds.
  - [x] Add or verify a TypeScript type-check command and run it.
- [x] Validate scope and handoff (AC: 1, 4, 5)
  - [x] Confirm `frontend/src/app` exists.
  - [x] Confirm `frontend/tsconfig.json` maps `@/*` to `./src/*`.
  - [x] Confirm no `api/` directory is created by this story.
  - [x] Confirm no business-domain pages, API clients, feature folders, or ArchiLAN UI are implemented.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story initializes the frontend starter only. It must not implement the ArchiLAN public shell, design tokens, event pages, auth, or any business-facing UI. Those belong to later stories.

### Prerequisite

Story 0.1 should be accepted or at least present in review state before implementation. Required baseline files:

- `README.md`
- `.gitattributes`
- `.editorconfig`
- `.gitignore`
- `.env.example`
- `docker-compose.yml`

Claude's Story 0.1 code review produced root baseline corrections that must be present before implementing this story:

- `.gitattributes` forces repository LF line endings with `* text=auto eol=lf`.
- `.env.example` includes Mercure dev-only variables and comments for future `frontend/.env.example` and `api/.env.example`.
- `docker-compose.yml` pins local service images and uses server-side defaults for Mercure keys.

If further Story 0.1 review changes affect root setup, address those before implementing this story.

### Required Starter Command

Run from repository root:

```bash
pnpm create next-app@latest frontend \
  --typescript \
  --tailwind \
  --eslint \
  --app \
  --src-dir \
  --import-alias "@/*" \
  --use-pnpm \
  --no-react-compiler
```

If the CLI prompts despite flags:
- choose TypeScript;
- choose ESLint, not Biome;
- choose Tailwind;
- choose App Router;
- choose `src/` directory;
- keep `@/*`;
- do not enable React Compiler unless architecture is updated first.

### shadcn/ui Initialization

Run from `frontend/` after the Next.js app exists:

```bash
pnpm dlx shadcn@latest init
```

Use the existing-project setup. The required output is `frontend/components.json` plus the standard shadcn utility/config files. Do not add individual shadcn components such as Button/Card in this story unless the CLI requires a base setup file. Component implementation starts in later UI stories.

### Dependency Installation

Run from `frontend/`:

```bash
pnpm add next-themes
pnpm add @tanstack/react-query
```

Do not add Redux, Zustand, Apollo, GraphQL clients, UI kits, or test frameworks in this story unless the generated starter already includes them or the architecture is explicitly updated.

### Architecture Compliance

- Frontend uses Next.js App Router with TypeScript and Tailwind.
- Frontend owns public rendering, UX, and client orchestration.
- Public pages should later use SSR/SSG/ISR where appropriate, but this story should leave starter-safe placeholder content only.
- Server state will later use TanStack Query. This story installs it but does not need to wire providers unless shadcn/Next setup requires a minimal provider placeholder.
- No business logic belongs in Next.js BFF routes.
- No API client, route groups, feature modules, product content, or visual design tokens should be implemented yet.

### Expected File Structure After This Story

The exact generated files may change with the current Next.js CLI, but the relevant structure must include:

```text
frontend/
├── package.json
├── pnpm-lock.yaml
├── next.config.ts
├── tsconfig.json
├── eslint.config.mjs
├── postcss.config.mjs
├── components.json
├── public/
└── src/
    └── app/
        ├── layout.tsx
        ├── page.tsx
        └── globals.css
```

If the current CLI emits `next.config.mjs` instead of `next.config.ts`, keep the generated supported format and document it in completion notes.

### Quality Commands

After initialization, ensure `frontend/package.json` exposes:

- `build`
- `lint`
- `typecheck` or equivalent TypeScript validation command

If no `typecheck` script is generated, add:

```json
"typecheck": "tsc --noEmit"
```

Run from `frontend/`:

```bash
pnpm lint
pnpm typecheck
pnpm build
```

If a generated script name differs, use the script names in `package.json` and record the exact commands.

### Testing Requirements

No user-facing feature tests are required because this story only initializes the starter. Required validation:

- `pnpm lint` passes.
- `pnpm typecheck` or equivalent passes.
- `pnpm build` passes.
- `frontend/src/app` exists.
- `frontend/components.json` exists.
- `frontend/tsconfig.json` contains `@/*` mapped to `./src/*`.
- `next-themes` and `@tanstack/react-query` appear in `frontend/package.json`.
- Generated frontend files respect repository LF line-ending policy from `.gitattributes`.
- No `api/` directory is created.
- No business-domain frontend code is introduced.

### Previous Story Intelligence

Story 0.1 created the root baseline and is currently in review while Claude performs code review. Known baseline validation:

- Required root files exist.
- `.gitattributes` was added to force LF line endings in the repository.
- `.env.example` includes root PostgreSQL defaults, Mercure dev-only keys, and pointers to future frontend/API env files.
- `docker-compose.yml` uses PostgreSQL 17 Alpine and pinned Mercure image for local development placeholders.
- `frontend/` and `api/` were not created.
- Planning and agent artifact directories are preserved.
- `docker compose config` parsed successfully, with a local Docker config access warning outside the repo.

### Latest Technical Information

- Official Next.js `create-next-app` supports `--typescript`, `--tailwind`, `--eslint`, `--app`, `--src-dir`, `--import-alias`, `--use-pnpm`, and `--no-*` negation flags. Turbopack is enabled by default in generated package scripts.
- Current Next.js CLI includes linter choices. This architecture requires ESLint, not Biome.
- shadcn/ui's Next.js guide supports initializing an existing project with `pnpm dlx shadcn@latest init`; `components.json` is required for CLI-driven component additions.
- TanStack Query is installed with `pnpm add @tanstack/react-query` and supports React 18+.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-0.2-Initialize-Next.js-Frontend-Starter]
- [Source: _bmad-output/planning-artifacts/architecture.md#Starter-1--Next.js-frontend]
- [Source: _bmad-output/planning-artifacts/architecture.md#Frontend-Architecture]
- [Source: _bmad-output/planning-artifacts/architecture.md#Project-Structure--Boundaries]
- [Source: _bmad-output/implementation-artifacts/0-1-initialize-monorepo-baseline.md]
- [Source: Next.js create-next-app docs](https://nextjs.org/docs/app/api-reference/cli/create-next-app)
- [Source: shadcn/ui Next.js installation](https://ui.shadcn.com/docs/installation/next)
- [Source: shadcn/ui CLI docs](https://ui.shadcn.com/docs/cli)
- [Source: TanStack Query React installation](https://tanstack.com/query/latest/docs/framework/react/installation)

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- `pnpm --version` failed in sandbox on npm registry access, then succeeded with escalation: `10.33.2`.
- `pnpm create next-app@latest ...` failed in sandbox on `realpath C:\Users\maste`, then succeeded with escalation.
- `pnpm dlx shadcn@latest init --help` failed in sandbox on `realpath C:\Users\maste`, then succeeded with escalation.
- `pnpm build` initially failed because Next 16/Turbopack inferred `C:\Users\maste` as workspace root due a user-level `package-lock.json`; fixed by setting `turbopack.root` in `frontend/next.config.ts`.
- `pnpm build` then failed on generated Google Font downloads; fixed by removing `next/font/google` usage from the starter layout and using system fonts.
- `pnpm build` hit `spawn EPERM` inside sandbox after compilation; final build succeeded with escalation.

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- Initialized `frontend/` with current `create-next-app@latest`: Next.js `16.2.4`, React `19.2.4`, TypeScript, Tailwind CSS v4, ESLint, App Router, `src/`, pnpm, and `@/*` alias.
- Initialized shadcn/ui configuration with `components.json`, `src/lib/utils.ts`, Tailwind v4 CSS variables, and required shadcn runtime dependencies.
- Removed the unused `src/components/ui/button.tsx` generated by the shadcn preset to keep this story scoped to configuration only.
- Installed `next-themes` and `@tanstack/react-query`.
- Added `typecheck` script: `tsc --noEmit`.
- Set `turbopack.root` to the frontend directory to avoid Next 16 root inference escaping to `C:\Users\maste`.
- Removed generated remote Google Font usage so `pnpm build` is reproducible without external font downloads.
- Verified `frontend/components.json`, `frontend/src/app`, `@/*` -> `./src/*`, no `api/` directory, and no ArchiLAN/business-domain frontend code.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed outside sandbox after the Turbopack root and font reproducibility fixes.
- `Test-Path frontend\components.json; Test-Path frontend\src\app; Test-Path api` - `True`, `True`, `False`.
- `rg "@tanstack/react-query" frontend\package.json` - dependency present.
- `rg "next-themes" frontend\package.json` - dependency present.
- `rg "./src/*" frontend\tsconfig.json` - alias mapping present.

### File List

- `frontend/.gitignore`
- `frontend/AGENTS.md`
- `frontend/CLAUDE.md`
- `frontend/README.md`
- `frontend/components.json`
- `frontend/eslint.config.mjs`
- `frontend/next-env.d.ts`
- `frontend/next.config.ts`
- `frontend/package.json`
- `frontend/pnpm-lock.yaml`
- `frontend/pnpm-workspace.yaml`
- `frontend/postcss.config.mjs`
- `frontend/public/file.svg`
- `frontend/public/globe.svg`
- `frontend/public/next.svg`
- `frontend/public/vercel.svg`
- `frontend/public/window.svg`
- `frontend/src/app/favicon.ico`
- `frontend/src/app/globals.css`
- `frontend/src/app/layout.tsx`
- `frontend/src/app/page.tsx`
- `frontend/src/lib/utils.ts`

### Change Log

- 2026-04-25: Implemented Story 0.2 frontend starter, shadcn/ui configuration, approved dependencies, quality scripts, and reproducible build fixes.
