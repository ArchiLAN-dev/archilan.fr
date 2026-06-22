# Story 18.10: Count Weekly Runs in Player History and Profile Showcase

## Story

**As a** member who completes weekly runs,
**I want** my completed weekly runs to appear in my run history and feed the profile showcase widgets
("Meilleures runs", "Les plus joués"),
**So that** my public profile reflects all the Archipelago I played - consistently with the header stats.

## Context

Follow-up to story 18.9 (which fed weekly runs into the global + player *stats*). The player profile
header now counts weekly runs, but the **showcase widgets** (`best_runs`, `most_played`) and the run
history list are derived from `GET /players/{slug}/history`, which only read `session_slot`
(event/personal runs). So the showcase ignored weekly runs while the header counted them.

Reported on the player profile page.

## Status

done

## Acceptance Criteria

**AC1:** `GET /players/{slug}/history` includes each completed weekly entry (`goal_reached_at` set) as a
history row, with the game (from the weekly template), checks (`checks_total`), items (`items_total`),
and completion date, joined `weekly_entries → weekly_runs → weekly_templates → game`.

**AC2:** Each history row carries `isWeekly`. Weekly rows render in the history list **without** the
`/runs/{id}/resultats` link (no such page exists for weekly sessions); event/personal rows are
unchanged.

**AC3:** The showcase widgets, computed client-side from the history, now include weekly runs:
"Meilleures runs" (by checks) and "Les plus joués" (count per game).

**AC4:** Incomplete weekly entries (no goal) don't appear (consistent with the stats rule).

**AC5:** Quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest).

## Tasks / Subtasks

- [x] Task 1: `DbalPlayerHistoryQuery` - add a `weekly_entries` path (completed only) joined to
  `weekly_runs`/`weekly_templates`/`game`, returning the same row columns plus `is_weekly`.
- [x] Task 2: `PlayerHistoryQuery` - map `isWeekly` into the history DTO.
- [x] Task 3: Frontend - `RunHistoryEntry.isWeekly` + type guard; `RunHistoryRow` renders weekly rows
  as a non-link; showcase (`bestRuns`/`mostPlayed`) is unchanged and now picks up weekly rows.
- [x] Task 4: Tests - `PlayerProfileTest::testHistoryIncludesCompletedWeeklyRuns`; updated the
  `player-profile-api` jest fixture for the new field.
- [x] Task 5: Quality gates.

## Dev Notes

### Why a non-link for weekly rows

Event/personal history rows link to `/runs/{sessionId}/resultats`. Weekly sessions have no such public
results page, so weekly rows are rendered as plain (non-link) cards. `session_id` for weekly is
`COALESCE(external_session_id, weekly_entry.id)` so React keys stay unique.

### Consistency with 18.9

History weekly rows are gated on `goal_reached_at IS NOT NULL` - the same "completed only" rule used by
the stats queries in 18.9, so the showcase and the header agree.

## File List

- `api/src/Identity/Infrastructure/DbalPlayerHistoryQuery.php` - modified
- `api/src/Identity/Application/PlayerHistoryQuery.php` - modified
- `frontend/src/features/players/player-profile-api.ts` - modified
- `frontend/src/features/players/player-profile-page.tsx` - modified
- `api/tests/Functional/PlayerProfileTest.php` - modified
- `frontend/src/features/players/player-profile-api.test.ts` - modified

## Change Log

| Date | Change |
|------|--------|
| 2026-06-21 | Story created and implemented (follow-up to 18.9; profile showcase missing weekly) |
