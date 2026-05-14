# Story 14.3: Catalogue diff service

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want to see what is new, changed, and disappeared from the sheet,
So that I can make informed decisions about what to create or update.

---

## Matching Strategy (in order)

1. `catalog_sheet_name` exact match
2. `archipelago_game_name` case-insensitive + trim
3. `name` case-insensitive + trim
4. No match → game is new

---

## Acceptance Criteria

1. `CatalogSyncService::computeDiff()` returns `newGames`, `stabilityChanged`, `removedFromSheet`.
2. `stabilityChanged` excludes games where `availability_locked = true`.
3. `removedFromSheet` only contains games with `catalog_sheet_name` set - informational only, no automatic action.
4. Unmatched bundled games appear in `newGames` with `bundledWithAp = true`.
5. Unit-tested including name-conflict edge cases.

---

## Tasks

- [x] Implement `CatalogSyncService::computeDiff()` with 3-step name matching
- [x] Exclude `availability_locked` games from `stabilityChanged`
- [x] Build `removedFromSheet` list (games with `catalog_sheet_name` set but absent from sheet)
- [x] Handle bundled games in `newGames`
- [x] Write unit tests for name-conflict edge cases
- [x] Run `cs-fixer`

---

## File List

- `src/CatalogSync/Application/CatalogSyncService.php` (modified - added `computeDiff()` + `findMatch()`)
- `tests/Unit/CatalogSync/CatalogDiffTest.php` (new - 13 tests)

---

## Change Log

- 2026-05-11: Story implemented - `computeDiff()` added to `CatalogSyncService`, 3-step matching, 13 unit tests (527/527 passing).

---

## Dev Agent Record

### Completion Notes

- `computeDiff(list<CatalogEntry> $sheetEntries, list<ArchipelagoGame> $existingGames)` is a pure method - no DB, no HTTP, fully unit-testable.
- Matching order strictly respected: `catalog_sheet_name` exact → `archipelago_game_name` case-insensitive → `name` case-insensitive. First match wins; no fallback once a step matches.
- `stabilityChanged` only emits if `!availabilityLocked` AND `game->availability !== entry->availability`.
- `removedFromSheet` built via `array_filter` on games with `catalogSheetName !== null && !== ''` whose ID isn't in the matched-IDs set.
- `ArchipelagoGame` imported from `GameSelection` domain - cross-context dependency in Application layer is acceptable (DDD validator only polices Domain layer imports).
- 13 unit tests: new game, bundled new, exact match, case-insensitive by archipelagoGameName, case-insensitive by name, priority conflict (catalogSheetName beats name), stability changed, locked excluded, no change, removed with catalogSheetName, not removed without catalogSheetName, not removed when matched, full mixed scenario.
- cs-fixer: clean (0 files to fix).
