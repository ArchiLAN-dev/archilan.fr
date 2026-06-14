# Story 9.32: Authoritative hint cost via connect-as-slot

**Status:** review
**Date:** 2026-06-14

## Story

As a player looking at a slot's Indices panel,
I want the displayed **pts/indice** to equal the price AP actually charges when I type `!hint`,
So that the budget and "X indices possibles" are truthful (today it can read e.g. 38 when `!hint`
costs 13).

## Context

AP prices a hint at `hint_cost% × len(locations[slot])`, where `locations[slot]` is the slot's **real**
location set (the seed's placements). The bridge connects to AP as a **single** TextOnly observer
("Bridge", `_my_slot`), so for that one slot `_handle_connected` already sets the hint cost from the
authoritative `checked_locations + missing_locations` (`ap_client.py:664-668`). ✅

For **every other slot** the cost is set by `_apply_location_totals` → `_slot_location_total`
(`ap_client.py:986`), which uses the **spoiler placements** when resolved, else falls back to the
**DataPackage size** — *"far larger than the locations actually in this seed, so using it inflates both
checks_total and the hint cost"* (its own docstring). When the spoiler placements for a slot aren't
resolved (e.g. a player-name mismatch → the `spoiler: no placements resolved` warning), the cost is
computed from the inflated DataPackage count: that's the reported `38 = 10% × 380` instead of the real
`13 = 10% × 130`. (Reported by Jean.)

The authoritative count is **already in hand but discarded**: story 9.30's `_connect_as_slot`
(`ap_client.py:415`) — used by **both** `fetch_hint_points` (the `GET /hints` probe) and
`run_self_hint` — receives the target slot's `Connected` packet, which carries that slot's
`checked_locations + missing_locations`. But the handler keeps only `hint_points` (`_store_hint_points`)
and drops the locations.

## Acceptance Criteria

1. **Given** the bridge connects **as slot N** (via `_connect_as_slot`, on either the `GET /hints` probe
   or a paid self-hint) **When** the `Connected` packet is received **Then** the slot's hint cost is set
   from AP's authoritative `len(checked_locations) + len(missing_locations)` (i.e.
   `hint_cost% × that_total`), overriding any spoiler/DataPackage estimate.
2. **Given** AP charges 13 for a slot whose real location set is 130 at 10% **Then** the panel shows
   `13 pts/indice` (not the DataPackage-inflated 38) for that slot, with no dependence on whether the
   spoiler placements were resolved.
3. **Given** the `Connected` packet has no/empty `checked_locations`/`missing_locations` (or a 0 total)
   **Then** the previous estimate is left untouched (no regression to 0); the override only applies when
   the authoritative total is known (> 0).
4. **Given** the `hint_cost` percentage is unknown (`_hint_cost_pct == 0`, no RoomInfo yet) **Then**
   nothing changes (`apply_hint_cost_for_slot` already no-ops at 0%).
5. **Gates green:** bridge `ruff` / `mypy` / `pytest`, plus a unit test asserting the Connected
   location counts drive the slot's `hint_cost`.

## Tasks / Subtasks

- [x] **Task 1 — Capture the authoritative total in `_connect_as_slot`** (AC 1, 3, 4)
  - [x] 1.1 Added `_apply_authoritative_locations(slot, connected)`: reads `checked_locations` +
    `missing_locations` (list-type guard), and when `total > 0` calls `set_checks_total(slot, total)` +
    `apply_hint_cost_for_slot(slot, total)` — mirrors `_handle_connected`. Empty/non-list/0 → no-op
    (AC 3); 0% pct → `apply_hint_cost_for_slot` already no-ops (AC 4).
  - [x] 1.2 Called from `_connect_as_slot` right before returning `Connected`, so both
    `fetch_hint_points` (GET /hints probe) and `run_self_hint` benefit; no extra connection.
- [x] **Task 2 — Test** (AC 5)
  - [x] 2.1 `test_self_hint.py::test_connect_as_slot_sets_authoritative_hint_cost`: `_hint_cost_pct=10`
    + a `Connected` of 130 locations → `ensure_slot(2).hint_cost == 13` and `checks_total == 130` after
    `fetch_hint_points`.
- [x] **Task 3 — Quality gates** (AC 5) — ruff ✓ / mypy ✓ (22 files) / pytest 170 ✓.

## Dev Notes

- **No new round-trip.** The fix reuses the `Connected` packet `_connect_as_slot` already reads; the
  `GET /hints` panel already triggers `fetch_hint_points`, so the corrected cost lands on the existing
  path. The DataPackage/spoiler estimate (`_apply_location_totals`) stays as the pre-probe fallback.
- **Why TextOnly still gets locations:** AP's `Connected` includes `checked_locations`/
  `missing_locations` for the authenticated slot regardless of `TextOnly`/`items_handling` — the same
  fields `_handle_connected` consumes for the Bridge slot.
- Also corrects the same inflation for the slot's `checks_total` (set together, as `_handle_connected`
  does) — a free side-benefit, not the primary goal.
- **API/frontend:** unchanged — `hintCost` already flows `bridge → /hints → ps.hint_cost → panel`.

### Project Structure Notes

- `bridge/core/ap_client.py` — `_connect_as_slot` + new `_apply_authoritative_locations`
- `bridge/tests/test_hint_cost.py` (or `test_self_hint.py`) — new assertion

### References

- [Source: bridge/core/ap_client.py:415] — `_connect_as_slot` (Connected packet, both probe + self-hint)
- [Source: bridge/core/ap_client.py:664] — `_handle_connected` sets cost from checked+missing for `_my_slot`
- [Source: bridge/core/ap_client.py:986] — `_slot_location_total` (spoiler/DataPackage estimate that inflates)
- [Source: bridge/core/state.py:82] — `apply_hint_cost_for_slot` / `compute_hint_cost`
- Story 9.30 — connect-as-slot machinery this builds on

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-14 | Created (draft). Authoritative per-slot hint cost from AP's Connected (checked+missing) on the connect-as-slot path, fixing the DataPackage-inflated estimate (38 shown vs 13 charged). |
| 2026-06-14 | Implemented in `bridge/core/ap_client.py` (`_apply_authoritative_locations`, called from `_connect_as_slot`) + test in `tests/test_self_hint.py`. Bridge gates green (ruff / mypy / pytest 170). Bridge-only; API/frontend unchanged. Status → review. |
