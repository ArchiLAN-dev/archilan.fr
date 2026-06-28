# Story 9.35: Hint priority writes actually reach the Archipelago server

**Status:** review
**Epic:** 9 - Sessions, bridge & hints
**Date:** 2026-06-28

## Story

As a player who sets a hint's priority on the indices page (story 9.34),
I want the change to land in Archipelago's own data storage,
so that AP's native clients (text client / launcher) and a page reload all show the same status -
not just our tool's local copy.

## Context

Story 9.34 wired a `PATCH .../hints/{locationId}` that, in the bridge, sent
`{"cmd": "UpdateHint", "player": slot, ...}` over the **main bridge connection** and optimistically
updated the bridge's local hint state. Two bugs made the write never reach AP:

1. The main bridge connection is the TextOnly **"Bridge" slot**. AP only lets a hint's *receiving* or
   *finding* player change its status, so `UpdateHint` from the Bridge slot is silently ignored.
2. `player` was the page `slot`; AP expects the hint's **receiving player**.

Because the bridge still updated its **local** state, our GET (reading local state) showed the change
after a reload - but AP's data storage was untouched, so the text launcher never saw it. (Reported by
Jean.)

The paid self-hint (story 9.30) already solves the permission problem by opening a throwaway
**connect-as-slot** connection. This applies the same technique to `UpdateHint`.

## Acceptance Criteria

1. `ArchipelagoClient.update_hint(slot, receiving_player, location_id, status)` opens a connect-as-slot
   connection (as `slot`, the hint's receiving/finding player) and sends `UpdateHint` with
   `player = receiving_player`, so AP accepts and persists it. Best-effort: returns False on
   connect/transport failure.
2. The `PATCH /slots/{slot}/hints/{location_id}` handler calls `update_hint` (no longer `send_packet`
   over the Bridge connection), using `hint.receiving_player`. On AP failure it returns 502 and does
   **not** update local state; on success it updates local state + broadcasts.
3. Existing behaviour preserved: 503 (ws disconnected), 404 (no such hint), 422 (invalid status).
4. Bridge gates green: `ruff`, `pytest`, `mypy`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). `update_hint` on `ArchipelagoClient` (mirrors `run_self_hint` / `fetch_hint_points`
  connect-as-slot, sends the `UpdateHint` packet, clean close flushes it).
- [x] **Task 2** (AC 2,3). Rewire `update_hint_status` to call it with `hint.receiving_player`; 502 on
  failure (local state untouched).
- [x] **Task 3** (AC 4). Updated `test_update_hint_status_success` (asserts `update_hint(1,1,42,30)`);
  added `test_update_hint_status_ap_failure_returns_502`. ruff / pytest (177) / mypy (22 files) green.

## Dev Notes

- Sending over a throwaway connect-as-slot connection is the same mechanism AP players use; AP allows
  multiple connections per slot, so the player's real client is undisturbed.
- The page `slot` is always the receiving or finding player of a hint shown on its page, so connecting
  as it gives the permission AP requires; `player` in the packet remains the receiving player.
- The api-side endpoint (story 9.34) already rejects the non-settable `found` (40); the bridge only ever
  receives settable statuses.

### Project Structure Notes

- `bridge/core/ap_client.py` (`update_hint`)
- `bridge/core/rest_hints.py` (`update_hint_status`)
- `bridge/tests/test_rest_handlers.py`

### References

- [Source: _bmad-output/implementation-artifacts/9-34-player-set-hint-priority.md (the API/frontend wiring this completes)]
- [Source: bridge/core/ap_client.py (run_self_hint - connect-as-slot pattern, story 9.30)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Hint-status writes now go through a connect-as-slot `UpdateHint`, so they persist in AP's data storage
  and are visible to native AP clients - fixing 9.34's write path (was Bridge-slot send → silently
  ignored, local-only).
- 502 + no local mutation when AP rejects. Bridge ruff/pytest/mypy green.

### File List

- `bridge/core/ap_client.py`
- `bridge/core/rest_hints.py`
- `bridge/tests/test_rest_handlers.py`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-28 | Created + implemented. Hint priority write never reached AP (UpdateHint sent over the TextOnly Bridge slot, which AP ignores; `player` was the page slot, not the receiving player). Added `update_hint` (connect-as-slot) and rewired the handler; 502 on AP failure with no local mutation. Completes story 9.34. Gates green. Status → review. |
