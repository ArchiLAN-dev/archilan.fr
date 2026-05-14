# Story 16.3: Game Configuration for Personal Run

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-05-12

## Story

As a run owner,
I want to configure which Archipelago games are included in my run,
So that the multiworld generation has the correct game list when I start the server.

## Acceptance Criteria

1. `PATCH /api/v1/runs/{runId}/games` with `{ "games": [{ "gameId": "..." }, ...] }` (owner only, run in `draft` or `idle`) updates `game_selection_config`. Unknown `gameId` values → 422 `unknown_game`. Active run → 422 `run_active`. Non-owner → 403. Empty games array → 422 `games_required`.
2. At least 1 game required.
3. Each `gameId` must exist in the Archipelago game library.

## Tasks / Subtasks

- [x] Task 1: API endpoint (AC: 1–3)
  - [x] `PATCH /api/v1/runs/{runId}/games` in `PersonalRunController`
  - [x] Validate each `gameId` against `App\GameSelection` game library
  - [x] Update `game_selection_config` on `PersonalRun` entity
  - [x] Guard: owner only, run not active

- [x] Task 2: Tests
  - [x] Valid update (draft → config saved), valid update (idle → config saved), update active run (422), unknown gameId (422), non-owner (403), empty games (422)

## Dev Notes

- `game_selection_config` format: `[{"gameId": "Hollow Knight"}, ...]` - same shape as `Event.game_selection_config`
- Reuse game library validation from `App\GameSelection\Application` (or query `games` table directly)
- No per-slot randomizer option configuration in v1 - owner sets the game list, options use defaults

### References

- `api/src/Events/Application/AdminEventDrafts.php` - `parseGameSelectionConfig()` pattern
- Story 16.1: `PersonalRun` entity

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `PersonalRunGameConfig` uses a named-parameter `result()` helper - avoids repeating default values across early-return paths.
- `parseGames()` silently skips malformed entries; only the final empty-list check produces a user-visible error.
- `validateGameIds()` wraps `ValidationErrors` to produce indexed keys (`games.0.gameId`) matching the request shape.
- Blocking completed/cancelled runs from game config via `run_not_configurable` - consistent with 16.2 cancel semantics.
- `ArchipelagoGame::create()` factory used in tests to avoid touching nullable optional constructor params.

### Debug Log

(none)

### File List

- `api/src/PersonalRuns/Domain/PersonalRun.php` (modified - `configureGames()` method)
- `api/src/PersonalRuns/Application/PersonalRunGameConfig.php` (new)
- `api/src/PersonalRuns/Presentation/PersonalRunController.php` (modified - `configureGames()` action)
- `api/tests/Functional/PersonalRunGameConfigTest.php` (new)

### Change Log

- 2026-05-12: Story 16.3 implemented - game config endpoint, application service, 9 functional tests (all passing, PHPStan level 8 clean).
