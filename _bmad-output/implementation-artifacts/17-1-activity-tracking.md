# Story 17.1: Activity Tracking on Sessions

**Status:** review
**Epic:** 17 - Session Lifecycle - Inactivity Timeout and Restart
**Date:** 2026-05-12

## Story

As a system operator,
I want sessions to track when they last had Archipelago activity,
So that the inactivity watchdog has a reliable signal for when to pause a run.

## Acceptance Criteria

1. Bridge.py calls `PATCH /api/v1/sessions/{sessionId}/activity` with JSON body `{ "activityType": "check" | "item" | "hint" | "status_update" | "chat", "occurredAt": "<ISO8601>" }` (machine-to-machine bearer token via `BRIDGE_INTERNAL_TOKEN` env). The API updates `sessions.last_activity_at` to `occurredAt` (or server time if `occurredAt` absent). Unknown `activityType` values are accepted and treated as a generic activity signal - no 422.
2. Bridge.py mapping: `LocationChecked` → `activityType: "check"` ; `ItemSent` → `activityType: "item"` ; `Hint` → `activityType: "hint"` ; `ClientStatus` (any value) → `activityType: "status_update"` ; `Chat` → `activityType: "chat"`. All these events constitute activity.
3. When a session transitions to `running` (callback from runner), `last_activity_at` is set to `started_at`. This ensures `last_activity_at` is never NULL for a running session.
4. Unknown `sessionId` → 404. Missing or invalid bearer token → 401.
5. Doctrine migration adds `last_activity_at TIMESTAMPTZ DEFAULT NULL` to `sessions`.

## Tasks / Subtasks

- [x] Task 1: DB migration (AC: 3, 5)
  - [x] `ALTER TABLE sessions ADD last_activity_at TIMESTAMPTZ DEFAULT NULL`
  - [x] On session start callback (transition to `running`): set `last_activity_at = started_at`

- [x] Task 2: Activity endpoint (AC: 1, 2, 4)
  - [x] `PATCH /api/v1/sessions/{sessionId}/activity` in `SessionActivityController`
  - [x] Machine-to-machine auth: static bearer token compared against `BRIDGE_INTERNAL_TOKEN` env var
  - [x] Symfony firewall pattern for this route uses a custom authenticator (not user JWT)
  - [x] Accept any `activityType` string; update `last_activity_at` regardless
  - [x] Use `occurredAt` from payload if present and valid ISO8601; fall back to `new \DateTimeImmutable()` server time

- [x] Task 3: Bridge.py update (AC: 2)
  - [x] On each of the 5 mapped event types: call `PATCH /api/v1/sessions/{sessionId}/activity`
  - [x] Pass `BRIDGE_INTERNAL_TOKEN` in `Authorization: Bearer` header
  - [x] Fire-and-forget (non-blocking, no retry - best-effort tracking)

- [x] Task 4: Tests
  - [x] Functional: valid activity update with occurredAt (last_activity_at = occurredAt), valid without occurredAt (last_activity_at ≈ now), unknown activityType accepted (200), started_at backfill on run start, unknown sessionId (404), missing token (401)

## Dev Notes

- **camelCase throughout**: all JSON fields use camelCase (`activityType`, `occurredAt`) - consistent with the existing API convention.
- `last_activity_at` is initialised to `started_at` (not `null`) when a session goes `running`. This guarantees the watchdog query `last_activity_at < NOW() - interval` always finds a meaningful value for running sessions.
- Applies to BOTH event sessions and personal run sessions (same `sessions` table).
- The `PersonalRunDrafts::payload()` must expose `lastActivityAt` (camelCase) so the frontend can compute "inactive since" duration.

### References

- `api/src/Sessions/` - existing session domain (Epic 9)
- `bridge/core/rest.py` - Bridge REST client (existing HTTP call patterns)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Task 1 (migration + backfill): `last_activity_at` column was already added in `Version20260507120000.php`. `Session::transition()` already sets `$this->lastActivityAt = $now` at the top (before the RUNNING-specific block), so `lastActivityAt == startedAt` on RUNNING transition. Domain model already correct, no new migration needed.
- Task 2 (endpoint): In-controller bearer token check (same pattern as heartbeat/callback controllers). `BRIDGE_INTERNAL_TOKEN` bound globally via `services.yaml`. `activityType` is stored in-payload but not persisted - only `last_activity_at` is updated. Unknown `activityType` values are accepted with no 422.
- Task 3 (bridge): Added `bridge_internal_token` field to `Config` (from env `BRIDGE_INTERNAL_TOKEN`). Added `http: aiohttp.ClientSession | None` param to `ArchipelagoClient.__init__`. `_report_activity()` is a fire-and-forget coroutine wrapped in `asyncio.create_task()`. Hooked into: `LocationChecks → "check"`, `PrintJSON/ItemSend → "item"`, `PrintJSON/Hint → "hint"`, `PrintJSON/Chat → "chat"`, `StatusUpdate → "status_update"`.
- `getStartedAt()` getter added to `Session.php` (needed by test for AC 3 verification).
- Pre-existing failure: `SessionLifecycleTest::testTransitionToRunningDispatchesOneMessagePerRegistrant` - unrelated "no such table: events" error (setUp() missing Event schema). Zero new test failures introduced.
- 7 new functional tests pass; all 104 bridge Python tests pass.

### Debug Log

- PHPStan level 8: `$request->headers->get('Authorization', '')` returns `string|null`. Fixed with `?? ''` coalescing.

### File List

- `api/src/Sessions/Domain/Session.php` (modified - added `recordActivity()`, `getStartedAt()`)
- `api/src/Sessions/Application/SessionLifecycleManager.php` (modified - added `recordActivity()`)
- `api/src/Sessions/Presentation/SessionActivityController.php` (new)
- `api/config/services.yaml` (modified - added `bridgeInternalToken` global binding)
- `api/.env.test` (modified - added `BRIDGE_INTERNAL_TOKEN=test-bridge-token`)
- `api/tests/Functional/SessionActivityTest.php` (new - 7 tests)
- `bridge/core/config.py` (modified - added `bridge_internal_token` field)
- `bridge/core/ap_client.py` (modified - added `http` param, `_report_activity()`, 5 event hooks)
- `bridge/bridge.py` (modified - passes `http` to `ArchipelagoClient`)

### Change Log

- 2026-05-12: Story 17.1 implemented - PATCH activity endpoint with bearer token auth, bridge fire-and-forget activity reporting on 5 AP event types, 7 functional tests.
