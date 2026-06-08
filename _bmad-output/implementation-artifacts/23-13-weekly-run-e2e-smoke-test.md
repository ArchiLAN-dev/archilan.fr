# Story 23.13: Weekly Run — Live End-to-End Smoke Test

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the ArchiLAN engineering team,
I want an automated end-to-end smoke test of the Weekly Run flow against the real
orchestrateur + MinIO + Docker stack,
so that cross-service contract regressions (generation artifact, launch restore, member
patches, download) are caught automatically instead of only in manual live testing.

## Context

Epic 23's retrospective (action item #1) found that the quality gates
(phpstan, phpunit with `Spy`/`Null` gateways, frontend build) validate the **PHP contract**
but never exercise the real **orchestrateur → bridge → ap-server** runtime. Every 23.8
regression escaped the gates and was caught only by hand:

- reachability broke (session volume missing `/data/yamls` + `/data/worlds`) → 23.10
- only the multidata was persisted, per-player patches + spoiler lost → 23.12
- zip-in-zip when AP produced a bundle → orchestrateur PR #4
- download saved as `.archipelago` (CORS `Content-Disposition` not exposed) → PR #32

This story automates the manual verification that ultimately proved the flow correct, so the
same class of bug fails CI. It also **de-risks the upcoming migration epics 24/25/26**, which
change more inter-service contracts.

## Acceptance Criteria

1. A single-command smoke test exists (e.g. `scripts/e2e/weekly-smoke.sh` or a tagged
   integration test) that runs against the local `archilan` Docker stack (postgres,
   `archilan-orchestrateur`, `archilan-minio`, rabbitmq, mercure) and the `archipelago:latest`
   image — no manual steps beyond `docker compose up`.
2. **Generation:** the test triggers a weekly generation (dispatch `GenerateWeeklyRunsMessage`
   or `POST /api/v1/admin/weekly-runs/generate`), then waits until the run's
   `generated_output_key` is set (via the `session.generated` webhook → `MarkWeeklyRunGenerated`).
   It fails if generation/webhook does not complete within a bounded timeout.
3. **Artifact contract:** the stored object (`sessions/weekly-gen-{runId}/output/archive.zip`,
   served by `GET /api/v1/admin/weekly-runs/{runId}/output`) is asserted to be a **flat zip**
   whose entries are the real generated files — at least one `*.archipelago` (multidata) and,
   for a ROM game, at least one per-player patch (e.g. `*.aplm`). It fails on a **nested zip**
   (`*.zip` inside) or a lone `*.archipelago` with no archive wrapper.
4. **Launch restore:** after launching an entry (opt-in + `LaunchWeeklyEntry`), the session
   volume `archilan_session_{entryId}:/data/output` is asserted to contain the **loose** files
   (the `*.archipelago` and the per-player patch), proving `LaunchFromFile` extraction. The
   member patch listing (bridge `/output`) is asserted to expose the patch (`.aplm`), excluding
   `*.archipelago` and `*_spoiler`.
5. **Seed validity:** `ArchipelagoServer` is asserted to load the generated seed without error
   (hosts the game; e.g. log line `server listening` / `Hosting game`).
6. **Download filename:** the admin download response carries
   `Content-Disposition: attachment; filename="weekly-run-{runId}.zip"` and is CORS-exposed
   (so a cross-origin browser fetch reads the `.zip` name).
7. The test **cleans up** what it creates (generated run rows / orchestrateur sessions / volumes
   / MinIO objects / temp containers) and is **idempotent** (re-runnable without manual reset).
8. A CI job runs the smoke test (services-enabled runner). If full Docker-in-Docker is not yet
   feasible in CI, ship the test as a documented, single-command **runbook** gated behind a make
   target, and open a follow-up to wire CI — but the automation itself must exist and pass locally.

## Tasks / Subtasks

- [ ] Task 1 (AC: 1, 8): Choose the harness and location. Recommended: a shell orchestration
  script under `scripts/e2e/` (drives the stack + asserts via `docker exec`, `mc`, `php bin/console`),
  since the flow spans Go + PHP + Docker + MinIO and no single test runner covers it. Decide
  CI vs runbook based on runner Docker capabilities.
- [ ] Task 2 (AC: 2): Implement the trigger + wait-for-generated:
  - dispatch generation (prefer the real admin endpoint with an authenticated admin; fall back to
    a `messenger:`/console trigger). Do NOT ship a debug command in `src/` — use a test-only path.
  - poll `weekly_runs.generated_output_key` (DBAL) until set, bounded timeout.
- [ ] Task 3 (AC: 3, 6): Fetch the artifact via the admin endpoint and assert: HTTP 200,
  `Content-Disposition` filename `weekly-run-{runId}.zip`, body magic `PK\x03\x04`, and entry list
  (flat real files; reject nested `.zip` / lone `.archipelago`).
- [ ] Task 4 (AC: 4): Opt-in + launch an entry; assert the session volume `/data/output` holds the
  loose files incl. the per-player patch; assert the bridge `/output` patch list exposes the `.aplm`.
- [ ] Task 5 (AC: 5): Run `ArchipelagoServer` on the extracted seed (or assert the launched
  ap-server container is healthy + its logs show the game hosted) — no load error.
- [ ] Task 6 (AC: 7): Teardown: terminate the orchestrateur session(s), remove temp containers,
  delete the created run + entry + MinIO objects. Make the whole script idempotent.
- [ ] Task 7 (AC: 8): Wire a CI job (services) or a `make e2e-weekly` target + runbook in
  `docs/`/README; document prerequisites (`archilan_test`/dev DB, stack up, AP image built).

## Dev Notes

This story automates the exact manual path proven during the 23.x work — reuse those touch points
rather than inventing new ones.

### Flow & touch points (verified by hand)

- **Trigger:** `App\WeeklyRuns\Presentation\Admin\AdminGenerateWeeklyRunsController`
  (`POST /api/v1/admin/weekly-runs/generate`) dispatches `GenerateWeeklyRunsMessage`
  (sync handler `GenerateWeeklyRunsMessageHandler`). It creates the `WeeklyRun`
  (active, `generated_output_key = null`) then dispatches generation to the orchestrateur.
- **Generator:** `OrchestratorWeeklyRunGenerator::generate` → orchestrateur session
  `weekly-gen-{runId}` (configure + generate, non-blocking).
- **Completion:** orchestrateur `runGeneration` zips `/data/output` → MinIO
  `sessions/weekly-gen-{runId}/output/archive.zip`, fires `session.generated` (with `outputKey`).
  `Sessions/Presentation/OrchestratorWebhookController` routes `weekly-gen-*` →
  `MarkWeeklyRunGenerated` (sets `generated_output_key`, idempotent, deletes the generator session).
- **Launch:** `LaunchWeeklyEntry` → `OrchestratorWeeklyRunnerGateway::launchEntry(entryId,
  apworldHash, templateYaml, outputKey)`: configure entry session, download artifact from MinIO,
  `client.sessions().launchFromFile(...)`. Orchestrateur `LaunchFromFile` stages yamls/worlds
  (`buildDataTar`) **and** extracts the artifact zip into `/data/output`, then launches
  bridge + ap-server.
- **AP server:** `archipelago/ap_server.sh` runs `ArchipelagoServer "$(ls *.zip *.archipelago | head -1)"`.
- **Member patches:** `WeeklyRuns/Presentation/WeeklyEntryPatchController` → bridge `/output`;
  the download path filters out `*.archipelago` and `*_spoiler`.
- **Admin download:** `WeeklyRuns/Presentation/Admin/AdminWeeklyRunOutputDownloadController`
  streams the MinIO object; `nelmio_cors` exposes `Content-Disposition`.

### Concrete assertions reference (from the manual run that passed)

- Artifact entries observed: `AP_*.archipelago`, `AP_*_P2_Player1.aplm`, `AP_*_Spoiler.txt`.
- MinIO inspect: `docker exec archilan-minio mc cp local/sessions/{key} /tmp/a.zip` then list.
- Launched volume: `docker exec ap-server-{entryId} ls -la /data/output` → loose files incl `.aplm`.
- Seed load: run `archipelago:latest` (`ARCHIPELAGO_OUTPUT_DIR=/data/output`) → logs
  `Loading embedded data package for game <Game>` + `server listening on 0.0.0.0:38281`.
- Download headers (cross-origin): `Content-Disposition: attachment; filename="weekly-run-{id}.zip"`,
  body starts `50 4b 03 04`.

### Project Structure Notes

- New artifacts live under `scripts/e2e/` (no production `src/` changes). Do **not** leave any
  throwaway console command in `api/src/` (the 23.x debugging used temporary `Debug*Command`s that
  were deleted — the test must trigger generation via the real endpoint or a test-only mechanism).
- Auth for the admin endpoint uses the signed cookie `__Host-archilan_session`
  (`AuthSessionSigner`, HMAC of `{sub,iat,exp}` with `APP_SECRET`). The test must obtain a real
  admin session (login flow / test fixture) rather than forging it ad hoc.
- Windows/git-bash note: `docker cp`/exec paths get MSYS path-mangled; prefer container-to-container
  `docker cp - | docker cp -` streams or `MSYS_NO_PATHCONV=1`. CI (Linux) is unaffected.

### Testing standards

- This is an **integration/smoke** layer, distinct from the existing unit/functional suites
  (which keep using `Spy`/`Null` gateways). It must run the **real** orchestrateur image
  (`archilan-orchestrateur`) and `archipelago:latest`.
- Keep it fast and bounded (timeouts), single happy-path; deeper scenarios can follow.

### References

- [Source: _bmad-output/implementation-artifacts/epic-23-retro-2026-06-08.md#Action Items]
- [Source: _bmad-output/implementation-artifacts/23-8-weekly-run-generate-once-reuse-seed.md]
- [Source: _bmad-output/implementation-artifacts/23-10-fix-weekly-reachability-staging.md]
- [Source: _bmad-output/implementation-artifacts/23-12-full-generation-output-zip.md]
- [Source: archipelago/ap_server.sh]
- [Source: api/src/WeeklyRuns/Presentation/Admin/AdminWeeklyRunOutputDownloadController.php]
- [Source: orchestrateur/internal/service/session.go (runGeneration, LaunchFromFile, buildOutputArtifact)]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-08 | Story created from epic 23 retrospective action item #1 (live weekly E2E smoke test). |
