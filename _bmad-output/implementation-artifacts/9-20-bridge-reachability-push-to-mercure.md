# Story 9.20: Bridge → Mercure Reachability Push

Status: review

## Story

As an admin watching the slot detail page,
I want the reachability data to update in real-time when checks are completed,
So that I can see the live progression without having to refresh the page.

## Acceptance Criteria

1. **Given** the bridge computes a new (non-cached) reachability result for a slot **When** the computation finishes **Then** the full reachability payload is published to the Mercure topic `runs/{sessionId}/slots/{slotIndex}/reachable` within 1 second.

2. **Given** the admin slot detail page is open **When** a player sends a check **Then** the checks/items/spheres update automatically within ~10 seconds (bridge recompute + push latency), without page refresh.

3. **Given** the `central_api_url` or `central_api_secret` is not configured **When** the sweep loop runs **Then** the push is silently skipped — no error, no crash, existing behavior unchanged.

4. **Given** the Symfony push endpoint is called **When** the `X-Internal-Secret` header doesn't match **Then** it returns HTTP 401 and the bridge logs a warning.

5. **Given** the push endpoint receives a valid payload **When** Mercure publish succeeds **Then** it returns `{"data": {"ok": true}}` and the SSE subscriber on the frontend receives the full ReachabilityData object.

## Tasks / Subtasks

- [x] Task 1 — Bridge: add push-to-API after non-cached reachability compute (AC: #1, #3)
  - [x] 1.1 Add `central_api_url: str = ""` and `central_api_secret: str = ""` params to `_reachable_sweep_loop` signature in `bridge/core/loops.py`
  - [x] 1.2 Implement `_push_reachable_to_api()` async helper in `loops.py`
  - [x] 1.3 Call `_push_reachable_to_api()` after `broadcast("reachable_changed", ...)` when not cached
  - [x] 1.4 Pass `config.central_api_url` and `config.central_api_secret` in `bridge/bridge.py`

- [x] Task 2 — Symfony API: new internal push endpoint (AC: #4, #5)
  - [x] 2.1 Create `api/src/Sessions/Presentation/ReachablePushController.php`

- [x] Task 3 — Quality gates
  - [x] 3.1 Bridge: ruff → 0 errors
  - [x] 3.2 Bridge: mypy → 0 errors (23 source files)
  - [x] 3.3 Bridge: pytest → 168 passed
  - [x] 3.4 API: phpstan → 0 errors
  - [x] 3.5 API: php-cs-fixer → 0 violations
  - [x] 3.6 API: phpunit → 919 tests, 7205 assertions (4 notices pré-existantes, 0 failure)
  - [x] 3.7 API: ddd → exit 0

## Dev Notes

### Root Cause

The admin slot detail page (`AdminSlotReachabilityPage`) opens a Mercure SSE connection on topic `runs/{sessionId}/slots/{slotIndex}/reachable` (token from `GET /api/v1/sessions/{sessionId}/slots/{slotIndex}/reachable-token`).

The bridge computes reachability in `_reachable_sweep_loop` (`bridge/core/loops.py:38`) and currently only calls:
```python
await broadcast("reachable_changed", {...})  # → WS clients only
```
Nobody ever publishes to the Mercure topic. The frontend waits forever.

---

### Bridge — `loops.py` changes

**New helper** (add before `_reachable_sweep_loop`):

```python
async def _push_reachable_to_api(
    session_id: str,
    slot_id: int,
    result: dict[str, Any],
    central_api_url: str,
    central_api_secret: str,
    log: logging.Logger,
) -> None:
    if not central_api_url or not central_api_secret:
        return
    url = f"{central_api_url.rstrip('/')}/api/v1/internal/sessions/{session_id}/slots/{slot_id}/reachable-push"
    headers = {"X-Internal-Secret": central_api_secret}
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.post(url, json=result, headers=headers)
            if resp.status_code not in (200, 204):
                log.warning("reachable push: unexpected status %d for slot %d", resp.status_code, slot_id)
    except Exception as exc:
        log.warning("reachable push error slot %d: %s", slot_id, exc)
```

**Updated loop signature:**

```python
async def _reachable_sweep_loop(
    state: StateManager,
    broadcast: BroadcastFn,
    session_id: str,
    semaphore: asyncio.Semaphore,
    recompute_event: asyncio.Event,
    runtime: Any = None,
    central_api_url: str = "",
    central_api_secret: str = "",
) -> None:
```

**Updated call site** (after `broadcast("reachable_changed", ...)`):

```python
if not result.get("cached"):
    await broadcast("reachable_changed", {
        "sessionId": session_id,
        "slot": slot_id,
        "reachableNow": ps.reachable_now if ps else 0,
    })
    await _push_reachable_to_api(
        session_id, slot_id, result,
        central_api_url, central_api_secret, log,
    )
```

**`bridge.py` call site** (line ~105):
```python
_sweep_task = asyncio.create_task(
    _reachable_sweep_loop(
        state, ws_server.broadcast, config.session_id,
        reachable_semaphore, recompute_event,
        runtime=runtime,
        central_api_url=config.central_api_url,
        central_api_secret=config.central_api_secret,
    )
)
```

---

### Symfony API — `ReachablePushController.php`

Follow the exact same pattern as `HeartbeatController`:

```php
<?php
declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ReachablePushController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private HubInterface $mercureHub,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/slots/{slotIndex}/reachable-push', methods: ['POST'])]
    public function push(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');
        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        $payload = $request->toArray();
        $topic = sprintf('runs/%s/slots/%d/reachable', $sessionId, $slotIndex);

        try {
            $this->mercureHub->publish(new Update(
                $topic,
                json_encode($payload, \JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable) {
            // Non-fatal: SSE clients will get the data on next recompute
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
```

**Important:** The Mercure topic used here (`runs/{sessionId}/slots/{slotIndex}/reachable`) must match exactly what `reachable-token` endpoint subscribes to (line 140 of `PlayerStateController.php`):
```php
$topic = 'runs/'.$runId.'/slots/'.$slotIndex.'/reachable';
```
✅ They match.

---

### Frontend expectation

The SSE handler in `AdminSlotReachabilityPage` (line ~188):
```typescript
es.onmessage = (event) => {
    const data = JSON.parse(event.data) as ReachabilityData;
    if (!data.counts) return;  // ← must have counts
    setState({ kind: "data", data });
```

The bridge's `_compute_reachable()` result already contains `counts`, `reachable_unchecked`, `reachable_checked`, `unreachable_unchecked`, etc. This is the same structure returned by `GET /reachable/{slot}`. No transformation needed.

---

### No new config needed

`central_api_url` and `central_api_secret` are already in the bridge `Config` and injected into the bridge container via Docker env vars. No new env vars required.

---

### Files to modify

**Bridge:**
- `bridge/core/loops.py` — new helper + updated signature + call
- `bridge/bridge.py` — pass new params to `_reachable_sweep_loop`

**Symfony API:**
- `api/src/Sessions/Presentation/ReachablePushController.php` — new file

### References

- [Source: bridge/core/loops.py:38] — `_reachable_sweep_loop`, insertion points
- [Source: bridge/core/loops.py:146] — `_api_heartbeat_loop` pattern for `httpx` call
- [Source: bridge/bridge.py:104] — `_sweep_task` creation, pass new params
- [Source: api/src/Sessions/Presentation/HeartbeatController.php] — auth pattern to replicate
- [Source: api/src/Sessions/Presentation/PlayerStateController.php:140] — Mercure topic format
- [Source: api/src/Communications/Application/SessionRunningHandler.php] — HubInterface + Update publish pattern
- [Source: frontend/src/features/admin/admin-slot-reachability-page.tsx:186] — SSE handler, `data.counts` guard

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
