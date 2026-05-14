# Story 17.2: Inactivity Watchdog - AP Process Stop & Wake-on-Connect Activation

**Status:** review
**Epic:** 17 - Session Lifecycle - Inactivity Timeout and Restart
**Date:** 2026-05-12

## Story

As a system operator,
I want idle sessions to have their Archipelago process stopped automatically after 1 hour without activity,
So that CPU and game-server RAM are freed while the bridge container stays alive for wake-on-connect.

## Acceptance Criteria

1. **Watchdog query**: Symfony Scheduler dispatches `InactivityWatchdogMessage` every 5 minutes. The handler queries sessions where `status = 'running'` AND (`last_activity_at IS NULL OR last_activity_at < NOW() - INTERVAL`) AND `started_at < NOW() - 60s` (grace period: don't pause sessions started less than 1 minute ago). For each matching session, dispatches `PauseRunJob`.

2. **PauseRunJob execution order**:
   a. Call Bridge.py `POST /pause` on the bridge REST port with `Authorization: Bearer {BRIDGE_INTERNAL_TOKEN}`.
   b. Bridge triggers Archipelago `/save` and waits up to 30s for `.apsave`.
   c. Bridge uploads `.apsave` to MinIO at key `sessions/{sessionId}/saves/{YYYYMMDDHHmmss}.apsave` when a save exists.
   d. Bridge kills the Archipelago process only; the bridge container stays alive.
   e. Bridge starts wake-on-connect listener on the AP port.
   f. Bridge sends callback `POST /api/v1/sessions/{sessionId}/paused` with save result.

3. **`/paused` callback contract** (internal, bearer-token auth: `BRIDGE_INTERNAL_TOKEN`):
   - Payload: `{ "saveKey": "<minio-key>" | null, "failedSave": true | false }`.
   - Validates that current session status is `running` (idempotent guard: if already `idle`, return 200 silently).
   - On success: sets `status = 'idle'`, stores `last_save_key`, sets `paused_without_save = failedSave`.
   - Also updates `PersonalRun.status = 'idle'` if a `personal_runs` record has `session_id` matching this session.
   - Response: 200 on success, 404 if session not found, 422 with `unexpected_status` if status is neither `running` nor already `idle`.

4. **Save timeout path**: if Bridge.py `/save` times out (30s), the bridge still kills the AP process, starts wake-on-connect, skips upload, and calls `/paused` with `{ "saveKey": null, "failedSave": true }`. Admin notification dispatched via Messenger after callback.

5. **Default threshold**: 3600s (1h) when `ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS` is not set.

## Tasks / Subtasks

- [x] Task 1: DB migration (AC: 3)
  - [x] `ALTER TABLE sessions ADD last_save_key VARCHAR(500) DEFAULT NULL`
  - [x] `ALTER TABLE sessions ADD paused_without_save BOOLEAN NOT NULL DEFAULT FALSE`

- [x] Task 2: Watchdog (AC: 1, 5)
  - [x] `symfony/scheduler` component - verify in `composer.json`, add if missing
  - [x] `InactivityWatchdogMessage` as a Symfony Scheduler recurring message (every 5 min)
  - [x] Handler: query with `IS NULL OR <` condition + 60s grace period
  - [x] Dispatch `PauseRunJob` per matching session
  - [x] Read `ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS` with fallback 3600

- [x] Task 3: PauseRunJob (AC: 2, 4)
  - [x] Runner calls Bridge.py `POST /pause` with `BRIDGE_INTERNAL_TOKEN`
  - [x] Bridge executes save, upload, AP process stop, wake-on-connect listener, callback
  - [x] Bridge `/save` timeout: skip upload step, callback with `failedSave: true`
  - [x] AP process stop always executes regardless of save outcome

- [x] Task 4: `/paused` callback endpoint (AC: 3)
  - [x] Internal auth: `BRIDGE_INTERNAL_TOKEN` bearer
  - [x] Status guard: accept `running` (normal path) or `idle` (idempotent); reject others with 422
  - [x] Update `sessions` record
  - [x] Sync `personal_runs.status = 'idle'` if linked personal run exists

- [x] Task 5: Admin notification (AC: 4)
  - [x] On `paused_without_save`, dispatch admin email notification via Messenger

- [x] Task 6: Tests
  - [x] Session last_activity_at below threshold -> not paused
  - [x] Session last_activity_at above threshold -> `PauseRunJob` dispatched
  - [x] Session last_activity_at IS NULL + started_at > 60s -> dispatched
  - [x] Session started_at < 60s + IS NULL -> NOT dispatched (grace period)
  - [x] Save timeout path -> `paused_without_save=true`, notification dispatched
  - [x] `/paused` callback already idle -> 200 idempotent
  - [x] `/paused` callback unexpected status -> 422
  - [x] Default threshold 3600s

## Dev Notes

- **Status model**: `sessions.status` is the source of truth. `personal_runs.status` mirrors it and is updated synchronously in the `/paused` callback handler. The personal run payload (`PersonalRunDrafts::payload()`) reads `personal_runs.status` directly - no need to join `sessions` in the frontend payload.
- **Execution order matters**: callback is sent after the AP process is killed, so when the API sets `status = idle` the game server is already down. The bridge container intentionally remains alive to support wake-on-connect.
- Symfony Scheduler dispatches `InactivityWatchdogMessage` -> Messenger handler queries and dispatches `PauseRunJob` -> separate runner worker calls Bridge.py `POST /pause`.

### References

- Story 17.1: `last_activity_at` field, `BRIDGE_INTERNAL_TOKEN` auth pattern
- Story 17.5: wake-on-connect TCP listener and restart flow
- Epic 15: MinIO upload pattern

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Task 1 (migration): `Version20260514100000.php` adds `last_save_key VARCHAR(500) DEFAULT NULL` and `paused_without_save BOOLEAN NOT NULL DEFAULT FALSE` to `archipelago_sessions`. `Session.php` adds `STATUS_IDLE = 'idle'`, the ALLOWED_TRANSITIONS entries (`running -> idle`, `idle -> restarting`), new fields, `markIdle()` method, and getters.
- Task 2 (watchdog): `InactivityWatchdogHandler` uses a DQL query with `(lastActivityAt IS NULL OR lastActivityAt < :threshold) AND startedAt < :graceLimit`. `$inactivityTimeoutSeconds` is bound per-handler in services.yaml with `default:default_inactivity_timeout:int` env processor (fallback 3600). Added to `Schedule.php` as `every('5 minutes', ...)`.
- Task 3 (PauseRunJob): `PauseRunJobHandler` calls Bridge.py `POST /pause` on the bridge REST port with `BRIDGE_INTERNAL_TOKEN`. Bridge handles save polling, MinIO upload, AP process kill, wake-on-connect listener startup, and `/paused` callback.
- Task 4 (/paused endpoint): `SessionPausedController` with same bearer-token pattern as activity endpoint. `SessionLifecycleManager::recordPaused()` handles status guard (idle=idempotent, !running=unexpected_status), calls `Session::markIdle()`, syncs PersonalRun via `findOneBy(['sessionId' => $sessionId])`, dispatches `SessionPausedWithoutSaveMessage` on failedSave.
- Task 5 (admin notification): `SessionPausedWithoutSaveHandler` sends email to `$mailerSender` (no dedicated admin email env var - small association pattern). Message dispatched on `async` transport.
- Task 6 (tests): 8 tests in `InactivityWatchdogTest.php` pass. Tests 1-5, 8 invoke `InactivityWatchdogHandler` directly (container-fetched); tests 6-7 use HTTP client against `/paused` endpoint. Bridge pause/wake tests cover `/pause`, save timeout, successful upload, AP process stop, and wake listener startup.

### Debug Log

- PHPStan level 8: test message and JSON error assertions needed explicit narrowing.

### File List

- `api/migrations/Version20260514100000.php` (new)
- `api/src/Sessions/Domain/Session.php` (modified - STATUS_IDLE, markIdle(), lastSaveKey, pausedWithoutSave)
- `api/src/Sessions/Application/ScheduledTask/InactivityWatchdogMessage.php` (new)
- `api/src/Sessions/Application/ScheduledTask/InactivityWatchdogHandler.php` (new)
- `api/src/Sessions/Application/Message/PauseRunJob.php` (new)
- `api/src/Sessions/Application/Handler/PauseRunJobHandler.php` (new)
- `api/src/Sessions/Application/SessionLifecycleManager.php` (modified - recordPaused())
- `api/src/Sessions/Presentation/SessionPausedController.php` (new)
- `api/src/Communications/Application/SessionPausedWithoutSaveMessage.php` (new)
- `api/src/Communications/Application/SessionPausedWithoutSaveHandler.php` (new)
- `api/src/Schedule.php` (modified - added InactivityWatchdogMessage every 5 min)
- `api/config/packages/messenger.yaml` (modified - routing for PauseRunJob, InactivityWatchdogMessage, SessionPausedWithoutSaveMessage)
- `api/config/services.yaml` (modified - default_inactivity_timeout param, InactivityWatchdogHandler binding)
- `bridge/core/rest.py` (modified - POST /pause flow)
- `api/tests/Functional/InactivityWatchdogTest.php` (new - 8 tests)

### Change Log

- 2026-05-12: Story 17.2 implemented - inactivity watchdog (5 min scheduler), PauseRunJob bridge trigger, bridge pause flow (save, upload, AP process stop, wake-on-connect, callback), /paused callback endpoint, admin notification on failed save, tests green.
