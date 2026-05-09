# Story 9.6: Server Lifecycle - Launch, Health Monitoring and Auto-recovery

Status: review

## Story

As the system,
I want the runner to launch a persistent Archipelago server container, monitor its health, and support restart on crash,
So that a session stays available to players and recovers automatically from transient failures.

## Context - What Was Already Done (Old Architecture)

The original Story 9.6 was implemented in the old Python runner architecture and marked done. With the Messenger architecture, handlers exist but are **incomplete for the new epics requirements**. What exists:

- `StartRunJobHandler` - launches a container with the wrong port mapping (`port:port` instead of `port:38281`), missing `bridge_port`, missing Bridge.py env vars
- `StopRunJobHandler` - stops/removes container, releases one port
- `RestartRunJobHandler` - restarts container, posts callback

**This story adds and fixes the following:**
1. `bridge_port` - second port allocated for Bridge.py REST API (mapped to container port 5000)
2. Updated `docker run` command with correct port mappings + Bridge.py env vars
3. `Session.bridge_port` field to store the allocated bridge port
4. `RunHealthCheckJob` - periodic health check via TCP, crash detection after 3 failures
5. Container name fix: `archipelago-run-{runId}` (currently uses `archilan-{runId}`)

## Acceptance Criteria

1. `StartRunJobHandler` allocates **two** ports from `PortPool`: `port` (game server → maps to 38281) and `bridge_port` (Bridge.py → maps to 5000).
2. `docker run` command: `docker run -d --name archipelago-run-{runId} -p {port}:38281 -p {bridge_port}:5000 -v {workspace}/{runId}/output:/archipelago/output:ro -v {workspace}/{runId}/saves:/archipelago/saves -e SERVER_PASSWORD={password} -e RUN_ID={runId} -e CENTRAL_API_SECRET={CENTRAL_API_SECRET} -e SYMFONY_INTERNAL_URL={SYMFONY_INTERNAL_URL} -e MERCURE_HUB_URL={MERCURE_HUB_URL} {serverImage}`.
3. Success callback payload includes `bridge_port` alongside `port`, `host`, `password`, `runner_id`.
4. The runner-callback handler stores `bridge_port` on the `Session` entity (`Session` needs a `bridge_port` nullable int column).
5. After a successful launch, the handler dispatches `RunHealthCheckJob{sessionId, port, bridgePort, consecutiveFailures: 0}` to the `run_server` queue with a 30-second delay (`DelayStamp(30000)`).
6. `RunHealthCheckJobHandler` checks TCP connectivity to `localhost:{port}`: on success, re-dispatches with delay; on failure, increments `consecutiveFailures`; at 3 failures POSTs `{status: "crashed"}` callback and releases both ports.
7. `RestartRunJob` carries `sessionId`, `port`, `bridge_port`, `password`; handler stops/removes container and relaunches it with same workspace and same two ports.
8. `StopRunJob` carries `sessionId`, `port`, `bridge_port`; handler releases both ports.
9. Functional tests: launch stores `bridge_port` on session; health check 3 failures → crashed; stop releases both ports; restart with correct command.

## Tasks / Subtasks

- [ ] Task 1: Add `bridge_port` to `Session` entity (AC: #4)
  - [ ] Add `#[ORM\Column(type: Types::INTEGER, nullable: true)] private ?int $bridgePort` to `src/Sessions/Domain/Session.php`
  - [ ] Add `getBridgePort(): ?int` getter and update `payload()`
  - [ ] Update `transition()` for `STATUS_RUNNING` to accept and store `?int $bridgePort` param
  - [ ] No migration needed - Jean handles migrations

- [ ] Task 2: Update runner-callback to store `bridge_port` (AC: #4)
  - [ ] In `src/Sessions/Application/SessionLifecycleManager.php` `transition()`, read `bridge_port` from callback data and pass to `$session->transition()`

- [ ] Task 3: Update `StartRunJobHandler` (AC: #1, #2, #3, #5)
  - [ ] Add `$bridgePort = $this->portPool->allocate()` after `$port`; handle null (release `$port` + return failed callback)
  - [ ] Rewrite `docker run` args to match AC #2 exactly - container name `archipelago-run-{runId}`, correct port mappings
  - [ ] Add constructor args: `string $centralApiSecret`, `string $symfonyInternalUrl`, `string $mercureHubUrl` (bind from `config/services.yaml`)
  - [ ] Update success callback payload to include `bridge_port`
  - [ ] On any failure: `$this->portPool->release($port)` AND `$this->portPool->release($bridgePort)` if allocated
  - [ ] After successful docker run: dispatch `RunHealthCheckJob` with `new DelayStamp(30000)`

- [ ] Task 4: Create `RunHealthCheckJob` message (AC: #6)
  - [ ] `src/Sessions/Application/Message/RunHealthCheckJob.php` - readonly, fields: `sessionId: string`, `port: int`, `bridgePort: int`, `consecutiveFailures: int`
  - [ ] Register on `run_server` queue in `config/packages/messenger.yaml`

- [ ] Task 5: Create `RunHealthCheckJobHandler` (AC: #6)
  - [ ] `src/Sessions/Application/Handler/RunHealthCheckJobHandler.php`
  - [ ] `fsockopen('localhost', $job->port, $errno, $errstr, 2.0)` - success: re-dispatch with `DelayStamp(30000)`, reset `consecutiveFailures`
  - [ ] Failure: if `consecutiveFailures + 1 >= 3` → post crash callback + release both ports; else re-dispatch with incremented counter + delay
  - [ ] Log each check with `session_id`, `port`, `result`

- [ ] Task 6: Update `StopRunJob` and `StopRunJobHandler` (AC: #8)
  - [ ] Add `public int $bridgePort` to `src/Sessions/Application/Message/StopRunJob.php`
  - [ ] Update handler to release `$job->bridgePort` after releasing `$job->port`
  - [ ] Update `SessionOrchestrator::orchestrateStop()` to read `getBridgePort()` and pass to constructor
  - [ ] Update `RunnerMessengerFoundationTest` call sites

- [ ] Task 7: Update `RestartRunJob` and `RestartRunJobHandler` (AC: #7)
  - [ ] Add `public int $bridgePort` to `src/Sessions/Application/Message/RestartRunJob.php`
  - [ ] Update handler to relaunch with same docker run command as StartRunJobHandler (same env vars)
  - [ ] Update `SessionOrchestrator::orchestrateRestart()` and test call sites

- [ ] Task 8: Functional tests (AC: #9)
  - [ ] Add tests to `tests/Functional/RunnerGeneratePipelineTest.php` or create `RunnerServerLifecycleTest.php`
  - [ ] Verify running callback stores `bridge_port` on session (retrieve and assert)
  - [ ] Verify `StopRunJob` dispatched with both ports on orchestrate stop
  - [ ] Verify `RestartRunJob` dispatched with `bridge_port` on orchestrate restart

## Dev Notes

### Critical Architecture Facts

- **PortPool** (`src/Sessions/Infrastructure/PortPool.php`): `allocate(): ?int`, `release(int $port)`. ALWAYS release both ports on failure.
- **Container name convention in new epics**: `archipelago-run-{runId}` - the current code uses `archilan-{runId}`, fix it.
- **Port mappings**: `{port}:38281` (game server, MultiServer.py listens on 38281 always), `{bridge_port}:5000` (Bridge.py REST on 5000 always).
- **Volume paths**: `/archipelago/output` and `/archipelago/saves` (per epics.md) - current code uses `/output` and `/saves`. Fix.
- **Env vars available** in `.env` / `.env.test`: `CENTRAL_API_SECRET`, `CENTRAL_API_URL` (use as `SYMFONY_INTERNAL_URL`), `MERCURE_URL` (use as `MERCURE_HUB_URL`).
- **Delayed dispatch**: `$this->bus->dispatch($msg, [new DelayStamp(30000)])` - requires `MessageBusInterface` injected into handler.
- **TCP health check**: `$sock = @fsockopen('localhost', $port, $errno, $errstr, 2.0); if ($sock) { fclose($sock); ... }` - `@` suppresses PHP warnings.

### files to modify

- `src/Sessions/Domain/Session.php`
- `src/Sessions/Application/Handler/StartRunJobHandler.php`
- `src/Sessions/Application/Handler/StopRunJobHandler.php`
- `src/Sessions/Application/Handler/RestartRunJobHandler.php`
- `src/Sessions/Application/Message/StopRunJob.php`
- `src/Sessions/Application/Message/RestartRunJob.php`
- `src/Sessions/Application/SessionOrchestrator.php`
- `src/Sessions/Application/SessionLifecycleManager.php`
- `config/services.yaml`
- `config/packages/messenger.yaml`
- `tests/Functional/RunnerMessengerFoundationTest.php` (update message constructors)

### Files to Create

- `src/Sessions/Application/Message/RunHealthCheckJob.php`
- `src/Sessions/Application/Handler/RunHealthCheckJobHandler.php`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
