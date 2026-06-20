# Story 17.14: Reconcile sessions & runs stuck in a transitional ("pending") status

**Status:** review
**Epic:** 17 - Session restart / idle lifecycle
**Date:** 2026-06-17

## Story

As a private-run owner (and as an operator),
I want any session/run that gets stuck in a transitional status (`validating`, `launching`,
`restarting`, `stopping`, `starting`) to be automatically resolved - forced to `running` if it really
started, or forced back to a stable resting state (`idle`/`stopped`/`failed`/`draft`) otherwise -
and to be able to force that resolution myself on demand,
so that a lost webhook or a crashed orchestrateur step never leaves a run frozen "en attente" forever.

## Context

Reported by Jean: there is **no guard-rail when a session is stuck in a pending status**. We need a
calculation that decides "force stop or force start depending on the pending status".

Two state machines can hang:

- **`Session`** (technical orchestration) transitional statuses: `validating`, `generating`,
  `launching`, `restarting`. `CleanupStaleSessionsHandler` (every 2 min) only watches
  `STALE_STATUSES = {generating, launching, running}` and reconciles `generating`/`launching` against
  the orchestrateur (`RunnerGatewayInterface::getSessionInfo`). **`restarting` and `validating` are not
  watched at all** → a relaunch-from-idle whose webhook never arrives stays `restarting` forever.

- **`Run`** (owner-facing, page `/runs/{id}`) transitional statuses: `starting`, `stopping`,
  `restarting`. The `Run` has **no timeout of its own**; it is advanced only by Session webhooks via
  `PersonalRunAdvancerInterface`. If the Session hangs - or worse, when the cleanup fails a stale
  `launching` Session to `failed` it does **not** advance the Run (it only calls
  `markPersonalRunStopped` for a previously-`running` Session) - the Run is left frozen in `starting`.

So the gap is: (1) the Session watchdog ignores `restarting`/`validating`; (2) the Run side desyncs
because terminal cleanup outcomes other than crash-from-running don't advance the Run.

## The reconciliation rule ("le calcul")

For a transitional record older than its threshold, query the orchestrateur's real state and force a
resolution. Decision table (Session):

| Stuck Session status | Orchestrateur says running | Otherwise |
|---|---|---|
| `validating` | n/a (pre-orchestrateur) | force `failed` (Run → `draft`) |
| `generating` | force `generated` (existing) | force `failed` (Run → `draft`) |
| `launching` | force `running` (existing) | force `failed` (Run → `draft`) |
| `restarting` | force `running` via `resumeRunning` (Run → `active`) | force `idle` via `markRestartFailed` (Run → `idle`, resumable) |

Run-side guard-rail (covers `stopping`, and `starting`/`restarting` desync where the Session already
resolved but the Run webhook was lost):

| Stuck Run status | Linked Session resolved running | Otherwise / threshold exceeded |
|---|---|---|
| `starting` | `markRunning` | reset to `draft`/`idle` (force stop) |
| `restarting` | `markRunning` | `markStopped` → `idle` (force stop) |
| `stopping` | n/a | `markStopped` → `idle` (force stop; container assumed gone) |

## Acceptance Criteria

1. `Session::STALE_STATUSES` / `STALE_THRESHOLDS` are extended to include `restarting` (and
   `validating`), and `CleanupStaleSessionsHandler` reconciles a stale `restarting` Session: if the
   orchestrateur reports it running it is forced to `running` (`resumeRunning`), otherwise forced back
   to `idle` (`markRestartFailed`, so the owner can retry). A stale `validating` Session is forced to
   `failed`.
2. Whenever the cleanup resolves a stale Session to a terminal/resting state, the linked `Run` is
   advanced off its transitional status in **every** branch (not only the previously-`running` one), so
   `session`/`run` never desync (e.g. stale `launching`→`failed` must move the Run off `starting`).
3. A `Run` stuck in `starting`/`stopping`/`restarting` past a threshold is reconciled even if the
   Session side is already terminal or absent: it is forced to a stable status per the table above.
   (Decision: drive this from the existing scheduled cleanup pass; no new scheduler entry unless needed.)
4. An owner (and an admin) can **force resolution on demand** from the run page for a run stuck in a
   transitional status, without waiting for the threshold - reusing the same reconciliation logic.
5. The reconciliation logic is pure/decision-isolated and unit-tested per branch (table above); a
   functional test covers a stuck `restarting` reconciled both ways and a stuck `stopping` forced to
   `idle`. Existing crash-from-running recovery (17.12) is preserved.
6. Realtime: each forced transition publishes the Session payload to Mercure (as cleanup already does)
   and the run page reflects the new status without a manual reload.
7. Gates green - API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`; Frontend (if the force
   button lands): typecheck / lint / build.

## Tasks / Subtasks

- [x] **Task 1** (AC 1): extended `Session::STALE_STATUSES`/`STALE_THRESHOLDS` (added `validating` 180s,
  `restarting` 300s); added `SessionLifecycleManager::reconcilePending()` (the decision table) and
  slimmed `CleanupStaleSessionsHandler` to delegate to it - which also fixes the Run desync, since the
  forced transitions now route through SLM (which advances the linked run + publishes Mercure).
- [x] **Task 2** (AC 2): the run is advanced in every terminal branch via the existing SLM transitions
  (`recordCrash` resets `starting`→`draft`; `markRestartFailed`/crash-recovery → `idle`).
- [x] **Task 3** (AC 3): `Run::STUCK_STATUSES`/`STUCK_THRESHOLDS` + `Run::isStuck()`;
  `ReconcileStuckRunsHandler` (+ `RunRepository::findByStatuses`) scheduled every 2 min, reconciles a
  stuck run against its linked session state (covers a lost `session.stopped` webhook).
- [x] **Task 4** (AC 4): `SessionLifecycleManager::forceReconcile()` (owner/admin auth + forceable-only
  guard) behind `POST /api/v1/sessions/{id}/reconcile`; "Bloqué ? Forcer la résolution" button on the
  run page for `starting`/`restarting`.
- [x] **Task 5** (AC 5,6): unit tests (handler delegation, run backstop per branch) + functional tests
  (reconcilePending both ways + endpoint auth/not_pending). Run advancement & Mercure publish covered by
  routing through SLM.

## Dev Notes

- Thresholds (decided with Jean - "aggressive"): genuinely fast transitions get short thresholds -
  `validating` 180s, Session `restarting` 300s, Run `stopping` 300s, Run `restarting` 300s. The
  existing `generating` 1200s / `launching` 600s / `running` 300s are **kept** because `starting`
  legitimately spans a full generation (~20 min) - forcing those short would kill valid generations.
  Run `starting` is therefore Session-driven (resolved when its linked Session resolves) rather than on
  a short raw timer.
- Keep `restarting`→`running` going through `resumeRunning` (preserves `startedAt`) and
  `restarting`→`idle` through `markRestartFailed` (sets `restartFailed=true` so the UI can warn).
- `isStale()` currently keys off `lastActivityAt` (and `lastHeartbeatAt` for running) - reuse it; the
  `Run` has only `updatedAt`, which `start()/stop()/markRestarting()` all bump, so it is a valid clock.
- No `new \DateTimeImmutable()` in domain - the handler already passes `$now`; keep ClockInterface/`$now`
  injection discipline in any new application service.
