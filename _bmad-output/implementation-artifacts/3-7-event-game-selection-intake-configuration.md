# Story 3.7: Event Game Selection Intake Configuration

Status: done

## Story

As an admin,
I want to configure game selection intake per event,
so that participants only see relevant games and options for that event.

## Acceptance Criteria

1. Given an event and game library entries exist, when an admin configures game selection for the event, then they can enable or disable game selection intake.
2. They can choose available games for the event.
3. They can choose which game options are visible for the event.
4. The configuration is saved independently per event.
5. Disabling game selection clearly affects the future registration flow.

## Tasks / Subtasks

- [x] Extend Event domain (AC: 1, 4, 5)
  - [x] Add `game_selection_enabled` boolean to Event entity.
  - [x] Add `game_selection_config` JSON to Event entity (list of `{gameId, visibleOptionKeys}`).
  - [x] Add `configureGameSelection()` domain method.
  - [x] Expose `gameSelectionEnabled` in admin event list payload.
- [x] Add migration (AC: 1, 4)
  - [x] Create `Version20260430001000.php` adding the two new columns.
- [x] Create AdminEventGameSelection application service (AC: 1, 2, 3, 4)
  - [x] `getConfig()`: returns enriched config (selected games with details + full available game list).
  - [x] `configure()`: validates and persists enabled flag + game list + visible option keys.
  - [x] Validate: gameSelectionEnabled required; each gameId must exist; each visibleOptionKey must exist in game options; no duplicate gameIds.
- [x] Add controller endpoints (AC: 1, 2, 3, 4, 5)
  - [x] `GET /api/v1/admin/events/{eventId}/game-selection` - enriched config for dialog.
  - [x] `PATCH /api/v1/admin/events/{eventId}/game-selection` - save configuration.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test anonymous/lambda cannot access endpoints (RBAC).
  - [x] Test 404 for unknown event.
  - [x] Test default disabled config with available games listed.
  - [x] Test admin enables game selection with specific games and option keys.
  - [x] Test empty visibleOptionKeys accepted (means all options visible).
  - [x] Test admin disables game selection.
  - [x] Test gameSelectionEnabled field is required.
  - [x] Test invalid gameId rejected.
  - [x] Test duplicate gameId rejected.
  - [x] Test invalid option key rejected.
  - [x] Test gameSelectionEnabled reflected in event list payload.
  - [x] Extend RBAC enforcement test with both new endpoints.
  - [x] Update existing Event constructor calls in test helpers (3 files).
- [x] Update frontend admin event dashboard (AC: 1, 2, 3, 5)
  - [x] Add `gameSelectionEnabled` to AdminEvent type.
  - [x] Add "Jeux" column with enabled/configurer badge per event row.
  - [x] Add `GameSelectionDialog` component with enable toggle, game checklist, and option key checklist.
  - [x] Save with PATCH and update local state on success.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

### Review Findings

- [x] [Review][Patch] Event game selection accepts games that are not available/relevant for the event [api/src/Events/Application/AdminEventGameSelection.php:32]

## Dev Notes

`visibleOptionKeys: []` (empty) means all of the game's options are visible - admins only need to act when restricting to a subset. This avoids requiring admins to re-select all options every time a game is added.

The GET endpoint returns both the current configuration and the full list of available games, so the frontend dialog is self-contained in a single fetch.

Cross-bounded-context validation (gameId exists, option keys exist) is handled at the application service layer without coupling domain entities across contexts.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.7]
- [Source: _bmad-output/implementation-artifacts/3-6-randomizer-option-definition-for-games.md]

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Completion Notes List

- Added `game_selection_enabled` and `game_selection_config` to `Event` entity with `configureGameSelection()` domain method.
- Created `AdminEventGameSelection` application service with `getConfig()` (enriched GET response) and `configure()` (validate + persist).
- Added `GET` and `PATCH /api/v1/admin/events/{eventId}/game-selection` routes.
- Created `AdminEventGameSelectionTest` with 10 test methods covering RBAC, validation, and behavior.
- Extended `RbacEnforcementTest` with both new endpoints.
- Updated 3 existing test files that construct `Event` directly to pass the 2 new constructor params.
- Added `gameSelectionEnabled` to the admin event list payload.
- Added `GameSelectionDialog` to `admin-event-dashboard.tsx` with enable toggle, game checklist, and per-game option key sub-checklist.

### Validation Results

- `composer test` passed: 92 tests, 1275 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-7-event-game-selection-intake-configuration.md
- api/migrations/Version20260430001000.php
- api/src/Events/Domain/Event.php
- api/src/Events/Application/AdminEventDrafts.php
- api/src/Events/Application/AdminEventGameSelection.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/AdminEventGameSelectionTest.php
- api/tests/Functional/AdminEventEditTest.php
- api/tests/Functional/AdminEventLifecycleTest.php
- api/tests/Functional/AdminEventPrivateAccessTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/admin/admin-event-dashboard.tsx

### Change Log

- 2026-05-01: Implemented Story 3.7 - per-event game selection intake configuration with backend validation, tests, and admin UI.
