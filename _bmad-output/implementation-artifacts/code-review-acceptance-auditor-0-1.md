# Acceptance Auditor Review Prompt - Story 0.1

You are the Acceptance Auditor. Review the implementation against the story and architecture constraints.

Check for:
- violations of acceptance criteria;
- deviations from story intent;
- missing implementation of specified behavior;
- contradictions between spec constraints and actual files.

Output findings as a Markdown list. Each finding must include:
- one-line title;
- which AC or constraint it violates;
- evidence from the diff/files;
- required remediation.

## Spec File

`_bmad-output/implementation-artifacts/0-1-initialize-monorepo-baseline.md`

## Context Documents

- `_bmad-output/planning-artifacts/architecture.md`
- `_bmad-output/planning-artifacts/epics.md`

## Implementation Files

- `README.md`
- `.editorconfig`
- `.gitignore`
- `.env.example`
- `docker-compose.yml`
- `_bmad-output/implementation-artifacts/0-1-initialize-monorepo-baseline.md`

## Acceptance Criteria

1. Given the existing repository contains BMAD planning artifacts, when the baseline setup is applied, then the repository contains root-level `README.md`, `.editorconfig`, `.gitignore`, `.env.example`, and `docker-compose.yml` placeholders aligned with the architecture.
2. No business-domain code is introduced.
3. Existing `_bmad`, `_bmad-output`, `.agents`, and `.claude` content is preserved.

## Key Constraints From Story

- Do not run `pnpm create next-app`.
- Do not run `symfony new`.
- Do not install dependencies.
- Do not create application source files.
- Do not create DDD bounded context folders.
- Do not create GitHub Actions workflows.
- Do not add business-domain code.
- Root `.env.example` must contain non-secret values only.
- `.gitignore` must not ignore `_bmad`, `_bmad-output`, `.agents`, or `.claude`.
- `docker-compose.yml` must be valid YAML and use current Compose format without legacy top-level `version`.
