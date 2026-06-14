# Story 9.30: Paid Hints via Connect-as-Slot (player-charged)

Status: review

## Story

As a player (or admin) using the **paid** hint buttons on a slot's session-tracking page,
I want the bridge to create the hint **as that slot** so the cost is debited from **that slot's** AP hint points,
So that a paid hint behaves like the player typing `!hint <item>` / `!hint_location <location>` in their own client — for any slot, not only the bridge's connected slot.

## Context

The bridge connects to the AP server as a **single** `TextOnly` observer slot ("Bridge", slot 1) over one WebSocket — see `ap_client._connect_and_run`. Two hint paths exist on the slot pages:

- **Gratuit (admin)** (`hintFree = true`): handled by stories 9.28/9.29 — the bridge sends `!admin /hint_location <player> <location>` on its own connection. Admin hints are **free/priority**, they do not spend any player's points. ✅
- **Payant** (`hintFree = false`): currently broken.
  - `handleHintLocation` → `POST /sessions/{id}/slots/{slot}/hints/request {location_id, free:false}` → bridge `request_hint`, which **also** sends the `!admin` command (admin = free), so nothing is actually charged; it only bumps `hints_used` locally.
  - `handleHintItem` → builds `!hint<item>` **client-side** and relays it through `POST /admin/sessions/{id}/commands`. `/hint` is a **self-hint** AP resolves against the *connected* slot — i.e. the **Bridge** slot, not the player's — so it fails or hints the wrong world, and never charges the player.

In Archipelago a **paid** self-hint (`!hint <item>` or `!hint_location <location>`) is charged to and resolved against the **currently-connected slot**. To charge slot *N*'s points the command must be sent on a connection **authenticated as slot N**. AP slots have **no individual password** — connecting uses the **server (room) password** (`ap_server_password`, **not** the admin password) — and AP **allows multiple simultaneous connections to the same slot**, so the bridge can open a second connection as slot N — even while the real player client is connected — without disrupting them. The ephemeral connection does **no `!admin login`** at all (admin is the *free* path).

### Approach: ephemeral connect-as-slot (decided)

For a paid hint the bridge opens a **short-lived** second WebSocket, `Connect`s as the target slot (`name = slot_name(N)`, `game = slot_game(N)`, `TextOnly`, `items_handling: 0`, **`password = ap_server_password`**), sends the single self-hint `Say`, **reads the server reply to confirm**, then closes. The created hint reaches the UI via the live data-storage path (story 9.27) on the **main** connection — no optimistic local add. An ephemeral connection (vs. a persistent per-slot pool) keeps state minimal and avoids long-lived sockets per slot.

**Decided with the user (2026-06-13):**
1. **Ephemeral** connections (open → hint → close), not a persistent per-slot pool.
2. Ack detection must be **100% reliable** — block on the authoritative server reply (hint-created `PrintJSON` vs. explicit failure), never a "probably worked" timeout guess. Timeout = transport failure only.
3. Connect with the **server (room) password** (`ap_server_password`), **never** the admin password / `!admin login`. Authz policy on the API endpoint is not a priority (keep the current guard).

> The bridge knows each slot's name/game from `slot_info` (`_store._slot_games`, slot names). Connecting needs the slot **name**, not the alias — confirm the source field before implementing (`slot_info[n].name`).

## Acceptance Criteria

1. **Given** a paid location hint is requested for `(slot N, locationId)` **When** the bridge handles it **Then** it opens an ephemeral connection **as slot N**, sends `!hint_location <location_name>` (location resolved from the DataPackage, no `!admin`), and closes the connection — so slot N's hint points are debited by the AP server.

2. **Given** a paid item hint is requested for `(slot N, itemName)` **When** the bridge handles it **Then** it opens an ephemeral connection **as slot N** and sends `!hint<itemName>` (self-hint), charging slot N's points.

3. **Given** the ephemeral connection is used **When** the hint completes (or times out) **Then** it is closed and the **main** bridge connection and any real player client connected as slot N are unaffected (no disconnect, no command leakage onto the main socket).

4. **Given** slot N has **insufficient** hint points (server rejects the hint) **When** the bridge reads the server's reply **Then** it returns a non-2xx (`409`/`502`) with a clear reason and does **not** increment `hints_used`. Ack detection must be **deterministic**: the bridge keeps the ephemeral connection open until it reads an authoritative server reply — the hint-created `PrintJSON` **or** the explicit failure/`error`/"not enough points" message — and only the timeout (connection/protocol failure, not "waited long enough and guessed") maps to `502`.

5. **Given** the paid hint succeeds **Then** the created Hint reaches the slot page within ~1s via the data-storage path (story 9.27) — no optimistic add — and `hints_used` is incremented once.

6. **Given** the **free** (admin) path **When** either hint button is used in admin mode **Then** behaviour is unchanged (stories 9.28/9.29 — `!admin /hint_location …`).

7. **Frontend:** **Given** `hintFree = false` **When** the user clicks an item hint **Then** the site calls the bridge **item** request endpoint (no longer builds `!hint<item>` client-side and relays it through `/admin/sessions/{id}/commands`). The location button already routes through `/hints/request`.

8. **Authz:** **Given** the paid request endpoint **Then** the existing guard is kept as-is for now (policy not a priority — keep `requireAuthenticatedAdmin`). Relaxing to slot-owner is out of scope for this story.

## Tasks / Subtasks

- [x] Task 1 — Bridge: ephemeral connect-as-slot helper (AC: #1, #2, #3, #4)
  - [x] 1.1 `ArchipelagoClient.run_self_hint(slot, command) -> SelfHintOutcome`: new `websockets.connect`, `Connect` as the slot (name/game/`TextOnly`/`items_handling:0`/**server password**), await `Connected`, send the `Say`, read the authoritative reply, close.
  - [x] 1.2 Resolve slot **name** (`DataPackageStore.slot_name`, from `slot_info[n].name`) + **game**; `unknown_slot` → 422 if missing.
  - [x] 1.3 Deterministic ack: `Hint` PrintJSON involving the slot → ok; failure-marker text reply → `rejected` (409); transport timeout → 502.
- [x] Task 2 — Bridge: route paid requests through the new helper (AC: #1, #2, #5)
  - [x] 2.1 `request_hint` (location): `free=False` → `!hint_location <location_name>` via `run_self_hint`; `free=True` keeps the admin path.
  - [x] 2.2 New `POST /slots/{slot}/hints/request-item {itemName, free}` → `run_self_hint(slot, "!hint<item>")`; `free=True` → `!admin /hint <player> <item>`.
  - [x] 2.3 `hints_used` bumped only on success; UI hint push via story 9.27 (no optimistic add).
- [x] Task 3 — API (Symfony): proxy (AC: #7)
  - [x] 3.1 `POST /api/v1/sessions/{sessionId}/slots/{slotIndex}/hints/request-item` in `PlayerStateController`, proxying via `BridgeClientPool`. `requireAuthenticatedAdmin` kept (AC #8).
  - [x] 3.2 `SlotsClient::requestHintItem` + `HintItemOkResponse` DTO added to the **`archilan/bridge-client` package** (see Dev Notes — package gate run; local `vendor/` synced pending the package PR + `composer update`).
- [x] Task 4 — Frontend: route the paid item path through the bridge (AC: #7)
  - [x] 4.1 `personal-run-slot-detail-page.tsx` + `admin-slot-reachability-page.tsx`: `handleHintItem` paid branch → `POST …/hints/request-item {itemName, free:false}`; the `hintFree` admin branch kept on `/admin/.../commands`.
  - [~] 4.2 Fetch kept inline in the handler to match the file's existing handlers (`handleHintLocation`, `sendBridgeCommand` all use inline `apiFetch`). Extraction to `*-api.ts` deferred to avoid an inconsistent one-off; noted as follow-up.
- [x] Task 5 — Tests
  - [x] 5.1 Bridge unit (`test_self_hint.py`): `Connect` name/game/server-password, `Say` verbatim; success / rejected / unknown_slot / refused.
  - [x] 5.2 Bridge REST (`test_rest_handlers.py`): paid location + item call `run_self_hint` (not `send_admin_command`); free item still uses `send_admin_command`; 409 on rejection; 422 empty item; 503 ws down.
  - [~] 5.3 API functional: not added — the sibling `requestHint` proxy has no functional test and there's no existing bridge-mock harness for this controller; followed the existing convention.
- [x] Task 6 — Quality gates
  - [x] 6.1 Bridge: ruff ✓ / mypy ✓ / pytest 163 ✓
  - [x] 6.2 API: phpstan ✓ / php-cs-fixer ✓ / phpunit 1015 ✓ / ddd ✓ — bridge-client package: PHPStan level 9 ✓
  - [x] 6.3 Frontend: typecheck ✓ / lint ✓ / build ✓

## Dev Notes

### Why a second connection (not the main socket)

The main socket is authenticated as the **Bridge** TextOnly slot. Sending `!hint<item>` there charges/resolves against the **Bridge** slot — wrong world, wrong points. AP binds hint cost and self-hint resolution to the connected slot, and there is no per-message "act as slot N". Hence a connection authenticated as slot N. AP permits multiple connections per slot, so this coexists with the player's real client.

### Free vs paid — the decisive difference

| Mode | Command                                       | Connection | Charged? |
|------|-----------------------------------------------|------------|----------|
| Gratuit (admin) | `!admin /hint_location <player> <location>`   | main (Bridge) | No (admin/priority) |
| Payant | `!hint_location <location>` or `!hint <item>` | **ephemeral, as slot N** | Yes (slot N points) |

### Connect packet (reuse from `_connect_and_run`)

```python
connect_packet = {
    "cmd": "Connect",
    "name": slot_name,            # slot N's name (not alias) — verify slot_info field
    "game": slot_game,            # slot N's game
    "password": self._config.ap_server_password,
    "uuid": str(uuid.uuid4()),
    "version": {"major": 0, "minor": 6, "build": 7, "class": "Version"},
    "tags": ["TextOnly"],
    "items_handling": 0,
    "slot_data": False,
}
```

### Design decisions (resolved 2026-06-13)

1. **Ephemeral** connect-as-slot (not persistent pool).
2. **100% reliable ack** — the ephemeral connection stays open until the server's authoritative reply (hint created vs. explicit failure) is read; only a transport/protocol timeout is treated as an error. No optimistic "assume success after N ms".
3. **Server (room) password** for the `Connect` (`ap_server_password`), no `!admin login`. API-endpoint authz left as-is (not a priority).

### References

- [Source: bridge/core/ap_client.py:326] — `_connect_and_run` Connect packet to mirror
- [Source: bridge/core/ap_client.py:305] — `send_command` (`Say`) / `send_admin_command`
- [Source: bridge/core/rest_hints.py:38] — `request_hint` (location; paid branch to change)
- [Source: bridge/core/ap_client.py:78] — `_store._slot_games` / slot name source for Connect
- [Source: api/src/Sessions/Presentation/PlayerStateController.php:263] — `requestHint` proxy + authz
- [Source: frontend/.../personal-run-slot-detail-page.tsx:545] — `handleHintItem`
- [Source: frontend/.../admin/admin-slot-reachability-page.tsx] — `handleHintItem`
- Story 9.27 — data-storage hint push (how the created hint reaches the UI)
- Stories 9.28 / 9.29 — the free/admin path (unchanged here)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- **Cross-repo:** the API proxy needs `SlotsClient::requestHintItem`, which lives in the standalone
  `archilan/bridge-client` package (`packages/bridge-client/`, its own git repo + gates). Per
  `packages/CLAUDE.md` a package gap is normally a dedicated story/PR; done here within 9.30 on the
  user's explicit go-ahead. The package change passes its own PHPStan level 9. The local
  `api/vendor/` copy was synced so the API builds/tests locally — the durable step is committing the
  package change in its repo, bumping the version, and `composer update` in `api/`.
- **Authz (AC #8):** `requireAuthenticatedAdmin` kept on both paid endpoints. So today the paid path
  is exercised by an admin viewing the page with the hint toggle set to "Payant"; opening it to the
  slot owner (so non-admin players can spend their own points) is a deliberate follow-up.
- **Ack reliability:** success = a `Hint` PrintJSON whose `receiving`/`item.player` matches the slot;
  rejection = a text PrintJSON matching `_HINT_FAILURE_MARKERS` (not enough points, unknown item…).
  Only a transport-level timeout maps to 502 — never an optimistic "assume success".

### File List

**bridge/** (separate repo)
- `core/ap_client.py` — `SelfHintOutcome`, `run_self_hint` + ack helpers; `DataPackageStore` slot-name storage + `slot_name()`
- `core/rest_hints.py` — paid location branch via `run_self_hint`; new `request-item` endpoint; `_raise_for_self_hint`
- `core/schemas.py` — `HintItemRequest`, `HintItemOkResponse`
- `tests/test_self_hint.py` — new; `tests/test_rest_handlers.py` — paid location/item REST tests

**packages/bridge-client/** (separate repo)
- `src/Slots/SlotsClient.php` — `requestHintItem`
- `src/Slots/Response/HintItemOkResponse.php` — new DTO

**api/** (monorepo)
- `src/Sessions/Presentation/PlayerStateController.php` — `requestHintItem` route
- `vendor/archilan/bridge-client/...` — local sync (gitignored; pending package release)

**frontend/** (monorepo)
- `src/features/admin/admin-slot-reachability-page.tsx` — `handleHintItem` paid path
- `src/features/personal-runs/personal-run-slot-detail-page.tsx` — `handleHintItem` paid path
