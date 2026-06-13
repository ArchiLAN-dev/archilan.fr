# Story 17.12: A crashed / stale session must stay relaunchable

**Status:** review
**Epic:** 17 - Session restart / idle lifecycle
**Date:** 2026-06-13

## Story

As a private-run owner,
I want to relaunch my run even when its session ended up `crashed` (the bridge died, or I stopped the
containers out-of-band),
so that I'm never stuck on "La session n'est pas en état idle" when the UI tells me to resume.

## Context

Diagnosing a stuck run surfaced a desync (reported by Jean): the **run was `idle`** (UI shows
"Reprends-la") but the underlying **session was `crashed`**, and the resume endpoint only accepts
`idle`/`stopped` → it rejected with `invalid_session_status` ("pas en état idle"). Two causes:

1. `CleanupStaleSessionsHandler` (every 2 min) marks a stale **RUNNING** session `crashed` via the bare
   `$session->transition(CRASHED)` **and** moves the run to idle (`markPersonalRunStopped`). That bypasses
   the crash→idle recovery that lives in `SessionLifecycleManager::transition` (which `markIdle`s the
   session), leaving the desync **session=crashed / run=idle**. (Made very reachable by the
   `CENTRAL_API_URL` mislaunch that killed the bridge heartbeat - the cleanup then fired.)
2. Even otherwise, a `crashed` session is a dead-end for resume: `crashed → restarting` wasn't an allowed
   transition and the resume guard excluded `crashed`.

The user's case is "I stopped the containers by hand, not via the site, but I must still be able to
relaunch" - so a crashed/stale session has to be relaunchable.

## Acceptance Criteria

1. `CleanupStaleSessionsHandler` crash-**recovers** a stale RUNNING session to `idle` (resumable) instead
   of leaving it `crashed`; generating/launching still go terminally `failed` (story 17.11). The run is
   moved off "active" as before.
2. The resume/relaunch endpoint accepts a `crashed` session (in addition to `idle`/`stopped`) and
   transitions it to `restarting`; `crashed → restarting` is a legal `Session` transition. A truly
   non-resumable status (e.g. `running`, `generating`) still returns `422 invalid_session_status`.
3. Relaunch reloads the retained volume/save if present, otherwise restarts from the generated seed
   (missing save = progress restarts) - unchanged relaunch semantics.
4. Gates green - API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`. Verified: a stale/crashed
   run can be relaunched.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). In `CleanupStaleSessionsHandler`, after transitioning a stale RUNNING session to
  `crashed`, `markIdle($lastSaveKey, true, $now)` so it ends `idle` (mirror the lifecycle crash recovery).
- [x] **Task 2** (AC 2). Add `STATUS_RESTARTING` to `Session::ALLOWED_TRANSITIONS[CRASHED]`; add
  `STATUS_CRASHED` to the resume guard in `SessionLifecycleManager`.
- [x] **Task 3** (AC 1,2). Tests: cleanup unit asserts stale-running → `idle`; functional
  `SessionRestartTest::testCrashedSessionIsRelaunchable` (crashed → 202 + restarting); `running` still 422.
- [x] **Task 4** (AC 4). Gates green.

## Dev Notes

- Belongs with 17.11 (crash propagation). 17.11 handled generating/launching crashes → `failed`; this
  handles the **runtime/stale** crash so the run stays resumable rather than dead-ended.
- The two fixes are complementary: (1) the cleanup no longer produces the crashed/idle-run desync, and
  (2) even if a `crashed` session arrives via another path, resume now handles it.
- Operational note (out of scope): the root trigger here was the bridge being unable to heartbeat
  because the orchestrateur injected `CENTRAL_API_URL=http://localhost:8000` into the bridge (a container
  can't reach the host API via `localhost`) - fixed by recreating the orchestrateur with
  `host.docker.internal:8000` (local dev config).

### Project Structure Notes

- `api/src/Sessions/Application/ScheduledTask/CleanupStaleSessionsHandler.php`
- `api/src/Sessions/Domain/Session.php` (CRASHED transitions)
- `api/src/Sessions/Application/SessionLifecycleManager.php` (resume guard)
- `api/tests/Unit/Sessions/CleanupStaleSessionsHandlerTest.php`,
  `api/tests/Functional/SessionRestartTest.php`

### References

- [Source: _bmad-output/implementation-artifacts/17-11-propagate-generation-launch-crashes.md]
- [Source: _bmad-output/implementation-artifacts/17-10-relaunch-idle-run-without-save.md (stopped relaunchable)]
- [Source: api/src/Sessions/Application/SessionLifecycleManager.php (crashed→idle recovery, resume guard)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Cleanup now crash-recovers stale RUNNING → `idle` (markIdle); generating/launching stay `failed`.
- `crashed → restarting` allowed; resume guard accepts `crashed`. `running`/`generating` still 422.
- Tests: cleanup unit (stale-running → idle), functional crashed-relaunch (202 + restarting). All gates green.

### File List

- `api/src/Sessions/Application/ScheduledTask/CleanupStaleSessionsHandler.php`
- `api/src/Sessions/Domain/Session.php`
- `api/src/Sessions/Application/SessionLifecycleManager.php`
- `api/tests/Unit/Sessions/CleanupStaleSessionsHandlerTest.php`
- `api/tests/Functional/SessionRestartTest.php`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-13 | Created + implemented. Stale-running sessions crash-recover to idle (no more crashed/idle-run desync) and crashed sessions are relaunchable (crashed→restarting + resume guard). Follow-up to 17.11. Tests + gates green. Status → review. |
