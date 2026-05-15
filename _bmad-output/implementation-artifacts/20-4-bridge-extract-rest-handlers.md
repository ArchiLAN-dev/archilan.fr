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
| POST | /commands | `post_command` |
| POST | /save | `post_save` |
| POST | /pause | `post_pause` |
| POST | /resume | `post_resume` |
| GET | /hints/{slot} | `get_hints` |
| POST | /hints/{slot}/request | `request_hint` |
| GET | /reachable/{slot} | `get_reachable` |
| GET | /item-locations/{slot} | `get_item_locations` |

*(This table reflects the actual `rest.py` as of 2026-05-15. Always verify with `grep -n "router.add_" bridge/core/rest.py` before implementing — the router call is authoritative.)*

**AC2:** `create_app` is reduced to a registration function: it instantiates shared objects, stores them on `app`, and wires routes. It contains no handler logic.

**AC3:** Handlers are split across files by domain (the split is by responsibility, not by line count — the domain groupings are already clear):
- `bridge/core/rest_keys.py` — `AppKey` constants (`APP_STATE`, `APP_AP_CLIENT`, `APP_SEMAPHORE`, `APP_COORDINATOR`); imported by both handler modules and `rest.py` — kept separate to avoid a circular import (`rest.py` imports handlers; handlers must import the keys)
- `bridge/core/rest_session.py` — `health`, `get_state`, `post_command`, `post_save`, `post_pause`, `post_resume`
- `bridge/core/rest_hints.py` — `get_hints`, `request_hint`
- `bridge/core/rest_reachable.py` — `get_reachable`, `get_item_locations`
- `bridge/core/rest.py` — `create_app` only (imports from `rest_keys`, imports handlers, wires routes — no handler logic, no key definitions)

**AC4:** At least one handler from **each extracted module** has a dedicated behavior test in `bridge/tests/test_rest_handlers.py` — the route parity test verifies route registration only, not handler behaviour. Each behavior test covers:
- A success path (correct request → expected JSON response)
- An error path (missing param, disconnected WS, or unauthorized → expected error JSON + status code)

| Module | Minimum tested handler (must cover success + error) | Additional coverage |
|---|---|---|
| `rest_session.py` | `post_command` (success + WS disconnected → 503) | `health` (success-only, Task 7a) |
| `rest_hints.py` | `request_hint` (success + missing `location_id` → 400) | — |
| `rest_reachable.py` | `get_reachable` (success + error path) | — |

A route parity test verifies that `create_app` registers **exactly** the routes in the table from AC1 — no more, no fewer:
```python
def test_route_parity():
    app = create_app(MagicMock(), MagicMock())
    # aiohttp auto-generates a HEAD route for every GET route — filter them out
    registered = {
        (r.method, r.resource.canonical)
        for r in app.router.routes()
        if r.method != "HEAD"
    }
    expected = {
        ("GET", "/health"), ("GET", "/state"),
        ("POST", "/commands"), ("POST", "/save"), ("POST", "/pause"), ("POST", "/resume"),
        ("GET", "/hints/{slot}"), ("POST", "/hints/{slot}/request"),
        ("GET", "/reachable/{slot}"), ("GET", "/item-locations/{slot}"),
    }
    assert registered == expected
```
*(Adjust the expected set to match the actual routes found in AC1's audit.)*

**AC5:** `mypy bridge/` exits 0, `ruff check bridge/` exits 0, the full existing test suite passes plus the new handler tests.

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Audit actual routes — run `grep -n "router.add_" bridge/core/rest.py` and record the route table
- [ ] Task 3: Create `bridge/core/rest_keys.py` with `AppKey` constants
  - [ ] Define `APP_STATE`, `APP_AP_CLIENT`, `APP_SEMAPHORE`, `APP_COORDINATOR` in `rest_keys.py` — not in `rest.py`; handler modules import from `rest_keys`, `rest.py` also imports from `rest_keys`, avoiding a circular import
  - [ ] Note: logging uses `logging.getLogger("bridge.rest_X")` at module level — no `APP_LOG` key needed
- [ ] Task 4: Extract all handlers found in the audit (one task per handler)
- [ ] Task 5: Extract auth helper `_require_internal_auth`
- [ ] Task 6: Split handlers into `rest_session.py`, `rest_hints.py`, `rest_reachable.py` per AC3 (always — not conditional)
- [ ] Task 7: Write `bridge/tests/test_rest_handlers.py` — at least one behavior test per extracted module
  - [ ] 7a: `health` (`rest_session`) — success path (ws_connected=True → 200 `{"status":"ok","ws_connected":true}`)
  - [ ] 7b: `post_command` (`rest_session`) — success path + WS disconnected path (503)
  - [ ] 7c: `request_hint` (`rest_hints`) on `POST /hints/{slot}/request` — success path + missing `location_id` in body (400) + non-integer `slot` in URL (400)
  - [ ] 7d: `get_reachable` (`rest_reachable`) on `GET /reachable/{slot}` — success path + at least one error path (e.g. unknown slot or disconnected state → appropriate error response)
  - [ ] 7e: Route parity test verifying all routes from the AC1 audit (filter HEAD)
- [ ] Task 8: Verify quality gates — ruff (0), mypy (0), full test suite green

## Dev Notes

### App storage keys

Use `web.AppKey` (aiohttp 3.9+) for typed app storage instead of plain string keys. This lets mypy infer the type when retrieving from `request.app`. This is where Story 20.2's `# type: ignore[assignment]` on `app["coordinator"]` is removed — the string key is replaced by a typed `AppKey`.

**Circular import risk**: `rest.py` imports the handler modules (to wire routes); handler modules must import the `AppKey` constants (to read `request.app`). Defining the constants in `rest.py` would create `rest.py → rest_session.py → rest.py`. The fix is a neutral module with no sibling imports:

```python
# bridge/core/rest_keys.py
from aiohttp.web import AppKey
from .state import StateManager
from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator  # defined in Story 20.2

APP_STATE: AppKey[StateManager] = AppKey("state")
APP_AP_CLIENT: AppKey[ArchipelagoClient] = AppKey("ap_client")
APP_COORDINATOR: AppKey[PauseResumeCoordinator] = AppKey("coordinator")
APP_SEMAPHORE: AppKey[asyncio.Semaphore] = AppKey("semaphore")
```

Both `rest.py` and all handler modules import from `rest_keys.py`. `rest_keys.py` imports only domain types — it never imports `rest.py` or any handler module, so no cycle is possible.

**Logging**: each handler module uses `log = logging.getLogger("bridge.rest_session")` (or `rest_hints`, `rest_reachable`) at module level. The logger is not stored in `app` — no `APP_LOG` key is needed.

### Handler signature

All aiohttp handlers have the same signature. Handler modules import keys from `rest_keys`, never from `rest`:
```python
# bridge/core/rest_session.py
from .rest_keys import APP_AP_CLIENT

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

- `bridge/core/rest_keys.py` — new: `AppKey` constants (`APP_STATE`, `APP_AP_CLIENT`, `APP_SEMAPHORE`, `APP_COORDINATOR`)
- `bridge/core/rest.py` — assembly only: imports from `rest_keys` + handler modules, `create_app`, route wiring (no handler logic, no key definitions)
- `bridge/core/rest_session.py` — new: `health`, `get_state`, `post_command`, `post_save`, `post_pause`, `post_resume`
- `bridge/core/rest_hints.py` — new: `get_hints`, `request_hint`
- `bridge/core/rest_reachable.py` — new: `get_reachable`, `get_item_locations`
- `bridge/tests/test_rest_handlers.py` — new: 6+ handler unit tests
- `_bmad-output/implementation-artifacts/20-4-bridge-extract-rest-handlers.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
