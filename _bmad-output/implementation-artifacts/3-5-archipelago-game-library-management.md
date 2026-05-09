# Story 3.5: Archipelago Game Library Management

Status: done

## Story

As an admin,
I want to manage the association's Archipelago game library,
so that events can offer accurate game choices and options.

## Acceptance Criteria

1. Given an admin is authenticated, when they open the game library backoffice, then they can add, edit, and remove games with name, slug, description, cover image metadata, availability state, and supported event types.
2. Removal is blocked or safely handled when a game is already used by existing registrations.
3. Game list empty states and validation follow UX patterns.
4. Non-admin users cannot manage the game library.
5. Game records are available for event-specific selection configuration.

## Tasks / Subtasks

- [x] Implement backend game library persistence (AC: 1, 2, 5)
  - [x] Add `GameSelection\Domain\ArchipelagoGame` entity.
  - [x] Add Doctrine mapping for `GameSelection\Domain`.
  - [x] Add migration for `game_selection_games`.
  - [x] Store name, slug, description, cover metadata, availability, and supported event types.
  - [x] Include a usage count boundary for future registration/game selection usage.
- [x] Add backend admin game library API (AC: 1, 2, 3, 4, 5)
  - [x] Add `GET /api/v1/admin/games`.
  - [x] Add `POST /api/v1/admin/games`.
  - [x] Add `PATCH /api/v1/admin/games/{gameId}`.
  - [x] Add `DELETE /api/v1/admin/games/{gameId}`.
  - [x] Validate required fields, slug format, availability, supported event types, and duplicate slugs.
  - [x] Protect all endpoints with admin RBAC.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test anonymous/lambda access rejected.
  - [x] Test empty list response.
  - [x] Test admin create/update/list/delete flow.
  - [x] Test validation errors and duplicate slug handling.
  - [x] Extend RBAC enforcement coverage for game endpoints.
- [x] Implement frontend game library backoffice (AC: 1, 3)
  - [x] Add `/admin/jeux` route.
  - [x] Add game list with required columns.
  - [x] Add empty state inviting first game creation.
  - [x] Add create/edit form with inline errors.
  - [x] Add delete action with confirmation and safe failure message.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

The `usageCount` is currently `0` because concrete event game selection and registration-game usage are introduced in later stories. The delete path already treats usage as a boundary and returns a conflict if it becomes non-zero later.

Supported event types are stored as normalized JSON strings so Story 3.7 can use the game library for event-specific selection configuration.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.5-Archipelago-Game-Library-Management]
- [Source: _bmad-output/implementation-artifacts/3-4-private-event-password-configuration.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `AdminGameLibraryTest` for CRUD, validation, empty state API behavior, duplicate slugs, and RBAC.
- Added `GameSelection` Doctrine mapping after PHPUnit exposed the new entity was outside the configured mapping chain.
- Added `/admin/jeux` frontend dashboard and validated it through lint/typecheck/build.

### Completion Notes List

- Added `ArchipelagoGame` entity and `game_selection_games` migration.
- Added admin game library service and controller with list/create/update/delete endpoints.
- Added validation for required fields, slug shape, availability, supported event types, and duplicate slugs.
- Added safe deletion boundary through `usageCount` and conflict handling.
- Added admin `/admin/jeux` UI with empty state, create/edit form, inline validation, table, and delete confirmation.

### Validation Results

- `composer test` passed: 79 tests, 1017 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-5-archipelago-game-library-management.md
- api/config/packages/doctrine.yaml
- api/migrations/Version20260425001000.php
- api/src/GameSelection/Application/AdminGameLibrary.php
- api/src/GameSelection/Domain/ArchipelagoGame.php
- api/src/GameSelection/Presentation/AdminGameLibraryController.php
- api/tests/Functional/AdminGameLibraryTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/app/admin/jeux/page.tsx
- frontend/src/features/admin/admin-game-library-dashboard.tsx

### Change Log

- 2026-04-25: Implemented Story 3.5 Archipelago game library management with backend CRUD, validation, RBAC tests, and admin UI.
