# Story 17.3: Session Restart from Idle State

**Status:** review
**Epic:** 17 - Session Lifecycle - Inactivity Timeout and Restart
**Date:** 2026-05-12

## Story

As a run owner or admin,
I want to restart a paused session and resume from where we left off,
So that an idle game is not permanently lost.

## Acceptance Criteria

1. `POST /api/v1/sessions/{sessionId}/restart` (admin OR personal run owner, session `idle`):
   - Validates `paused_without_save = false` AND `last_save_key IS NOT NULL`; if either fails → 422 `no_save_available`.
   - Transitions session status `idle` → `restarting`.
   - Also transitions linked `personal_runs.status` to `restarting` if applicable.
   - Dispatches `RestartRunJob`.
   - Response: **202** `{ "data": { "sessionId": "...", "status": "restarting" } }`.

2. **`/restarted` callback contract** (internal, bearer-token auth: `BRIDGE_INTERNAL_TOKEN`):
   - Called by runner once the container is healthy and Bridge.py is responding.
   - Payload: `{ "connectionHost": "...", "connectionPort": 38281 }`.
   - Validates that current session status is `restarting` (guard: if already `running`, return 200 silently).
   - On success: transitions `restarting` → `running`, resets `last_activity_at = NOW()`, stores connection details on the personal run record if applicable.
   - Also syncs `personal_runs.status = 'active'` if linked personal run exists.
   - Response: 200 on success, 404 if session not found, 422 `unexpected_status` if status is neither `restarting` nor already `running`.

3. Validation guards:
   - `paused_without_save = true` → 422 `no_save_available`.
   - `last_save_key IS NULL` (even if `paused_without_save = false`) → 422 `no_save_available`.
   - Status not `idle` → 422 `invalid_session_status`.
   - Non-admin, non-owner → 403.

4. **`RestartRunJob` execution**:
   - Download `.apsave` from MinIO using `last_save_key`.
   - Re-launch Archipelago container with `--savefile` flag.
   - Bridge.py readiness probe (same health check as initial launch).
   - Send `POST /api/v1/sessions/{sessionId}/restarted` callback.

## Tasks / Subtasks

- [x] Task 1: API endpoint (AC: 1, 3)
  - [x] `POST /api/v1/sessions/{sessionId}/restart` in `SessionRestartController`
  - [x] Authorization: `ROLE_ADMIN` OR owner check via `personal_runs.session_id = sessionId AND owner_id = callerId`
  - [x] Validate `idle` status, `paused_without_save`, and `last_save_key` not null
  - [x] Transition session to `restarting`; sync `personal_runs.status` if applicable
  - [x] Dispatch `ResumeRunJob`; return 202

- [x] Task 2: `/restarted` callback endpoint (AC: 2)
  - [x] Internal auth: `BRIDGE_INTERNAL_TOKEN` bearer
  - [x] Status guard: accept `restarting` (normal path) or `running` (idempotent); reject others with 422
  - [x] Update `sessions` + `personal_runs` records

- [x] Task 3: ResumeRunJob (AC: 4)
  - [x] Download `.apsave` from MinIO
  - [x] Re-launch container with save file (`SAVE_FILE` env var)
  - [x] Bridge.py health probe via async `RunHealthCheckJob`
  - [x] Send `/restarted` callback

- [x] Task 4: Tests
  - [x] Restart idle (202, status=restarting, job dispatched)
  - [x] `/restarted` callback (running + last_activity_at reset)
  - [x] `/restarted` already running → 200 idempotent
  - [x] `/restarted` unexpected status → 422
  - [x] `paused_without_save = true` → 422 `no_save_available`
  - [x] `last_save_key = null` with `paused_without_save = false` → 422 `no_save_available`
  - [x] Status not idle → 422 `invalid_session_status`
  - [x] Non-authorized → 403

## Dev Notes

- **Status synchronization**: `sessions.status` is authoritative. `personal_runs.status` is a mirror updated in `/paused` (→ idle), `/restarted` (→ active), and here (→ restarting). The mirror exists so `PersonalRunDrafts::payload()` can return `personal_runs.status` without joining the `sessions` table.
- `restarting` is a transient status. A secondary watchdog (can be added later) can detect sessions stuck in `restarting` for >10 minutes and roll back to `idle`.
- MinIO download: use `MinioStorageInterface::download()` (or a presigned URL if the runner downloads directly).

### References

- Story 17.2: `PauseRunJob`, `/paused` callback pattern, `BRIDGE_INTERNAL_TOKEN` auth
- Epic 9 Story 9.6: initial container launch pattern
- Epic 15: MinIO storage interface

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Named the new message `ResumeRunJob` (not `RestartRunJob`) to avoid collision with the pre-existing `RestartRunJob` which handles docker-restart of crashed/unhealthy sessions.
- `SessionRestartController` is a dedicated controller (not merged into `SessionOrchestrationController`) for clean separation: `/restart` uses user auth, `/restarted` uses bearer-token auth.
- `resumeRunning()` is a separate method from `transition()` to preserve the original `startedAt` value - the session was not re-started from scratch.
- `ResumeRunJobHandler` allocates fresh ports; ports from the previous run are gone after `PauseRunJobHandler` stopped the container.
- `MinioStorageInterface::download()` added; `S3MinioStorage` uses `StreamInterface::getContents()` (not cast) to satisfy PHPStan level 8.
- PHPStan level 8: 0 errors across all 9 story files.
- 9/9 functional tests pass. Suite-wide pre-existing failures in `GameSelection`/`CatalogSync` domain unrelated to this story.

### Debug Log

- PHPStan flagged `(string) $body` on `mixed` return of `Result::get('Body')`. Fixed by instanceof-checking against `StreamInterface` and calling `getContents()`.

### File List

- `api/src/Sessions/Domain/Session.php` (modified)
- `api/src/PersonalRuns/Domain/PersonalRun.php` (modified)
- `api/src/Shared/Infrastructure/MinioStorageInterface.php` (modified)
- `api/src/Shared/Infrastructure/S3MinioStorage.php` (modified)
- `api/src/Shared/Infrastructure/NullMinioStorage.php` (modified)
- `api/src/Sessions/Application/Message/ResumeRunJob.php` (new)
- `api/src/Sessions/Application/Handler/ResumeRunJobHandler.php` (new)
- `api/src/Sessions/Application/SessionLifecycleManager.php` (modified)
- `api/src/Sessions/Presentation/SessionRestartController.php` (new)
- `api/config/packages/messenger.yaml` (modified)
- `api/config/services.yaml` (modified)
- `api/tests/Functional/SessionRestartTest.php` (new)

### Change Log

- Added `STATUS_RESTARTING` + `markRestarting()` + `resumeRunning()` to `Session`
- Added `STATUS_RESTARTING` + `markRestarting()` to `PersonalRun`
- Added `download()` to `MinioStorageInterface`, `S3MinioStorage`, `NullMinioStorage`
- New `ResumeRunJob` message + `ResumeRunJobHandler`
- New `SessionRestartController` with `/restart` and `/restarted` endpoints
- `SessionLifecycleManager::initiateRestart()` + `recordRestarted()`
- messenger.yaml: `ResumeRunJob` → `run_server`
- services.yaml: `ResumeRunJobHandler` argument bindings
