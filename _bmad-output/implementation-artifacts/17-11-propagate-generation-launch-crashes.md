# Story 17.11: Session lifecycle — propagate generation/launch crashes (don't hang on "generating"/"starting")

**Status:** review
**Epic:** 17 - Session restart / idle lifecycle
**Date:** 2026-06-11

## Story

As a private-run owner (and as an operator),
I want a session that **crashes during generation or launch** to end in a clear failed
state — with the run no longer stuck on "starting" —
so that the UI shows the failure and I can retry, instead of the run hanging forever.

## Context

Diagnosing a stuck run (`8771220b…`, session `144f43ef…`) surfaced a real lifecycle gap,
independent of the BOM fix (PR #122):

- The orchestrateur, on a generation failure, sends the `session.crashed` webhook.
- `OrchestratorWebhookController` handles `session.crashed` by calling
  `SessionLifecycleManager::transition($sessionId, 'crashed')`.
- But `Session::ALLOWED_TRANSITIONS` only allows `crashed` **from `running`**
  (`GENERATING => [generated, failed]`, `LAUNCHING => [running, failed]`). So from
  `generating`, `Session::transition('crashed', …)` throws `LogicException`.
- `SessionLifecycleManager::transition` **catches the LogicException** and returns
  `['found' => true, 'errors' => [...]]` — and the webhook **ignores `errors`** and returns
  `200`. Net result: **the session stays `generating`** and the personal run stays
  `starting` **forever** ("génération faite mais le serveur se lance pas").
- There is also **no run-side handling**: `PersonalRunAdvancerInterface` has
  `autoAdvancePersonalRun` and `markPersonalRunStopped`, but **no crash/fail path**, so the
  `Run` is never moved off `starting` on a session crash.

(Live evidence: session `144f43ef…` = `generating`, run = `starting`; orchestrateur logged
`runGeneration: GenerateMultiworld failed` then sent `session.crashed`, which the API
silently no-op'd.)

PR #122 fixes the *common cause* of those crashes (BOM in default templates) and resets the
run on **configure** failure, but a **generation/launch** crash (bad apworld, generator
error, OOM, …) still hangs. This story makes crashes terminal and visible.

### Decisions (to confirm with Jean)

- A crash during **generating** or **launching** ends the session in **`failed`**; a crash
  while **running** ends in **`crashed`** (current semantics). The webhook picks the target
  from the current status instead of always sending `crashed`.
- On a session crash/fail, the personal **run is reset** (`Run::resetAfterValidationFailure`
  → `draft`) so the owner can fix and retry; the crash reason is surfaced (reuse the session
  `validationErrors`/`lastLogs` channel and the existing `ValidationErrorBanner`, or a new
  "génération échouée" banner).

## Acceptance Criteria

1. When the orchestrateur reports `session.crashed` for a session in `generating` or
   `launching`, the session reaches a **terminal failed state** (`failed`) — never left in
   `generating`/`launching`. A crash while `running` still yields `crashed`.
2. The webhook **does not silently succeed** on a no-op/invalid transition: an unreached
   terminal state is logged at error level (and, where possible, forced to `failed`).
3. The associated **personal run leaves `starting`** on a session crash (e.g. reset to
   `draft`), so the run page no longer hangs; a non-owner sees a coherent state too.
4. The crash reason is **surfaced in the UI** (run page) rather than a silent hang —
   reusing the validation-error banner or an equivalent "generation failed" message.
5. No regression on the happy path (generated → launching → running) nor on the existing
   `session.stopped` / `session.idle` / `session.ready` handlers.
6. Quality gates green — API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`;
   frontend: typecheck / lint / build. Verified live: trigger a generation crash → session
   `failed`, run off `starting`, error shown.

## Tasks / Subtasks

- [ ] **Task 1 — Crash → valid terminal state** (AC: 1,2). In `OrchestratorWebhookController`
  `session.crashed` (and/or `SessionLifecycleManager`), choose the target from the current
  status: `generating`/`launching` → `failed`; `running` → `crashed`. Either add a dedicated
  method (e.g. `SessionLifecycleManager::recordCrash(sessionId)`) that resolves the target, or
  extend `ALLOWED_TRANSITIONS` so `generating`/`launching` → `crashed` is legal — decide and
  document. Ensure the webhook acts on (logs) a failed/no-op transition rather than returning
  `200` blindly.
- [ ] **Task 2 — Run failure propagation** (AC: 3). Add a crash/fail path to
  `PersonalRunAdvancerInterface` (e.g. `markPersonalRunFailed(sessionId)`) implemented in
  `SessionOrchestrator`, resetting the `Run` (`resetAfterValidationFailure` → `draft`) when
  its session crashes/fails. Call it from the `session.crashed` webhook (and the
  generation-failed path). Mirror the existing `markPersonalRunStopped` wiring.
- [ ] **Task 3 — Surface the reason** (AC: 4). Ensure the crash reason reaches the run read
  model (reuse session `validationErrors`/`lastLogs`), and the run page shows it
  (`ValidationErrorBanner` or a new banner). No silent hang.
- [ ] **Task 4 — Event semantics check** (AC: 1). Confirm what the orchestrateur emits on a
  generation crash (`session.crashed` today) and whether a distinct `session.generation_failed`
  would be cleaner; if kept as `session.crashed`, the API decides the target by status. Document.
- [ ] **Task 5 — Tests** (AC: 1-3). Unit: `Session::transition` from `generating`/`launching`
  on crash → `failed` (and the chosen mechanism); `SessionLifecycleManager` crash mapping;
  `SessionOrchestrator::markPersonalRunFailed` resets the run. Functional: posting
  `session.crashed` for a `generating` session leaves it `failed` and the run off `starting`.
- [ ] **Task 6 — Gates + live verify** (AC: 6). All gates; reproduce a generation crash and
  confirm session `failed` + run `draft` + error shown.

## Dev Notes

- **Transition map:** `api/src/Sessions/Domain/Session.php` `ALLOWED_TRANSITIONS`
  (`GENERATING => [generated, failed]`, `LAUNCHING => [running, failed]`,
  `RUNNING => [stopped, crashed, finished, launching, idle]`). `transition()` throws
  `LogicException` on an illegal transition.
- **Swallowed today:** `SessionLifecycleManager::transition` wraps `$session->transition()`
  in `try/catch (\LogicException)` and returns `['found' => true, 'errors' => [...]]`;
  `OrchestratorWebhookController::webhook` (`session.crashed` branch) only checks `found`,
  so the no-op transition returns `200` and the session stays `generating`.
- **Run side:** `App\PersonalRuns\Domain\Run::resetAfterValidationFailure()` → `draft`;
  `App\Sessions\Application\PersonalRunAdvancerInterface` (`autoAdvancePersonalRun`,
  `markPersonalRunStopped`) + `SessionOrchestrator` (the personal-run advancer impl).
- **Related:** PR #122 (BOM fix) already resets the run on **configure** failure
  (`LaunchPersonalRunJobHandler`); this story covers the **generation/launch crash** path.
- **DDD:** Application/Domain only in the lifecycle logic; webhook stays thin (deserialize →
  one Application call → serialize).

### Project Structure Notes

- `api/src/Sessions/Presentation/OrchestratorWebhookController.php` (`session.crashed`)
- `api/src/Sessions/Application/SessionLifecycleManager.php` (crash mapping / non-silent)
- `api/src/Sessions/Domain/Session.php` (transition map, if extended)
- `api/src/Sessions/Application/PersonalRunAdvancerInterface.php` + `SessionOrchestrator.php`
  (+ `markPersonalRunFailed`)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (surface the failure)
- Tests under `api/tests/Unit/Sessions/` and `api/tests/Functional/`

### References

- [Source: api/src/Sessions/Domain/Session.php (ALLOWED_TRANSITIONS, transition())]
- [Source: api/src/Sessions/Application/SessionLifecycleManager.php (transition try/catch swallow)]
- [Source: api/src/Sessions/Presentation/OrchestratorWebhookController.php (session.crashed branch)]
- [Source: api/src/Sessions/Application/PersonalRunAdvancerInterface.php, SessionOrchestrator.php]
- [Source: api/src/PersonalRuns/Domain/Run.php (resetAfterValidationFailure)]
- Related: PR #122 (BOM fix + configure-failure run reset); story 16-7/16-8/16-9.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `SessionLifecycleManager::recordCrash($sessionId, $reason)`: maps a crash to a valid terminal
  state by current status — `generating`/`launching` → **`failed`** (sets a friendly
  `validationErrors` message + stores the raw reason in `lastLogs`, resets the personal run via
  `resetAfterValidationFailure` → `draft`); `running` → delegates to the existing `crashed`→idle
  recovery; any other state is **logged at error** (no silent 200).
- `OrchestratorWebhookController` `session.crashed` now calls `recordCrash` (with `body.error` as the
  reason) instead of the illegal `transition('crashed')` that was being swallowed.
- `PersonalRunDrafts::payload` surfaces the session's `validationErrors` on a reset run when the
  session is `draft` **or `failed`** — so the existing `ValidationErrorBanner` on the run page shows
  the "génération échouée" reason. **Backend-only** (the frontend banner already renders when
  `run.validationErrors` is present).
- Non-owner sees a coherent state (run back to `draft`), no hang.
- Tests: functional `OrchestratorWebhookTest` — crash from `generating` → session `failed` + run
  `draft` + `validationErrors` set; and crash from `generating` without a run still reaches `failed`.
  The existing running-crash test still passes. Gates green: phpstan / php-cs-fixer / phpunit (1009) /
  `app:architecture:ddd`.

### File List

- `api/src/Sessions/Application/SessionLifecycleManager.php` (`recordCrash`)
- `api/src/Sessions/Presentation/OrchestratorWebhookController.php` (`session.crashed` → `recordCrash`)
- `api/src/PersonalRuns/Application/PersonalRunDrafts.php` (surface validationErrors on `failed`)
- `api/tests/Functional/OrchestratorWebhookTest.php` (2 new tests)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Story created from the `8771220b…` diagnosis: `session.crashed` from `generating` is an illegal transition, swallowed by `SessionLifecycleManager`, so the session hangs on `generating` and the run on `starting`; no run-side crash path exists. Scope: map crash → valid terminal state by status, stop silently 200-ing, propagate failure to the run, surface the reason. Status → ready-for-dev. |
| 2026-06-13 | Implemented: `recordCrash` (gen/launch → failed + run reset + reason; running → crashed; other → logged); webhook switched to it; run payload surfaces `validationErrors` when session `failed`. Backend-only; functional tests + all gates green. Status → review. |
