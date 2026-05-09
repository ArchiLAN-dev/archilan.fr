# Story 9.14: Player Progress Dashboard

Status: review

## Story

As a confirmed player or admin,
I want a real-time player progress dashboard showing each slot's checks, items, and connection status,
So that I can monitor the overall session at a glance without reading the event feed.

## Acceptance Criteria

1. `GET /api/v1/sessions/{runId}/players` - auth required (confirmed registrant or admin); Symfony proxies the request to `GET http://{session.host}:{session.bridgePort}/state` on Bridge.py and returns the current player aggregate JSON for initial page load.
2. `GET /api/v1/sessions/{runId}/players-token` - same auth rules as feed-token (Story 9.13); returns a subscriber JWT (TTL 1h) scoped to topic `runs/{runId}/players` only, plus the hub URL.
3. Non-registrant / unauthenticated callers receive 403 from both endpoints.
4. On page load: call `/players` first to render the initial grid; then subscribe to `runs/{runId}/players` via EventSource for live updates.
5. Each slot card shows: player display name, game name, slot name, checks done (X / Total), items received count, client status badge.
6. ClientStatus labels: UNKNOWN(0) → "Hors ligne", CONNECTED(5) → "Connecté", READY(10) → "Prêt", PLAYING(20) → "En jeu", GOAL(30) → "Objectif atteint !".
7. Slots with GOAL (30) display a distinct visual indicator (accent color border + checkmark icon).
8. Slots sorted: GOAL slots first ordered by `goal_reached_at` ascending, then non-GOAL slots by `checks_done` descending.
9. Grid updates in real time via Mercure without page refresh.
10. Disconnected indicator with 5-second auto-reconnect (same pattern as feed in Story 9.13).
11. Functional tests: `/players` proxy returns Bridge.py state; `/players-token` - registrant ✅, non-registrant ❌, admin ✅; JWT topic scope is `runs/{runId}/players`; real-time update on state change.

## Tasks / Subtasks

- [x] Task 1: API - `PlayerStateController` (AC: #1, #2, #3, #11)
  - [x] Created `src/Sessions/Presentation/PlayerStateController.php`
  - [x] Route `GET /api/v1/sessions/{runId}/players` - auth via `isAuthorized()` (admin OR confirmed registrant); proxy to `http://{host}:{bridgePort}/state`; returns `{"data": {<bridge state>}}`
  - [x] Route `GET /api/v1/sessions/{runId}/players-token` - same auth; subscriber JWT scoped to `runs/{runId}/players` (1h TTL)
  - [x] Bridge.py unreachable → 503 `bridge_unavailable` (also when host/bridgePort null)

- [x] Task 2: Add `HttpClientInterface` to PlayerStateController (AC: #1)
  - [x] `HttpClientInterface` injected; timeout 3s; `symfony/http-client` confirmed in composer.json
  - [x] `MockHttpClient` bound as `HttpClientInterface` in `when@test` of `services.yaml`

- [x] Task 3: Functional tests for `/players` and `/players-token` (AC: #11)
  - [x] Created `tests/Functional/PlayerStateTest.php`
  - [x] `testPlayersProxyReturnsState` - MockHttpClient returns Bridge.py JSON → 200 + correct slots
  - [x] `testPlayersReturns503WhenBridgeUnreachable` - callable throws TransportException → 503
  - [x] `testPlayersReturns403ForNonRegistrant` - 403
  - [x] `testPlayersTokenAllowsRegistrant` - 200 + correct topic
  - [x] `testPlayersTokenForbidsNonRegistrant` - 403
  - [x] `testPlayersTokenAllowsAdmin` - 200 + correct topic
  - [x] 6 tests, 40 assertions; 481/481 total PHP suite

- [x] Task 4: Frontend - player grid component (AC: #4, #5, #6, #7, #8, #9, #10)
  - [x] Created `frontend/src/components/session/PlayerProgressGrid.tsx`
  - [x] On mount: fetches `/players` (initial state) then `/players-token` → EventSource
  - [x] On SSE message: updates `slots` state in-place
  - [x] SlotCard: slot_name, checks_done/checks_total progress bar, items_received, status badge
  - [x] Status labels/colors: UNKNOWN→muted, CONNECTED→gray-400, READY→yellow-500, PLAYING→blue-500, GOAL→green-500
  - [x] GOAL card: `ring-2 ring-green-500/30` border + `CheckCircle2` icon
  - [x] Sort: GOAL first (by goal_reached_at asc), then by checks_done desc
  - [x] Disconnect indicator WifiOff + 5s reconnect

- [x] Task 5: Integrate into session pages (AC: #4)
  - [x] Player page: `PlayerProgressGrid` embedded in `session-connection-gate.tsx` (above feed)
  - [x] Admin page: `PlayerProgressGrid` embedded in `SessionDetail` in `admin-session-page.tsx` (above feed)

## Dev Notes

### HTTP Client Proxy Pattern

```php
use Symfony\Contracts\HttpClient\HttpClientInterface;

$response = $this->httpClient->request('GET', sprintf('http://%s:%d/state', $host, $bridgePort), ['timeout' => 3]);
$data = $response->toArray();
return new JsonResponse(['data' => $data]);
```

In tests, use `MockHttpClient`:
```yaml
# config/services_test.yaml or when@test in services.yaml:
Symfony\Contracts\HttpClient\HttpClientInterface:
    class: Symfony\Component\HttpClient\MockHttpClient
```

Or inject a `MockHttpClient` with a `MockResponse` in the test directly.

### Subscriber JWT for Players Topic

Same pattern as Story 9.13's feed-token, scoped to `runs/{runId}/players`:
```php
$token = $this->hub->getFactory()->create(subscribe: ["runs/{$runId}/players"]);
```

### Session Field Access

`session->getBridgePort()` - added in Story 9.6. Verify field exists before implementing this story; if Story 9.6 is not yet implemented, bridge_port will be null and the proxy will fail gracefully (503).

### Bridge.py `/state` Response (from Story 9.12)

```json
{
  "slots": {
    "1": {"slot_name": "Alice_HK1", "checks_done": 12, "checks_total": 47, "items_received": 8, "client_status": 20, "goal_reached_at": null},
    "2": {"slot_name": "Bob_LttP", "checks_done": 47, "checks_total": 47, "items_received": 15, "client_status": 30, "goal_reached_at": "2026-05-05T14:32:00Z"}
  }
}
```

The frontend receives this as-is and renders it directly.

### Auth Check Reuse

The registrant check from `FeedTokenController` (Story 9.13) can be extracted to a shared service `SessionAccessChecker` to avoid duplication between `feed-token`, `players`, and `players-token`. Create `src/Sessions/Application/SessionAccessChecker.php` if reuse makes sense.

### References

- `src/Sessions/Presentation/FeedTokenController.php` (Story 9.13) - auth pattern to follow
- `src/Realtime/Presentation/RealtimeController.php` - subscriber JWT pattern
- Story 9.12 `GET /state` spec - Bridge.py response format
- Story 9.6 - `Session.bridgePort` field added there

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `MockHttpClient` bound globally as `HttpClientInterface` in tests - safe because all other HTTP-dependent services (IgdbHttpClient, TwitchApiClient) are already replaced by stubs in the test environment.

### Completion Notes List

- Auth helper `isAuthorized()` is private to `PlayerStateController` (not extracted to shared service) since the story doesn't mandate it and avoiding premature extraction is preferred.
- `MockHttpClient::setResponseFactory()` accepts a callable; throwing `TransportException` from the callable propagates through `request()` and is caught by the controller's `\Throwable` catch → returns 503.
- Null `host` or `bridgePort` (session not yet running) also returns 503 `bridge_unavailable`.
- Frontend `PlayerProgressGrid` handles gracefully when `/players` returns 4xx (session not running yet) by starting with an empty slots map; Mercure updates will populate it once Bridge.py starts publishing.

### File List

- `api/src/Sessions/Presentation/PlayerStateController.php` (new)
- `api/tests/Functional/PlayerStateTest.php` (new)
- `api/config/services.yaml` (modified - `when@test`: MockHttpClient public, bound as HttpClientInterface)
- `frontend/src/components/session/PlayerProgressGrid.tsx` (new)
- `frontend/src/features/events/session-connection-gate.tsx` (modified - imports and embeds PlayerProgressGrid)
- `frontend/src/features/admin/admin-session-page.tsx` (modified - imports and embeds PlayerProgressGrid in SessionDetail)
