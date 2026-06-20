# Story 17.5: Bridge - Wake-on-Connect TCP Listener

Status: superseded

> **Superseded (2026-06-10) by stories 17.6 / 17.7 / 17.8.** The wake-on-connect model - bridge
> killing the AP sub-process and surviving inside a warm container to relaunch on the next connection
> - was dropped: the bridge depends on its AP server (it shouldn't outlive it), and keeping a
> container warm per idle run wastes resources. **Idle is already Archipelago's own feature**
> (`auto_shutdown`, wired via the `autoShutdown` session config in epic 27), so the Symfony
> `InactivityWatchdog` + bridge `/pause` were a redundant parallel mechanism and are removed too.
> The new model: **AP auto-shuts-down itself**; the orchestrateur detects the clean exit, persists the
> save, and stops the bridge; **manual resume** relaunches a fresh container from the MinIO save,
> driven by Symfony → orchestrateur (no auto-restart-on-connect). See:
> - 17.6 - orchestrateur: stop on idle + relaunch-from-save
> - 17.7 - bridge: remove wake listener, `/pause` = save+upload then quit
> - 17.8 - api + frontend: drop the bridge wake trigger, repoint resume, fix the UI copy
>
> The description below is retained for historical context only and reflects the **old** (in-bridge,
> PID-file) architecture.

## Story

As a player,
I want the Archipelago server to restart automatically when I attempt to connect to a paused game,
So that I can resume playing without having to ask anyone to restart it.

## Acceptance Criteria

1. **Given** Bridge.py has killed the AP process (Story 17.2 `POST /pause`)
   **When** the bridge enters idle mode
   **Then** it opens a TCP server socket on `config.ap_port` (port 38281 - derived from `archipelago_ws_url`)
   **And** the socket runs in a non-blocking asyncio task (does not block heartbeat or REST loops)

2. **Given** the TCP listener is active and a client connects on the AP port
   **When** connection is accepted
   **Then** Bridge.py immediately closes the accepted socket (client gets connection reset - expected UX)
   **And** Bridge.py closes the TCP listener (no longer listening on AP port)
   **And** calls `POST /api/v1/internal/sessions/{sessionId}/restarting` (internal, bearer-token auth: `BRIDGE_INTERNAL_TOKEN`)
   **And** Symfony transitions session `idle → restarting`
   **And** Bridge.py launches the AP process from most recent `.apsave` on disk
   **And** on AP health-check pass: calls `POST /api/v1/internal/sessions/{sessionId}/restarted`
   **And** Symfony transitions `restarting → running`, resets `lastActivityAt`

3. **Given** the AP process fails to start after wake-on-connect
   **When** launch or health-check fails
   **Then** Bridge.py calls `POST /api/v1/internal/sessions/{sessionId}/restart-failed`
   **And** Symfony transitions back to `idle`, sets `restartFailed = true`
   **And** admin notification is dispatched

4. **Given** the `POST /resume` endpoint is called (Story 17.3 explicit restart)
   **When** bridge is in TCP listener mode
   **Then** the TCP listener task is cancelled (asyncio.Event signal)
   **And** the explicit resume flow takes over (no conflict)

## Tasks / Subtasks

- [ ] Task 1: `WakeOnConnectServer` class in `bridge/core/wake_on_connect.py` (AC: 1, 2, 3, 4)
  - [ ] New file: `bridge/core/wake_on_connect.py`
  - [ ] Class `WakeOnConnectServer`:
    ```python
    class WakeOnConnectServer:
        def __init__(self, ap_port: int, stop_event: asyncio.Event, on_connect: Callable[[], Coroutine]):
            ...
        async def serve(self) -> None:
            """Open TCP listener; accept first connection; call on_connect()."""
    ```
  - [ ] `serve()` coroutine:
    1. `asyncio.start_server(handle, '0.0.0.0', ap_port)` - starts TCP server
    2. Await first accepted connection (`asyncio.wait_for`, 2h timeout)
    3. Immediately close accepted connection writer (no data sent)
    4. Stop the server (no more accepting)
    5. Call `on_connect()` callback
  - [ ] `stop_event`: when set by `POST /resume`, the `serve()` loop exits cleanly before accepting
  - [ ] AP port extraction: `int(config.archipelago_ws_url.split(':')[-1])` → `38281`

- [ ] Task 2: `BridgeLifecycleManager` class in `bridge/core/lifecycle.py` (AC: 1, 2, 3, 4)
  - [ ] New file: `bridge/core/lifecycle.py`
  - [ ] `async def pause(config, ap_client) -> dict`:
    1. Send `/save` command → poll for `.apsave` in `config.save_dir` (30s timeout, 2s interval)
    2. If found: upload to MinIO → `sessions/{run_id}/saves/{timestamp}.apsave`
    3. Kill AP process: `ap_process.terminate()` → await 5s → `ap_process.kill()` if alive
    4. Disconnect WebSocket client gracefully (`ap_client.disconnect()`)
    5. Notify Symfony: `POST .../paused` with `{ "lastSaveKey": ..., "pausedWithoutSave": ... }`
    6. Start `WakeOnConnectServer` as asyncio task (store as `self._wake_task`)
    7. Return `{ "paused_without_save": bool, "save_key": str|None }`
  - [ ] `async def resume(config) -> None`:
    1. Cancel `self._wake_task` if running (set stop_event)
    2. Find latest `.apsave` in `config.save_dir`; fallback: download from MinIO
    3. Launch AP process with save file
    4. Health-check loop: TCP connect to AP port every 2s, timeout 60s
    5. On ready: notify Symfony `POST .../restarted` with connection details
    6. Reconnect bridge WebSocket client
  - [ ] `async def _launch_ap(save_path: str | None) -> asyncio.subprocess.Process`:
    - Uses `asyncio.create_subprocess_exec(...)` with AP entrypoint command
    - Injects `--savefile` arg if `save_path` is provided

- [ ] Task 3: Wire `WakeOnConnectServer` into `rest.py` `POST /pause` and `POST /resume` (AC: 1, 4)
  - [ ] `POST /pause`: call `lifecycle_manager.pause(config, ap_client)` (async background task)
  - [ ] `POST /resume`: call `lifecycle_manager.resume(config)` (async background task)
  - [ ] Both endpoints return 200 immediately; background task handles async work

- [ ] Task 4: Symfony - `POST /api/v1/internal/sessions/{sessionId}/restarting` endpoint (AC: 2)
  - [ ] Add to `RunnerCallbackController` or new `BridgeLifecycleController`
  - [ ] Auth: `Authorization: Bearer {BRIDGE_INTERNAL_TOKEN}`
  - [ ] Call `SessionLifecycleManager::markRestarting(sessionId)`: transition `idle → restarting`
  - [ ] Return 200

- [ ] Task 5: Symfony - `POST /api/v1/internal/sessions/{sessionId}/restart-failed` endpoint (AC: 3)
  - [ ] Auth: `Authorization: Bearer {BRIDGE_INTERNAL_TOKEN}`
  - [ ] `SessionLifecycleManager::markRestartFailed(sessionId)`:
    - Transition `restarting → idle`
    - Set `restartFailed = true` on session (add boolean column if not present)
    - Dispatch admin notification via Messenger
  - [ ] Return 200

- [ ] Task 6: MinIO upload in bridge (AC: 2)
  - [ ] Add `MinioUploader` helper in `bridge/core/minio_uploader.py`
  - [ ] Config fields to add to `Config` dataclass:
    - `minio_endpoint: str` (env: `MINIO_ENDPOINT`)
    - `minio_access_key: str` (env: `MINIO_ACCESS_KEY`)
    - `minio_secret_key: str` (env: `MINIO_SECRET_KEY`)
    - `minio_bucket: str` (env: `MINIO_BUCKET`, default: `"archipelago-saves"`)
  - [ ] Library: use `aiobotocore` (async S3-compatible) or `boto3` in a thread executor; check `bridge/requirements.txt`
  - [ ] Upload: `put_object(Bucket=bucket, Key=key, Body=file_bytes)`

- [ ] Task 7: Tests (AC: all)
  - [ ] `bridge/tests/test_wake_on_connect.py`:
    - [ ] TCP listener starts on correct port
    - [ ] First TCP connection → `on_connect()` called once
    - [ ] `stop_event` set → listener exits without calling `on_connect()`
    - [ ] AP launch failure → `restart-failed` callback called
  - [ ] `bridge/tests/test_lifecycle_manager.py`:
    - [ ] `pause()` → save, MinIO upload, AP killed, TCP listener started
    - [ ] `pause()` with save timeout → `pausedWithoutSave=True`
    - [ ] `resume()` explicit → wake task cancelled, AP relaunched, restarted callback called
  - [ ] Symfony functional: `bridge/tests/test_restarting_callback.py` (or Symfony `tests/Functional/`)
    - [ ] `/restarting` → session `idle → restarting`
    - [ ] `/restart-failed` → session `restarting → idle`, admin notification dispatched
    - [ ] Missing secret → 401

## Dev Notes

### Critical: AP port derivation

The Archipelago server port is **38281**, derived from `config.archipelago_ws_url = "ws://localhost:38281"`:
```python
ap_port = int(config.archipelago_ws_url.rsplit(':', 1)[-1])  # → 38281
```
The TCP listener opens on this same port. Since the AP process is dead when the listener is active, there is no port conflict.

### asyncio task coordination

Both `WakeOnConnectServer` and the REST loops run in the same asyncio event loop. Use `asyncio.Event` for clean coordination:
```python
# Shared across pause/resume:
self._wake_stop_event = asyncio.Event()
self._wake_task: asyncio.Task | None = None

# In pause():
self._wake_stop_event.clear()
self._wake_task = asyncio.create_task(
    WakeOnConnectServer(ap_port, self._wake_stop_event, self._on_wake_connect).serve()
)

# In resume():
self._wake_stop_event.set()
if self._wake_task:
    await asyncio.wait_for(self._wake_task, timeout=3.0)
```

### AP process management

The bridge does NOT currently manage the AP process lifecycle (it only connects to it as a WebSocket client). This story introduces direct process management.

The AP process PID is not known to the bridge - the AP server was started by the container entrypoint. Options:
- **Option A (preferred):** Store the AP process PID in a file on startup (e.g. `/tmp/ap.pid`), written by `entrypoint.sh`
- **Option B:** Use `psutil.Process` to find the AP process by name (`ArchipelagoServer` or `multiserver`)
- **Option C:** Kill by port: find the process listening on port 38281 with `psutil`

The entrypoint approach is cleanest. Update `archipelago/entrypoint.sh` to write the PID:
```bash
python MultiServer.py ... &
echo $! > /tmp/ap.pid
```

Then in bridge:
```python
with open('/tmp/ap.pid') as f:
    pid = int(f.read().strip())
os.kill(pid, signal.SIGTERM)
```

### AP process re-launch command

On restart, the bridge needs to re-launch the AP server with the same arguments as the original start, plus `--savefile`. The entrypoint command is stored in `archipelago/entrypoint.sh`. The bridge needs access to it - simplest: inject via env var `AP_LAUNCH_CMD` or reconstruct from known args.

Add `ap_launch_cmd: str` to `Config` dataclass (env: `AP_LAUNCH_CMD`). The runner sets this when launching the container.

### Existing bridge WebSocket handling

When the AP process restarts, the bridge WebSocket client (which was disconnected when AP died) needs to reconnect. The bridge already has exponential backoff reconnect logic (`_WS_RETRY_DELAYS` in `ap_client.py`). After launching AP, trigger a reconnect by calling the existing retry mechanism (don't add a new one).

### Symfony Session - `restarting → idle` transition

Check `Session.php:ALLOWED_TRANSITIONS` - currently `restarting → running` exists. Need to add `restarting → idle` for the `restart-failed` path if not present. This requires a state machine amendment.

### MinIO config in bridge

The runner already uses MinIO for archiving saves (check runner code for MinIO client usage). The same MinIO instance and credentials should be reused. Inject same env vars into the container.

### Quality gates
```bash
# Bridge
python -m pytest bridge/tests/test_wake_on_connect.py bridge/tests/test_lifecycle_manager.py -v

# Symfony
php bin/phpunit tests/Functional/BridgeRestartingCallbackTest.php
vendor/bin/phpstan analyse src/Sessions/ --level=6
vendor/bin/php-cs-fixer fix --dry-run --diff src/Sessions/
```

### References
- Bridge REST: `bridge/core/rest.py` (aiohttp, asyncio patterns)
- Bridge config: `bridge/core/config.py` (add `ap_launch_cmd`, `minio_*` fields)
- Bridge AP client (WebSocket reconnect): `bridge/core/ap_client.py:21` (`_WS_RETRY_DELAYS`)
- Container entrypoint: `archipelago/entrypoint.sh` (add PID file write)
- Session transitions: `api/src/Sessions/Domain/Session.php:28-42`
- RunnerCallbackController (internal auth pattern): `api/src/Sessions/Presentation/RunnerCallbackController.php:26`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
