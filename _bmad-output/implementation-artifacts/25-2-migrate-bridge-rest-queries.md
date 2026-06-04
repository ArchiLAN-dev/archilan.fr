# Story 25.2 — Migrate PlayerStateController bridge REST queries to BridgeClientPool

**Epic:** 25 — Intégration des clients PHP (orchestrateur-client, bridge-client-bundle)  
**Branch:** `chore/ci-coverage`  
**Status:** Done

---

## Context

`PlayerStateController` contained 5 endpoints that called the bridge over raw `HttpClientInterface`.
Three of them — hints, requestHint, itemLocations — could be migrated to `BridgeClientPool`
(from `archilan/bridge-client-bundle`). Two remain on raw HTTP due to package-level gaps.

---

## Acceptance Criteria

- **AC1 (Done):** `archilan/bridge-client-bundle` added to `api/composer.json` (path repo `../packages/bridge-client-bundle`, constraint `*@dev`).
- **AC2 (Done):** `ArchiBridgeBundle` registered in `api/config/bundles.php`.
- **AC3 (Done):** `api/config/packages/archi_bridge.yaml` created wiring `admin_token` to `%env(BRIDGE_INTERNAL_TOKEN)%`.
- **AC4 (Done):** `slotHints()` migrated to `BridgeClientPool` + `SlotsClient::hints()` with manual serialization (remaps `receivingSlot`→`receivingPlayer`, `findingSlot`→`findingPlayer`, reconstructs `statusName` from `HintStatus::label()`).
- **AC5 (Done):** `requestHint()` migrated to `BridgeClientPool` + `SlotsClient::requestHint()`.
- **AC6 (Done):** `slotItemLocations()` migrated to `BridgeClientPool` + `SlotsClient::itemLocations()` with manual serialization (remaps `findingSlot`→`findingPlayer`).
- **AC7 (Done):** `players()` and `slotReachable()` kept on raw HTTP with `// BRIDGE CLIENT GAP` comments.
- **AC8 (Done):** All 4 quality gates green.

---

## Known gaps

### `players()` — `/state` endpoint not in bridge-client

`BridgeClient` does not expose `/state`. That endpoint returns the full AP game state dict in
snake_case and is served directly as-is to the frontend. Migrating would require a new
sub-client method that proxies the raw dict — a package-level change.

**Decision:** Keep raw HTTP with `// BRIDGE CLIENT GAP` comment.

### `slotReachable()` — `ReachableResponse` camelCase/snake_case mismatch

`ReachableResponse::fromArray()` reads `$data['reachableUnchecked']` (camelCase) but the
bridge `/slots/{slot}/reachable` endpoint returns raw Python dict keys in snake_case
(`reachable_unchecked`, `reachable_checked`, etc.).

Using `SlotsClient::reachable()` would silently zero-out every field. Dedicated story needed
to add snake_case normalization to the package.

**Decision:** Keep raw HTTP with `// BRIDGE CLIENT GAP` comment.

---

## Serialization mapping

### Hints (`Hint` PHP class → wire JSON)

| PHP field | JSON key | Notes |
|---|---|---|
| `receivingSlot` | `receivingPlayer` | bridge key renamed by PHP client |
| `receivingPlayerName` | `receivingPlayerName` | direct |
| `findingSlot` | `findingPlayer` | bridge key renamed by PHP client |
| `findingPlayerName` | `findingPlayerName` | direct |
| `status->value` | `status` | int enum value |
| `status->label()` | `statusName` | reconstructed from `HintStatus::label()` |
| `found` | `found` | computed property on `Hint` |

### ItemLocations (`ItemLocation` PHP class → wire JSON)

| PHP field | JSON key | Notes |
|---|---|---|
| `findingSlot` | `findingPlayer` | bridge key renamed by PHP client |
| `findingPlayerName` | `findingPlayerName` | direct |
| Other fields | same name | direct pass-through |

---

## Composer notes

`archilan/bridge-client-bundle` has no tagged release. Constraint `*@dev` is required because
`minimum-stability: stable` would otherwise reject the dev-only package.

Installing the bundle also pulled in `textalk/websocket ^1.6` (required by `bridge-client`)
and downgraded `psr/http-message` from 2.0 to 1.1 to satisfy websocket's `^1.0` constraint.
This downgrade is safe — PSR-7 1.x and 2.x are interface-compatible for our usage.
