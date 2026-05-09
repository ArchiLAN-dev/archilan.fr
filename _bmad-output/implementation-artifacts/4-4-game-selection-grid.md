# Story 4.4: Game Selection Grid

Status: done

## Story

As a registered participant,
I want to select my Archipelago games after reserving a seat,
So that the organiser knows which games to include in the randomiser.

## Acceptance Criteria

1. Given a user has a valid reservation, when they visit the game selection page, then they see the games configured for the event.
2. A per-registrant maximum can be configured; when reached, un-selected games are disabled.
3. The user can save their selection, which is persisted against their registration.
4. Game selection not enabled shows a friendly "not yet available" message.
5. After reserving a seat, the confirmation panel links directly to the game selection page.

## Tasks / Subtasks

- [x] Domain: add `gameSelectionMaxPerRegistrant: ?int` trailing optional param to `Event` constructor + `getGameSelectionMaxPerRegistrant()` getter + update `configureGameSelection()` to accept it
- [x] Domain: add `selectedGameIds: list<string>` trailing optional param to `Registration` constructor (JSON column) + `getSelectedGameIds()` + `selectGames()` mutator
- [x] Migration: `Version20260501002000.php` - add `game_selection_max` to events, `selected_game_ids` to registrations
- [x] Application: `RegistrationGameSelection` service with `getSelection()` and `saveSelection()` (AC: 1–4)
  - [x] `getSelection` returns null for unknown/foreign registration.
  - [x] `saveSelection` returns 'error' when game selection disabled, game not in config, or max exceeded.
- [x] Admin: update `AdminEventGameSelection::getConfig()` to include `gameSelectionMax`; update `configure()` to parse and persist it
- [x] Controller: add `GET /api/v1/registrations/{registrationId}/game-selection` (ROLE_USER) → 200 or 404
- [x] Controller: add `PUT /api/v1/registrations/{registrationId}/game-selection` (ROLE_USER) → 200, 404, or 422
- [x] Backend tests: `RegistrationGameSelectionTest` with 10 test methods (AC: 1–4)
- [x] Extend `RbacEnforcementTest` with both new endpoints
- [x] Frontend: update `EligiblePanel` confirmation panel to link to game selection page (AC: 5)
- [x] Frontend: new page `/evenements/[eventSlug]/inscription/[registrationId]/jeux/page.tsx`
- [x] Frontend: `GameSelectionGate` client component with `GameCard` sub-component (AC: 1–4)
  - [x] Fetches auth + game selection data on mount; redirects to login if unauthenticated.
  - [x] Renders game grid with selected/limit-reached states.
  - [x] Keyboard navigation: Enter/Space toggles selection.
  - [x] Save button persists selection via PUT; shows saved/error feedback.

### Review Findings

- [x] [Review][Bug] Game selection accepts cancelled registrations as valid reservations [api/src/Registrations/Application/RegistrationGameSelection.php:28]

## Dev Notes

`gameSelectionMaxPerRegistrant` and `selectedGameIds` are added as trailing optional constructor parameters (defaults: `null` and `[]`) so all existing test call sites remain valid without modification.

The registrant-facing `availableGames` list is built by fetching only the game IDs present in `event.gameSelectionConfig`, preserving the admin's curation. The full game library is never exposed to registrants.

`saveSelection` validates that submitted game IDs are a subset of the event's configured game IDs; it does not re-validate availability (the admin is trusted to have configured a valid set).

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Completion Notes List

- Added `gameSelectionMaxPerRegistrant` to `Event` (trailing optional `?int`) and updated `configureGameSelection()`.
- Added `selectedGameIds` to `Registration` (trailing optional `list<string>`, JSON column) with `getSelectedGameIds()` and `selectGames()`.
- Created migration `Version20260501002000.php`.
- Created `RegistrationGameSelection` application service.
- Updated `AdminEventGameSelection` to expose and accept `gameSelectionMax`.
- Added `getGameSelection` and `saveGameSelection` endpoints to `RegistrationController`.
- Created `RegistrationGameSelectionTest` with 10 test methods.
- Extended `RbacEnforcementTest` with 2 new endpoints.
- Updated `EligiblePanel` confirmation to show "Choisir mes jeux →" link.
- Created `GameSelectionGate` component with `GameCard` sub-component.
- Created `/evenements/[eventSlug]/inscription/[registrationId]/jeux/page.tsx`.

### Validation Results

- `composer test` passed: 147 tests, 1708 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/4-4-game-selection-grid.md
- api/src/Events/Domain/Event.php
- api/src/Events/Application/AdminEventGameSelection.php
- api/src/Registrations/Domain/Registration.php
- api/src/Registrations/Application/RegistrationGameSelection.php
- api/src/Registrations/Presentation/RegistrationController.php
- api/migrations/Version20260501002000.php
- api/tests/Functional/RegistrationGameSelectionTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/events/registration-eligibility-gate.tsx
- frontend/src/features/events/game-selection-gate.tsx
- frontend/src/app/evenements/[eventSlug]/inscription/[registrationId]/jeux/page.tsx

### Change Log

- 2026-05-01: Implemented Story 4.4 - game selection grid with per-registrant max, keyboard navigation, and seat-reservation → game-selection flow.
