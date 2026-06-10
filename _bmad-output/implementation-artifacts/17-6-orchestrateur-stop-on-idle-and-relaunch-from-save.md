# Story 17.6: orchestrateur — stop the session container on idle, relaunch from the MinIO save

Status: ready-for-dev

Repo: `archilan-orchestrateur` (Go) — branch from `master`.

## Story

As the platform operator,
I want a paused (idle) session to **fully stop its containers** and to be relaunchable from the
latest Archipelago save,
so that we stop keeping containers warm 24/7 and the resume path is driven by Symfony →
orchestrateur instead of a bridge that has to survive its own AP server.

## Context

Today the idle/resume design is "Option A: the container stays alive" — the bridge kills the AP
**sub-process** on `/pause` and keeps a wake-on-connect TCP listener running inside the still-running
container; resume relaunches AP in place (`ResumeRunJobHandler` → bridge `/resume`). This is fragile
(the bridge surviving its AP server) and wasteful (a warm container per idle run). We are removing
wake-on-connect (supersedes story 17.5) and making the lifecycle **stop + relaunch-from-save**,
orchestrated by Symfony.

The orchestrateur already exposes `POST /sessions/{id}/stop` (stop both containers, release port) and
`POST /sessions/{id}/restart` — but `restart` re-launches from the **generated seed/output file**
(fresh game, after a crash), **not** from a mid-game save. Resuming a paused game with progress
requires the latest `.apsave` (the MultiServer save the bridge uploads to MinIO at
`sessions/{sessionId}/saves/{timestamp}.apsave`) to be injected into the AP data volume so MultiServer
auto-loads it on boot.

This story adds that capability. Sequencing: **17.6 (this) and 17.7 (bridge) ship first**, then the
monorepo story **17.8** repoints Symfony's pause/resume jobs onto them.

## Acceptance Criteria

1. `POST /sessions/{sessionId}/stop` is safe to call on a `running` session and leaves the session in
   `stopped` with the host port released and **both** containers (AP + bridge) removed. (Existing
   behaviour — assert it, no warm container remains.)
2. New endpoint **`POST /sessions/{sessionId}/relaunch-from-save`** (bearer-token auth):
   - Resolves the latest `.apsave` for the session from MinIO (`sessions/{sessionId}/saves/`, most
     recent by key/timestamp). If none exists → `409` (`no_save_available`); the caller keeps the
     session idle.
   - Acquires a port, starts a fresh AP server container **and** bridge container (same wiring as
     `launch`), injecting the multidata (already in the session's stored output) **and** the
     downloaded `.apsave` into `/data` so MultiServer auto-loads progress.
   - On AP health-check pass, fires the **`session.ready`** webhook (same payload as a normal launch:
     `apPort`, `bridgePort`) so Symfony transitions `restarting → running` via its existing
     `/restarted` callback path.
   - On launch/health failure, fires **`session.crashed`** (Symfony maps it to a restart failure).
3. Reuses the existing port pool, sweeper reconciliation, and webhook signing — no new state added to
   the orchestrateur lifecycle machine beyond reusing `launching → running`/`crashed`.
4. The relaunch is **idempotent-safe**: calling it on a session that already has live containers
   returns `409` (`already_running`) without touching them.
5. Swagger regenerated (`swag init`). Go gates green: `go build ./...`, `go vet ./...`,
   `go test ./...`. New unit tests for save resolution (latest-by-key) and the relaunch handler's
   error branches (no save → 409, already running → 409); Docker calls stay untested (infra), per the
   repo convention.

## Tasks / Subtasks

- [ ] Task 1 — `storage`: `LatestSaveKey(sessionId) (string, bool)` — list `sessions/{id}/saves/`,
      return the most recent object key (AC 2).
- [ ] Task 2 — `docker`/`service`: `RelaunchFromSave(ctx, sessionId)` — resolve save → download →
      start AP+bridge with multidata + save injected into the data volume (AC 2, 3).
- [ ] Task 3 — `api`: `POST /sessions/{id}/relaunch-from-save` handler with the 409 branches (AC 2, 4).
- [ ] Task 4 — webhook `session.ready`/`session.crashed` on success/failure of the relaunch (AC 2).
- [ ] Task 5 — tests + `swag init` + gates (AC 5).

## Dev Notes

### Save injection mechanism

Archipelago MultiServer auto-loads a `.apsave` placed next to the multidata in its data directory on
start. The relaunch therefore needs both files in the volume before AP boots: the multidata from the
session's stored output (already handled by `launch`/`launch-from-file`) **plus** the `.apsave`
downloaded from MinIO. No `--savefile` flag juggling if the file is in the expected location — verify
against the AP image entrypoint.

### Who saves, and when

The save upload stays the **bridge**'s job on `/pause` (story 17.7): it issues `!save`, waits for the
`.apsave`, uploads it to MinIO, then returns 200. Symfony only calls the orchestrateur `stop` **after**
the bridge confirms the save (sequenced in 17.8's `PauseRunJobHandler`). The orchestrateur does not
itself trigger the save — it only consumes the uploaded `.apsave` on relaunch.

### Why not extend `restart`

`POST /sessions/{id}/restart` is the crash-recovery verb (re-launch from the generated seed). Keep its
semantics; add a distinct `relaunch-from-save` so the two intents stay legible and the crash sweeper
path is unaffected.

### References

- Orchestrateur layout, sessions API, webhook events, lifecycle: memory `orchestrateur` and
  `orchestrateur/internal/service/session.go`, `internal/storage/`.
- Save key convention `sessions/{sessionId}/saves/{timestamp}.apsave` (bridge upload, story 17.2/17.5).
- Symfony callbacks consumed: `POST /api/v1/sessions/{id}/restarted` (running),
  webhook `session.crashed` → restart-failed (see monorepo `SessionLifecycleManager`).

## Dev Agent Record

## Change Log

- 2026-06-10 — Story created (supersedes the orchestrateur half of 17.5).
