# Story 0.4: Establish Project Structure and DDD Boundaries

Status: done

## Story

As a developer,
I want the approved frontend and backend directories created,
so that future stories place code consistently.

## Acceptance Criteria

1. Given frontend and API starters exist, when project structure is established, then `frontend/src/app`, `frontend/src/features`, `frontend/src/components`, `frontend/src/lib`, `frontend/src/providers`, and `frontend/src/types` exist.
2. `api/src/Shared`, `Identity`, `Events`, `Registrations`, `GameSelection`, `Content`, `Payments`, `Realtime`, `Communications`, and `Legal` exist with intended DDD subdirectories.
3. Placeholder files do not introduce business behavior.
4. Architecture boundaries are documented in the local README or equivalent developer notes.

## Tasks / Subtasks

- [x] Establish frontend source directories (AC: 1, 3, 4)
  - [x] Confirm Story 0.2 frontend starter exists.
  - [x] Create or confirm `frontend/src/app`.
  - [x] Create or confirm `frontend/src/features`.
  - [x] Create or confirm `frontend/src/components`.
  - [x] Create or confirm `frontend/src/lib`.
  - [x] Create `frontend/src/providers`.
  - [x] Create `frontend/src/types`.
  - [x] Add local frontend source boundary documentation without product UI.
- [x] Establish backend bounded-context directories (AC: 2, 3, 4)
  - [x] Confirm Story 0.3 Symfony API starter exists.
  - [x] Create `Shared`, `Identity`, `Events`, `Registrations`, `GameSelection`, `Content`, `Payments`, `Realtime`, `Communications`, and `Legal` under `api/src`.
  - [x] Create intended DDD subdirectories for each context: `Domain`, `Application`, `Infrastructure`, and `Presentation`.
  - [x] Keep generated Symfony placeholder folders (`Controller`, `Entity`, `Repository`) inert until replaced by bounded-context mappings.
  - [x] Add local backend source boundary documentation without business code.
- [x] Establish backend test directories (AC: 3, 4)
  - [x] Create `api/tests/Unit`.
  - [x] Create `api/tests/Functional`.
  - [x] Create `api/tests/Integration`.
  - [x] Create `api/tests/Fixtures`.
  - [x] Keep the existing framework smoke test valid.
- [x] Validate scope and handoff (AC: 1, 2, 3, 4)
  - [x] Run frontend quality commands.
  - [x] Run backend quality commands.
  - [x] Confirm no business-domain implementation files were introduced.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story creates directory boundaries only. It must not implement ArchiLAN public shell, auth, event management, registrations, game selection, content, payments, realtime, communications, or legal behavior.

### Frontend Structure

- `frontend/src/app/` contains Next.js routes only.
- `frontend/src/features/{feature}/` contains feature components, hooks, schemas, and API clients.
- `frontend/src/components/ui/` contains shadcn/ui primitives only.
- `frontend/src/components/` outside `ui` contains shared, non-feature UI only.
- `frontend/src/lib/` contains framework-level utilities.
- `frontend/src/providers/` contains React provider composition.
- `frontend/src/types/` contains shared DTO and API types.

### Backend Structure

Each bounded context follows:

- `Domain/` for entities, value objects, domain services, repository interfaces.
- `Application/` for commands, queries, handlers, use cases, DTOs.
- `Infrastructure/` for Doctrine repositories, external adapters, Messenger handlers.
- `Presentation/` for controllers and request/response mapping.

Bounded contexts required now:

- `Shared`
- `Identity`
- `Events`
- `Registrations`
- `GameSelection`
- `Content`
- `Payments`
- `Realtime`
- `Communications`
- `Legal`

Controllers must not contain business rules. API responses expose DTOs, not Doctrine entities.

### Testing Requirements

No feature tests are required for placeholder directories. Required validation:

- Frontend `pnpm lint`, `pnpm typecheck`, and `pnpm build` still pass.
- Backend `composer validate`, `composer test`, `composer phpstan`, and `composer cs-fixer` still pass.
- Directory existence checks pass.
- Search confirms placeholders contain no business behavior.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-0.4-Establish-Project-Structure-and-DDD-Boundaries]
- [Source: _bmad-output/planning-artifacts/architecture.md#Structure-Patterns]
- [Source: _bmad-output/planning-artifacts/architecture.md#Architectural-Boundaries]
- [Source: _bmad-output/implementation-artifacts/0-2-initialize-nextjs-frontend-starter.md]
- [Source: _bmad-output/implementation-artifacts/0-3-initialize-symfony-api-starter.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Story 0.3 review correction renamed `api/phpunit.dist.xml` to `api/phpunit.xml.dist`; PHPUnit auto-discovery confirmed the new filename.
- `composer validate` initially failed because the Story 0.3 review correction changed `composer.json` PHP constraint to `>=8.3` without resynchronizing `composer.lock`.
- Ran `composer update --lock --no-interaction`; no packages changed, lock metadata was rewritten.

### Completion Notes List

- Created frontend boundary documentation in `frontend/src/README.md`.
- Created frontend placeholder directories for `features`, `providers`, and `types`, and documented component boundaries.
- Created backend source boundary documentation in `api/src/README.md`.
- Created backend bounded contexts: `Shared`, `Identity`, `Events`, `Registrations`, `GameSelection`, `Content`, `Payments`, `Realtime`, `Communications`, and `Legal`.
- Created DDD layer directories for every bounded context: `Domain`, `Application`, `Infrastructure`, and `Presentation`.
- Created backend test boundary directories: `Unit`, `Functional`, `Integration`, and `Fixtures`.
- Added only documentation and `.gitkeep` placeholders; no new PHP, TypeScript, routes, controllers, entities, services, or product UI were introduced.
- Preserved Symfony starter placeholder folders `api/src/Controller`, `api/src/Entity`, and `api/src/Repository` as inert directories.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- `composer validate` - passed after lock resync.
- `composer test` - passed: `OK (1 test, 1 assertion)`.
- `composer phpstan` - passed: no errors.
- `composer cs-fixer` - passed dry-run; PHP CS Fixer still warns that local PHP `8.4.12` is newer than project minimum `>=8.3`.
- Frontend directory existence check passed for `app`, `features`, `components`, `lib`, `providers`, and `types`.
- Backend bounded-context directory existence check passed for all contexts and all four DDD layers.
- Backend test directory existence check passed for `Unit`, `Functional`, `Integration`, and `Fixtures`.
- Implementation file scan under `api/src` and `frontend/src` found only starter files: `api/src/Kernel.php`, `frontend/src/app/layout.tsx`, `frontend/src/app/page.tsx`, and `frontend/src/lib/utils.ts`.

### File List

- `api/composer.lock`
- `api/src/README.md`
- `api/src/Shared/README.md`
- `api/src/Shared/Domain/.gitkeep`
- `api/src/Shared/Application/.gitkeep`
- `api/src/Shared/Infrastructure/.gitkeep`
- `api/src/Shared/Presentation/.gitkeep`
- `api/src/Identity/README.md`
- `api/src/Identity/Domain/.gitkeep`
- `api/src/Identity/Application/.gitkeep`
- `api/src/Identity/Infrastructure/.gitkeep`
- `api/src/Identity/Presentation/.gitkeep`
- `api/src/Events/README.md`
- `api/src/Events/Domain/.gitkeep`
- `api/src/Events/Application/.gitkeep`
- `api/src/Events/Infrastructure/.gitkeep`
- `api/src/Events/Presentation/.gitkeep`
- `api/src/Registrations/README.md`
- `api/src/Registrations/Domain/.gitkeep`
- `api/src/Registrations/Application/.gitkeep`
- `api/src/Registrations/Infrastructure/.gitkeep`
- `api/src/Registrations/Presentation/.gitkeep`
- `api/src/GameSelection/README.md`
- `api/src/GameSelection/Domain/.gitkeep`
- `api/src/GameSelection/Application/.gitkeep`
- `api/src/GameSelection/Infrastructure/.gitkeep`
- `api/src/GameSelection/Presentation/.gitkeep`
- `api/src/Content/README.md`
- `api/src/Content/Domain/.gitkeep`
- `api/src/Content/Application/.gitkeep`
- `api/src/Content/Infrastructure/.gitkeep`
- `api/src/Content/Presentation/.gitkeep`
- `api/src/Payments/README.md`
- `api/src/Payments/Domain/.gitkeep`
- `api/src/Payments/Application/.gitkeep`
- `api/src/Payments/Infrastructure/.gitkeep`
- `api/src/Payments/Presentation/.gitkeep`
- `api/src/Realtime/README.md`
- `api/src/Realtime/Domain/.gitkeep`
- `api/src/Realtime/Application/.gitkeep`
- `api/src/Realtime/Infrastructure/.gitkeep`
- `api/src/Realtime/Presentation/.gitkeep`
- `api/src/Communications/README.md`
- `api/src/Communications/Domain/.gitkeep`
- `api/src/Communications/Application/.gitkeep`
- `api/src/Communications/Infrastructure/.gitkeep`
- `api/src/Communications/Presentation/.gitkeep`
- `api/src/Legal/README.md`
- `api/src/Legal/Domain/.gitkeep`
- `api/src/Legal/Application/.gitkeep`
- `api/src/Legal/Infrastructure/.gitkeep`
- `api/src/Legal/Presentation/.gitkeep`
- `api/tests/Unit/.gitkeep`
- `api/tests/Functional/.gitkeep`
- `api/tests/Integration/.gitkeep`
- `api/tests/Fixtures/.gitkeep`
- `frontend/src/README.md`
- `frontend/src/features/.gitkeep`
- `frontend/src/features/README.md`
- `frontend/src/components/README.md`
- `frontend/src/components/ui/.gitkeep`
- `frontend/src/providers/.gitkeep`
- `frontend/src/providers/README.md`
- `frontend/src/types/.gitkeep`
- `frontend/src/types/README.md`
- `_bmad-output/implementation-artifacts/0-4-establish-project-structure-and-ddd-boundaries.md`

### Change Log

- 2026-04-25: Created Story 0.4 directory boundaries, frontend/backend source documentation, DDD context placeholders, backend test directories, and resynchronized Composer lock metadata from Story 0.3 review corrections.
