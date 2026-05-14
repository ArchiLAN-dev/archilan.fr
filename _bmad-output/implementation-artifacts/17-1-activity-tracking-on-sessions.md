# Story 17.1: Activity Tracking on Sessions

Status: ready-for-dev

## Story

As a system operator,
I want sessions to track when they last had Archipelago activity,
So that the inactivity watchdog has a reliable signal for when to pause a run.

## Acceptance Criteria

1. **Given** Bridge.py receives an Archipelago game-state event (ItemSent / LocationChecked / StatusUpdate)
   **When** the event is processed
   **Then** Bridge.py calls `PATCH /api/v1/internal/sessions/{sessionId}/activity` with `Authorization: Bearer {BRIDGE_INTERNAL_TOKEN}` and body `{ "activityType": "check"|"item"|"hint", "occurredAt": "<ISO8601>" }`
   **And** Symfony updates `sessions.last_activity_at` to `occurredAt` (or server NOW() if missing)

2. **Given** a session is created (status `running`)
   **When** no activity event has been received yet
   **Then** `last_activity_at` defaults to session `created_at` - this is already satisfied by `Session::create()` setting `lastActivityAt: $now`

3. **Given** the activity endpoint is called with an unknown `sessionId`
   **When** the request is processed
   **Then** the response is 404

4. **Given** the activity endpoint is called without a valid bearer token
   **When** the request is processed
   **Then** the response is 401

## Tasks / Subtasks

- [ ] Task 1: Symfony - internal activity endpoint (AC: 1, 3, 4)
  - [ ] Create `BridgeActivityController` in `api/src/Sessions/Presentation/`
  - [ ] Route: `PATCH /api/v1/internal/sessions/{sessionId}/activity`
  - [ ] Auth: validate `Authorization: Bearer {token}` against `BRIDGE_INTERNAL_TOKEN` env var (bind as `string $bridgeInternalToken` in services.yaml, same pattern as `$centralApiSecret` in `RunnerCallbackController`)
  - [ ] Read `activityType` (string, optional) and `occurredAt` (ISO8601 string, optional) from JSON body
  - [ ] Find `Session` entity; return 404 if missing
  - [ ] Call `SessionLifecycleManager::recordActivity(sessionId, occurredAt)` which sets `lastActivityAt`
  - [ ] Return 200 `{ "data": { "ok": true } }`

- [ ] Task 2: Symfony - `SessionLifecycleManager::recordActivity()` (AC: 1, 3)
  - [ ] Add method to `api/src/Sessions/Application/SessionLifecycleManager.php`
  - [ ] Signature: `recordActivity(string $sessionId, ?\DateTimeImmutable $at): array{found: bool}`
  - [ ] Find session; if not found return `['found' => false]`
  - [ ] Set `session->setLastActivityAt($at ?? new \DateTimeImmutable())`; flush
  - [ ] Add `setLastActivityAt(\DateTimeImmutable $at): void` to `Session` domain entity if not present (check: `lastActivityAt` field exists but setter may be missing)

- [ ] Task 3: Bridge.py - call activity endpoint on game events (AC: 1)
  - [ ] Add `_report_activity(activity_type: str, occurred_at: str)` coroutine to `ArchipelagoClient` (or a dedicated `ActivityReporter` helper) in `bridge/core/ap_client.py`
  - [ ] Call `PATCH {config.symfony_internal_url}/api/v1/internal/sessions/{config.run_id}/activity` using `aiohttp` with `Authorization: Bearer {config.bridge_internal_token}` header
  - [ ] Trigger on: `LocationChecks` packet → `activityType: "check"`, `ReceivedItems` packet → `activityType: "item"`, `PrintJSON` with type `"Hint"` → `activityType: "hint"`
  - [ ] Fire-and-forget (don't block event loop): wrap call in `asyncio.create_task()`, log warning on HTTP error, never raise

- [ ] Task 4: Tests (AC: all)
  - [ ] Symfony functional test: `tests/Functional/BridgeActivityTest.php`
    - [ ] Valid call → 200, `last_activity_at` updated
    - [ ] Missing token → 401
    - [ ] Wrong token → 401
    - [ ] Unknown session → 404
  - [ ] Bridge unit test: `bridge/tests/test_activity_reporter.py`
    - [ ] `LocationChecks` packet → activity call made with `activityType: "check"`
    - [ ] `ReceivedItems` packet → activity call made with `activityType: "item"`
    - [ ] HTTP error in activity call → logged, does not raise

## Dev Notes

### Existing patterns to reuse

**Auth pattern - `X-Internal-Secret` vs Bearer:**
- `RunnerCallbackController` uses `X-Internal-Secret` header + `$centralApiSecret` (env: `CENTRAL_API_SECRET`)
- Story 17.1 requires **bearer token** (`Authorization: Bearer`) using `BRIDGE_INTERNAL_TOKEN`
- Inject `string $bridgeInternalToken` via `services.yaml` binding (same approach as `$centralApiSecret`):
  ```yaml
  # config/services.yaml
  _defaults:
    bind:
      string $bridgeInternalToken: '%env(BRIDGE_INTERNAL_TOKEN)%'
  ```
- Validate: `$request->headers->get('Authorization', '') !== 'Bearer '.$this->bridgeInternalToken`

**Session entity - existing fields (no migration needed for this story):**
`lastActivityAt` already exists in `Session.php:119` as `?\DateTimeImmutable`. Confirm getter/setter exist; add setter if missing.

**`SessionLifecycleManager` location:** `api/src/Sessions/Application/SessionLifecycleManager.php`

**Bridge config access:** `config.bridge_internal_token` (from `BRIDGE_INTERNAL_TOKEN` env), `config.symfony_internal_url`, `config.run_id` - all available via `Config.from_env()`

**Bridge aiohttp pattern (existing):**
```python
# rest.py - aiohttp is already imported; use same session pattern
async with aiohttp.ClientSession() as session:
    async with session.patch(url, json=payload, headers=headers) as resp:
        if resp.status not in (200, 204):
            log.warning("activity report failed: %d", resp.status)
```

**Fire-and-forget pattern (existing in ap_client.py):**
```python
asyncio.create_task(self._report_activity("check", occurred_at))
```

### Bridge file locations
- `bridge/core/ap_client.py` - add `_report_activity()` here
- `bridge/core/config.py` - `Config.bridge_internal_token` already exists (line 22)
- `bridge/tests/test_activity_reporter.py` - new file

### Which packets trigger activity
From `ap_client.py` packet handling:
- `LocationChecks` → player checked a location → `"check"`
- `ReceivedItems` → player received an item → `"item"`
- `PrintJSON` with `data.type == "Hint"` → hint exchanged → `"hint"`

### Quality gates
```bash
# Symfony
php bin/phpunit tests/Functional/BridgeActivityTest.php
vendor/bin/phpstan analyse src/Sessions/ --level=6
vendor/bin/php-cs-fixer fix --dry-run --diff src/Sessions/

# Bridge
python -m pytest bridge/tests/test_activity_reporter.py
```

### References
- `Session.php` entity: `api/src/Sessions/Domain/Session.php:119` (`lastActivityAt`)
- `RunnerCallbackController.php`: `api/src/Sessions/Presentation/RunnerCallbackController.php` (auth pattern)
- `Config.from_env()`: `bridge/core/config.py:24` (`bridge_internal_token`)
- `ArchipelagoClient` packet handling: `bridge/core/ap_client.py`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
