# Story 9.22: Real-time per-slot check tracking in the bridge (decouple live progress from the apsave)

**Status:** done
**Epic:** 9 - Archipelago Session Management
**Date:** 2026-06-10
**Repo:** `Archipelago-Bridge` (Python) - and possibly `archilan-archipelago` (AP server image)

---

## Story

As a viewer of a live Archipelago run,
I want every location check to appear on the UI within ~1-2 s - including checks the AP server does not broadcast (e.g. solo/filler self-finds),
so that progress is truly real-time instead of waiting for the AP server's periodic save.

---

## Context

The bridge connects to the AP server over WebSocket as a **TextOnly observer slot** ("Bridge",
`tags: ["TextOnly"], items_handling: 0` - `core/ap_client.py`). It learns other slots' progress from
**`PrintJSON ItemSend`** broadcasts (`_track_item_send`) and pushes state to Symfony → Mercure
`runs/{id}/players` on every event (`_push_state_to_api`). A 5 s **apsave reconcile loop** is a backstop.

**Observed gap (confirmed live):** in solo runs, checks appear "often but not always" instantly, and the
missing ones only show up after the page is refreshed or after a *new* item is sent. Diagnosis:
- the AP server does **not** broadcast a `PrintJSON ItemSend` for every check (notably filler / self-finds),
  so those checks never reach the observer over the WS;
- they are only picked up by the **apsave reconcile**, which is bounded by **AP's own save cadence**
  (stock `ArchipelagoServer` auto-saves on a long interval, not per check - `ap_server.sh` sets no
  save-interval flag), hence the multi-second / "only on refresh" latency.

A prior bridge fix (PR #1, merged) made the apsave reconcile **push** on change, removing the
"stuck until refresh" failure - but the residual latency is still bounded by the AP save interval.
This story closes that gap with a **real-time** signal for all checks.

AP version in use: **0.6.7** (`archilan-archipelago/Dockerfile` `ARCHIPELAGO_VERSION=0.6.7`; the AP source
tarball is fetched at build, so `MultiServer.py` is available in the image / downloadable for reference).

---

## Acceptance Criteria

1. Every location check for every slot is reflected on the live UI within **~1-2 s**, independent of the
   AP server's save interval - verified including a **solo run with filler items** (the case that fails today).
2. The mechanism does not rely on the apsave for the common path; the apsave reconcile remains only as a
   backstop (and still pushes, per PR #1).
3. No regression to the existing real-time paths (PrintJSON feed, hints, reachability, goal callback) or to
   reconnect/backoff behaviour.
4. Connection load is bounded and safe for large multiworlds (see spike) - no unbounded fan-out that could
   overwhelm the AP server.
5. Bridge quality gates green: `ruff`, `pytest`, `mypy` (run per `bridge/CLAUDE.md`). New behaviour covered
   by tests with a stubbed AP WS (no live server needed).

---

## Tasks / Subtasks

- [ ] **Task 1 - Spike: pick the real-time mechanism for AP 0.6.7** (AC: 1, 4). Read the AP `MultiServer.py`
  source (`register_location_checks`, `RoomUpdate` emission, the `_read_*` data-storage keys, the
  `Tracker` tag handling, and the auto-save logic) and determine which gives real-time per-slot checks to a
  non-playing client. Candidates, in rough priority:
  - **(A) Per-slot tracking connections** - AP sends `RoomUpdate { checked_locations: [...] }` to **all
    clients connected to a given slot**, and AP allows **multiple connections per slot**. The bridge could
    open a lightweight client per player slot (reusing the WS infra) that receives `Connected`
    (initial `checked_locations`) + `RoomUpdate` (incremental) + `ReceivedItems` in real time. Confirm the
    packets, that extra same-slot connections don't disturb the real player, and the connection ceiling.
  - **(B) Data-storage `SetNotify`** - check whether a notify-able key reflects checks in 0.6.7
    (`_read_client_status_*` exists; confirm if any check/location key exists). Likely insufficient for
    checks but cheap if present.
  - **(C) Fix `ItemSend` parsing** - verify whether self-finds *are* broadcast but `_track_item_send`
    fails to resolve the sender for the self-find message shape (then this is a cheap parser fix, possibly
    combined with A/B).
  - **(D) Fallback** - if no clean real-time signal exists, reduce the effective save latency (e.g. a
    save-interval lever on the AP server in `ap_server.sh`, or an admin-triggered save) - document the
    trade-off. Only if A–C are not viable.
  - Write the decision + evidence into the Dev Agent Record before coding.

- [ ] **Task 2 - Implement the chosen real-time path** in `core/ap_client.py` (and wiring in `bridge.py`)
  (AC: 1, 2, 3): ingest per-slot checks/items in real time, update `StateManager`, and call
  `notify_state_changed()` (existing) so the push to Symfony → Mercure fires immediately. Keep the existing
  observer connection for the feed/hints/permissions.

- [ ] **Task 3 - Bound and harden** (AC: 3, 4): reuse the existing reconnect/backoff
  (`run_with_reconnect`), cap concurrent connections / degrade gracefully on large slot counts, ensure clean
  teardown, and avoid duplicate counting between the new path and PrintJSON ItemSend (dedupe by
  slot+location).

- [ ] **Task 4 - Keep the apsave backstop** (AC: 2): leave `_apsave_reconcile_loop` (now pushing) as the
  safety net; confirm no double-push storms when both paths see the same change.

- [ ] **Task 5 - Tests** (AC: 5): unit-test the new packet handlers (feed a stubbed `RoomUpdate` /
  `ReceivedItems` and assert state + `notify_state_changed` fire) using the existing `AsyncMock` broadcast
  style (`tests/test_checks.py`); assert dedupe (no double count vs ItemSend). No live AP server.

- [ ] **Task 6 - If Task 1 chose (D)**: change `archilan-archipelago/ap_server.sh` accordingly, rebuild the
  image, and note the redeploy requirement. (Separate repo / coordinated change.)

- [ ] **Task 7 - Gates + manual verify** (AC: 1, 5): `ruff` / `pytest` / `mypy` green; verify live on a
  **solo run with filler items** that checks appear ~instantly without refresh.

---

## Dev Notes

- **Current connect packet** (`core/ap_client.py` ~l.357): `cmd: Connect`, `name: "Bridge"`,
  `tags: ["TextOnly"]`, `items_handling: 0`, `slot_data: False`. The "Bridge" observer slot is injected
  into every generated multiworld by the orchestrateur.
- **Existing real-time hooks to reuse**: `_handle_packet` (dispatch), `_handle_connected`
  (`checked_locations`/`missing_locations` for the bridge's own slot - the per-slot path would reuse this
  for each tracked slot), `RoomUpdate` handler (currently only permissions/hint_cost - extend for
  `checked_locations` if approach A), `StateManager.add_location_checks` / `set_checks_total` /
  `update_client_status`, and `notify_state_changed()` (added in PR #1) which broadcasts + pushes.
- **Dedupe**: `_track_item_send` already increments checks from PrintJSON; the new path must not
  double-count. `StateManager.add_location_checks` should be idempotent per (slot, location) - verify.
- **Do not** reintroduce the apsave as the primary path; it stays a backstop (PR #1 keeps it pushing).
- **AP source to read for the spike**: `MultiServer.py` (`register_location_checks`, `update_checked_locations`,
  `send_new_items`, `RoomUpdate` construction, `Context.save` / auto-save interval). The 0.6.7 source is the
  tarball in `archilan-archipelago/Dockerfile` (line ~23).

### Project Structure Notes

- Primary changes in `Archipelago-Bridge`: `core/ap_client.py`, `bridge.py`, `tests/`.
- Possible coordinated change in `archilan-archipelago/ap_server.sh` only if Task 1 picks fallback (D).
- Bridge gates per `bridge/CLAUDE.md`: from repo root `cd bridge && python -m ruff check . && python -m pytest`;
  `cd .. && python -m mypy bridge/ --config-file bridge/pyproject.toml`.

### References

- [Source: _bmad-output/implementation-artifacts/9-12-bridge-py-realtime-observer-service.md] - original observer
- [Source: _bmad-output/implementation-artifacts/9-14-player-progress-dashboard.md] - consumer UI
- [Source: _bmad-output/implementation-artifacts/9-20-bridge-reachability-push-to-mercure.md] - push pattern
- [Source: Archipelago-Bridge core/ap_client.py, core/loops.py, bridge.py] - observer + reconcile + push
- [Source: Archipelago-Bridge PR #1 — apsave reconcile now pushes (notify_state_changed)]
- [Source: archilan-archipelago/ap_server.sh, Dockerfile (AP 0.6.7)] — server launch, no save-interval flag

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Spike Findings

Read `MultiServer.py` @ tag 0.6.7 (`register_location_checks`, RoomUpdate emission, auto-save):

- **AP broadcasts `PrintJSON ItemSend` to the whole team for every check** — `ctx.broadcast_team(team, info_texts)`, with `info_texts` appended for **every** item in the newly-checked locations, **no** filtering by flags/filler/self. So the bridge's TextOnly observer **does** receive an ItemSend for every check (my earlier "filler isn't broadcast" hypothesis was wrong).
- `RoomUpdate { checked_locations, hint_points }` is sent only to `ctx.clients[team][slot]` — i.e. only to clients connected to *that* slot, **not** to the observer. So candidate (A) would require per-slot connections (items_handling mismatch / item-delivery pitfalls) — rejected as overkill/risky.
- `self.auto_save_interval = 60` — the `.apsave` is flushed ~every 60 s, confirming the apsave fallback latency (the ">5 s / only on refresh" symptom).

**Decision → candidate (C), refined:** the observer already receives every check via ItemSend; the bug is that `_track_item_send` resolves the slot/location by **parsing the human-readable `data` text parts**, which is fragile across games/message shapes (intermittent misses → only the apsave catches them, 60 s later). The ItemSend packet also carries a **structured `item` (NetworkItem: item/location/player/flags) and top-level `receiving`** — authoritative and game-agnostic. Fix: read those structured fields (fast path), keep text-parsing as a fallback for `ItemCheat`/unusual shapes. No per-slot connections, no apsave dependence; the merged apsave-reconcile push stays as the backstop.

### Completion Notes List

- Spike (source-confirmed) flipped the working hypothesis: AP **does** broadcast every check's ItemSend to
  the observer; the real bug was the bridge's **text-parsing** of the message. Implemented candidate (C):
  read the structured `item` NetworkItem (`item`/`location`/`player`/`flags`) + top-level `receiving` as a
  fast path in `_track_item_send`, falling back to the existing text parse for `ItemCheat`/odd shapes.
  Extracted `_apply_item_send()` shared by both paths.
- No per-slot connections (avoids items_handling-mismatch / duplicate-delivery pitfalls — candidate A
  rejected per spike). The merged apsave-reconcile push (PR #1) remains the backstop.
- Idempotent: checks accumulate in a set, so a repeated/duplicate ItemSend (or overlap with the apsave
  reconcile) does not double-count — covered by a test.
- **Verification:** unit tests prove a self-find with empty `data` text parts is now tracked (the failing
  case). Live verification on a solo-with-filler run is pending a bridge image rebuild/redeploy.

### File List

- `Archipelago-Bridge` (PR #2): `core/ap_client.py` (structured ItemSend fast path + `_apply_item_send`),
  `tests/test_item_send_tracking.py` (new: self-find / cross-player / idempotence).
- Related prior: `Archipelago-Bridge` PR #1 (apsave reconcile now pushes — `core/loops.py`,
  `core/ap_client.py`, `bridge.py`).

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-10 | Story created. Goal: real-time per-slot check tracking so non-broadcast checks (solo/filler) appear ~instantly instead of waiting for the AP save interval. Builds on the merged bridge fix (apsave reconcile now pushes). Spike-first to pick the AP 0.6.7 mechanism. Status → ready-for-dev. |
| 2026-06-10 | Spike done (AP 0.6.7 source): AP broadcasts every check's ItemSend; bug was text-parsing. Implemented structured-NetworkItem fast path (PR #2). ruff/pytest(173)/mypy green. Needs bridge redeploy. Status → done. |
