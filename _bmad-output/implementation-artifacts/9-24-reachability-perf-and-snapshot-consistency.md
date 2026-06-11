# Story 9.24: Reachability — speed up the compute and fix the snapshot-consistency race

**Status:** ready-for-dev
**Epic:** 9 - Archipelago Session Management
**Date:** 2026-06-11
**Repo:** `Archipelago-Bridge` (Python) - and `archilan-archipelago` (the AP/reachable image)

## Story

As a player watching my run,
I want the "reachable now" / doable-checks figures to update **quickly** and to **never
get stuck one step behind** when I grab items in quick succession,
so that the progress view is both fast and correct.

## Context

Follow-up to story 9.23 (which fixed *propagation* — pushing `players` from the sweep and
adding hints-push). Two issues remain, found while reading `bridge/core/reachable.py` +
`loops.py` and live-diagnosing:

### A. The reachability compute is slow (~10 s/sweep)

In Docker mode (prod), `_compute_reachable` delegates to
`DockerRuntimeAdapter.run_reachable`, which **creates an ephemeral AP container per call**
(`containers.create` → `start` → `reachable.py` loads the **whole multiworld** from the
`.archipelago` → `wait` → `delete`). For a 915-location world the dominant cost is
**re-loading the multiworld on every call**, plus the container lifecycle. The sweep runs
this per changed slot, every few seconds.

Notably, a **long-lived daemon path already exists** for non-Docker mode
(`_reachable_daemons` / `_start_daemon`: a persistent subprocess that loads the world once
and answers state queries over stdin/stdout in ms). Prod (Docker) doesn't use it.

### B. Snapshot-consistency race (items grabbed during a compute go stale)

`_compute_reachable` computes for a **snapshot** taken at its start (`cache_key =
(checks_done, items_received)`, stored in `_reachable_cache[slot]`), and the compute takes
~10 s. But in `bridge/core/loops.py` the sweep then does
`last_computed[slot] = (ps.checks_done, ps.items_received)` reading the **current** state
*after* the await. If items/checks arrived **during** the compute, `last_computed` is
marked at the newer counts while the result reflects the older snapshot →
`last_computed == current` at the next sweep → **no recompute** → `reachable_now` stays one
batch behind until the next change. No data corruption (asyncio is single-threaded), but a
real staleness edge when grabbing items in quick succession.

## Acceptance Criteria

1. **Race fix (B):** after a sweep computes a slot, `last_computed` is marked with the
   **snapshot the computation actually used** (the cache_key), not the post-await current
   state. So any check/item that arrives during a compute triggers a recompute on the next
   sweep, and `reachable_now` never gets stuck one step behind. Covered by a test.
2. **Perf (A):** the reachability compute reuses a **pre-loaded multiworld** instead of
   loading it per call in an ephemeral container — bringing a single compute from ~seconds
   to sub-second. Approach options (pick one in the spike):
   a. a **long-lived reachable daemon container** (start once per session, query over a
      socket/stdin) — the Docker analogue of the existing `_start_daemon` path; or
   b. `docker exec` into the **running AP server container** with a persistent helper that
      keeps the world loaded; or
   c. compute reachability inside the AP server process (it already has the world loaded).
3. No regression on cache behaviour (cache keyed on `(checks_done, items_received)`), on the
   `reachable-push` payload, or on the non-Docker daemon/subprocess path.
4. Quality gates (from repo root): `ruff check .`, `pytest`, `mypy bridge/`. Perf verified
   on a live run (a single reachable compute drops well under a second after warm-up).

## Tasks / Subtasks

- [ ] **Task 1 — Race fix (small, ship first)** (AC: 1). In `bridge/core/loops.py`, mark
  `last_computed[slot]` with the key the compute used: read it from
  `_reachable_cache[slot]` (the `(cache_key, result)` the compute just stored) instead of
  the current `ps` counters. Add a test proving a slot whose state changed during the
  compute is re-swept. *(Implemented alongside this story — see Dev Agent Record.)*
- [ ] **Task 2 — Spike: reachability worker for Docker mode** (AC: 2). Decide between a
  long-lived reachable daemon container, `docker exec` + persistent helper, or in-AP-server
  computation. Prototype load-once + per-query latency; document the choice. The existing
  non-Docker `_start_daemon` (stdin/stdout JSON protocol) is the reference design.
- [ ] **Task 3 — Implement the worker** (AC: 2,3). Add a `run_reachable`-equivalent on the
  Docker runtime that talks to the persistent worker (start on first need / on launch, reuse
  across sweeps, tear down with the session). Keep the ephemeral-container path as a fallback.
- [ ] **Task 4 — Tests + gates + live verify** (AC: 3,4). Unit-test the runtime selection /
  fallback; `ruff`/`pytest`/`mypy`; verify warm-compute latency on a live run.

## Dev Notes

- **Race:** `bridge/core/loops.py` `_reachable_sweep_loop` line ~95 (`last_computed[...] =
  (ps.checks_done, ps.items_received)`); `_reachable_cache[slot] = (cache_key, result)` is
  set in `bridge/core/reachable.py` on every successful compute (Docker, daemon, subprocess
  paths) and on cache hit, so `_reachable_cache[slot][0]` is the key the current result
  corresponds to.
- **Perf:** `bridge/adapters/docker_runtime.py` `run_reachable` (ephemeral container);
  `bridge/core/reachable.py` `_start_daemon` + `_reachable_daemons` (the existing long-lived
  subprocess protocol to mirror for Docker); `/reachable/reachable.py` lives in the
  `archilan-archipelago` image.
- **Cadence:** the sweep is gated on `(checks_done, items_received)` change and serialized by
  an `asyncio.Semaphore(1)`; cache short-circuits unchanged slots. So the win is per-compute
  latency, not frequency.
- **Gates / launch:** bridge from repo root; `bridge/CLAUDE.md` (`ruff` / `pytest` / `mypy`).

### Non-goals

- Incremental/partial reachability (only re-evaluating locations near new items) — much
  larger AP-logic effort, out of scope.
- Frontend changes (it already renders whatever `reachable_now` / reachable map it receives).

### References

- [Source: bridge/core/loops.py (_reachable_sweep_loop, last_computed)]
- [Source: bridge/core/reachable.py (_compute_reachable, _reachable_cache, _start_daemon, _reachable_daemons)]
- [Source: bridge/adapters/docker_runtime.py (run_reachable ephemeral container)]
- Builds on story 9.23 (realtime propagation); 9.20 (reachability push); 9.22 (check tracking).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- **Task 1 (race fix) implemented** with this story: `loops.py` now marks `last_computed`
  from `_reachable_cache[slot][0]` (the snapshot the compute used) rather than the current
  counters; falls back to current counters only if the cache entry is missing. Test added
  proving a slot mutated during the compute is re-swept on the next iteration. Gates green.
- Tasks 2-4 (perf worker) remain — bigger, separate change touching the runtime adapter and
  the archipelago image.

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Story created from the reachability deep-dive: (A) per-call ephemeral-container reload is the ~10 s bottleneck (a long-lived daemon path already exists for non-Docker mode), (B) `last_computed` is marked with the post-await current state instead of the computed snapshot → items grabbed during a compute go stale by one step. Task 1 (race fix) implemented immediately; Tasks 2-4 (Docker reachability worker) scoped for follow-up. Status → ready-for-dev. |
