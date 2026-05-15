# Story 20.4: Extract rest.py Route Handlers into Named Coroutines

## Story

**As a** developer,
**I want** each REST route handler in `rest.py` to be a named async function rather than a closure nested inside `create_app`,
**So that** each handler is independently readable, testable in isolation, and fully analysable by mypy.

## Status

todo

## Acceptance Criteria

**AC1:** All route handlers are extracted from the `create_app` closure into module-level `async def` functions. Each handler receives its dependencies explicitly (via parameters or `request.app` storage) — no closure captures remain.

Before writing any code, the implementer must run:
```bash
grep -n "router.add_" bridge/core/rest.py
```
and record the exact route table:

| Method | Path | Handler name |
|--------|------|--------------|
| GET | /health | `health` |
| GET | /state | `get_state` |
| POST | /command | `post_command` |
| GET | /hints | `get_hints` |
| POST | /hints/{location_id} | `request_hint` |
| GET | /reachable | `get_reachable` |
| GET | /locations | `get_item_locations` |
| POST | /save | `post_save` |
| POST | /pause | `post_pause` |
| POST | /resume | `post_resume` |

*(Verify against actual `rest.py` before implementing — the table above is based on the last known state; the router call is authoritative.)*

**AC2:** `create_app` is reduced to a registration function: it instantiates shared objects, stores them on `app`, and wires routes. It contains no handler logic.

**AC3:** If `rest.py` exceeds 300 lines after extraction, handlers are split across files by domain:
- `bridge/core/rest_session.py` — `health`, `get_state`, `post_command`, `post_save`, `post_pause`, `post_resume`
- `bridge/core/rest_hints.py` — `get_hints`, `request_hint`
- `bridge/core/rest_reachable.py` — `get_reachable`, `get_item_locations`
- `bridge/core/rest.py` — `create_app` only (imports and wires the above)

**AC4:** At least 3 handlers have dedicated unit tests in `bridge/tests/test_rest_handlers.py`, each covering:
- A success path (correct request → expected JSON response)
- An error path (missing param, disconnected WS, or unauthorized → expected error JSON + status code)

A route parity test verifies that `create_app` registers **exactly** the routes in the table from AC1 — no more, no fewer:
```python
def test_route_parity():
    app = create_app(MagicMock(), MagicMock())
    registered = {(r.method, r.resource.canonical) for r in app.router.routes()}
    expected = {
        ("GET", "/health"), ("GET", "/state"), ("POST", "/command"),
        ("GET", "/hints"), ("POST", "/hints/{location_id}"),
        ("GET", "/reachable"), ("GET", "/locations"),
        ("POST", "/save"), ("POST", "/pause"), ("POST", "/resume"),
    }
    assert registered == expected
```
*(Adjust the expected set to match the actual routes found in AC1's audit.)*

**AC5:** `mypy bridge/` exits 0, `ruff check bridge/` exits 0, the full existing test suite passes plus the new handler tests.

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Audit actual routes — run `grep -n "router.add_" bridge/core/rest.py` and record the route table
- [ ] Task 3: Identify app-storage keys needed (set during `create_app`, read in handlers)
  - [ ] Define constants: `APP_STATE`, `APP_AP_CLIENT`, `APP_SEMAPHORE`, `APP_COORDINATOR`, `APP_LOG`
- [ ] Task 4: Extract all handlers found in the audit (one task per handler)
- [ ] Task 5: Extract auth helper `_require_internal_auth`
- [ ] Task 6: Split into sub-files if `rest.py` > 300 lines after extraction
- [ ] Task 7: Write `bridge/tests/test_rest_handlers.py`
  - [ ] 7a: `health` — success path
  - [ ] 7b: `post_command` — success path + WS disconnected path
  - [ ] 7c: `request_hint` — success path + invalid `location_id` path
  - [ ] 7d: Route parity test verifying all routes from the AC1 audit
- [ ] Task 8: Verify quality gates — ruff (0), mypy (0), full test suite green

## Dev Notes

### App storage keys

Use `web.AppKey` (aiohttp 3.9+) for typed app storage instead of plain string keys. This lets mypy infer the type when retrieving from `request.app`:
```python
from aiohttp.web import AppKey

APP_STATE: AppKey[StateManager] = AppKey("state")
APP_AP_CLIENT: AppKey[ArchipelagoClient] = AppKey("ap_client")
```

If `AppKey` is not available (older aiohttp), use plain strings with a cast — still better than closures.

### Handler signature

All aiohttp handlers have the same signature:
```python
async def health(request: web.Request) -> web.Response:
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    return web.json_response({"status": "ok", "ws_connected": ap_client.ws_connected})
```

### Auth check duplication

`post_pause` and `post_resume` both inline a token auth check. After extraction, extract this into a helper:
```python
def _require_internal_auth(request: web.Request, token: str) -> web.Response | None:
    auth = request.headers.get("Authorization", "")
    if not token or auth != f"Bearer {token}":
        return web.json_response({"error": "unauthorized"}, status=401)
    return None
```
Returns `None` if auth passes, an error `Response` if not.

### Testing strategy

Use `aiohttp.test_utils.TestClient` / `aiohttp.test_utils.TestServer` with the app created via `create_app(mock_state, mock_ap_client)`. Each test creates a fresh app instance — this is exactly what the module-global state in Story 20.2 prevented.

```python
async def test_health_returns_ok(aiohttp_client):
    state = MagicMock(spec=StateManager)
    ap_client = MagicMock(spec=ArchipelagoClient)
    ap_client.ws_connected = True
    app = create_app(state, ap_client)
    client = await aiohttp_client(app)
    resp = await client.get("/health")
    assert resp.status == 200
    data = await resp.json()
    assert data["status"] == "ok"
    assert data["ws_connected"] is True
```

### Ordering with other stories

Story 20.4 depends on Story 20.2 (coordinator must be injectable) and should be implemented after Story 20.3 (relative imports). The recommended order is 20.1 → 20.2 → 20.3 → 20.4.

## File List

- `bridge/core/rest.py` — reduced to `create_app` + app storage keys + route registration
- `bridge/core/rest_session.py` — new: session-related handlers (if split needed)
- `bridge/core/rest_hints.py` — new: hint handlers (if split needed)
- `bridge/core/rest_reachable.py` — new: reachability handlers (if split needed)
- `bridge/tests/test_rest_handlers.py` — new: 6+ handler unit tests
- `_bmad-output/implementation-artifacts/20-4-bridge-extract-rest-handlers.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
