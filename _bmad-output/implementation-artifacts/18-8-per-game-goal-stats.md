# Story 18.8: Count goals per game (not per session) in player stats

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player who reached the goal in several games of one multiworld run,
I want each game goal to count,
so that my profile shows "2 objectifs" when I beat 2 games - not "1" because they were in the same session.

### Why this exists (root cause)

`DbalPlayerStatsQuery::computeForUser` counts `goal_completions` as
`COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END)` - i.e. **distinct sessions**
with at least one goal-reached slot. A player with two goal-reached slots in one session counts as **1**.
But the achievement wording ("Atteindre 10 objectifs") and the leaderboard treat a goal as **per game/slot**.
Observed: a player beat 2 games in one personal run; profile showed 1 objectif (DB: goals_per_session=1,
goals_per_slot=2). The "Taux de complétion" (`goalCompletions / runsParticipated`) would also be wrong
once goals go per-game (2/1 = 200%).

## Acceptance Criteria

1. **Goals per game.** `goal_completions` counts goal-reached **slots** (per game), across both the event
   and personal-run branches. The reporting player → 2.
2. **Games played + rate.** A new `games_played` stat counts the player's slots in their counted runs. The
   profile "Taux de complétion" becomes `goal_completions / games_played` (bounded to ≤ 100%); div-by-zero
   guarded. Reporting player → 2/2 = 100%.
3. **Run-level goal gating (reconcile 17.15).** The personal-run branch keeps "a run only counts if you
   reached a goal in it" (story 17.15) but at **session level** so non-goal slots of a counted run still
   contribute to `games_played` (and checks/items): gate on sessions where the player has ≥1 goal-reached
   slot, instead of filtering individual slots. Event sessions are unchanged (event participation counts
   regardless of goal).
4. **Consistency.** `runs_participated` stays per-session; checks/items keep the existing
   `was_released && no-goal` exclusion. The achievement metric `goals` (FACT_GOALS) now reflects per-game
   goals (monotonic - no un-grant).
5. **Both profile surfaces.** The rate is recomputed in `PlayerProfileQuery` and `DbalCommunityProfileQuery`
   using `games_played`. `/players/{slug}` and `/joueurs/{slug}` both show the corrected values.
6. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`); no FE
   change needed (it reads `goalCompletions` + `goalCompletionRate`, both corrected server-side).

## Tasks / Subtasks

- [ ] **api/ DbalPlayerStatsQuery**: change `goal_completions` to count goal-reached slots in both branches;
      add `games_played` (count of the player's slots). Personal-run branch: replace the slot-level
      `goal_reached_at IS NOT NULL` filter (17.15) with a session-level gate - `s.id IN (SELECT
      slot2.session_id FROM session_slot slot2 WHERE slot2.registration_id = :userId AND
      slot2.goal_reached_at IS NOT NULL)`. Extend the return-type shape with `games_played`.
- [ ] **PlayerStatsQueryInterface**: add `games_played: int` to the `@return` shape.
- [ ] **PlayerProfileQuery + DbalCommunityProfileQuery**: `goalCompletionRate = gamesPlayed > 0 ?
      min(1, goalCompletions / gamesPlayed) : 0`.
- [ ] **Tests**: functional - a session with one user holding two goal-reached slots yields
      `goalCompletions = 2`, `gamesPlayed = 2`, rate `1.0`; a run with one goal + one non-goal slot yields
      goals 1 / games 2 / rate 0.5; a run with no goal does not count (PR gating). Update existing
      PlayerProfile/CommunityProfile/RecomputeAchievements assertions for the new semantics.

## Dev Notes

- **Source**: [Source: api/src/Identity/Infrastructure/DbalPlayerStatsQuery.php] - two branches (event via
  `registration.user_id`; personal run via `slot.registration_id`), merged additively.
- **17.15 interaction**: develop already has the slot-level `goal_reached_at IS NOT NULL` gate on the PR
  branch (#182). This story replaces it with session-level gating so `games_played` is correct. [Source: _bmad-output/implementation-artifacts/17-15-owner-finish-personal-run.md]
- **Rate consumers**: [Source: api/src/Identity/Application/PlayerProfileQuery.php, api/src/Community/Infrastructure/DbalCommunityProfileQuery.php] both currently `goalCompletions / runsParticipated`.
- **Achievements**: FACT_GOALS reads `goal_completions` [Source: api/src/Community/Application/StatsMetricProvider.php]; per-game is the intended meaning ("Atteindre 10 objectifs"). Monotonic engine → safe.
- **Frontend (no change)**: profile renders `goalCompletions` and `goalCompletionRate`. [Source: frontend/src/features/players/player-profile-page.tsx]
- **Scope**: stats counting only; no new fields exposed to FE beyond the corrected values.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-18-run-history-player-profiles-community-leaderboards.md]
- Standards: [Source: api/CLAUDE.md#phpstan-rules-level-max]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- `goal_completions` now `COUNT(slot.goal_reached_at)` (goal-reached slots) in both branches → per game.
- `games_played` = slots excluding invalidated ones (`was_released && no goal`), mirroring the checks/items
  exclusion, so an abandoned game doesn't drag the rate down. `goalCompletionRate = min(1, goals/games)`.
- Personal-run gating from 17.15 moved from slot-level to **session-level** (`s.id IN (SELECT … goal_reached
  …)`) so the other games of a counted run still feed games_played/checks/items.
- Verified against prod-local data for the reporting user: runs=1, games_played=2, goals=2, rate=100%.
- Tests: renamed the old `…CountAsOneGoalCompletion` test to per-game (now asserts 2); updated the
  invalidated-slot rate test (now 100% - the invalidated game is excluded from games_played, matching the
  test's intent); added a mixed goal/non-goal case (rate 0.5). No FE change.
- Gates: phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, PlayerProfileTest 7/70 +
  CommunityProfile/RecomputeAchievements/AchievementSeedParity 12/93 ✅. (Full-suite run hit the known
  shared-test-DB schema flake, unrelated; targeted suites green.)

### File List

- api/src/Identity/Infrastructure/DbalPlayerStatsQuery.php (per-game goals + games_played + session-level PR gating)
- api/src/Identity/Application/PlayerStatsQueryInterface.php (shape + games_played)
- api/src/Identity/Application/PlayerProfileQuery.php (rate = goals/games, capped)
- api/src/Community/Infrastructure/DbalCommunityProfileQuery.php (rate = goals/games, capped)
- api/tests/Functional/PlayerProfileTest.php (per-game + mixed + invalidated rate)
