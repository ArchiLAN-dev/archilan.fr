# Story: real game usage count + sortable admin games list

Status: review

Repo: `archilan.fr` (monorepo, `api/` + `frontend/`).

## Story

As an admin,
I want the games library to show each game's real usage count and to be sortable by name and by
usage,
so that I can see which games are actually used and order the list accordingly.

## Context

`AdminGameLibrary::usageCount()` was a stub returning `0`, and `DbalAdminGameListQuery` hardcoded
`usageCount => 0`. So the "Utilisations" column was always 0, the delete guard ("can't delete a used
game") never triggered, and there was nothing meaningful to sort by. This wires a real count and adds
sorting.

**Usage definition:** a game is "used" when it is referenced by a **session slot**
(`session_slot.game_id` - actual sessions / personal runs) or a **weekly template**
(`weekly_templates.game_id`). Usage = the sum of both counts. (Event game-selection lives in an
`event.game_selection_config` JSON blob = "available for selection", not "used"; excluded.)

## Acceptance Criteria

1. `GameUsageCounterInterface::count(gameId)` (Application) + `DbalGameUsageCounter` (Infrastructure,
   DBAL QueryBuilder COUNT on `session_slot` + `weekly_templates`). Bound in `services.yaml`.
2. `AdminGameLibrary::usageCount()` uses it (so the delete guard + detail are accurate too).
3. The list query (`DbalAdminGameListQuery`) returns the real `usageCount` per game and accepts a
   `sort` (`name`|`usage`) + `dir` (`asc`|`desc`); ORDER BY applied accordingly (default name asc).
4. `GET /admin/games` accepts `sort` + `dir` query params (validated; defaults name/asc).
5. Frontend: a "Trier par" control (Nom A→Z / Z→A, Utilisations ↓ / ↑) on `/admin/jeux`, driving the
   query; works for the desktop table and the mobile cards.
6. Gates green: API (phpstan, php-cs-fixer, phpunit, app:architecture:ddd), Frontend
   (typecheck/lint/build).

## Tasks / Subtasks

- [ ] Task 1 - `GameUsageCounterInterface` + `DbalGameUsageCounter` + services binding (AC 1).
- [ ] Task 2 - `AdminGameLibrary` uses the counter in `usageCount()` (AC 2).
- [ ] Task 3 - list query: real usage + sort/dir (AC 3); thread sort/dir through `AdminGameLibrary::list`.
- [ ] Task 4 - controller parses sort/dir (AC 4).
- [ ] Task 5 - frontend sort control + api param (AC 5).
- [ ] Task 6 - tests + gates (AC 6).

## Dev Notes

- DDD: no DBAL in Application; the counter and list query live in Infrastructure behind interfaces.
- The list usage is computed in-query (correlated subquery) to avoid N+1; the single-game counter is
  used by the delete guard / detail.

## Dev Agent Record

## Change Log

- 2026-06-10 - Story created.
