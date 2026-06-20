# Story 17.15: Owner can finish a personal run (archive + count in stats)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the owner of a personal/private run,
I want an explicit "Terminer" action that finalizes my run,
so that its real goal/check state is captured and the run is counted in my stats — without waiting for
the automatic all-goal callback or an admin force-end.

### Why this exists (root cause)

A personal run's slot progress (`checks_done`, `items_received`, `goal_reached_at`) is persisted to
`session_slot` **only at archive time** (`SessionLifecycleManager::storeArchive`, fed by the bridge
`GET /state` snapshot in `ArchiveRunJobHandler`). Archive only runs when the **session** reaches
`finished`, which today happens only via the all-goal callback (all slots done) or an admin force-end.
The run owner has **no finish action** — only "Arrêter" (`/stop`), which sends the run to `idle` and never
finishes the session. So a player who reaches their goal but whose all-goal never fired can never get
their run archived, and `DbalPlayerStatsQuery` (which filters `s.status = 'finished'`) never counts it.
Observed live: a completed run sat at session `running`, slots `0 checks / goal null`, absent from stats —
while the bridge still held the real goal/check data.

## Acceptance Criteria

1. **Owner finish action.** A run owner can finish their run via `POST /api/v1/runs/{runId}/finish`
   (owner-gated). Allowed only when the run is `active` (session `running`, bridge alive — so archive can
   snapshot the real state). Other statuses return a blocked result (e.g. `run_not_active`).
2. **Domain transition.** `Run` gains `complete(\DateTimeImmutable $now)`: `active → completed` (a guarded
   business method; throws/zero-ops otherwise). No public setter. `STATUS_COMPLETED` becomes reachable
   (it is currently dead).
3. **Session finalized + archived.** Finishing transitions the session to `finished`, stops the runner,
   and dispatches the archive job — reusing the existing force-end mechanism
   (`ForceEndSessionCommand` / `ArchiveRunJob`) rather than duplicating it. The archive snapshots the
   bridge's real `goal_reached_at` / `checks_done` / `items_received` into `session_slot`.
4. **Stats gating.** `DbalPlayerStatsQuery` counts a **personal run** toward the player's stats **only when
   the player's slot reached its goal** (`slot.goal_reached_at IS NOT NULL`), per product decision. Scope
   this change to the personal-runs branch; leave the event-session branch unchanged.
5. **Frontend.** The run detail page shows a "Terminer" action (distinct from "Arrêter") for the owner when
   the run is `active`, with a confirmation dialog clarifying it ends the run for good and that only a
   goal-reached run counts in stats. On success the run shows as `completed` and links to its results.
6. **`/slot-goal` note.** Document (or fix) that the `/slot-goal` callback currently only records weekly
   goals and ignores personal runs — acceptable because personal-run goals are captured at archive, but
   call it out so it isn't mistaken for live personal-run sync. (No behavior change required for this
   story unless trivial.)
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Domain**: add `Run::complete(\DateTimeImmutable $now)` (`active → completed`, guarded), with a unit
      test for the happy path + the no-op/guard on a non-active run.
- [ ] **Application**: `PersonalRunLifecycle::finish(runId, callerId)` — owner check, status check
      (`active`), `run->complete()`, flush, then finalize the session. Reuse `ForceEndSessionCommand`
      (PersonalRuns already depends on Sessions infra in `StopPersonalRunJobHandler`) to transition the
      session `finished` + stop + dispatch `ArchiveRunJob`. Mirror the existing `result(...)` shape.
- [ ] **Presentation**: `POST /api/v1/runs/{runId}/finish` in `PersonalRunController` → `requireUser` →
      `lifecycle->finish(...)` → map not_found/forbidden/blocked to 404/403/409.
- [ ] **Stats**: `DbalPlayerStatsQuery` personal-runs branch — add `AND slot.goal_reached_at IS NOT NULL`
      gating so only goal-reached personal runs contribute (runs_participated/checks/items).
- [ ] **Frontend**: add a "Terminer" button + confirm dialog in `personal-run-detail-page.tsx` (mirror the
      `StopDialog` pattern); `personal-runs` api gains `finishRun(runId)` → `POST /runs/{runId}/finish`.
      Show only when `run.status === "active"` and the caller is the owner.
- [ ] **Tests**: backend functional (owner finishes an active run → session finished + run completed;
      non-owner 403; non-active 409; stats now count it via `NullMinioStorage`/archive path or a seeded
      goal-reached finished run); domain unit test; frontend jest (`finishRun` posts to the right route).

## Dev Notes

- **Archive snapshots the bridge**: `ArchiveRunJobHandler::fetchBridgeState()` GETs `http://localhost:
  {bridgePort}/state` and `storeArchive` writes each slot by `slot_name`. So finishing while the bridge is
  alive captures the *real* goal — never fabricate a goal on finish (product decision: "Terminer" does not
  stamp the goal). [Source: api/src/Sessions/Application/Handler/ArchiveRunJobHandler.php, api/src/Sessions/Application/SessionLifecycleManager.php]
- **Reuse force-end, don't duplicate**: `ForceEndSessionCommand` already does transition→stop→archive→
  audit and requires the session `running`; that matches the `active`-only guard. [Source: api/src/Sessions/Application/ForceEndSessionCommand.php]
- **Cross-context**: `StopPersonalRunJobHandler` shows PersonalRuns already uses `App\Sessions` repos +
  `RunnerGatewayInterface`; calling `ForceEndSessionCommand` from PersonalRuns Application/handler is
  consistent with the existing dependency direction. [Source: api/src/PersonalRuns/Application/Handler/StopPersonalRunJobHandler.php]
- **Stats source**: [Source: api/src/Identity/Infrastructure/DbalPlayerStatsQuery.php] — personal-runs
  branch joins `session_slot → session → run`, filters `s.status='finished'`, matches `slot.registration_id
  = userId`.
- **Run lifecycle**: [Source: api/src/PersonalRuns/Domain/Run.php] — statuses, no `complete()` today;
  `STATUS_COMPLETED` referenced in `PersonalRunDrafts:201` but never set.
- **Frontend run page**: [Source: frontend/src/features/personal-runs/personal-run-detail-page.tsx] — has
  the Stop dialog + status-driven actions to mirror.
- **Scope**: this story does not attempt live per-slot personal-run sync (AC-6) nor change event-session
  stats. One-off data already rescued for the reporting user's run.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-17-session-lifecycle-inactivity-timeout-wake-on-connect.md]
- Stats epic: [Source: _bmad-output/planning-artifacts/epics/epic-18-run-history-player-profiles-community-leaderboards.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- `PersonalRunLifecycle::finish` reuses `ForceEndSessionCommand` (which transitions the session to
  `finished`, stops the runner, dispatches `ArchiveRunJob`) rather than duplicating that flow — the archive
  is what snapshots the bridge's real goal/check state into `session_slot`. Session is finalized first so a
  non-running session blocks the finish (409) before the run flips to completed.
- `Run::complete()` is `active → completed` only (guarded), clearing connection fields; `STATUS_COMPLETED`
  was previously dead.
- Stats: personal-runs branch of `DbalPlayerStatsQuery` now also requires `slot.goal_reached_at IS NOT
  NULL` — only goal-reached personal runs count (product decision). Event-session branch untouched.
- Frontend: "Terminer la partie" button (accent) next to "Arrêter" in the active state, with a confirm
  dialog spelling out that it's terminal and that only a goal-reached run counts in stats. Implemented
  inline (apiFetch) mirroring the existing stop/start handlers, which have no separate api module or jest
  test — so no contrived jest test was added; typecheck/lint/build cover it.
- One-off rescue already applied to the reporting user's run (session `running`→`finished`, real bridge
  goal/checks persisted, run `completed`) — verified the run now appears in their stats (1 run, 1 goal,
  212 checks, 314 items).
- AC-6: documented that `/slot-goal` only records weekly goals; no behavior change (personal-run goals are
  captured at archive). Left as a noted follow-up.
- Gates: phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, phpunit (RunComplete unit +
  PersonalRunLifecycle finish functional + stats/archival suites, 129 green) ✅; typecheck ✅, lint ✅,
  jest 20/121 ✅, build ✅.

### File List

- api/src/PersonalRuns/Domain/Run.php (complete())
- api/src/PersonalRuns/Application/PersonalRunLifecycle.php (finish() + ForceEndSessionCommand dep)
- api/src/PersonalRuns/Presentation/PersonalRunController.php (POST /runs/{runId}/finish)
- api/src/Identity/Infrastructure/DbalPlayerStatsQuery.php (goal-reached gating, personal-runs branch)
- api/tests/Unit/PersonalRuns/RunCompleteTest.php (new)
- api/tests/Functional/PersonalRunLifecycleTest.php (finish tests + helpers)
- frontend/src/features/personal-runs/personal-run-detail-page.tsx (Terminer button + FinishDialog + handler)
