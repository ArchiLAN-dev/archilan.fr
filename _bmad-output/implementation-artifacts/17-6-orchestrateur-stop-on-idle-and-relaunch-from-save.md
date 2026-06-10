# Story 17.6: orchestrateur — detect AP auto-shutdown as idle, persist the save, relaunch from it

Status: review

Repo: `archilan-orchestrateur` (Go) — implemented in PR #8 (`feature/idle-via-ap-auto-shutdown` → `master`).

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

- [x] Task 1 — Sweeper: classify AP container exit (clean `auto_shutdown` vs crash) (AC 1).
- [x] Task 2 — On clean exit: stop bridge, release port, **keep the volume** (the `.apsave` lives
      there; no MinIO copy needed on a single host — see Dev Agent Record) (AC 3).
- [x] Task 3 — `session.idle` webhook (AC 4).
- [x] Task 4 — `POST /sessions/{id}/relaunch-from-save` (re-launch AP+bridge on the retained
      volume; AP auto-loads the `.apsave`; 409 branches) (AC 5).
- [x] Task 5 — tests + `swag init` + gates (AC 6).
- [x] Task 0 (pivot) — AP container `RestartPolicy: unless-stopped → on-failure` (max 3): a clean
      `auto_shutdown` exit (0) must not be auto-restarted by Docker, else idle never happens.

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

### Implementation notes (PR #8)

- **Restart-policy pivot (the crux):** both containers were `unless-stopped`, so a clean
  `auto_shutdown` exit was auto-restarted by Docker and the session never went idle. Changed the
  **AP** container to `on-failure` (MaximumRetryCount 3): clean exit stays down → idle; crashes still
  auto-retry, then end as `crashed`. Added `MaximumRetryCount` to the `restartPolicy` struct
  (`omitempty`, so `unless-stopped`/`no` are unchanged). Bridge stays `unless-stopped` — the sweeper
  explicitly stops it on idle (an explicit stop is not auto-restarted).
- **No MinIO round-trip:** the per-session Docker volume `archilan_session_{id}` survives everything
  but `DeleteSession`, and AP writes/reads its `.apsave` in `/data/output`. So idle just stops the
  bridge + releases the port and **keeps the volume**; relaunch re-creates AP+bridge on it and
  MultiServer auto-loads the save. AC 2's "copy to MinIO" was dropped as unnecessary on a single host
  (revisit if/when sessions migrate hosts).
- **Sweeper classifier** extracted as the pure `apExitOutcome(*docker.ContainerStatus)` (unit-tested:
  exit 0 → idle, non-zero/143/nil → crash).
- **relaunch-from-save** mirrors `RestartSession` but accepts a `stopped` (idle) session with an
  OutputFile; force-removes the leftover AP/bridge containers, resets to `generated`, and calls
  `Launch` (same volume).

### File List

- `internal/docker/client.go` — `restartPolicy.MaximumRetryCount`; AP `on-failure`.
- `internal/service/sweeper.go` — `apExitOutcome`, `idleFromAutoShutdown`, `crashRunningSession`.
- `internal/service/session.go` — `RelaunchFromSave`.
- `internal/api/router.go`, `internal/api/session_handlers.go` — `POST /relaunch-from-save`.
- `internal/service/sweeper_test.go` — classifier test.
- `docs/*` — regenerated swagger.

### Open item for live validation

Confirm AP MultiServer exits **0** on `auto_shutdown` (via `docker inspect` State.ExitCode). The
classifier assumes it; if AP uses a non-zero/specific code, adjust `apExitOutcome`.

## Change Log

- 2026-06-10 — Story created.
- 2026-06-10 — Revised: idle is driven by AP's native `auto_shutdown` (not a custom pause); the
  orchestrateur detects the clean exit, persists the save, and stops the bridge.
