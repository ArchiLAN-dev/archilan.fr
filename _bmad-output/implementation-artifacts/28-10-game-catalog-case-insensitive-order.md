# Story 28.10: Case-insensitive alphabetical ordering of game lists

**Status:** review
**Epic:** 28 - Jeux catalogue & game selection
**Date:** 2026-06-27

## Story

As a player browsing the game-selection grid (and admins browsing game lists),
I want games ordered case-insensitively by name,
so that "Animal Well" sorts under the A-n group instead of jumping ahead of "ActRaiser" (uppercase
sorting before lowercase, ASCII/byte order).

## Context

The Postgres database uses a byte-ordered (`C`) collation, so `ORDER BY name` sorts uppercase before
lowercase: e.g. "ANIMAL WELL" (`A`,`N`=78) lands before "ActRaiser" (`A`,`c`=99). The public `/jeux`
catalog hides this because it re-sorts client-side (`localeCompare("fr", { sensitivity: "base" })`), but
the **run game-selection** grid renders the API order directly, exposing the ASCII order. (Reported by
Jean.)

The order is produced by SQL `ORDER BY <name>` in three queries; making them case-insensitive at the
source fixes every consumer (run selection, event registration selection, public catalog SSR, weekly
admin game list) without per-page client sorts.

## Acceptance Criteria

1. Game lists are ordered case-insensitively by name. Specifically, for games "ActRaiser", "ANIMAL WELL",
   "banjo", the run selection returns them as ActRaiser < ANIMAL WELL < banjo (not the byte order
   ANIMAL WELL < ActRaiser < banjo).
2. Fixed at the SQL source (`ORDER BY LOWER(name)`):
   - `DoctrineGameRepository::findByAvailabilitiesSortedByName` (run selection availableGames),
   - `DbalGameCatalogQuery` (public catalog + event registration selection),
   - `DbalAdminWeeklyRunGameListQuery` (weekly admin game list).
3. No regression in existing game-selection / registration / weekly functional tests.
4. Gates green: API `phpstan` / `php-cs-fixer` / `phpunit` / `app:architecture:ddd`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2). `ORDER BY LOWER(...)` in the three queries (DQL `LOWER(g.name)` for the ORM
  repository; SQL `LOWER(...)` for the two DBAL queries; the GROUP BY query keeps `g.name` grouped).
- [x] **Task 2** (AC 1,3). Functional test in `PersonalRunGameSelectionPayloadTest` seeding mixed-case
  names and asserting ActRaiser < ANIMAL WELL < banjo.
- [x] **Task 3** (AC 4). phpstan / cs-fixer / ddd green; affected functional suites green (42 tests);
  verified live on the run selection grid (ActRaiser now precedes Animal Well).

## Dev Notes

- `LOWER()` addresses the reported issue (case). It does not locale-fold accents the way the public
  catalog's `localeCompare("fr", base)` does, so an accented name (e.g. "Éclat") still byte-orders under
  the `C` collation. Game names are overwhelmingly ASCII; a fully locale-correct order would need a
  `COLLATE "fr-x-icu"` (ICU collation availability dependent) - out of scope for this case fix.
- Run selection (`PersonalRunGameSelection`) uses the **repository** `findByAvailabilitiesSortedByName`,
  not `DbalGameCatalogQuery` - that was the actual source of the reported page's order; the catalog query
  feeds the public catalog (already re-sorted client-side) and event registration.
- Other name-ordered admin queries (`DbalAdminGameListQuery`, etc.) were left as-is; they were not part
  of the report and some carry user-driven sort direction. Can get the same treatment if needed.

### Project Structure Notes

- `api/src/GameSelection/Infrastructure/DoctrineGameRepository.php`
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php`
- `api/src/WeeklyRuns/Infrastructure/DbalAdminWeeklyRunGameListQuery.php`
- `api/tests/Functional/PersonalRunGameSelectionPayloadTest.php`

### References

- [Source: frontend/src/features/games/games-filter.ts (public catalog client-side localeCompare "fr" base)]
- [Source: api/src/PersonalRuns/Application/PersonalRunGameSelection.php (availableGames via repository)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Three SQL `ORDER BY` made case-insensitive via `LOWER(...)`; covers run selection, event registration,
  public catalog SSR, and weekly admin game list.
- Root cause: byte-ordered (`C`) DB collation. Run selection renders API order directly (no client sort).
- New functional assertion locks the case-insensitive order; gates green; verified live.

### File List

- `api/src/GameSelection/Infrastructure/DoctrineGameRepository.php`
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php`
- `api/src/WeeklyRuns/Infrastructure/DbalAdminWeeklyRunGameListQuery.php`
- `api/tests/Functional/PersonalRunGameSelectionPayloadTest.php`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-27 | Created + implemented. Game selection grid showed ASCII order (uppercase before lowercase) from the byte-ordered DB collation. Made the three name `ORDER BY` clauses case-insensitive (`LOWER()`). Functional test added; gates green; verified live. Status → review. |
