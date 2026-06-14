# Story 23.14: Weekly completion time measured from the player's launch, not the run start

**Status:** review
**Epic:** 23 - Weekly runs
**Date:** 2026-06-14

## Story

As a member competing on a weekly run,
I want my leaderboard time to count from **when I launched my game**, not from when the weekly run was
generated,
So that the "Meilleur temps" reflects how fast *I* finished, not how long I waited before starting.

## Context

`RecordWeeklyGoal` (called by the goal callback) computed:

```php
$completionTimeSeconds = max(0, $goalReachedAt - $run->getStartedAt());
```

`run.startedAt` is set once when the **run is generated** (`GenerateWeeklyRunForTemplate` /
`GenerateWeeklyRunsMessageHandler`, status ACTIVE) — a **global** timestamp shared by all participants.
So a player who opts in and launches days after generation has all that idle delay folded into their
time. (Reported by Jean.)

The intended semantics is a **personal stopwatch from launch**. The right anchor is the entry's
`launchedAt` (set in `LaunchWeeklyEntry` when the player launches), which equals the 17.13
`Session.startedAt` and is **stable across relaunch** (pause/resume does not reset it). It is **not**
`entry.createdAt` (that is the opt-in moment, before launch).

## Acceptance Criteria

1. `RecordWeeklyGoal` computes `completionTimeSeconds = max(0, goalReachedAt - entry.launchedAt)`.
2. If `launchedAt` is somehow null (defensive; a goal implies a launch), fall back to
   `run.startedAt` so the value is never computed against null.
3. Idempotency, the Mercure leaderboard event, and the stored `checks/items` totals are unchanged.
4. Already-recorded goals keep their stored value (the formula only affects goals recorded from now on).
5. Gates green: API phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2). `RecordWeeklyGoal`: anchor on `$entry->getLaunchedAt() ?? $run->getStartedAt()`.
- [x] **Task 2** (AC 5). `WeeklyGoalCallbackTest`: set `launchedAt` later than `run.startedAt` and assert
  `completionTimeSeconds` is measured from launch (not the run start).
- [x] **Task 3** (AC 5). Gates.

## Dev Notes

- `run` is still loaded (needed for the fallback + ownership of the entry's run); no extra query.
- `launchedAt` == `Session.startedAt` (both set at the same launch instant, 17.13); using the entry
  field keeps `RecordWeeklyGoal` free of a Session lookup.
- Frontend unchanged — `formatTime(completionTimeSeconds)` already renders the stored value.

### Project Structure Notes

- `api/src/WeeklyRuns/Application/RecordWeeklyGoal.php`
- `api/tests/Functional/WeeklyGoalCallbackTest.php`

### References

- [Source: api/src/WeeklyRuns/Application/RecordWeeklyGoal.php:60]
- [Source: api/src/WeeklyRuns/Application/GenerateWeeklyRunForTemplate.php:67 (run.startedAt = generation)]
- [Source: api/src/WeeklyRuns/Domain/WeeklyEntry.php (launchedAt set by launch())]
- Story 23.4 (goal detection) · 17.13 (Session.startedAt at launch)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-14 | Created + implemented. Completion time now counts from `entry.launchedAt` (fallback `run.startedAt`) instead of the global run start. Test asserts the launch-based value. |
