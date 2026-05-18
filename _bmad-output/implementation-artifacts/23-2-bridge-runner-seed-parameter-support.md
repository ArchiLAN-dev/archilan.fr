# Story 23.2: Bridge/Runner Extension - Seed Parameter Support

## Story

**As a** developer,
**I want** to add an optional `--seed` parameter to the Archipelago generation pipeline and implement `HttpWeeklyRunnerGateway::launchEntry()`,
**So that** weekly runs always produce the same game world for a given seed, and each player's on-demand session can be triggered via the existing runner infrastructure.

## Status

ready

## Acceptance Criteria

**AC1:** `GenerateRunJob` is moved from `Sessions/Application/Message/` to `Shared/Application/Message/` and gains a new constructor parameter `seed: ?string` (default `null`). All existing imports updated. Old file deleted. `DddArchitectureValidator` confirms `Shared\Application\Message\` imports are allowed from any Application layer.

**AC2:** `GenerateRunJobHandler` (located in `Sessions/Application/Handler/`) appends `'--seed', $job->seed` to the Docker CLI command array when `seed` is non-null; omits both flags when null. Existing tests that import `GenerateRunJob` are updated to use the new `Shared` namespace; the tested behaviour is unchanged (seed is null → same CLI invocation as before).

**AC3:** `runner/app/generator.py`'s `run_generation()` function gains a new keyword-only parameter `seed: str | None = None`. When `seed` is a non-empty string, `--seed <value>` is appended to the Archipelago CLI invocation. When absent, `None`, or empty, the command is unchanged.

**AC4:** A new runner endpoint `POST /sessions/{sessionId}/generate-and-launch` is added to `runner/app/main.py`. It accepts:

```json
{
  "seed": "<optional string>",
  "slots": [
    {
      "slotName": "<string, ≤16 chars, unique>",
      "apworldStorageKey": "<storage key>",
      "apworldDownloadUrl": "<pre-signed MinIO URL>",
      "playerYaml": "<non-empty YAML string>"
    }
  ]
}
```

The endpoint runs YAML writing → Archipelago generation (awaited synchronously, not as a background task) → Docker container launch in sequence. On success it returns 200:

```json
{ "sessionId": "<id>", "containerHost": "0.0.0.0", "containerPort": <int>, "serverPassword": "<string>" }
```

On failure it returns:
- 500 `{"error": "write_failed"}` if YAML writing fails
- 503 `{"error": "generation_failed", "details": "<error message>"}` if generation does not reach `status=generated`
- 503 `{"error": "<launch error>"}` if Docker container launch fails (404/409 from `launch_server` are unreachable in this flow since the session was just created by this request)

Input validation: `slots` are passed directly to `write_slot_yamls()` which handles YAML file creation. Invalid or missing `playerYaml`, missing `apworldDownloadUrl`, or empty slots will cause the generation step to fail (status `failed`), returning 503. Callers should pre-validate via `POST /sessions/{id}/preflight` before invoking this endpoint.

`HttpWeeklyRunnerGateway::launchEntry(entryId, seed, apworldStorageKey, apworldDownloadUrl, playerName, yaml)` calls this single endpoint (one HTTP POST, timeout ≥120s), then maps the response to:

```php
[
    'externalSessionId' => $entryId,
    'connectionInfo' => [
        'host'     => $this->runnerPublicHost,   // injected from RUNNER_PUBLIC_HOST - NOT containerHost ("0.0.0.0")
        'port'     => (int) $response['containerPort'],
        'password' => is_string($response['serverPassword']) ? $response['serverPassword'] : null,
    ],
]
```

**AC5:** `php bin/console app:architecture:ddd` exits 0. All four API quality gates pass (`phpstan analyse src tests`, `php-cs-fixer check src`, `php bin/phpunit`, `app:architecture:ddd`). Python test suite passes: `python -m pytest runner/tests/ -q`. Unit test for `HttpWeeklyRunnerGateway::launchEntry()` verifies: `apworldDownloadUrl` is present in the POST body, `containerPort` maps to `port`, `$runnerPublicHost` is used as `host` (not the runner's `containerHost`), and a `RuntimeException` is thrown when the response contains an `error` key.

## Tasks / Subtasks

- [ ] Task 1: Move `GenerateRunJob` to `Shared/Application/Message/GenerateRunJob.php` and add `seed: ?string` (default `null`)
- [ ] Task 2: Update `Sessions/Application/Handler/GenerateRunJobHandler.php` import path + append `--seed $job->seed` to Docker CLI cmd array when non-null
- [ ] Task 3: Update `messenger.yaml` routing: `App\Shared\Application\Message\GenerateRunJob` → `run_generation` transport (same transport as before, only namespace changes)
- [ ] Task 4: Update `DddArchitectureValidator` if it explicitly whitelists the old `Sessions\Application\Message\GenerateRunJob` path
- [ ] Task 5: Search and update all remaining imports of the old class (`grep -r "Sessions\\Application\\Message\\GenerateRunJob"` in `api/src` and `api/tests`)
- [ ] Task 6: Python - update `runner/app/generator.py`: add `seed: str | None = None` keyword arg, append `--seed <value>` to CLI when set
- [ ] Task 7: Python - add/update tests in `runner/tests/test_generation.py` for seed flag injection
- [ ] Task 8: Add `POST /sessions/{id}/generate-and-launch` to `runner/app/main.py` (synchronous yamls + generate + launch)
- [ ] Task 9: Implement `HttpWeeklyRunnerGateway::launchEntry()` in PHP (single POST, timeout 120s, use `$runnerPublicHost` for `host`)
- [ ] Task 10: Add `App\WeeklyRuns\Infrastructure\HttpWeeklyRunnerGateway` explicit args to `services.yaml` (`$runnerBaseUrl`, `$runnerApiKey`, `$runnerPublicHost`); add `RUNNER_PUBLIC_HOST` to `.env` and `.env.test`
- [ ] Task 11: Add PHP unit test for `HttpWeeklyRunnerGateway::launchEntry()` covering: `apworldDownloadUrl` in POST body, `containerPort` → `port`, `$runnerPublicHost` → `host`, RuntimeException on error response
- [ ] Task 12: Run all four API quality gates + Python test suite

## Dev Notes

### Moved message: import path change

Old: `App\Sessions\Application\Message\GenerateRunJob`
New: `App\Shared\Application\Message\GenerateRunJob`

Files that import the old path:
- `Sessions/Application/Handler/GenerateRunJobHandler.php`
- `PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php` (if it imports the message directly)
- `Sessions/Application/SessionOrchestrator.php` (if it dispatches the message)
- Any functional test that creates a `GenerateRunJob` instance directly
- `messenger.yaml` routing section

Run `grep -r "Sessions\\\\Application\\\\Message\\\\GenerateRunJob"` in `api/src` and `api/tests` to find all occurrences.

### messenger.yaml routing after move

```yaml
# Before
App\Sessions\Application\Message\GenerateRunJob: run_generation

# After
App\Shared\Application\Message\GenerateRunJob: run_generation
```

Note: the transport stays `run_generation` (not `async`).

### GenerateRunJobHandler - actual location and Docker CLI pattern

The handler is `Sessions/Application/Handler/GenerateRunJobHandler.php`, **not** `PersonalRuns/Application/Handler/`. It runs the Archipelago generator as a Docker container via `DockerSocketClient`, building a CLI command array. Add seed injection to `runGenerate()`:

```php
$cmd = ['--player_files_path', '/yamls', '--outputpath', '/output', '--multi', (string) $yamlCount];

if (null !== $job->seed) {
    $cmd[] = '--seed';
    $cmd[] = $job->seed;
}
```

There is no JSON serialization step - the seed travels in the `GenerateRunJob` message object through Symfony Messenger, then the handler reads `$job->seed` directly.

### Python generator.py

Find `run_generation()` in `runner/app/generator.py`. Add `seed: str | None = None` as a keyword-only parameter after `world_dir_flag`. After the initial `cmd` list is built (after `--outputpath`), append the seed flag:

```python
if seed:
    cmd.extend(["--seed", seed])
```

Ensure the seed is a non-empty string before appending to avoid injecting a bare `--seed` flag.

### New runner endpoint: `POST /sessions/{sessionId}/generate-and-launch`

The existing runner flow (yamls → generate async → poll → launch) is unsuitable for a synchronous `launchEntry()` call because `POST /sessions/{id}/generate` returns 202 immediately and runs generation as a background task. Story 23.2 adds a dedicated synchronous endpoint that the weekly gateway can call with a single HTTP request.

**Runner implementation** (`runner/app/main.py`):

**Important constraints from the actual source:**
- `run_generation()` (`runner/app/generator.py`) returns `None` - it updates the `SessionStore` directly. After awaiting it, read the status from `session_store.get(session_id)`.
- `write_slot_yamls()` (`runner/app/yaml_writer.py`) only creates `apworld_urls.json` (needed for APWorld download) when `apworldDownloadUrl` is present in the slot. Without it, the generator won't download the APWorld. The slot payload **must include `apworldDownloadUrl`** (a pre-signed MinIO URL generated by the PHP side).
- `launch_server()` returns `containerHost: "0.0.0.0"` (Docker bind address, not a client-accessible host). The PHP gateway substitutes the runner's public host using the injected `string $runnerPublicHost`.

```python
@app.post("/sessions/{session_id}/generate-and-launch", dependencies=[Depends(_require_api_key)])
async def generate_and_launch(request: Request, session_id: str) -> JSONResponse:
    body: dict[str, Any] = await _json_body(request)
    seed: str | None = body.get("seed") or None
    raw_slots: list[Any] = body.get("slots") if isinstance(body.get("slots"), list) else []

    # 1. Write YAML files (slots must include apworldDownloadUrl for APWorld support)
    slots = [s for s in raw_slots if isinstance(s, dict)]
    try:
        write_slot_yamls(session_id, slots, WORKSPACE_ROOT)
    except Exception as exc:
        return JSONResponse({"error": "write_failed", "details": str(exc)}, status_code=500)

    # 2. Generate (synchronous - await directly, no background task)
    session_store.create(session_id)
    await run_generation(
        session_id, WORKSPACE_ROOT, session_store,
        generate_cmd=ARCHIPELAGO_GENERATE_CMD,
        timeout=GENERATION_TIMEOUT,
        world_dir_flag=ARCHIPELAGO_WORLD_DIR_FLAG,
        seed=seed,
    )
    # run_generation() returns None - read result from store
    session = session_store.get(session_id)
    if session is None or session.get("status") != "generated":
        error = (session or {}).get("error", "unknown")
        return JSONResponse({"error": "generation_failed", "details": error}, status_code=503)

    # 3. Launch Docker container
    launch_result = await launch_server(session_id, session_store, port_pool, docker_manager, image=ARCHIPELAGO_SERVER_IMAGE)
    if "error" in launch_result:
        return JSONResponse(launch_result, status_code=503)

    return JSONResponse({
        "sessionId": session_id,
        "containerHost": launch_result["containerHost"],  # "0.0.0.0" - PHP side uses RUNNER_PUBLIC_HOST
        "containerPort": launch_result["containerPort"],
        "serverPassword": launch_result["serverPassword"],
    })
```

### HttpWeeklyRunnerGateway::launchEntry()

Constructor requires three string params injected from `services.yaml`:
- `string $runnerBaseUrl` - from `%env(RUNNER_BASE_URL)%`
- `string $runnerApiKey` - from `%env(RUNNER_API_KEY)%`
- `string $runnerPublicHost` - from `%env(RUNNER_PUBLIC_HOST)%` - the publicly accessible hostname/IP for player connections (since `launch_server()` returns `containerHost: "0.0.0.0"`, the Docker bind address, which is not client-usable)

**Updated `WeeklyRunnerGatewayInterface::launchEntry()` signature** (also update Story 23.1 AC6):
```php
public function launchEntry(
    string $weeklyEntryId,
    string $seed,
    string $apworldStorageKey,
    string $apworldDownloadUrl,  // pre-signed MinIO URL, generated by LaunchWeeklyEntry (Story 23.4)
    string $playerName,
    string $yaml,
): array;
```

**Implementation:**
```php
public function launchEntry(
    string $weeklyEntryId,
    string $seed,
    string $apworldStorageKey,
    string $apworldDownloadUrl,
    string $playerName,
    string $yaml,
): array {
    $data = $this->post(
        "/sessions/{$weeklyEntryId}/generate-and-launch",
        [
            'seed' => $seed,
            'slots' => [[
                'slotName'           => $playerName,
                'apworldStorageKey'  => $apworldStorageKey,
                'apworldDownloadUrl' => $apworldDownloadUrl,  // required for APWorld download in runner
                'playerYaml'         => $yaml,
            ]],
        ],
        timeout: 120,
    );

    if (isset($data['error'])) {
        $errorMsg = is_string($data['error']) ? $data['error'] : 'unknown';
        throw new \RuntimeException('Runner launchEntry failed: '.$errorMsg);
    }

    $portRaw = $data['containerPort'] ?? null;
    if (!is_int($portRaw) && !is_string($portRaw) && !is_float($portRaw)) {
        throw new \RuntimeException('Runner launchEntry: missing or invalid containerPort in response');
    }

    $passwordRaw = $data['serverPassword'] ?? null;

    return [
        'externalSessionId' => $weeklyEntryId,
        'connectionInfo' => [
            'host'     => $this->runnerPublicHost,  // NOT $data['containerHost'] - that is "0.0.0.0"
            'port'     => (int) $portRaw,
            'password' => is_string($passwordRaw) ? $passwordRaw : null,
        ],
    ];
}
```

`private function post()` passes `x-api-key` header and accepts an optional `int $timeout` parameter (default 30, override to 120 for generate-and-launch).

### services.yaml explicit args for HttpWeeklyRunnerGateway

```yaml
App\WeeklyRuns\Infrastructure\HttpWeeklyRunnerGateway:
    arguments:
        $runnerBaseUrl:    '%env(RUNNER_BASE_URL)%'
        $runnerApiKey:     '%env(RUNNER_API_KEY)%'
        $runnerPublicHost: '%env(RUNNER_PUBLIC_HOST)%'
```

Add `RUNNER_PUBLIC_HOST=<runner-public-ip-or-hostname>` to `.env` and `.env.test`.

### DddArchitectureValidator

Check if the validator explicitly prohibits imports of `Shared\Application\Message\` from Application layers. If `Shared\Application\` is already in the allowed cross-context list, no change is needed. If it needs updating, the rule should be: any context's Application layer may import from `Shared\Application\`.

## File List

- `api/src/Shared/Application/Message/GenerateRunJob.php` - new (moved + extended with `seed: ?string`)
- `api/src/Sessions/Application/Message/GenerateRunJob.php` - **deleted**
- `api/src/Sessions/Application/Handler/GenerateRunJobHandler.php` - modified (import path, seed appended to Docker CLI cmd)
- `api/src/WeeklyRuns/Infrastructure/HttpWeeklyRunnerGateway.php` - modified (implement `launchEntry()`, calls new runner endpoint, uses `$runnerPublicHost`)
- `api/config/services.yaml` - modified (explicit args for `HttpWeeklyRunnerGateway`: `$runnerBaseUrl`, `$runnerApiKey`, `$runnerPublicHost`)
- `api/config/packages/messenger.yaml` - modified (routing key namespace updated to `Shared`)
- `api/.env` - modified (add `RUNNER_PUBLIC_HOST`)
- `api/.env.test` - modified (add `RUNNER_PUBLIC_HOST=localhost` for test environment)
- `api/src/Shared/Application/DddArchitectureValidator.php` - modified if needed
- `runner/app/main.py` - modified (add `POST /sessions/{id}/generate-and-launch` synchronous endpoint; pass `seed` from body in `/generate`)
- `runner/app/generator.py` - modified (add `seed: str | None = None` keyword arg, append `--seed` to CLI)
- `runner/tests/test_generation.py` - modified (seed injection tests + generate-and-launch endpoint tests)
- `api/tests/Unit/WeeklyRuns/HttpWeeklyRunnerGatewayTest.php` - new (unit test for `launchEntry()`)

## Change Log

| Date       | Change                                                                                              |
|------------|-----------------------------------------------------------------------------------------------------|
| 2026-05-17 | Story created                                                                                       |
| 2026-05-17 | Revised: AC4 and Task 8 updated - `launch()` → `launchEntry(single-player)` to match Story 23.1 interface revision. Dev notes rewritten for single-slot payload. |
| 2026-05-17 | Revised: AC4 specifies a new runner endpoint `POST /sessions/{id}/generate-and-launch` (synchronous yamls + generation + launch in one call). Dev notes detail exact Python and PHP implementation. |
| 2026-05-17 | Revised: Python code corrected - `run_generation()` returns None, read status from `session_store.get()` after await. Slot payload must include `apworldDownloadUrl`. `containerHost: "0.0.0.0"` → use injected `$runnerPublicHost`. `launchEntry()` interface gains `string $apworldDownloadUrl` parameter. |
| 2026-05-17 | Revised (findings): AC2 corrected - handler is `Sessions/Application/Handler/GenerateRunJobHandler.php` (not PersonalRuns); uses Docker CLI cmd array, not JSON bridge payload. AC4 updated: `launchEntry()` signature now includes `apworldDownloadUrl`; host mapping clarified (`$runnerPublicHost`, not `containerHost`); error codes clarified (503 for all launch failures, 404/409 unreachable); input validation note added. AC5 extended: Python pytest gate added. Task 3 corrected: transport is `run_generation`, not `async`. Task 11 added: PHP unit test for `HttpWeeklyRunnerGateway`. File List updated: Sessions handler path corrected, `services.yaml`, `.env`, `.env.test`, and PHP test file added. |
