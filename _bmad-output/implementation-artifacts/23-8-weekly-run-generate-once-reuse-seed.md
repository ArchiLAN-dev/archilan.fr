# Story 23.8: Weekly Run — Generate the Multiworld Once, Reuse It Per Player

## Story

**As** the ArchiLAN platform,
**I want** each weekly run's Archipelago multiworld generated exactly once (at the Monday 00:00 tick),
**So that** a member clicking "Lancer ma partie" gets an instant server from the pre-generated seed instead of triggering a fresh, minutes-long generation on every launch.

## Status

review

## Context (current behaviour)

The weekly-run "generation" is misnamed and mis-timed today:

- **Monday 00:00** — `GenerateWeeklyRunsMessageHandler` → `OrchestratorWeeklyRunGenerator::generate()` does **not** generate any multiworld. It only downloads the apworld from MinIO, uploads it to the orchestrator, and stores the **apworld hash** in `WeeklyRun.markGenerated()` (the field `getGeneratedSeedPath()` actually holds an apworld hash).
- **Every player launch** — `LaunchWeeklyEntry` → `OrchestratorWeeklyRunnerGateway::launchEntry()` runs the **full Archipelago generation**: `configureSession` → `sessions()->generate(seed)` → poll up to 180s → `launch`. So the heavy generation (the "combined apworlds / Generate.py" run) happens **once per player**, not once per run.

Since the weekly design fixes one seed + one template YAML for all players (epic 23 amendment: players cannot customize YAML), the generated world is identical for everyone. Regenerating per launch wastes minutes of CPU per player, adds launch latency, and risks divergence if any world is non-deterministic.

### What the orchestrator already supports (investigated)

- `POST /sessions/{id}/generate` → `GenerateMultiworld` writes the output into the session's Docker volume (`/data/output`), stores it as `session.OutputFile`, status → `generated`.
- `POST /sessions/{id}/launch-from-file` (multipart: `adminPassword`, `serverPassword`, `file`) → `Service.LaunchFromFile` injects a **provided** multiworld file into a fresh session volume and launches it — **no regeneration**. Already wrapped in the PHP client: `SessionsClient::launchFromFile(sessionId, fileContents, filename, adminPassword, serverPassword?)`.
- `RestartSession` reuses `session.OutputFile` (same session only).
- MinIO storage client handles apworlds + per-session YAML/manifest.

### The gap (new work required)

There is **no way to retrieve the generated multiworld output bytes** after generation: the output lives only inside the session's Docker volume. There is no copy-from-volume, no `GET .../output` endpoint, and no MinIO upload of the generated archive. Without it, the Monday-generated artifact cannot be reused by `launch-from-file` for each player. Closing this gap is the core of this story.

## Chosen design: asynchronous, webhook-driven (option b)

The real generation takes minutes, so it must **not** run synchronously inside the Monday message handler. Instead:

1. **Monday 00:00** — for each active template, create the `WeeklyRun` (status `active`, `generatedSeedPath = null` ⇒ not launchable yet) and kick off **one async generation per template** against a deterministic generator session `weekly-gen-{weeklyRunId}` (upload apworld → `configure` → `generate(seed)`). The handler does **not** poll/block.
2. **Orchestrator** — `runGeneration` runs in its goroutine, uploads the output to MinIO keyed by session id, and fires the existing `session.generated` webhook (or `session.crashed` on failure).
3. **API webhook** — `OrchestratorWebhookController` recognises `weekly-gen-*` session ids and routes them to a new `MarkWeeklyRunGenerated` service that stores the artifact key on the run (now launchable) and cleans up the generator session.
4. **Player launch** — downloads the stored artifact and calls `launchFromFile`; zero generation.

No new `WeeklyRun` status is required: `generatedSeedPath === null` already gates launch (`LaunchWeeklyEntry` throws `run_not_generated`).

## Acceptance Criteria

**AC1 (orchestrator — output persistence):** At the end of a successful `runGeneration`, the generated multiworld output is copied out of the session volume and uploaded to MinIO (sessions bucket) under a key **derivable from the session id** (e.g. `sessions/{sessionId}/output/<file>`), so the API can fetch it without a new endpoint or webhook-payload change. (`GET /sessions/{id}/output` is a fallback only if direct MinIO read from the API is rejected.)

**AC2 (Monday dispatch — non-blocking):** `GenerateWeeklyRunsMessageHandler` creates each `WeeklyRun` (active, not launchable) and triggers generation against generator session `weekly-gen-{weeklyRunId}` (apworld upload → configure → `generate(seed)`) **without polling**. One run with a failed *dispatch* (e.g. orchestrator unreachable) is logged and left not-launchable; it does not abort the other templates.

**AC3 (webhook — mark generated):** `OrchestratorWebhookController` detects a `weekly-gen-{weeklyRunId}` session id and, on `session.generated`, calls `MarkWeeklyRunGenerated(weeklyRunId, artifactKey)` → `WeeklyRun.markGenerated(artifactKey)` (artifact key derived from the session id, AC1). The handler is **idempotent** (duplicate webhook is a no-op) and then deletes the generator session/volume (`sessions()->delete(weekly-gen-id)`). Non-weekly session ids keep the existing `SessionLifecycleManager` path unchanged.

**AC4 (webhook — generation failure):** On `session.crashed` for a `weekly-gen-*` id, the run is left not-launchable and the failure is logged/observable. No auto-retry; an admin can re-trigger via the existing "Générer maintenant" endpoint, which re-dispatches generation only for active runs of the current week whose `generatedSeedPath` is still null (idempotent).

**AC5 (per-player launch — no regeneration):** `OrchestratorWeeklyRunnerGateway::launchEntry()` no longer calls `configure`+`generate`. It downloads the run's stored output artifact and calls `client.sessions().launchFromFile(entryId, output, filename, adminPassword, serverPassword)`. A launch performs **zero** Archipelago generation. Launching before generation completes still yields `run_not_generated` (unchanged).

**AC6 (determinism + parity):** All players of a run get individual servers from the **identical** generated world. Connection info, goal detection, leaderboard, and the member-facing flow are unchanged from the player's perspective — only generation timing/source changes.

**AC7 (quality gates):** Orchestrator (`go test`, vet/lint) and API (phpstan, php-cs-fixer, phpunit, `app:architecture:ddd`) green. Functional coverage: webhook `weekly-gen` routing → run becomes launchable; `launchEntry` uses `launchFromFile` (spy gateway) and never calls generate.

## Tasks / Subtasks

### Orchestrator (Go repo `archilan-orchestrateur`, branch `master`)
- [x] Task 1: `docker.CopyOutputFromVolume(ctx, sessionID, filename) ([]byte, error)` — read the generated file out of the session volume (Docker copy-from-container/volume).
- [x] Task 2: `storage.UploadSessionOutput` / `DownloadSessionOutput` (MinIO sessions bucket, key derivable from session id). In `runGeneration`, after `UpdateSessionGenerated`, upload the output before firing the `session.generated` webhook.
- [x] Task 3: Tests for copy-from-volume + output upload/download. (No new HTTP route unless the MinIO-direct read is rejected.)

### API (PHP, monorepo `api/`)
- [x] Task 4: `GenerateWeeklyRunsMessageHandler` → async dispatch: create the run, then upload apworld + `configure` + `generate(seed)` against `weekly-gen-{weeklyRunId}` **without polling**; never block on generation.
- [x] Task 5: New `MarkWeeklyRunGenerated` application service: set the artifact key on the run (`markGenerated`), idempotent, then delete the generator session.
- [x] Task 6: `OrchestratorWebhookController` — detect `weekly-gen-{id}` session ids; route `session.generated` → `MarkWeeklyRunGenerated`, `session.crashed` → log generation failure (run stays not-launchable). Leave the non-weekly path untouched.
- [x] Task 7: `OrchestratorWeeklyRunnerGateway::launchEntry()` → download artifact from MinIO + `launchFromFile`; remove configure+generate+poll-generated.
- [x] Task 8: `WeeklyRun` — `generatedSeedPath` now holds the MinIO output key; rename to `generatedOutputKey` for clarity (+ migration). `OrchestratorWeeklyRunGenerator` is repurposed/removed (its apworld-upload role folds into Task 4's configure step).
- [x] Task 9: Update `NullWeeklyRunGenerator` / `SpyWeeklyRunnerGateway`; functional tests for webhook→launchable and launch-uses-launchFromFile.
- [x] Task 10: All quality gates (orchestrator + API).

### Frontend (follow-up, can be a separate slice)
- [ ] Task 11: Member weekly-run page shows "Génération en cours…" while `generatedSeedPath` is null (launch disabled), flipping to launchable once the webhook lands. (Out of core scope; track separately if preferred.)

## Dev Notes

### Recommended mechanism (AC1)

Prefer **MinIO-as-handoff** over a streaming endpoint: at the end of `runGeneration`, upload the output to the sessions bucket; the PHP side already has `MinioStorageInterface` and presigned-URL plumbing (see `OrchestratorWeeklyRunGenerator`), so it can download the artifact without a new orchestrator endpoint or client method. This keeps the artifact durable across orchestrator restarts and avoids large multipart round-trips through PHP. A `GET /sessions/{id}/output` endpoint is the fallback if direct MinIO access from the API is undesirable.

### Files (current → change)

- `orchestrateur/internal/service/session.go` — `runGeneration` (add output upload), maybe new `GetSessionOutput`.
- `orchestrateur/internal/storage/client.go` — add `UploadSessionOutput` / `DownloadSessionOutput`.
- `orchestrateur/internal/docker/client.go` — add copy-from-volume for the output file.
- `orchestrateur/internal/api/{router,session_handlers}.go` — optional `GET /sessions/{id}/output`.
- `api/src/WeeklyRuns/Infrastructure/OrchestratorWeeklyRunGenerator.php` — real generation + store artifact.
- `api/src/WeeklyRuns/Infrastructure/OrchestratorWeeklyRunnerGateway.php` — `launchEntry` → `launchFromFile` (already in client).
- `api/src/WeeklyRuns/Domain/WeeklyRun.php` — artifact reference field.
- `api/src/WeeklyRuns/Application/Handler/GenerateWeeklyRunsMessageHandler.php` — unchanged in shape (still calls `generator->generate`), but `generate` now does the heavy work; mind the per-template timeout (generation can take minutes — ensure the Messenger transport/worker timeout accommodates it, or make generation async and mark the run generated via webhook).

### Async flow (locked — option b)

```
Monday 00:00  GenerateWeeklyRunsMessageHandler
  └─ per active template:
       create WeeklyRun (active, generatedSeedPath=null → not launchable)
       upload apworld → configure(weekly-gen-{runId}) → generate(seed)   [non-blocking]

Orchestrator (goroutine)
  runGeneration → GenerateMultiworld → UpdateSessionGenerated
       → UploadSessionOutput(sessionId)            [new]
       → webhook session.generated (sessionId)      [already emitted]

API  POST /internal/orchestrateur/webhook
  OrchestratorWebhookController
    sessionId starts with "weekly-gen-"?
      yes → MarkWeeklyRunGenerated(runId, artifactKey)  → markGenerated + delete generator session
      no  → existing SessionLifecycleManager path (unchanged)

Player "Lancer ma partie"  LaunchWeeklyEntry → launchEntry
  download artifact (MinIO) → launchFromFile(entryId, output, ...)   [zero generation]
```

Mapping is by the deterministic id `weekly-gen-{weeklyRunId}`; the artifact MinIO key is
derived from the session id, so the existing webhook payload (`event` + `sessionId`) needs
no change. Idempotency: `MarkWeeklyRunGenerated` is a no-op if the run already has an
artifact (duplicate webhooks, retries).

### Open design points to confirm with the architect

1. **Generator session id convention** — `weekly-gen-{weeklyRunId}` (parsed in the webhook) vs storing the generator session id on the `WeeklyRun`. Convention chosen for zero extra state; confirm it can't collide with real session ids.
2. **Retry policy on generation failure** — none + manual admin re-trigger (chosen) vs bounded auto-retry.
3. **Generator-session cleanup owner** — API deletes it on `session.generated` (chosen) vs orchestrator self-cleans after upload.
4. **Frontend "génération en cours" state** — in this story or a separate slice (Task 11).

### Cross-repo / delivery

Spans three repos: `archilan-orchestrateur` (Go, master), `archilan/orchestrateur-client` (PHP package — only if a download endpoint is added), and the `api/` monorepo. Sequence carefully: orchestrator + client first (publish), then API against the new capability.

### Out of scope

- Changing the per-player individual-server model (each player still gets their own server; only the world is shared/pre-generated).
- Player YAML customization (remains fixed per epic 23).

## Change Log

| Date       | Change                                                                 |
|------------|------------------------------------------------------------------------|
| 2026-06-06 | Story drafted after investigating the orchestrator. Confirmed `launch-from-file` reuse primitive exists (Go + PHP client); the missing piece is retrieving the generated output bytes for storage/reuse. Pending architect grooming on sync-vs-async Monday generation. |
| 2026-06-06 | Refined to the **async/webhook-driven** design (option b): non-blocking Monday dispatch, `weekly-gen-{runId}` generator sessions, orchestrator uploads output to MinIO + fires `session.generated`, `OrchestratorWebhookController` routes weekly ids to a new `MarkWeeklyRunGenerated` (idempotent, cleans up the generator session). No new `WeeklyRun` status needed (`generatedSeedPath===null` already gates launch). Tasks re-split (orchestrator output-persistence; API dispatch/webhook/launchFromFile). 4 open points listed for confirmation. |
| 2026-06-07 | 4 open points confirmed (deterministic `weekly-gen-{runId}`, no auto-retry + admin re-trigger, API-side cleanup, frontend = separate slice). **Implemented Tasks 1–10** across two repos. Artifact handoff uses an additive `outputKey` field on the `session.generated` webhook (PHP MinIO has no `list`, so a derivable key alone is insufficient — the key carries the real filename/extension). Column `generated_seed_path` renamed to `generated_output_key`; legacy `DockerWeeklyRunGenerator` deleted (incompatible with async model). Orchestrator: PR [archilan-orchestrateur#1](https://github.com/ArchiLAN-dev/archilan-orchestrateur/pull/1) (`go build/vet/test` green). API: branch `feature/epic-23-story-8-generate-once-reuse-seed` (phpstan, cs-fixer, phpunit 923/923, ddd green). Task 11 (member "génération en cours" UI) deferred to a separate slice. Pending: live end-to-end integration (orchestrator + MinIO + Docker). |
