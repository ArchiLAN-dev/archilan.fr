# Story 17.7: bridge — remove pause/resume/wake; the bridge lives only while AP lives

Status: review

Repo: `Archipelago-Bridge` (Python) — implemented in PR #3 (`feature/remove-pause-resume-wake` → `master`).

## Story

As a maintainer,
I want the bridge to be a pure relay that exists only while its Archipelago server exists,
so that it no longer pretends to manage pause/resume or to survive its own AP server — idle is now
Archipelago's native `auto_shutdown` and the save is persisted by the orchestrateur.

## Context

Story 17.5 had the bridge kill the AP sub-process on `/pause`, save+upload, and survive inside a warm
container with a wake-on-connect TCP listener. We are dropping all of that. Idle is handled by AP's
native `auto_shutdown` (config-driven, epic 27); when AP exits, the orchestrateur persists the
`.apsave` and stops the bridge (story 17.6). The bridge therefore no longer needs `/pause`, `/resume`,
the wake listener, or any AP process management — it depends on AP and should die with it.

Pairs with **17.6** (orchestrateur) and **17.8** (Symfony). Ship 17.6 + 17.7 before 17.8.

## Acceptance Criteria

1. **Remove pause/resume/wake:** delete the `/pause` and `/resume` endpoints, `core/wake_on_connect.py`
   (`WakeOnConnectServer`), the `BridgeLifecycleManager` pause/resume/`_launch_ap` logic, the Symfony
   `POST /restarting` callback, and any code that opens a TCP socket on the AP port or kills/relaunches
   the AP process. No endpoint mutates AP lifecycle anymore.
2. **Pure relay:** the bridge connects to AP (observer slot), relays state, reports activity/heartbeat,
   and pushes the structured `PrintJSON ItemSend` → Mercure (stories 9.20/9.22). Unchanged.
3. **Dies with AP:** when AP exits (auto_shutdown or crash) the bridge's WS connection closes; the
   bridge exits cleanly (no infinite reconnect against a dead server, no orphaned asyncio tasks). The
   orchestrateur stops the bridge container regardless (17.6) — the bridge must not block that.
4. **Save is not the bridge's job:** the bridge no longer issues `!save` / uploads `.apsave`. AP
   auto-saves during play; the orchestrateur copies the latest `.apsave` to MinIO on shutdown (17.6).
5. Bridge gates green from the repo root (`ruff`, `pytest`, `mypy`). Remove `test_wake_on_connect.py`
   and the pause/resume/lifecycle-manager tests; keep/relay tests for the WS + activity + ItemSend
   path.

## Tasks / Subtasks

- [x] Task 1 — Delete `/pause`, `/resume`, wake listener, lifecycle helpers + `/restarting`
      callback; delete `core/wake_on_connect.py` + `core/coordinator.py`; unwire deps/rest/bridge (AC 1).
- [x] Task 2 — (Revised) The orchestrateur stops the bridge container on idle (17.6), so the bridge
      need not self-exit; reconnect loop left as-is. See Dev Agent Record (AC 3).
- [x] Task 3 — Remove the in-bridge save/upload code; drop dead config `ap_pid_file`/`ap_launch_cmd` (AC 4).
- [x] Task 4 — Tests + gates: drop wake/pause/resume tests; ruff/pytest(142)/mypy green (AC 5).

## Dev Notes

### Reconnect vs exit

The bridge has exponential-backoff reconnect (`_WS_RETRY_DELAYS` in `ap_client.py`). Under the new
model a closed WS usually means AP shut down → the bridge should bound the retries and then exit
rather than spin forever; the orchestrateur is tearing the container down anyway.

### Nothing schedules a pause anymore

Symfony's `InactivityWatchdog` (which called `/pause`) is removed in 17.8, and AP owns the timeout, so
no caller hits `/pause` once 17.8 ships. Removing the endpoint in 17.7 is safe in that order.

### References

- Story 17.5 (superseded) — the design being removed.
- Story 17.6 — orchestrateur now persists the save + relaunches.
- Bridge: `core/rest.py`, `core/ap_client.py`, `core/config.py`.

## Dev Agent Record

### Implementation notes (PR #3)

- Went further than "reduce `/pause`": **removed `/pause` entirely** too. In the two-container model
  the bridge can't manage AP's lifecycle anyway (`_kill_ap` used a PID file from the legacy
  single-container `entrypoint.sh`), and AP now self-saves + the orchestrateur persists/relaunches
  (17.6). So the bridge has no save/pause/resume role.
- **AC 3 relaxed:** the bridge does **not** self-exit on AP death. The orchestrateur explicitly stops
  the bridge container on idle (17.6 `idleFromAutoShutdown`), and an explicit `docker stop` is not
  auto-restarted. Changing `run_with_reconnect` to bounded-then-exit would also exit on transient
  blips → more risk than value. Left the reconnect loop as-is.
- **`ws_server.request_approve_restart` left in place:** now unused, but entangled with the WS
  `_pending_requests` receive loop; a clean removal is more risk than value. No import of deleted code.

### File List

- `core/rest_session.py` — removed `/pause`, `/resume` + all pause/resume/wake helpers + imports.
- `core/deps.py`, `core/rest.py`, `bridge.py` — removed coordinator import/param/wiring.
- `core/config.py` — removed `ap_pid_file`, `ap_launch_cmd`.
- Deleted: `core/wake_on_connect.py`, `core/coordinator.py`,
  `tests/test_pause_endpoint.py`, `tests/test_resume_endpoint.py`, `tests/test_wake_on_connect.py`.
- `tests/test_rest_handlers.py` — dropped `/pause`,`/resume` route + auth tests.
- `BRIDGE_API.md` — removed `/pause`, `/resume` endpoint docs.

## Change Log

- 2026-06-10 — Story created.
- 2026-06-10 — Revised: remove `/pause` too (not just wake); idle = AP `auto_shutdown`, save persisted
  by the orchestrateur. The bridge is a pure relay that dies with AP.
