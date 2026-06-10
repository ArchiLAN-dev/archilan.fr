# Story 17.6: orchestrateur — detect AP auto-shutdown as idle, persist the save, relaunch from it

Status: ready-for-dev

Repo: `archilan-orchestrateur` (Go) — branch from `master`.

## Story

As the platform operator,
I want a session that Archipelago has **auto-shut-down** (its native `auto_shutdown` option) to be
recognised as *idle* — its save persisted and its containers stopped — and to be relaunchable from
that save,
so that idle is driven by Archipelago itself (no custom watchdog) and resume is a clean
"relaunch a fresh container from the save".

## Context

Idle is **already** an Archipelago feature: MultiServer's `auto_shutdown: N` stops the server after
`N` seconds without new location checks. We wire it through the session config as `autoShutdown`
(epic 27, `SessionServerConfig::toServerFlags()` → orchestrateur launch flag → AP). So the AP server
shuts **itself** down — the Symfony `InactivityWatchdogHandler` (epic 17, every 5 min → bridge
`/pause`) and the wake-on-connect listener (story 17.5) are a **redundant** parallel mechanism. We are
removing them (supersedes 17.5) and consolidating on AP's native shutdown.

What's missing for that to work end-to-end is on the orchestrateur side: when AP exits via
`auto_shutdown`, the orchestrateur must (a) tell it apart from a crash, (b) persist the latest
`.apsave` somewhere durable, (c) stop the now-useless bridge container, and (d) signal Symfony the
session is *idle* (resumable), not *crashed*. Plus a relaunch-from-save path for the manual resume.

Sequencing: **17.6 (this) + 17.7 (bridge) ship first**, then the monorepo story **17.8** removes the
Symfony watchdog and repoints resume.

## Acceptance Criteria

1. **Clean shutdown detection:** the sweeper distinguishes an AP server container that exited via
   `auto_shutdown` (clean: exit code 0 / shutdown marker) from a genuine crash (non-zero / OOM).
   - Clean exit → treat as **idle** (AC 2–4), webhook `session.idle`.
   - Crash → existing `session.crashed` path, unchanged.
2. **Persist the save:** on clean exit, copy the latest `.apsave` from the session's data volume to
   MinIO at `sessions/{sessionId}/saves/{timestamp}.apsave` **before** removing the volume. If no
   `.apsave` exists (never saved), record idle with no save (Symfony surfaces "reprise impossible").
3. **Stop the bridge + release resources:** on clean exit, stop the bridge container and release the
   host port (the bridge has nothing to relay once AP is gone). The session ends in `stopped` with no
   warm containers.
4. **`session.idle` webhook:** fired on clean shutdown with `{ sessionId, saveKey|null }` so Symfony
   transitions the session to its idle state (replaces the bridge-driven `recordPaused`).
5. **Relaunch endpoint:** `POST /sessions/{sessionId}/relaunch-from-save` (bearer auth):
   - Resolves the latest `.apsave` from MinIO; none → `409 no_save_available`.
   - Acquires a port, starts fresh AP + bridge containers with the multidata (stored output) **and**
     the downloaded `.apsave` injected into `/data` so MultiServer auto-loads progress.
   - On AP health-check pass → `session.ready` webhook (`apPort`, `bridgePort`) → Symfony
     `restarting → running`; on failure → `session.crashed` → restart-failed.
   - Idempotent-safe: live containers already present → `409 already_running`.
6. Swagger regenerated; Go gates green (`go build`/`go vet`/`go test`). Unit tests: clean-vs-crash
   classification, latest-save resolution, relaunch error branches. Docker calls stay untested (infra).

## Tasks / Subtasks

- [ ] Task 1 — Sweeper: classify AP container exit (clean `auto_shutdown` vs crash) (AC 1).
- [ ] Task 2 — On clean exit: copy `.apsave` volume → MinIO, stop bridge, release port (AC 2, 3).
- [ ] Task 3 — `session.idle` webhook (AC 4).
- [ ] Task 4 — `POST /sessions/{id}/relaunch-from-save` (download save, fresh AP+bridge, inject,
      `session.ready`/`session.crashed`, 409 branches) (AC 5).
- [ ] Task 5 — tests + `swag init` + gates (AC 6).

## Dev Notes

### Detecting a clean auto_shutdown

MultiServer's `auto_shutdown` ends the server loop and the process exits. Verify against the AP image
how it exits (exit code, or a sentinel line in logs) so the sweeper can tell clean shutdown from a
crash — this is the one risk to nail down first. If AP can't be made to exit distinguishably, fall
back to: "AP container exited AND the bridge reported no recent activity" as the clean-idle heuristic.

### Save injection on relaunch

MultiServer auto-loads a `.apsave` placed next to the multidata in `/data`. Relaunch injects both
(multidata from stored output + `.apsave` from MinIO) before AP boots. AP also auto-saves during play
(`auto_save_interval`, ~60s), so a recent `.apsave` exists in the volume at shutdown.

### What replaces the Symfony watchdog

Nothing on a timer — AP owns the timeout. **Caveat:** `auto_shutdown` defaults to 0 (never). For idle
to actually happen, the type profiles must default it to a sane non-zero value — handled in 17.8
(and consistent with 27.9, where `autoShutdown` is admin-only). Document the chosen default there.

### References

- `SessionServerConfig::toServerFlags()` (monorepo) → orchestrateur launch flag `auto_shutdown`.
- Orchestrateur sweeper / lifecycle: memory `orchestrateur`, `internal/service/{session,sweeper}.go`.
- Save key convention `sessions/{sessionId}/saves/{timestamp}.apsave`.
- Symfony callbacks: `POST /api/v1/sessions/{id}/restarted`; webhooks `session.idle` (new) /
  `session.ready` / `session.crashed`.

## Dev Agent Record

## Change Log

- 2026-06-10 — Story created.
- 2026-06-10 — Revised: idle is driven by AP's native `auto_shutdown` (not a custom pause); the
  orchestrateur detects the clean exit, persists the save, and stops the bridge.
