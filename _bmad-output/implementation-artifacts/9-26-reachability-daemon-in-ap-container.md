# Story 9.26: Reachability daemon exec'd in the running AP container (no per-compute container)

**Status:** review
**Epic:** 9 - apworld management, realtime & reachability
**Date:** 2026-06-13

## Story

As the operator,
I want reachability to run inside the already-running AP server container instead of spawning a fresh
ephemeral container on every sweep,
so that an active session doesn't churn through create/start/delete of AP containers (and recomputes
faster, since the apworld/seed stay loaded).

## Context

The bridge's `DockerRuntimeAdapter.run_reachable` spawned a **new ephemeral AP container** per
reachability compute (`docker create` + `start` + `delete`), once per slot whose `(checks, items)` cache
key changed - i.e. roughly every 30s / on WS events during active play (observed by Jean:
"il continue de lancer des containers pour calculer la reachability"). No leak (cleaned up in `finally`),
but heavy churn and repeated apworld/seed loading.

The agreed design (recalled by Jean): run the computation **in the archipelago container that's already
running** - the AP server (`ap-server-{sessionId}`), which has the AP runtime (`/reachable/reachable.py`),
the session volume (`/data`), and is up during play. The bridge already has a `reachable.py --daemon`
protocol (`{"ready":true}` then one JSON state line in → one JSON result line out) used by the
local-subprocess path; this wires that daemon over a `docker exec` into the AP container.

## Acceptance Criteria

1. Reachability no longer creates a per-compute container. Instead a long-lived `reachable.py --daemon`
   is `exec`'d **inside `ap-server-{sessionId}`**, one per slot, and reused across sweeps (state fed on
   stdin, one JSON result line read back).
2. The daemon is (re)started lazily: first use per slot; restarted if the `.archipelago` file changes
   (regeneration) or the exec stream dies (e.g. AP container relaunch). Any I/O error drops the daemon so
   the next sweep re-execs cleanly.
3. `run_reachable` keeps its signature → `_compute_reachable` (the docker path) is unchanged.
4. `run_save_parse` stays a one-shot ephemeral container (the AP container may be down at resume time).
5. Daemons + the persistent Docker client are torn down on bridge shutdown (`aclose`).
6. Gates green - bridge: `ruff` / `mypy` / `pytest`. Verified live: an active run computes reachability
   without spawning new containers; relaunch re-execs the daemon.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3,5). `DockerRuntimeAdapter`: persistent aiodocker client + per-slot
  `_ReachableDaemon` (exec stream + stdout line-buffer + lock); daemon-backed `run_reachable`
  (`container.exec(... reachable.py --daemon ...)` in `ap-server-{sessionId}`, read `{"ready":true}`,
  then per-request write/read with a timeout); `_drop_daemon` on arch-change/error; `aclose()`.
- [x] **Task 2** (AC 5). Wire `runtime.aclose()` into `bridge.py`'s shutdown `finally`.
- [x] **Task 3** (AC 4). Leave `run_save_parse` as the ephemeral container.
- [x] **Task 4** (AC 6). Unit test for the stdout-frame line buffering (multiplexed exec stream, stderr
  ignored, EOF → error). ruff / mypy / pytest (150) green.
- [ ] **Task 5** (AC 6). Rebuild `archilan-bridge:latest`, redeploy, relaunch a run, confirm no new
  reachability containers + correct counts (live verify - deploy step).

## Dev Notes

- AP container name = `ap-server-{session_id}` (the WS host the bridge connects to;
  `session_id` is in the bridge config, already used for the volume bind).
- The exec stream multiplexes frames; `_ReachableDaemon.read_line` accumulates stdout (frame type 1) to a
  newline and ignores stderr (type 2, daemon logs). aiodocker 0.26 `container.exec(...).start(detach=False)`
  → `Stream(read_out/write_in/close)`.
- Startup loads the apworld/seed (≤ ~100s ready timeout); per-request read 30s. The existing
  `_compute_reachable` 120s wrapper is the backstop.
- The local-subprocess daemon path in `bridge/core/reachable.py` is unchanged (used in non-docker mode).

### Project Structure Notes

- `archilan-bridge/adapters/docker_runtime.py` (daemon-backed `run_reachable` + `_ReachableDaemon` + `aclose`)
- `archilan-bridge/bridge.py` (`aclose` on shutdown)
- `archilan-bridge/tests/test_docker_runtime_daemon.py` (new)

### References

- [Source: _bmad-output/implementation-artifacts/9-24-reachability-perf-and-snapshot-consistency.md]
- [Source: archilan-bridge/core/reachable.py (--daemon protocol, local-subprocess path)]
- [Source: archilan/archipelago reachable.py (--daemon: `{"ready":true}` then line-in/line-out)]
- Delivered via Archipelago-Bridge PR #8.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Reachability is daemon-backed via `docker exec` into the running AP container; no per-compute container.
  `run_reachable` signature unchanged; lazy start + restart on arch-change/stream-death; `aclose` on
  shutdown. `run_save_parse` left ephemeral. Unit test for the frame line-buffering; ruff/mypy/pytest green.
  Requires bridge image rebuild + relaunch to take effect (live verify pending deploy).

### File List

- `archilan-bridge/adapters/docker_runtime.py`
- `archilan-bridge/bridge.py`
- `archilan-bridge/tests/test_docker_runtime_daemon.py`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-13 | Created + implemented (Archipelago-Bridge PR #8). Reachability runs as a `reachable.py --daemon` exec'd in the running AP server container instead of a fresh ephemeral container per compute. Follow-up to 9.24. ruff/mypy/pytest green. Status → review (live verify after bridge redeploy). |
