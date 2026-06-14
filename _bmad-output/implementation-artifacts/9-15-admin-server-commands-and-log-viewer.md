# Story 9.15: Admin Server Commands and Log Viewer

Status: review

## Story

As an admin,
I want to send commands to the Archipelago server and view container logs in real time,
So that I can manage the session without SSH access to the runner.

## Acceptance Criteria

1. `POST /api/v1/admin/sessions/{id}/commands` - admin only; validates `{"command": "..."}` body; forwards to Bridge.py `POST http://{host}:{bridgePort}/commands`; returns 200 on success, 503 if Bridge.py unreachable.
2. `GET /api/v1/admin/sessions/{id}/logs` - admin only; dispatches `FetchLogsJob{sessionId}` via Messenger to the `run_server` queue; stores the result on the session once the callback arrives; returns latest stored logs immediately (empty on first call, populated after first callback).
3. `FetchLogsJob` handler on runner: executes `docker logs --tail 200 --timestamps archipelago-run-{runId}` and POSTs the output back via callback to central API `POST /api/v1/internal/sessions/{id}/runner-callback` with `{"status": "logs", "output": "..."}`.
4. Callback handler stores the log output on the `Session` entity (`last_logs` TEXT column, nullable).
5. The admin frontend polls `GET /api/v1/admin/sessions/{id}/logs` every 10 seconds while the log panel is open; each response replaces the panel content.
6. Log panel: fixed-height monospace scrollable area, 200 most recent lines.
7. `POST /api/v1/admin/sessions/{id}/force-end` - admin only; requires confirmation via AlertDialog on frontend; transitions session from `running` → `finished`; dispatches `StopRunJob` to runner (fire-and-forget); triggers archival (dispatches `ArchiveRunJob` - Story 9.16); sets `finished_at` on session.
8. `STATUS_FINISHED` added to Session state machine: `running → finished` transition allowed; `finished_at` nullable datetime column on Session entity.
9. All command and force-end actions are recorded in an audit log: `run_id`, `admin_user_id`, `action`, `payload`, `timestamp` - stored on a new `run_audit_logs` table.
10. Functional tests: command forwarded to Bridge.py, log fetch dispatches `FetchLogsJob` + callback stores logs, force-end transitions to finished + dispatches both `StopRunJob` and `ArchiveRunJob`, audit log entry created.

## Tasks / Subtasks

- [x] Task 1: Add `STATUS_FINISHED` and `finished_at` to `Session` entity (AC: #8)
  - [x] Add `public const STATUS_FINISHED = 'finished'` to `src/Sessions/Domain/Session.php`
  - [x] Add `running → finished` to `ALLOWED_TRANSITIONS`
  - [x] Add `#[ORM\Column(type: 'datetimetz_immutable', nullable: true)] private ?\DateTimeImmutable $finishedAt` field
  - [x] Add `getFinishedAt(): ?\DateTimeImmutable` getter, include in `payload()`
  - [x] On `STATUS_FINISHED` transition: `$this->finishedAt = $now`
  - [x] No migration needed - Jean handles migrations

- [x] Task 2: Add `last_logs` column to `Session` entity (AC: #4)
  - [x] Add `#[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $lastLogs` to Session
  - [x] Add `getLastLogs(): ?string`, `setLastLogs(?string $logs): void`, include in `payload()`

- [x] Task 3: Create `RunAuditLog` entity (AC: #9)
  - [x] `src/Sessions/Domain/RunAuditLog.php` - `id (UUID)`, `runId (string)`, `adminUserId (string)`, `action (string)`, `payload (json, nullable)`, `createdAt (datetimetz_immutable)`
  - [x] Table name: `run_audit_logs`
  - [x] No migration needed

- [x] Task 4: `CommandsController` - forward to Bridge.py (AC: #1)
  - [x] Create `src/Sessions/Presentation/CommandsController.php`
  - [x] Route `POST /api/v1/admin/sessions/{id}/commands`
  - [x] Require admin auth; validate `{"command": "..."}` body (non-empty string)
  - [x] Find session; verify status is `running`; POST to `http://{host}:{bridgePort}/commands` via `HttpClientInterface` (3s timeout)
  - [x] On success: write audit log entry `action: "command", payload: {command}`; return 200
  - [x] On timeout/network error: return 503
  - [x] On session not running: return 409

- [x] Task 5: `LogsController` - dispatch FetchLogsJob and return latest (AC: #2, #5, #6)
  - [x] Create `src/Sessions/Presentation/LogsController.php`
  - [x] Route `GET /api/v1/admin/sessions/{id}/logs` - admin only
  - [x] Dispatch `FetchLogsJob{sessionId}` via `MessageBusInterface`
  - [x] Return `{"data": {"logs": session->getLastLogs() ?? "", "fetched_at": now}}` immediately

- [x] Task 6: Create `FetchLogsJob` message and handler (AC: #3)
  - [x] `src/Sessions/Application/Message/FetchLogsJob.php` - `sessionId: string`
  - [x] Registered on `run_server` queue in `config/packages/messenger.yaml`
  - [x] `src/Sessions/Application/Handler/FetchLogsJobHandler.php`: docker logs → callback

- [x] Task 7: Handle `logs` callback in `SessionLifecycleManager` (AC: #4)
  - [x] Added `storeLogs()` to `SessionLifecycleManager`
  - [x] `RunnerCallbackController` short-circuits on `status === "logs"` → calls `storeLogs()`

- [x] Task 8: `ForceEndController` (AC: #7, #9)
  - [x] Created `src/Sessions/Presentation/ForceEndController.php`
  - [x] Route `POST /api/v1/admin/sessions/{id}/force-end` - admin only
  - [x] Transition running → finished; dispatch StopRunJob + ArchiveRunJob; audit log `force_end`

- [x] Task 9: Create `ArchiveRunJob` message placeholder (AC: #7)
  - [x] `src/Sessions/Application/Message/ArchiveRunJob.php` - `sessionId: string`
  - [x] Registered on `run_server` queue
  - [x] `src/Sessions/Application/Handler/ArchiveRunJobHandler.php` - logs received, no-op

- [x] Task 10: Frontend - command input and log panel (AC: #1, #5, #6, #7)
  - [x] `CommandPanel` component: command input + Send button, inline success/error feedback
  - [x] `LogPanel` component: toggle Afficher/Masquer, 10s poll interval, fixed-height monospace div
  - [x] `ForceEndDialog` component: modal confirm before calling `POST force-end`
  - [x] "finished" added to `SessionStatus`, `STATUS_LABELS`, `STATUS_CLASSES`
  - [x] All panels disabled/hidden when session is not `running`

- [x] Task 11: Functional tests (AC: #10)
  - [x] `testCommandForwardsToBridge` - mock HttpClient, verify 200 + audit log entry
  - [x] `testCommandReturns503WhenBridgeUnreachable` - 503 bridge_unavailable
  - [x] `testLogsFetchDispatchesFetchLogsJob` - verify FetchLogsJob in run_server transport, 200
  - [x] `testLogsCallbackStoresOutput` - POST callback with `status: logs`, verify session.lastLogs
  - [x] `testForceEndTransitionsToFinished` - 200, status=finished, StopRunJob + ArchiveRunJob in transport
  - [x] `testForceEndReturns409WhenNotRunning` - 409 session_not_running

## Dev Notes

### Session State Machine Addition

Current `ALLOWED_TRANSITIONS`:
```php
self::STATUS_RUNNING => [self::STATUS_STOPPED, self::STATUS_CRASHED],
```
Must add `STATUS_FINISHED`:
```php
self::STATUS_RUNNING => [self::STATUS_STOPPED, self::STATUS_CRASHED, self::STATUS_FINISHED],
```

### Bridge.py Commands Endpoint (from Story 9.12)

`POST http://{host}:{bridgePort}/commands` - body `{"command": "..."}` - returns `{"ok": true}`. Use `HttpClientInterface` with 3s timeout. **This requires `bridge_port` on Session (Story 9.6)**.

### FetchLogsJob - Docker Logs Command

```php
$process = new Process(['docker', 'logs', '--tail', '200', '--timestamps', 'archipelago-run-'.$job->sessionId]);
$process->setTimeout(30);
$process->run();
$output = $process->getOutput().$process->getErrorOutput(); // both stdout and stderr
```

### Audit Log - Simple Pattern

```php
$log = new RunAuditLog(bin2hex(random_bytes(16)), $sessionId, $adminUser->getId(), 'command', ['command' => $cmd], new \DateTimeImmutable());
$this->entityManager->persist($log);
$this->entityManager->flush();
```

### ForceEnd Flow

1. `session->transition(STATUS_FINISHED, $now)` - enforced by state machine
2. `$this->bus->dispatch(new StopRunJob($session->getId(), $session->getPort(), $session->getBridgePort()))` - fire-and-forget
3. `$this->bus->dispatch(new ArchiveRunJob($session->getId()))` - fire-and-forget; handler stub until Story 9.16
4. Flush entity manager

### HttpClientInterface in Tests

Register a `MockHttpClient` in `when@test` in `config/services.yaml`:
```yaml
when@test:
    services:
        Symfony\Contracts\HttpClient\HttpClientInterface:
            class: Symfony\Component\HttpClient\MockHttpClient
            public: true
```

Then in tests: `$mockClient = self::getContainer()->get(HttpClientInterface::class); $mockClient->setResponseFactory([...]);`

### References

- `src/Sessions/Domain/Session.php` - add `STATUS_FINISHED`, `finished_at`, `last_logs`
- `src/Sessions/Application/Handler/StopRunJobHandler.php` - existing pattern for fire-and-forget jobs
- `src/Sessions/Application/SessionOrchestrator.php` - dispatch pattern
- `src/Sessions/Infrastructure/RunnerCallbackClient.php` - `sendCallback()` for FetchLogsJob handler

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Test assertion for `command` payload had a leading-space typo; corrected to `self::assertSame('!hint AliceSlot ItemName', $log->getPayload()['command'])`.

### Completion Notes List

- `storeLogs()` added to `SessionLifecycleManager` (not as a standalone service) - `RunnerCallbackController` short-circuits before calling `transition()` when `status === "logs"`, delegating to `storeLogs()`.
- `FetchLogsJobHandler` and `ArchiveRunJobHandler` require explicit `$runnerId` binding in `services.yaml` (same pattern as other handlers).
- `CommandsController` uses `requireAdmin()` from `ApiAccessGuard` (not the `requireUser` + manual role check pattern used in `PlayerStateController` - only admins can send commands).
- `LogPanel` renders `null` when session is not running and panel is closed; when session becomes non-running after a run, the panel can still be toggled open.
- 6 functional tests, 52 assertions; 487/487 total PHP suite green.

### File List

- `api/src/Sessions/Domain/Session.php` (modified - STATUS_FINISHED, finishedAt, lastLogs)
- `api/src/Sessions/Domain/RunAuditLog.php` (new)
- `api/src/Sessions/Presentation/CommandsController.php` (new)
- `api/src/Sessions/Presentation/LogsController.php` (new)
- `api/src/Sessions/Presentation/ForceEndController.php` (new)
- `api/src/Sessions/Application/Message/FetchLogsJob.php` (new)
- `api/src/Sessions/Application/Message/ArchiveRunJob.php` (new)
- `api/src/Sessions/Application/Handler/FetchLogsJobHandler.php` (new)
- `api/src/Sessions/Application/Handler/ArchiveRunJobHandler.php` (new)
- `api/src/Sessions/Application/SessionLifecycleManager.php` (modified - storeLogs())
- `api/src/Sessions/Presentation/RunnerCallbackController.php` (modified - logs callback)
- `api/config/packages/messenger.yaml` (modified - FetchLogsJob, ArchiveRunJob routing)
- `api/config/services.yaml` (modified - FetchLogsJobHandler, ArchiveRunJobHandler runnerId binding)
- `api/tests/Functional/AdminServerCommandsTest.php` (new)
- `frontend/src/features/admin/admin-session-page.tsx` (modified - CommandPanel, LogPanel, ForceEndDialog, finished status)
