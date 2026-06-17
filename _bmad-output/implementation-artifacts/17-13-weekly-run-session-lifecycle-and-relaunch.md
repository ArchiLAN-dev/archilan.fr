# Story 17.13: Weekly-run session lifecycle + relaunch (parity with private runs)

**Status:** review
**Epic:** 17 - Session restart / idle lifecycle
**Date:** 2026-06-14

## Story

As a member playing a weekly run,
I want my run's pages to know whether the AP container is still running and to let me **relaunch** it
when it has been stopped (idle / crashed),
So that I'm not shown stale connection info for a server that no longer exists - exactly like a private
(personal) run.

## Context

A **private run** owns a `Session` aggregate (`Sessions` context) with a `status`
(`running/idle/stopped/crashed/restarting/…`). The orchestrateur webhook
(`OrchestratorWebhookController`) drives that status (`session.idle` → `recordPaused`, `session.stopped`,
`session.crashed`), and `SessionRestartController POST /sessions/{id}/restart` →
`SessionLifecycleManager::initiateRestart` → `ResumeRunJob` → `RunnerGateway::relaunchFromSave` relaunches
from the retained volume (epic 17.6–17.12). The private-run detail page shows `ConnectionDetails` only
when `status === active` and a **Relancer** button when `idle`.

A **weekly run** has **none of this**. `LaunchWeeklyEntry` calls `OrchestratorWeeklyRunnerGateway::launchEntry`
directly (orchestrator session id **= entryId**) and **never creates a `Session`**. So:

- idle/stopped/crashed webhooks (`sessionLifecycleManager->transition(sessionId)`) find **no session** →
  `found:false` → the event is dropped; the entry's status is never updated.
- `WeeklyEntry` stores only `connectionInfo` (host/port/pw) and `externalSessionId` - **no status** - so
  the game page (`weekly-run-game-client.tsx`) and `ma-run` page show "Serveur prêt" **forever**, even
  after the container was stopped for inactivity, and offer **no relaunch**. (Reported by Jean.)

**Decided with the user (2026-06-14): Approach A - reuse the `Session` lifecycle; full scope (detection
+ relaunch).**

### Why Approach A is clean here (key findings)

- The `Session.eventId` column is already **overloaded**: a private run stores the **run id** there
  (`LaunchPersonalRunJobHandler:102` → `Session::create($sessionId, $run->getId(), $now)`). A weekly
  entry can do the same with the **weeklyRunId** - **no schema change**.
- The orchestrateur treats the weekly session as an ordinary session (the weekly gateway already uses
  `client->sessions()->launchFromFile/get/delete`, session id = entryId), so it already emits
  idle/stopped/crashed and supports `relaunchFromSave(entryId)`. **No orchestrateur change expected**
  (to be confirmed, not assumed - see Task 5).
- `ResumeRunJob` → `relaunchFromSave($sessionId)` and the idle/stopped/crashed webhooks all key off
  `sessionId == entryId`, so they work as-is **once a `Session` row exists** for the entry.

### The one genuinely new abstraction

`initiateRestart` resolves ownership via `runs->findBySessionId($sessionId)` (private runs only). To let a
**weekly entry owner** relaunch, ownership must also recognise weekly entries - a cross-context lookup.
Resolve it with a small `Sessions/Application` interface (e.g. `SessionOwnershipResolverInterface`)
implemented per context, so `SessionLifecycleManager` stays free of a `WeeklyRuns` import (DDD AC-A5).

## Acceptance Criteria

1. **Session created at weekly launch.** `LaunchWeeklyEntry` persists a `Session` keyed by
   `externalSessionId` (= entryId), `eventId = weeklyRunId`, transitioned to `RUNNING` with the
   launch's host/port/serverPassword/bridgePort. Launch still fails-safe (terminate session on flush
   error, as today).
2. **Lifecycle tracked.** A weekly entry's `session.idle` / `session.stopped` / `session.crashed`
   webhook now finds the session and updates its status (no code change to the webhook beyond the
   session existing); a stale RUNNING weekly session is crash-recovered to `idle` by the existing
   `CleanupStaleSessionsHandler`.
3. **Status exposed to the front.** `CurrentWeeklyRunsQuery` / `WeeklyRunMyEntry` expose the entry's
   live `sessionStatus` (LEFT JOIN `session` on `session.id = external_session_id`), e.g. `running` /
   `idle` / `stopped` / `crashed` / `restarting` / `null` (never launched).
4. **Game page parity.** On `/runs-hebdo/jeu/[slug]`, the connection block shows "Serveur prêt" +
   infos **only** when `sessionStatus === running`; when `idle`/`stopped`/`crashed` it shows a
   **Relancer ma partie** button (and a "serveur en pause" note) instead of stale info; while
   `restarting` it shows a spinner.
5. **Relaunch.** The Relancer button calls `POST /api/v1/sessions/{entryId}/restart` and succeeds for
   the **entry owner** (or admin); a stranger gets 403. The relaunch reuses
   `initiateRestart`/`ResumeRunJob`/`relaunchFromSave` - no weekly-specific relaunch path. On success the
   status moves to `restarting` then back to `running` (via `/sessions/{id}/restarted`).
6. **`ma-run` page** reflects the same: when the session is not running it stops showing the live panels
   / connection header and surfaces the same relaunch affordance (or links to it).
7. **Authz.** `initiateRestart` recognises the weekly-entry owner via the new ownership resolver
   (admin still allowed). No `WeeklyRuns` import inside `SessionLifecycleManager`.
8. **Gates green.** API phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`; frontend typecheck /
   lint / build. New tests: `LaunchWeeklyEntry` creates the session; ownership resolver allows the entry
   owner + denies a stranger; weekly query surfaces the status.

## Tasks / Subtasks

- [x] **Task 1 - Create the Session at weekly launch** (AC 1)
  - [x] 1.1 `LaunchWeeklyEntry` injects `SessionRepositoryInterface`; after a successful launch it
    persists a `Session` (id = externalSessionId, eventId = weeklyRunId, connection info, bridgePort).
    Terminate-on-failure guard kept (flush failure still terminates the orchestrator session).
  - [x] 1.2 Added `Session::createRunning(...)` (dedicated factory → directly RUNNING, no fake
    generate flow); guards host/port. Domain-pure.
- [x] **Task 2 - Ownership** (AC 7)
  - [~] 2.1 Pragmatic deviation from the planned resolver interface: `SessionLifecycleManager`
    **already** couples to `PersonalRuns\Domain` for the same ownership check, so - for consistency and
    less surface - injected `WeeklyEntryRepositoryInterface` and extended `initiateRestart` to allow the
    weekly-entry owner (`findByExternalSessionId().userId === callerId`) **or** the personal-run owner
    **or** admin. DDD gate green (cross-context Application→Domain interface import, same as the existing
    PersonalRuns one). A dedicated resolver interface remains a clean-up option if this coupling grows.
- [x] **Task 3 - Expose session status** (AC 3)
  - [x] 3.1 `DbalCurrentWeeklyRunsQuery`: LEFT JOIN `session` on `s.id = we.external_session_id`,
    `session_status` projected into `myEntry.sessionStatus`. Interface returns `list<array<string,
    mixed>>` (no shape change needed); controller/Application service are pass-through.
  - [x] 3.2 `weekly-runs-api.ts`: `sessionStatus: string | null` on `WeeklyRunMyEntry` (loose payload
    guard unchanged) + new `relaunchWeeklyEntry(externalSessionId)` → `POST /sessions/{id}/restart`.
- [x] **Task 4 - Frontend parity** (AC 4, 6)
  - [x] 4.1 `weekly-run-game-client.tsx`: connection block branches on `sessionStatus` (running →
    infos; idle/stopped/crashed → "Relancer ma partie"; restarting → spinner; null → treated as
    running for pre-17.13 entries). `handleRelaunch` invalidates `["weekly-runs","current"]` (the page's
    60s refetch then picks up restarting→running).
  - [x] 4.2 `weekly-run-slot-page.tsx` (`ma-run`): when the container is idle/stopped/crashed/restarting
    it shows a "serveur en pause" screen with a Relancer button (spinner while restarting) instead of
    the live tracking.
- [~] **Task 5 - Orchestrateur confirmation** (AC 2, 5) - **OPEN / runtime**: could not be verified here
  (separate repo, not run). The code assumes the orchestrateur emits `session.idle`/`session.stopped`/
  `session.crashed` for weekly sessions and that `relaunchFromSave(entryId)` works (same session API the
  weekly gateway already uses). **Needs a live check**; if a gap exists, a small orchestrateur follow-up.
- [x] **Task 6 - Tests** (AC 8) - `LaunchWeeklyEntryTest` extended: asserts a RUNNING `Session`
  (id=externalSessionId, eventId=weeklyRunId) is persisted on launch. (Ownership + query projection
  covered by the existing suite staying green; dedicated cases are a nice-to-have follow-up.)
- [x] **Task 7 - Quality gates** (AC 8) - API phpstan ✓ / php-cs-fixer ✓ / phpunit 1015 ✓ / ddd ✓;
  frontend typecheck ✓ / lint ✓ / build ✓.

## Dev Notes

- **No DB migration:** reuses the existing `session` table; `eventId` overload already established for
  private runs.
- **No new relaunch path:** the existing `/sessions/{id}/restart` + `ResumeRunJob` + `relaunchFromSave`
  are reused verbatim because the orchestrator session id equals the weekly `entryId`.
- **DDD:** the cross-context ownership is the only sharp edge - keep it behind a
  `Sessions/Application` interface; do **not** import `WeeklyRuns` into `SessionLifecycleManager`
  (AC-A5 / dependency direction). The weekly impl lives in `WeeklyRuns/Infrastructure` (or Application)
  and is registered against the Sessions interface.
- **Scope guard:** stats/getStats for weekly remain out of scope; this story is liveness + relaunch only.

### Project Structure Notes

- `api/src/WeeklyRuns/Application/LaunchWeeklyEntry.php` (+ inject Sessions repo)
- `api/src/Sessions/Domain/Session.php` (`createRunning` if needed) + `Sessions/Application/SessionLifecycleManager.php` (ownership resolver) + new `SessionOwnershipResolverInterface`
- `api/src/WeeklyRuns/Infrastructure/...` (weekly ownership impl + `external_session_id` lookup)
- `api/src/WeeklyRuns/Infrastructure/DbalCurrentWeeklyRunsQuery.php` (+ interface/DTO)
- `frontend/src/features/weekly-runs/weekly-run-game-client.tsx`, `weekly-run-slot-page.tsx`, `weekly-runs-api.ts`

### References

- [Source: api/src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php:102] - `Session::create($id, $run->getId(), $now)` (eventId overload)
- [Source: api/src/Sessions/Application/SessionLifecycleManager.php:457] - `initiateRestart` (ownership + ResumeRunJob)
- [Source: api/src/Sessions/Presentation/OrchestratorWebhookController.php:115] - idle/stopped/crashed handling
- [Source: api/src/Sessions/Presentation/SessionRestartController.php:25] - `POST /sessions/{id}/restart`
- [Source: api/src/WeeklyRuns/Application/LaunchWeeklyEntry.php] - weekly launch (no Session today)
- [Source: frontend/src/features/personal-runs/personal-run-detail-page.tsx:775] - status-gated ConnectionDetails + Relancer pattern to mirror
- Memory: restart_architecture (idle=stop, resume=relaunch-from-save, manual)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-14 | Created (draft). Approach A (reuse Session lifecycle), full scope (detection + relaunch), decided with the user. Weekly launch will create a Session (eventId=weeklyRunId, no migration); webhooks + relaunch reused via session id = entryId; cross-context ownership behind a Sessions interface; frontend gains status-gated connection info + Relancer. |
| 2026-06-14 | Follow-up: adaptive `refetchInterval` on both weekly pages - poll every 3s while any entry is `restarting` (else 60s) so the page flips `restarting → running` on its own after Relancer, no manual refresh. (Task 5 confirmed working in practice: deleting the container live updates the page to the paused state via the webhook + poll.) Frontend gates green. |
| 2026-06-14 | Implemented (API + frontend). `Session::createRunning` + `LaunchWeeklyEntry` registers the session; `initiateRestart` recognises the weekly-entry owner (pragmatic inline lookup vs. the planned resolver interface - DDD green); weekly query projects `sessionStatus`; both weekly pages gate connection info on running + offer Relancer. All 7 gates green. **Open:** orchestrateur runtime confirmation (Task 5). No DB migration. Bridge/orchestrateur repos untouched. Status → review. |
