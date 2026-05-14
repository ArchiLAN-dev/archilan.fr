# Story 17.2: Inactivity Watchdog - AP Process Stop & Wake-on-Connect Activation

Status: ready-for-dev

## Story

As a system operator,
I want idle sessions to have their Archipelago process stopped automatically after 1 hour without activity,
So that CPU and game-server RAM are freed while the container stays alive for instant wake-on-connect.

## Acceptance Criteria

1. **Given** a Symfony Messenger scheduled message fires every 5 minutes
   **When** the `InactivityWatchdogMessage` handler runs
   **Then** it queries sessions with status `running` where `last_activity_at < NOW() - ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS`
   **And** for each match, dispatches a `PauseRunJob` to the runner

2. **Given** the runner receives a `PauseRunJob`
   **When** the job executes
   **Then** it calls `POST http://{container_host}:{bridge_port}/pause` on the bridge
   **And** Bridge.py saves state (Archipelago `/save` command, 30s timeout), uploads `.apsave` to MinIO, kills the AP process, starts TCP listener on AP port
   **And** Bridge.py calls `POST /api/v1/internal/sessions/{sessionId}/paused` with `{ "lastSaveKey": "<minio-key>", "pausedWithoutSave": false }`
   **And** Symfony transitions session from `running` → `idle` and stores `lastSaveKey`
   **And** the container is NOT stopped

3. **Given** `ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS` is not set
   **Then** watchdog defaults to 3600 seconds

4. **Given** Bridge.py `/save` call times out (AP unresponsive, 30s)
   **When** timeout occurs
   **Then** bridge kills AP anyway, enters TCP listener mode, calls `/paused` with `{ "pausedWithoutSave": true }`
   **And** Symfony sets `pausedWithoutSave = true` and dispatches an admin notification

## Tasks / Subtasks

- [ ] Task 1: Symfony - `InactivityWatchdogMessage` + handler (AC: 1, 3)
  - [ ] Create `api/src/Sessions/Application/Message/InactivityWatchdogMessage.php` (empty class, Symfony Messenger message)
  - [ ] Create `api/src/Sessions/Application/MessageHandler/InactivityWatchdogMessageHandler.php`
    - [ ] Query: `SELECT s FROM Session s WHERE s.status = 'running' AND s.lastActivityAt < :threshold`
    - [ ] Threshold: `new \DateTimeImmutable('-' . $timeoutSeconds . ' seconds')` where `$timeoutSeconds = (int) ($_ENV['ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS'] ?? 3600)`
    - [ ] For each stale session: dispatch `PauseRunJob` (to runner queue, not default)
  - [ ] Register as Symfony Scheduler recurring message: every 5 minutes
    ```php
    // src/Scheduler/MainSchedule.php or equivalent
    yield RecurringMessage::every('5 minutes', new InactivityWatchdogMessage());
    ```

- [ ] Task 2: Runner - `PauseRunJob` handler (AC: 2, 4)
  - [ ] Add `POST /sessions/{sessionId}/pause` endpoint to runner FastAPI service (`runner/`)
  - [ ] Handler calls `POST http://{bridge_host}:{bridge_port}/pause` (bridge REST on port 5000, not AP port 38281)
  - [ ] On success: runner returns 202; bridge handles the rest asynchronously
  - [ ] On bridge unreachable: return 503 to Symfony dispatcher

- [ ] Task 3: Symfony - `POST /api/v1/internal/sessions/{sessionId}/paused` endpoint (AC: 2, 4)
  - [ ] Add route to `RunnerCallbackController` (existing file, `api/src/Sessions/Presentation/RunnerCallbackController.php`)
    - [ ] OR create dedicated `BridgeLifecycleController` if separation is preferred
  - [ ] Auth: `X-Internal-Secret` header (same pattern as existing runner callbacks)
  - [ ] Body: `{ "lastSaveKey": string|null, "pausedWithoutSave": bool }`
  - [ ] Call `SessionLifecycleManager::pause(sessionId, lastSaveKey, pausedWithoutSave)`
  - [ ] Return 200

- [ ] Task 4: Symfony - `SessionLifecycleManager::pause()` (AC: 2, 4)
  - [ ] Signature: `pause(string $sessionId, ?string $lastSaveKey, bool $pausedWithoutSave): array{found: bool}`
  - [ ] Transition session: `running → idle` using existing `Session::transition()` state machine
  - [ ] Set `$session->setLastSaveKey($lastSaveKey)` and `$session->setPausedWithoutSave($pausedWithoutSave)`
  - [ ] If `$pausedWithoutSave`: dispatch admin notification message via Messenger
  - [ ] Add `setLastSaveKey()` and `setPausedWithoutSave()` setters to `Session` entity (fields already exist at lines 121-125)

- [ ] Task 5: Bridge.py - `POST /pause` endpoint (AC: 2, 4)
  - [ ] Add route to `bridge/core/rest.py`
  - [ ] Auth: validate `Authorization: Bearer {BRIDGE_INTERNAL_TOKEN}` (same as Story 17.1 activity endpoint)
  - [ ] Handler (coroutine): 
    1. Send Archipelago `/save` command via `ap_client.send_command("/save")`
    2. Poll for `.apsave` file in `config.save_dir` (up to 30s, check every 2s)
    3. If file found: upload to MinIO at `sessions/{run_id}/saves/{timestamp}.apsave`
    4. Kill AP process (SIGTERM → wait 5s → SIGKILL if still alive)
    5. Start TCP listener coroutine on AP port (Story 17.5 - bridge signals `WakeOnConnectServer` to start)
    6. Call `POST {symfony_internal_url}/api/v1/internal/sessions/{run_id}/paused` with `{ "lastSaveKey": ..., "pausedWithoutSave": ... }`
  - [ ] On `/save` timeout (30s): set `pausedWithoutSave=True`, still kill AP and start listener
  - [ ] MinIO upload: add `miniopy-async` or use `boto3` - check `bridge/requirements.txt` for existing deps
  - [ ] Return 200 immediately after initiating (async background task) to avoid runner timeout

- [ ] Task 6: Tests (AC: all)
  - [ ] Symfony functional: `tests/Functional/InactivityWatchdogTest.php`
    - [ ] Session below threshold → not paused
    - [ ] Session above threshold → `PauseRunJob` dispatched
    - [ ] Default timeout = 3600s when env not set
  - [ ] Symfony functional: `tests/Functional/SessionPausedCallbackTest.php`
    - [ ] Valid call → session transitions to idle, `lastSaveKey` stored
    - [ ] `pausedWithoutSave=true` → flag stored, admin notification dispatched
    - [ ] Missing secret → 401, unknown session → 404
  - [ ] Bridge unit: `bridge/tests/test_pause_endpoint.py`
    - [ ] `/save` timeout path → `pausedWithoutSave=True`, AP killed
    - [ ] Successful save → MinIO upload called, AP killed

## Dev Notes

### Critical architecture: container stays alive

**The container is NOT stopped** - this is the key difference from the original Epic 17 design. Only the Archipelago process is killed. The bridge process remains running. No `docker stop`.

The runner's `PauseRunJob` calls the bridge REST API (`POST /pause`) and returns. The bridge handles everything asynchronously from there.

### AP port vs bridge port

- **Bridge REST port:** `config.rest_port = 5000` (env: `REST_PORT`)
- **AP (Archipelago) port:** `38281` - derived from `config.archipelago_ws_url` (`ws://localhost:38281`)
- The runner calls bridge on port **5000** (`/pause`)
- The TCP listener for wake-on-connect runs on port **38281** (Story 17.5)

### Session state machine - existing transitions

`Session.php:35` already has:
```php
self::STATUS_RUNNING => [..., self::STATUS_IDLE],
self::STATUS_IDLE => [self::STATUS_RESTARTING],
```
Use `Session::transition('idle', $now)` - no state machine changes needed.

### Existing fields (no migration needed)

`Session.php` already has:
- `lastActivityAt` (line 119)
- `lastSaveKey` (line 122)
- `pausedWithoutSave` (line 125)

**Check if setters exist before adding them.** Getters already exist (used by `PersonalRunDrafts::payload()`).

### MinIO in bridge

Check `bridge/requirements.txt` for existing S3/MinIO library. If none: add `boto3` or `aiobotocore`. MinIO credentials injected via env: `MINIO_ENDPOINT`, `MINIO_ACCESS_KEY`, `MINIO_SECRET_KEY`, `MINIO_BUCKET` - these need to be added to `Config.from_env()` in `bridge/core/config.py`.

### Symfony Scheduler pattern

Check `api/src/Scheduler/` for existing schedule. If `MainSchedule.php` exists, append to it. Pattern:
```php
use Symfony\Component\Scheduler\RecurringMessage;
yield RecurringMessage::every('5 minutes', new InactivityWatchdogMessage());
```

### Runner API pattern

The runner is a FastAPI service (`runner/`). Study `runner/runner.py` or equivalent for existing endpoint patterns. Runner communicates with bridge via direct HTTP to `http://{host}:{bridge_port}`.

### Quality gates
```bash
# Symfony
php bin/phpunit tests/Functional/InactivityWatchdogTest.php tests/Functional/SessionPausedCallbackTest.php
vendor/bin/phpstan analyse src/Sessions/ --level=6
vendor/bin/php-cs-fixer fix --dry-run --diff src/Sessions/

# Bridge
python -m pytest bridge/tests/test_pause_endpoint.py

# Runner
python -m pytest runner/tests/test_pause_job.py
```

### References
- Session entity: `api/src/Sessions/Domain/Session.php` (transitions line 28, fields line 119-125)
- Existing callback: `api/src/Sessions/Presentation/RunnerCallbackController.php`
- Bridge REST: `bridge/core/rest.py`
- Bridge config: `bridge/core/config.py`
- Bridge AP client: `bridge/core/ap_client.py` (send_command)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
