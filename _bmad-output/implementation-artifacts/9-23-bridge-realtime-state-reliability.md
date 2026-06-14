# Story 9.23: Bridge — realtime state reliability (checks_done lands on the players topic, not just the apsave sweep)

**Status:** ready-for-dev
**Epic:** 9 - Archipelago Session Management
**Date:** 2026-06-11
**Repo:** `Archipelago-Bridge` (Python)

## Story

As a player (or spectator) watching a run,
I want my **checks count and progress to update on the interface in near-real-time**,
so that I don't see a stale value for several seconds (or never, until the next reachable
sweep) after I check a location.

## Context

Live diagnosis on a running prod run (session `e41fd93e…`, Luigi's Mansion):

- The **bridge** had the correct count (`GET /slots` → `checksDone: 14`, no recompute) and the
  **API** had it too (`/sessions/{id}/players` → `checks_done: 14`). Only the **open browser
  tab** was stale (showed less). So the data is correct server-side; the gap is in **how/when
  the bridge pushes the `players` topic**.
- Observed precisely: a **`players-push`** did **not** move the UI, but the following
  **`reachable-push`** (a few seconds later) did. The progress grid subscribes only to
  `runs/{sessionId}/players` (`PlayerStateController` players-token), while reachable publishes
  to `runs/{sessionId}/slots/{n}/reachable` — so the UI changing "at reachable time" means the
  fresh `checks_done` only materialises around the **Docker apsave parse** (the reachable
  sweep), and the `players` topic isn't pushed then.

Two root causes in the bridge (building on story 9.22, which decoupled live progress from the
apsave but still falls back to it):

1. **`_track_item_send` (WS) doesn't reliably bump `checks_done` for some games.** Its own
   comment notes the human-readable parse "intermittently fails to resolve the slot for some
   games/message shapes, leaving checks to surface only via the apsave." Luigi's Mansion is
   affected → checks don't advance on the immediate `players-push`; they only appear once the
   apsave is parsed.
2. **The reachable sweep updates `checks_done` (from the Docker parse) but never pushes the
   `players` topic.** In `loops.py` the sweep computes reachable, pushes `reachable-push`, and
   ends with `pass  # state_changed will be broadcast by ap_client on next event` — so the
   grid's `players` topic only refreshes on the *next* WS event (or the separate apsave
   reconcile loop), not when the fresh count is actually computed. Hence "players-push = no
   change, reachable-push = it moves, a few seconds apart."

Related: the in-process apsave parse (`save_parser.py` / `merge_state_from_save`) needs
`NetUtils` at `_ap_src = "/app/ArchipelagoSrc"`, which is **absent from the bridge image** →
`No module named 'NetUtils'`. The reconcile loop prefers a "Docker parse" (the AP container has
NetUtils) and falls back to this in-process path, which can't parse → a degraded cycle that
updates nothing ("parfois aucune notif").

## Acceptance Criteria

1. When the bridge's `checks_done` advances (whether via the WS path or the apsave/Docker
   parse), the **`runs/{sessionId}/players` topic is pushed promptly** (`players-push`), so the
   progress grid updates within ~1-2 s without waiting for an unrelated WS event.
2. A location check that the AP server *does* broadcast (e.g. `ItemSend`) bumps the finder's
   `checks_done` **live** for the games we support — including Luigi's Mansion — via
   `_track_item_send` (no longer "surfacing only via the apsave").
3. The apsave reconcile no longer silently produces a no-op cycle when the in-process parse
   can't run: either the **Docker parse** is the reliable path and the in-process fallback is
   removed/guarded, or `NetUtils` is made importable so the fallback works. No "missing update"
   cycles.
4. No regression on the feed, reachable, hints, or status flows; the `players` topic payload
   shape stays `{"slots": {...}}` (`to_api_dict`) so the existing SSE handler keeps working.
5. Quality gates (from repo root): `ruff check .`, `pytest`, and `mypy bridge/`. Verified live:
   check a location → the grid's count updates within a couple of seconds (not only at the next
   reachable sweep).

## Tasks / Subtasks

- [ ] **Task 1 — Push `players` when the sweep changes state** (AC: 1). In `bridge/core/loops.py`,
  replace the `pass  # state_changed will be broadcast … on next event` with an actual
  `notify_state_changed()` call (the hook already does `_broadcast_state_changed` + `players-push`)
  when `changed_slots` is non-empty. Wire `notify_state_changed` into the reachable sweep like it
  is wired into the apsave reconcile loop.
- [ ] **Task 2 — Reliable live `checks_done`** (AC: 2). Harden `_track_item_send` /
  `_apply_item_send` (`bridge/core/ap_client.py`) so the finder's slot resolves for the affected
  games (Luigi's Mansion). Prefer the structured `NetworkItem` fast path; fix/extend the
  human-readable fallback's slot resolution. Add a test with a representative LM `ItemSend` packet.
- [ ] **Task 3 — Robust apsave reconcile** (AC: 3). Make the apsave parse not depend on the
  missing in-process `NetUtils`: either route reconciliation through the **Docker parse** (AP
  container has the AP source) and remove/guard the in-process `pickle` fallback, or make
  `NetUtils` importable in the bridge. Ensure a failed parse logs and retries rather than
  silently updating nothing. (See the `save_parser.py` `_ap_src = "/app/ArchipelagoSrc"` gap.)
- [ ] **Task 4 — Tests** (AC: 2,3,5). Unit: `_track_item_send` bumps `checks_done` for an LM-shaped
  packet; the sweep triggers a `players` push on change; the reconcile path degrades gracefully
  (no silent no-op) when the in-process parse is unavailable. Mock HTTP/Docker as the repo does.
- [ ] **Task 5 — Gates + live verify** (AC: 5). `ruff` / `pytest` / `mypy` green; verify on a live
  run that a check moves the grid within ~1-2 s.

## Dev Notes

- **Push hook:** `bridge/core/ap_client.py` `notify_state_changed()` → `_broadcast_state_changed()`
  → WS `state_changed` + `_push_state_to_api()` (POST `players-push`, payload `to_api_dict()` =
  `{"slots": {...}}`). The API `PlayersPushController` republishes verbatim to Mercure topic
  `runs/{sessionId}/players`; the front `PlayerProgressGrid` SSE handler updates on `data.slots`.
- **The gap:** `bridge/core/loops.py` reachable sweep — `changed_slots` is computed but the final
  branch is a `pass`; it only `reachable-push`es (topic `runs/{sid}/slots/{n}/reachable`), which the
  grid doesn't subscribe to. Call `notify_state_changed()` there.
- **WS check tracking:** `_track_item_send` / `_apply_item_send` in `ap_client.py` (story 9.22).
  The comment flags slot-resolution failures for some games → checks fall back to the apsave.
- **apsave parse:** `bridge/core/save_parser.py` (`_ap_src = "/app/ArchipelagoSrc"`, `pickle.loads`
  needs `NetUtils`), `bridge/core/state.py` `merge_state_from_save`, `loops.py` apsave reconcile
  (Docker parse preferred, in-process fallback). The bridge image lacks `/app/ArchipelagoSrc`.
- **Topics:** `PlayerStateController` players-token → `runs/{runId}/players`; reachable-token →
  `runs/{runId}/slots/{n}/reachable`. Keep them; just make sure `checks_done` changes hit the
  `players` topic.
- **Launch/gates:** run the bridge from the repo root; gates per `bridge/CLAUDE.md`
  (`ruff check .`, `pytest`, `mypy bridge/ --config-file bridge/pyproject.toml` from root).

### Non-goals

- Frontend changes (the SSE handler already updates on `{"slots": …}`); this is bridge-side.
- Reworking the reachable computation itself (only its push wiring).

### References

- [Source: bridge/core/loops.py (reachable sweep `pass`; apsave reconcile; `_push_reachable_to_api`)]
- [Source: bridge/core/ap_client.py (`_track_item_send`, `_apply_item_send`, `notify_state_changed`, `_push_state_to_api`, `to_api_dict` path)]
- [Source: bridge/core/save_parser.py (`_ap_src`, NetUtils/pickle), bridge/core/state.py (`merge_state_from_save`)]
- [Source: api/src/Sessions/Presentation/PlayersPushController.php, ReachablePushController.php, PlayerStateController.php (topics)]
- [Source: frontend/src/components/session/PlayerProgressGrid.tsx (SSE `data.slots` handler)]
- Builds on story 9.22 (realtime per-slot check tracking); related to the lifecycle work in epic 17.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Story created from a live prod diagnosis: bridge + API both correct (checks_done=14), only the open UI stale; `players-push` didn't move the UI but the following `reachable-push` did. Root causes: (1) `_track_item_send` doesn't reliably bump `checks_done` for some games (LM) so checks only surface via the apsave; (2) the reachable sweep updates state from the Docker parse but ends on a `pass` instead of pushing the `players` topic; (3) the in-process apsave parse can't import `NetUtils` (absent from the bridge image) → degraded reconcile cycles. Scope: push `players` on checks change, reliable WS check tracking, robust apsave reconcile. Status → ready-for-dev. |
