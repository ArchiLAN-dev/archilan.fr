# Story 18.9: Count Weekly Runs in Community and Player Stats

## Story

**As a** member who completes weekly runs,
**I want** my completed weekly runs to count in the global community stats and in my player profile stats,
**So that** the numbers reflect all the Archipelago I actually played, not only event/personal runs.

## Context

Weekly runs (WeeklyRuns context) record a member's completion in the `weekly_entries` table
(`goal_reached_at`, `checks_total`, `items_total`, `user_id`) when the goal callback fires — they
**never write to `session_slot`**. Both stats read models aggregate only `session_slot`, so weekly
runs are invisible to:

- the global community stats (`DbalCommunityStatsQuery` → `/community/stats`), and
- the per-player profile stats (`DbalPlayerStatsQuery` → `/players/{slug}`), which also feeds
  achievement metrics via `StatsMetricProvider`.

Reported as bug #2 in `HOTFIX-BACKLOG.md`.

## Scope

In scope: the two numeric stats read models above. A **completed** weekly entry
(`goal_reached_at IS NOT NULL`) counts as one finished run / one game / one goal, contributing its
`checks_total` and `items_total`.

Out of scope (noted as follow-ups):

- The main `/classements` leaderboard ranking (weekly runs already have their own dedicated weekly
  leaderboard).
- `StatsMetricProvider::distinctGames()` (reads `PlayerHistoryQueryInterface`, not the stats query) —
  weekly games won't add to the distinct-games achievement fact until player history includes them.

## Status

done

## Acceptance Criteria

**AC1:** `GET /community/stats` includes completed weekly entries: each completed weekly entry adds 1
to `totalFinishedSessions` and `totalGoalsReached`, and its `checks_total` to `totalChecksDone`.

**AC2:** `GET /players/{slug}` includes the player's completed weekly entries: each adds 1 to
`runsParticipated`, `gamesPlayed`/goal-rate denominator, and `goalCompletions`, plus `checks_total`
to `totalChecksDone` and `items_total` to `totalItemsReceived`.

**AC3:** Non-completed weekly entries (`goal_reached_at IS NULL`) contribute nothing.

**AC4:** Existing event/personal-run stats are unchanged (no double counting — weekly lives in its own
table and is summed alongside, not joined).

**AC5:** All quality gates pass: `phpstan` (max, 0 errors), `php-cs-fixer` (0 violations), `phpunit`
(green, 0 notices/deprecations/warnings), `app:architecture:ddd` (exit 0).

## Tasks / Subtasks

- [x] Task 1: `DbalCommunityStatsQuery` — add a `weekly_entries` aggregation (`goal_reached_at IS NOT
  NULL`): `COUNT(*)` into finished-sessions and goals, `SUM(checks_total)` into checks. Resolve the
  table name from `WeeklyEntry` metadata (cross-context import, allowed — see Dev Notes).
- [x] Task 2: `DbalPlayerStatsQuery` — add a third query path over `weekly_entries WHERE user_id =
  :userId AND goal_reached_at IS NOT NULL`, summed into the returned totals.
- [x] Task 3: Functional tests — extend `CommunityLeaderboardTest` (global stats) and
  `PlayerProfileTest` (player stats) with a completed + a non-completed weekly entry, asserting the
  deltas and that incomplete entries are ignored.
- [x] Task 4: Run all backend quality gates (AC5).

## Dev Notes

### Cross-context data access

Both queries are read models that already reach across contexts (`DbalLeaderboardQuery` in the same
`Sessions/Infrastructure` imports `User`, `Run`, `Registration` from other contexts). Reading the
WeeklyRuns-owned `weekly_entries` table here is consistent and not flagged by the DDD validator, which
only restricts Doctrine `Connection`/`EntityManager` in Application/Presentation and same-context
imports inside Domain.

### Why "completed only"

A weekly entry records `checks_total`/`items_total` only at goal time (`recordGoal`). An incomplete
entry has no recorded stats, so counting only `goal_reached_at IS NOT NULL` is both correct (no NULL
sums) and consistent with the personal-run rule (story 17.15: a personal run counts only when a goal
was reached).

### Achievements side effect

`DbalPlayerStatsQuery` feeds `StatsMetricProvider` (achievement facts: runs/goals/checks/items).
Including weekly completions there is intentional — weekly achievements should count toward those
facts. `distinctGames` is unaffected (separate history query) and left as a follow-up.

## File List

- `api/src/Sessions/Infrastructure/DbalCommunityStatsQuery.php` — modified
- `api/src/Identity/Infrastructure/DbalPlayerStatsQuery.php` — modified
- `api/tests/Functional/CommunityLeaderboardTest.php` — modified
- `api/tests/Functional/PlayerProfileTest.php` — modified

## Change Log

| Date | Change |
|------|--------|
| 2026-06-21 | Story created and implemented (bug #2 from HOTFIX-BACKLOG) |
