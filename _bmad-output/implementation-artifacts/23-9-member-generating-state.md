# Story 23.9: Member "Génération en cours" State for Weekly Runs

## Story

**As a** member who has opted into a weekly run,
**I want** to see a clear "Génération en cours…" state with the launch action disabled until the run's world is ready,
**So that** I don't hit a `run_not_generated` error by clicking "Lancer ma partie" before the Monday generation has finished.

## Status

done

## Context

Story **23.8** made weekly-run generation asynchronous: the `WeeklyRun` is created
not-launchable (`generated_output_key = null`) and only becomes launchable once the
orchestrator's `session.generated` webhook lands (`MarkWeeklyRunGenerated`). Until then,
`LaunchWeeklyEntry` throws `run_not_generated` (HTTP 422). Task 11 of 23.8 was deferred:
the member UI still shows an enabled "Lancer ma partie" button even while generation is
pending, so a member can click it and get an error.

This slice surfaces the generation status to members and gates the launch action.

## Acceptance Criteria

**AC1:** `GET /api/v1/weekly-runs/current` exposes a boolean `isGenerated` per run
(`generated_output_key IS NOT NULL AND <> ''`). Additive; existing fields unchanged.

**AC2:** On the member per-game page (`CategorySection` in `weekly-run-game-client.tsx`),
when the member has an entry but no `connectionInfo`:
- if `run.isGenerated` → existing "Lancer ma partie" button (enabled),
- else → a disabled "Génération en cours…" state (spinner + short hint that the world is
  being built and the button will unlock automatically).

**AC3:** The page already polls (`refetchInterval: 60_000` on the `["weekly-runs","current"]`
query) so the state flips to launchable automatically once generation completes - no manual
refresh required. No new realtime channel is introduced in this slice.

**AC4:** All quality gates pass - API (`phpstan`, `php-cs-fixer`, `phpunit`,
`app:architecture:ddd`) and frontend (`pnpm typecheck`, `pnpm lint`, `pnpm build`).

## Tasks / Subtasks

- [x] Task 1: API - add `generated_output_key` to `DbalCurrentWeeklyRunsQuery` select and an
  `isGenerated` boolean to each run DTO.
- [x] Task 2: API - assert `isGenerated` in the member current-runs functional test
  (`CurrentWeeklyRunsTest`): false before generation, true after `markGenerated`.
- [x] Task 3: Frontend - add `isGenerated: boolean` to `CurrentWeeklyRun`
  (`weekly-runs-api.ts`); the loose payload guard is unchanged.
- [x] Task 4: Frontend - render the "Génération en cours…" disabled state in `CategorySection`
  when `!run.isGenerated`; keep the enabled launch button when generated. Mirror the same gate
  in `weekly-run-card.tsx` for consistency.
- [x] Task 5: Quality gates (API + frontend).

## Dev Notes

- The launch gate is per **run** (one shared world for all players), so `isGenerated` lives on
  the run, not the entry.
- `WeeklyRunCard` is currently unused (the live UI is `CategorySection`), updated only to keep
  the two near-duplicate launch branches consistent.

## File List

### API
- `api/src/WeeklyRuns/Infrastructure/DbalCurrentWeeklyRunsQuery.php` - `generated_output_key` + `isGenerated`
- `api/tests/Functional/CurrentWeeklyRunsTest.php` - `isGenerated` assertions

### Frontend
- `frontend/src/features/weekly-runs/weekly-runs-api.ts` - `isGenerated` on `CurrentWeeklyRun`
- `frontend/src/features/weekly-runs/weekly-run-game-client.tsx` - generating state in `CategorySection`
- `frontend/src/features/weekly-runs/weekly-run-card.tsx` - same gate (consistency)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-08 | Story created and implemented - completes the deferred Task 11 of 23.8 (member "génération en cours" state, launch disabled until the run is generated). |
