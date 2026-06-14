# Story 9.28: Bridge - Admin Hint Command by Item (any slot)

Status: review

## Story

As an admin/player requesting a hint for a location in any slot's world,
I want the bridge to issue the correct AP **admin** hint command for that location's item,
So that the hint is actually created on the server (for any slot, not only the one the bridge is connected as).

## Context

The bridge connects to the AP server as a **single** slot (slot 1, `TextOnly`). In Archipelago:
- a **non-admin** self-hint (`!hint <name>` / `!hint_location <location>`) only resolves against the **currently-connected slot** — it cannot hint another player's world;
- to hint **any** player you must use the **admin** command `!admin /hint <player> <item>` (validated live by the user).

The bridge's `request_hint` was sending a self/location form (observed in the AP log: `Bridge: !hint 1F Washroom Key` — no `!admin`, no player), so requests for any slot other than the bridge's own resolved wrong or failed.

The bridge already knows, from the spoiler loaded at startup, the item placed at any location: `get_placement(slot, locationId) -> (item_id, receiver_slot)`. So for the chosen location it can name the item and its owner and issue the validated admin command.

> **Correction (post-test):** keying off the spoiler placement (`!admin /hint <owner> <item>`)
> hinted the **wrong item** — the spoiler location→item mapping is unreliable. The bridge now
> sends `!admin /hint_location <player> <location>` so the **AP server** resolves the location to
> its item (authoritative). The created hint reaches the UI via the data-storage path (story 9.27),
> so no optimistic local add is needed.

## Acceptance Criteria

1. **Given** a hint is requested for `(slot, locationId)` **When** the bridge issues the command **Then** it sends `!admin /hint_location <player_name> <location_name>`, where `player_name = resolve_player(slot)` and `location_name` is resolved from the DataPackage — preceded by `!admin login` (existing `send_admin_command`).

2. **Given** the location id is unknown for the slot **When** the hint is requested **Then** the bridge returns HTTP 422 and does **not** send a malformed command.

3. **Given** the admin command is sent **When** the AP server creates the hint **Then** the new hint reaches the UI via the live data-storage path (story 9.27) — no fragile spoiler-based optimistic add.

4. **Given** the request targets a slot other than the bridge's connected slot **When** the command runs in admin mode **Then** the hint is created for that slot's world (no "only current slot" limitation).

## Tasks / Subtasks

- [x] Task 1 - Bridge: switch `request_hint` to the admin item command (AC: #1, #2, #4)
  - [x] 1.1 Require `get_placement(slot, locationId)`; 422 if missing
  - [x] 1.2 Send `!admin /hint {owner_name} {item_name}` via `send_admin_command`
  - [x] 1.3 Keep the optimistic local `add_hint` + `_broadcast_hints`
- [x] Task 2 - Quality gates
  - [x] 2.1 ruff / mypy / pytest green

## Dev Notes

### Bridge - `rest_hints.py` `request_hint`

Replace:
```python
await ap_client.send_admin_command(f"!admin /hint_location {player_name} {location_name}")
```
with a placement-derived item command:
```python
placement = ap_client.get_placement(slot, body.locationId)
if placement is None:
    raise HTTPException(status_code=422, detail=f"no placement for location {body.locationId} (spoiler not loaded?)")
item_id, receiver_slot = placement
owner_name = ap_client._store.resolve_player(receiver_slot)
item_name = ap_client._store.resolve_item(item_id, receiver_slot)
await ap_client.send_admin_command(f"!admin /hint {owner_name} {item_name}")
```
The optimistic local hint build (already using `placement`) and the `hints_used` increment stay unchanged.

### Why item, not location

The user validated `!admin /hint <player> <item>` live (`[Hint]: masterkafey_LM's Boolossus MiniBoo 1 is at Conservatory Clear Chest`). `!hint_location` was only ever exercised as a non-admin self-hint, which AP restricts to the connected slot. Hinting by `(owner, item)` reveals the same `(item, location)` pair and works for any slot under admin.

### References

- [Source: bridge/core/rest_hints.py:74] - command construction (the line to change)
- [Source: bridge/core/rest_hints.py:71] - `get_placement` already fetched
- [Source: bridge/core/ap_client.py:309] - `send_admin_command` (prepends `!admin login`)
- [Source: ap-server log] - validated `!admin /hint <player> <item>`; failing `Bridge: !hint <name>`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

### File List
