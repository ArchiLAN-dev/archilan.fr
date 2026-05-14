# Story 17.3: Explicit Session Restart from UI ("Reprendre")

Status: ready-for-dev

## Story

As a run owner or admin,
I want to explicitly restart a paused session from the UI,
So that I can resume a game without waiting for a player connection.

## Acceptance Criteria

1. **Given** `POST /api/v1/sessions/{sessionId}/restart` is called by an authenticated user
   **When** session status is `idle` and caller is admin or personal-run owner
   **Then** Symfony calls `POST http://{bridge_host}:{bridge_port}/resume` on the bridge
   **And** transitions session to `restarting`
   **And** returns 202 `{ "data": { "sessionId": "...", "status": "restarting" } }`

2. **Given** Bridge.py receives `POST /resume`
   **When** bridge is in wake-on-connect mode (TCP listener active)
   **Then** bridge closes TCP listener
   **And** launches AP process from most recent `.apsave` on disk (fallback: MinIO `lastSaveKey`)
   **And** on AP ready: calls `POST /api/v1/internal/sessions/{sessionId}/restarted`
   **And** Symfony transitions `restarting → running`, resets `lastActivityAt`

3. **Given** `pausedWithoutSave = true` AND no local `.apsave` file
   **Then** endpoint returns 422 `{ "error": { "code": "no_save_available" } }`

4. **Given** session status is `running` or `completed`
   **Then** endpoint returns 422 `{ "error": { "code": "invalid_session_status" } }`

5. **Given** a non-admin, non-owner authenticated user calls the endpoint
   **Then** response is 403

## Tasks / Subtasks

- [ ] Task 1: Symfony - `POST /api/v1/sessions/{sessionId}/restart` endpoint (AC: 1, 3, 4, 5)
  - [ ] Determine controller location: check if `AdminSessionController` or a new `SessionRestartController` is cleaner
  - [ ] Auth: JWT user auth (public-facing endpoint, not internal - uses `ApiAccessGuard::requireUser()`)
  - [ ] Authorization check: caller must be admin (`ROLE_ADMIN`) OR owner of the associated PersonalRun
    - [ ] Session has `eventId`; check if a `PersonalRun` with `sessionId = sessionId` exists and `ownerId = callerId`
    - [ ] Admin check: `$user->hasRole('ROLE_ADMIN')`
  - [ ] If session not `idle`: return 422 `no_save_available` or `invalid_session_status`
  - [ ] If `pausedWithoutSave = true`: check for local save via bridge `GET /health` or just return 422 immediately
  - [ ] Call `SessionLifecycleManager::initiateRestart(sessionId)` which:
    - Calls runner → bridge `POST /resume`
    - Transitions session to `restarting`
  - [ ] Return 202

- [ ] Task 2: Symfony - `SessionLifecycleManager::initiateRestart()` (AC: 1)
  - [ ] Fetch session; validate status = `idle`
  - [ ] Call runner API: `POST http://{runner_url}/sessions/{sessionId}/resume` (runner proxies to bridge)
  - [ ] Transition session: `idle → restarting` via `Session::transition('restarting', $now)`
  - [ ] Flush and return

- [ ] Task 3: Symfony - `POST /api/v1/internal/sessions/{sessionId}/restarted` endpoint (AC: 2)
  - [ ] Add to `RunnerCallbackController` or new `BridgeLifecycleController`
  - [ ] Auth: `X-Internal-Secret` header (same pattern as existing callbacks)
  - [ ] Call `SessionLifecycleManager::markRestarted(sessionId, host, port, password, bridgePort)`
  - [ ] Transition `restarting → running` with connection details
  - [ ] Reset `lastActivityAt = NOW()`
  - [ ] Return 200

- [ ] Task 4: Bridge.py - `POST /resume` endpoint (AC: 2)
  - [ ] Add route to `bridge/core/rest.py`
  - [ ] Auth: `Authorization: Bearer {BRIDGE_INTERNAL_TOKEN}`
  - [ ] Handler:
    1. Signal `WakeOnConnectServer` to stop (Story 17.5 - set a shared asyncio.Event)
    2. Find most recent `.apsave` file in `config.save_dir` (glob `*.apsave`, sort by mtime)
    3. If no local file and `last_save_key` is configured: download from MinIO
    4. Launch AP process: `subprocess.Popen([...archipelago command...], --savefile=<path>)`
    5. Health check loop: try TCP connect to AP port (38281) every 2s, timeout 60s
    6. On ready: call `POST {symfony_internal_url}/api/v1/internal/sessions/{run_id}/restarted` with connection details
  - [ ] Return 200 immediately (async background task)

- [ ] Task 5: Runner - `POST /sessions/{sessionId}/resume` endpoint (AC: 1)
  - [ ] Proxies to bridge `POST /resume` (runner knows bridge host/port for the session)
  - [ ] Forward response

- [ ] Task 6: Tests (AC: all)
  - [ ] Symfony functional: `tests/Functional/SessionRestartTest.php`
    - [ ] Owner calls restart on idle → 202, session → restarting
    - [ ] Admin calls restart on idle → 202
    - [ ] Non-owner/non-admin → 403
    - [ ] `pausedWithoutSave=true` → 422 `no_save_available`
    - [ ] Already running → 422 `invalid_session_status`
    - [ ] Restarted callback → session → running, connection details set
  - [ ] Bridge unit: `bridge/tests/test_resume_endpoint.py`
    - [ ] Local `.apsave` exists → used directly
    - [ ] No local file → MinIO fallback download
    - [ ] AP ready → restarted callback called

## Dev Notes

### Authorization model - personal runs vs event sessions

For **personal runs**: check `PersonalRun` entity where `sessionId = {sessionId}` and `ownerId = {callerId}`
For **event sessions**: only admins can restart (no participant-level restart for events)

Example query in `SessionLifecycleManager`:
```php
$personalRun = $this->entityManager->createQueryBuilder()
    ->select('r')
    ->from(PersonalRun::class, 'r')
    ->where('r.sessionId = :sid')
    ->setParameter('sid', $sessionId)
    ->getQuery()
    ->getOneOrNullResult();
$isOwner = $personalRun instanceof PersonalRun && $personalRun->isOwnedBy($callerId);
```

### Session state machine (existing)

`Session.php:36-38`:
```php
self::STATUS_IDLE => [self::STATUS_RESTARTING],
self::STATUS_RESTARTING => [self::STATUS_RUNNING],
```
`transition('restarting', $now)` - no state machine changes needed.

### Bridge: AP process launch command

The AP process launch command is the same as in `StartRunJobHandler` (Symfony Messenger). Check runner for the exact entrypoint command used when starting the container. For a restart, the command adds `--savefile=/path/to/latest.apsave`.

The bridge needs to know the AP launch command. Best approach: store it in bridge config at startup (injected via env `AP_LAUNCH_COMMAND` or reconstructed from slot YAMLs already present on disk).

### Bridge: WakeOnConnectServer coordination

The TCP listener (Story 17.5) uses an `asyncio.Event` to signal shutdown:
```python
# In bridge main context, shared object:
wake_stop_event = asyncio.Event()

# In /resume handler:
wake_stop_event.set()  # signals TCP listener to stop
```

### `markRestarted` vs existing `transition` callback

The restarted callback to Symfony should include `host`, `port`, `password`, `bridgePort` so `Session::transition('running', ...)` gets all required fields (see `Session.php:176` - running requires host, port, password).

The connection info hasn't changed (same container, same host/port). The runner stores these at session start and can re-inject on restart.

### Quality gates
```bash
# Symfony
php bin/phpunit tests/Functional/SessionRestartTest.php
vendor/bin/phpstan analyse src/Sessions/ --level=6
vendor/bin/php-cs-fixer fix --dry-run --diff src/Sessions/

# Bridge
python -m pytest bridge/tests/test_resume_endpoint.py
```

### References
- Session entity transitions: `api/src/Sessions/Domain/Session.php:35-41`
- Session `transition()` + running validation: `api/src/Sessions/Domain/Session.php:175`
- PersonalRun entity: `api/src/PersonalRuns/Domain/PersonalRun.php`
- RunnerCallbackController (auth pattern + transition): `api/src/Sessions/Presentation/RunnerCallbackController.php`
- Bridge REST: `bridge/core/rest.py`
- Bridge config: `bridge/core/config.py` (`rest_port=5000`, `archipelago_ws_url` → port 38281)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
