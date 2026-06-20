# Story 9.27: Bridge - Live Hints for All Slots via AP Data Storage

Status: review

## Story

As an admin/player watching a slot detail page,
I want new hints to appear in real-time for **every** slot (not only the slot the bridge is connected as),
So that I see hints live without having to reload the page.

## Acceptance Criteria

1. **Given** the bridge connects to the AP server **When** it receives `Connected` **Then** it sends a `Get` and a `SetNotify` for the data-storage keys `_read_hints_{team}_{slot}` of **every** slot in the multiworld.

2. **Given** the AP server returns `Retrieved` (initial) or `SetReply` (live update) for a `_read_hints_{team}_{slot}` key **When** the bridge handles it **Then** it replaces that slot's hint list from the storage payload and pushes the slot's hints to the Mercure topic `runs/{sessionId}/slots/{slot}/hints` (via the existing `hints-push` endpoint) within ~1 second.

3. **Given** a hint is created in-game for **any** slot (including a slot the bridge is not connected as) **When** the AP server updates the hint storage **Then** the slot detail page reflects it within ~1 second, without a page reload.

4. **Given** a slot already has hints at connect time **When** the initial `Retrieved` arrives **Then** those hints are emitted once (no duplicate spam on subsequent identical `SetReply`s - only changed slots are pushed).

5. **Given** a malformed/empty storage payload **When** the bridge parses it **Then** it is ignored (no crash); the existing PrintJSON-based `_track_hint` path keeps working unchanged.

6. **Given** `central_api_url`/`central_api_secret` are not configured **When** a hint update is ingested **Then** the WS broadcast still happens and the API push is silently skipped (existing `_push_hints_to_api` behavior).

## Tasks / Subtasks

- [x] Task 1 - Bridge: subscribe to hint storage on connect (AC: #1)
  - [x] 1.1 Capture `self._team` in `_handle_connected` (`int(packet.get("team", 0))`)
  - [x] 1.2 Build the full slot list from `slot_info` and send one `Get` + one `SetNotify` for all `_read_hints_{team}_{slot}` keys (alongside the existing `_read_race_mode` Get)
- [x] Task 2 - Bridge: ingest hint storage payloads (AC: #2, #4, #5)
  - [x] 2.1 Add `_hint_storage_key(slot)` / `_slot_from_hint_key(key)` helpers
  - [x] 2.2 Add `_ingest_hint_storage(slot, raw_list)`: parse each raw hint dict to `HintInfo` (resolving names), replace the slot's hints via `state.set_hints`, and `_broadcast_hints(slot)` only when the list changed
  - [x] 2.3 Handle `_read_hints_*` keys in the `Retrieved` branch
  - [x] 2.4 Add a `SetReply` branch handling `_read_hints_*` keys
- [x] Task 3 - State: full-list replace (AC: #2, #4)
  - [x] 3.1 Add `StateManager.set_hints(slot, hints) -> bool` (returns whether the list changed)
- [x] Task 4 - Tests
  - [x] 4.1 Unit: `_ingest_hint_storage` parses a storage payload into hints and returns changed=True, then False on identical re-ingest
  - [x] 4.2 Unit: malformed/empty payload → no hints, no raise
- [x] Task 5 - Quality gates
  - [x] 5.1 Bridge: ruff → 0 errors
  - [x] 5.2 Bridge: mypy → 0 errors
  - [x] 5.3 Bridge: pytest → all green

## Dev Notes

### Root Cause

The bridge only ever pushes hints to Mercure from `_track_hint` (`ap_client.py:809`), which fires on a `PrintJSON` of type `Hint`. The AP server sends that `PrintJSON` **only to the clients of the slots involved** in the hint. The bridge connects as a **single** slot with `tags: ["TextOnly"]` (`ap_client.py:364`), so it never receives `Hint` PrintJSON for hints between other slots.

New hints for other slots *do* land in memory eventually - the apsave reconcile loop (`loops.py:131`) calls `apply_saved_states`, which replaces `ps._hints` (`state.py:201`) - but:
- the reconcile change-detection only looks at `checks_done`/`items_received` (`loops.py:151,174`), not hints, and
- `notify_state_changed()` pushes only the `state_changed` (slots summary) payload, **never** `_broadcast_hints`/`hints-push`.

So hints for non-connected slots are only visible on a manual `GET /hints` (page reload), never live. This story adds the AP **data-storage** subscription that trackers use, which delivers hint updates for *all* slots instantly.

### AP data storage hints

The server exposes a read-only key per slot: `_read_hints_{team}_{slot}`. Its value is the full list of that slot's hints (serialized `Hint` objects). Clients can:
- `{"cmd": "Get", "keys": [...]}` → server replies `{"cmd": "Retrieved", "keys": {key: [hint, ...]}}`
- `{"cmd": "SetNotify", "keys": [...]}` → server replies `{"cmd": "SetReply", "key": key, "value": [hint, ...]}` on every change

`SetNotify` is allowed on `_read_*` keys. Each serialized hint dict carries flat fields (not the nested `NetworkItem` of the PrintJSON form): `receiving_player`, `finding_player`, `item`, `location`, `item_flags`, `found`, `entrance`, `status`. These match the fields `save_parser._extract_hints` already reads (`save_parser.py:39`).

### Bridge changes - `ap_client.py`

- `_handle_connected`: capture `self._team`; after the existing `_read_race_mode` Get, send `Get` + `SetNotify` for `[_read_hints_{team}_{slot} for slot in slot_info]`.
- New helper builds a `HintInfo` from a storage dict and resolves names via `self._store` (mirror `_track_hint`'s field mapping + `resolve_*`).
- `_ingest_hint_storage(slot, raw_list)`: build the list, `state.set_hints(slot, hints)`; if changed, `await self._broadcast_hints(slot)`.
- `Retrieved` branch: for keys matching `_read_hints_*`, route to `_ingest_hint_storage`.
- New `SetReply` branch: `key`/`value` → `_ingest_hint_storage`.

### State changes - `state.py`

Add `set_hints(slot_index, hints) -> bool`: replace `ps._hints` wholesale, return whether it differs from the previous list (so the loop only pushes on real changes - AC #4).

### No API / frontend change

The push endpoint `HintsPushController.php` (topic `runs/{id}/slots/{n}/hints`) and the frontend `EventSource` subscription (`hints-token`, same topic) already exist (story 9.23). This story only feeds them from a new source.

### Files to modify

**Bridge (repo `Archipelago-Bridge`, branch from `master`):**
- `bridge/core/ap_client.py` - team capture, Get/SetNotify, ingest + Retrieved/SetReply branches
- `bridge/core/state.py` - `set_hints`
- `bridge/tests/test_*_hints*.py` - new unit tests

### References

- [Source: bridge/core/ap_client.py:809] - `_track_hint`, field mapping to mirror
- [Source: bridge/core/ap_client.py:454] - `Retrieved` branch (extend)
- [Source: bridge/core/ap_client.py:513] - existing `Get` for `_read_race_mode`, insertion point
- [Source: bridge/core/ap_client.py:470] - `_handle_connected`, slot_info / team
- [Source: bridge/core/ap_client.py:901] - `_broadcast_hints` / `_push_hints_to_api` (reused as-is)
- [Source: bridge/core/state.py:162] - `add_hint`/`get_hints` (add `set_hints` alongside)
- [Source: bridge/core/save_parser.py:39] - `_extract_hints` field names

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

### File List
