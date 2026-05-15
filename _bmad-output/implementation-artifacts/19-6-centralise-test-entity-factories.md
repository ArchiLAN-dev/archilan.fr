# Story 19.6: Centralise Test Entity Factories in FunctionalTestCase

## Story

**As a** developer,
**I want** `createUser()`, `createEvent()`, `createGame()`, and `createRegistration()` defined once in `FunctionalTestCase`,
**So that** functional test files do not each maintain their own copy of entity-construction boilerplate.

## Status

review

## Acceptance Criteria

**AC1:** `FunctionalTestCase` exposes `createUser()`, `createEvent()`, `createGame()`, and `createRegistration()` — each persists and flushes the entity before returning it.

**AC2:** `createEvent()` accepts optional `$published`, `$gameSelectionEnabled`, and `$gameSelectionConfig` parameters so tests requiring published or game-selection-enabled events do not need to bypass the factory.

**AC3:** `createRegistration()` accepts an optional `$selectedGameIds` list so tests requiring pre-seeded game slots do not need to bypass the factory.

**AC4:** No functional test file retains a full duplicate of the shared construction logic — local helpers that previously inlined `new Event(...)`, `Event::draft(...)`, `ArchipelagoGame::create(...)`, or `new Registration(...)` are replaced by calls to the shared factories.

**AC5:** All four quality gates (PHPStan level max, CS Fixer, `phpunit`, DDD validator) pass green.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Implement shared factories in `FunctionalTestCase`
  - [x] 2a: `createUser(email, roles, displayName, slug)`
  - [x] 2b: `createEvent(title, startsAt, endsAt, capacity, published, gameSelectionEnabled, gameSelectionConfig)`
  - [x] 2c: `createGame(name, slug)`
  - [x] 2d: `createRegistration(eventId, userId, status, selectedGameIds)`
- [x] Task 3: Migrate local helpers to use shared factories
  - [x] `AdminRegistrationDetailTest::makeEvent()` — delegates to `createEvent(published: true, ...)`
  - [x] `AdminRegistrationDetailTest::makeRegistration()` — delegates to `createRegistration(..., selectedGameIds)`
  - [x] `RegistrationSubmitTest::makeEvent()` — delegates to `createEvent(published: true, ...)`
  - [x] `RegistrationSubmitTest::makeRegistration()` — delegates to `createRegistration(..., selectedGameIds)`
  - [x] `PlayerProfileTest` — replaces inline `Event::draft` / `ArchipelagoGame::create` / `new Registration` with factory calls
  - [x] `CommunityLeaderboardTest` — same (5 test methods updated)
  - [x] `RunResultsTest` — replaces inline `Event::draft` / `ArchipelagoGame::create` with factory calls
- [x] Task 4: Quality gates

## Dev Notes

### Factory design

`createEvent()` uses `Event::draft()` internally then calls `configureGameSelection()` and `transitionTo(STATUS_PUBLISHED)` when the optional flags are set. This preserves domain invariants (publish transition is validated) rather than bypassing them with a raw `new Event(...)` constructor call.

`createRegistration()` builds the `$slots` array from `$selectedGameIds` using the same shape expected by the Registration constructor (`slotId`, `gameId`, `slotOrder`).

### Session helpers

`makeFinishedSession()` / `createFinishedSession()` remain as local helpers in their respective test classes — they are test-scenario helpers (setting session state to `finished`, adding timestamps) rather than basic entity factories, and are not repeated across files.

## File List

- `api/tests/Functional/FunctionalTestCase.php` — extended with richer factory signatures
- `api/tests/Functional/AdminRegistrationDetailTest.php` — local helpers delegate to shared factories
- `api/tests/Functional/RegistrationSubmitTest.php` — local helpers delegate to shared factories
- `api/tests/Functional/PlayerProfileTest.php` — direct constructions replaced
- `api/tests/Functional/CommunityLeaderboardTest.php` — direct constructions replaced (5 test methods)
- `api/tests/Functional/RunResultsTest.php` — direct constructions replaced
- `_bmad-output/implementation-artifacts/19-6-centralise-test-entity-factories.md` — this file

## Change Log

| Date | Change |
|------|--------|
| 2026-05-15 | Story created and implemented |
