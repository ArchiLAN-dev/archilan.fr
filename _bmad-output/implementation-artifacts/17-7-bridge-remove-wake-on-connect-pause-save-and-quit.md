# Story 17.7: bridge — remove wake-on-connect; `/pause` saves, uploads, then quits

Status: ready-for-dev

Repo: `Archipelago-Bridge` (Python) — branch from `master`.

## Story

As a maintainer,
I want the bridge to stop pretending it can outlive its Archipelago server,
so that pausing a session means "save the state and let the container be stopped", not "kill AP but
keep a TCP listener alive inside a half-dead container".

## Context

Story 17.5 had the bridge open a wake-on-connect TCP listener on the AP port after killing the AP
sub-process, surviving so it could relaunch AP on the next connection. We are dropping that model: the
bridge depends on AP, the container shouldn't stay warm, and resume is now driven by Symfony →
orchestrateur relaunch-from-save (story 17.6). This story removes the wake/relaunch responsibilities
from the bridge and reduces `/pause` to a clean "save → upload → done".

Pairs with **17.6** (orchestrateur stop + relaunch-from-save) and **17.8** (Symfony repoint). Ship
17.6 and 17.7 before 17.8 flips Symfony.

## Acceptance Criteria

1. `POST /pause` (bearer-token auth, called by Symfony): issues the AP `!save` command, waits for the
   `.apsave` to appear (bounded timeout), uploads it to MinIO at
   `sessions/{sessionId}/saves/{timestamp}.apsave`, then returns `200` with
   `{ "paused_without_save": bool, "save_key": str|null }`. It does **not** open any TCP listener and
   does **not** kill/relaunch AP itself — the orchestrateur stops the container afterwards.
2. On save timeout (no `.apsave`), returns `200` with `paused_without_save: true` and `save_key: null`
   (Symfony already surfaces this as "reprise impossible").
3. The wake-on-connect TCP listener is **removed**: delete `core/wake_on_connect.py`, the
   `WakeOnConnectServer` task wiring, and the call to Symfony `POST /restarting`. No code path opens a
   socket on the AP port.
4. `POST /resume` is **removed** (resume is now a fresh container via the orchestrateur). Any
   `BridgeLifecycleManager.resume()` / `_launch_ap()` / save-relaunch logic is deleted.
5. The bridge process is free to exit when the container is stopped; it no longer needs to survive a
   dead AP. Heartbeat/activity reporting and the live PrintJSON relay (epic 9/17.1) are unchanged
   while AP is running.
6. Bridge gates green from the repo root: `ruff`, `pytest`, `mypy`. Tests updated: remove
   `test_wake_on_connect.py`; `test_lifecycle_manager.py` (or equivalent) asserts `/pause` does
   save+upload and returns the right shape, with no listener started.

## Tasks / Subtasks

- [ ] Task 1 — Reduce `/pause` to save → upload → return; drop the listener start (AC 1, 2, 3).
- [ ] Task 2 — Delete `core/wake_on_connect.py` and all `WakeOnConnectServer` wiring + the
      `/restarting` callback (AC 3).
- [ ] Task 3 — Remove `POST /resume` and the in-bridge relaunch logic (AC 4).
- [ ] Task 4 — Confirm the bridge exits cleanly on container stop; no orphaned tasks (AC 5).
- [ ] Task 5 — Tests + gates: drop wake tests, adjust pause tests (AC 6).

## Dev Notes

### Save-then-stop ordering

The orchestrateur stops the container only **after** `/pause` returns 200 (Symfony sequences this in
17.8). So `/pause` must fully complete the save+upload before returning — do not background it.

### What stays

The WebSocket client, heartbeat, activity reporting, and the structured `PrintJSON ItemSend` →
Mercure relay (stories 9.20/9.22) are untouched: they only run while AP is up, which is the only time
the bridge is up under the new model.

### References

- Story 17.5 (superseded) — the wake-on-connect design being removed.
- Save key convention consumed by orchestrateur 17.6: `sessions/{sessionId}/saves/{timestamp}.apsave`.
- Bridge REST/config: `core/rest.py`, `core/config.py`, `core/ap_client.py`.

## Dev Agent Record

## Change Log

- 2026-06-10 — Story created (supersedes the bridge half of 17.5).
