# Story 3.6: Randomizer Option Definition for Games

Status: done

## Story

As an admin,
I want to define configurable randomizer options per game,
so that participants can configure selected games during registration.

## Acceptance Criteria

1. Given a game exists in the library, when an admin adds configurable options, then each option can define label, key, input type, plain-language description, required flag, default value, and advanced/basic visibility.
2. Invalid option schemas are rejected server-side.
3. Advanced options are distinguishable from base options.
4. Option definitions are version-safe enough not to break existing registrations silently.
5. Configured options are available to event game selection setup.

## Tasks / Subtasks

- [x] Extend game option model (AC: 1, 3, 4, 5)
  - [x] Add versioned `randomizerOptions` JSON to games.
  - [x] Add `optionSchemaVersion`.
  - [x] Support option key, label, input type, description, required flag, default value, and visibility.
  - [x] Expose versioned options in game payloads.
- [x] Add backend option configuration API (AC: 1, 2, 3, 4, 5)
  - [x] Add migration for option schema fields.
  - [x] Add `PATCH /api/v1/admin/games/{gameId}/options`.
  - [x] Validate option shape, key format, duplicate keys, input type, and visibility.
  - [x] Increment schema version on each successful option reconfiguration.
  - [x] Keep options attached to game records for later event selection setup.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test admin can configure basic and advanced options.
  - [x] Test schema version increments.
  - [x] Test invalid option schemas are rejected with field-level errors.
  - [x] Extend RBAC coverage for option endpoint.
- [x] Update frontend game library UI (AC: 1, 3, 4)
  - [x] Display option count and schema version.
  - [x] Add option configuration dialog.
  - [x] Preserve version metadata while editing the editable schema fields.
  - [x] Submit option definitions to the admin API.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

The option schema is versioned at game level. Every successful update increments `optionSchemaVersion`, and each option in the stored payload receives the new version. Future registration records can store the schema version they were created against to avoid silent breakage.

The admin UI uses a JSON schema editor for this story to avoid introducing a premature complex option-builder UI before registration and event selection flows are known.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.6-Randomizer-Option-Definition-for-Games]
- [Source: _bmad-output/implementation-artifacts/3-5-archipelago-game-library-management.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Extended `AdminGameLibraryTest` with option configuration and invalid schema tests.
- Added schema versioning to `ArchipelagoGame` so options can evolve without silent registration breakage later.
- Added the options dialog to the existing `/admin/jeux` dashboard.

### Completion Notes List

- Added `randomizer_options` and `option_schema_version` persistence.
- Added server-side option schema parsing/validation.
- Added admin option configuration endpoint.
- Added versioned option payloads to game records for future event selection setup.
- Added frontend option count/version display and JSON configuration dialog.

### Validation Results

- `composer test` passed: 81 tests, 1078 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-6-randomizer-option-definition-for-games.md
- api/migrations/Version20260425001100.php
- api/src/GameSelection/Application/AdminGameLibrary.php
- api/src/GameSelection/Domain/ArchipelagoGame.php
- api/src/GameSelection/Presentation/AdminGameLibraryController.php
- api/tests/Functional/AdminGameLibraryTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/admin/admin-game-library-dashboard.tsx

### Change Log

- 2026-04-25: Implemented Story 3.6 versioned randomizer option definitions for games with backend validation, tests, and admin UI.
