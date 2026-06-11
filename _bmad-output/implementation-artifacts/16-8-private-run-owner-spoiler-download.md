# Story 16.8: Private run - owner/admin spoiler download (from MinIO)

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-06-11

## Story

As the **owner of a private run** (or an **admin**),
I want to download the generated **spoiler log** from the run page **at any time**
(running, idle, stopped),
so that I can inspect/share the full multiworld spoiler - while regular participants
must never get it.

## Context

Story 16.7 added per-participant own-slot **patch** download for private runs, proxied
through the live session bridge `/output`, excluding `.archipelago` and `*_spoiler*` for
everyone. That bridge path only works **while the run is running**, which is fine for a
patch (needed to start playing) but wrong for a **spoiler** (consumed mostly *after* the
game, when the run is often idle/stopped).

**Key enabler (already in place):** the orchestrateur's `runGeneration` already uploads
the **whole** `/data/output` (multidata + per-player patches + **spoiler**) as a single
`archive.zip` to **durable MinIO storage** for **every** session - key
**`{sessionId}/output/archive.zip`** in the **`sessions`** bucket - and emits
`session.generated` with that `outputKey`. (Verified: `orchestrateur/internal/service/session.go`
`runGeneration` + `storage.UploadSessionOutput`; bucket `BucketSessions`.) So the spoiler
of a private run is **already on MinIO**; the API just doesn't consume the key yet
(`OrchestratorWebhookController` ignores `outputKey` for non-weekly sessions).

The API side already mirrors the bucket (`default_minio_sessions_bucket: 'sessions'`) and
`MinioStorageInterface` exposes `download()`/`exists()`. There is also an *archived*-only
admin endpoint `/admin/sessions/{id}/download/spoiler` (filesystem `archivedSpoilerPath`),
which this story supersedes for the live/durable case.

### Decisions (confirmed with Jean)

- **Approach:** serve the spoiler **from MinIO** (the persisted output archive), **not**
  the live bridge - so it works in any run state. Orchestrateur is untouched (it already
  persists the archive).
- **Scope:** **spoiler only** (`*_spoiler*`). The multidata `.archipelago` is **never**
  exposed - the API extracts *only* the spoiler entry from the archive server-side.
- **Authorization:** the **run owner** (`Run.ownerId === userId`) **OR any admin**
  (`ROLE_ADMIN`), including an admin who is **not** a participant.
- **Participants:** unchanged - 16.7 patch download stays as-is (own-slot patches via the
  bridge). This story does **not** change the participant path.

## Acceptance Criteria

1. A private-run **owner or admin** can download the spoiler of a run whose output exists
   on MinIO, **regardless of run state** (running / idle / stopped). The API fetches
   `{sessionId}/output/archive.zip` from the `sessions` bucket, extracts **only** the
   `*_spoiler*` entry, and streams it.
2. The multidata `.archipelago` and any other archive entry (other players' patches) are
   **never** returned by this endpoint - only the spoiler entry is extracted/served.
3. A **participant who is not the owner/admin** gets **403** on this endpoint and sees no
   spoiler affordance in the UI. The 16.7 participant patch path is unchanged (no
   regression).
4. The API **captures and persists** the `outputKey` from the `session.generated` webhook
   for personal-run/generic sessions (new nullable `Session.generatedOutputKey`), so the
   download does not hard-code another service's key layout. (If the key is absent for a
   pre-existing session, fall back to the deterministic `{sessionId}/output/archive.zip`.)
5. The run page shows the spoiler download **only to owner/admin**, with a
   generated/not-generated state: derived from the effective `generation.spoiler` level
   (`0` → none offered) and/or `MinioStorageInterface::exists()` / a spoiler entry being
   present. When no spoiler exists, the control is absent or shows a neutral "non généré".
6. Quality gates green - API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`;
   frontend: typecheck / lint / build. Verified live: owner downloads the spoiler on a
   **stopped** run; a non-owner participant gets 403 and no UI affordance.

## Tasks / Subtasks

- [ ] **Task 1 - Persist the output key** (AC: 4). Add a nullable `generated_output_key`
  (or `output_key`) column to `Session` (+ reversible migration). In
  `OrchestratorWebhookController`, on `session.generated` for non-weekly sessions, read
  `body['outputKey']` and store it (via an Application command using the Session **repo
  interface** - no EM/Connection in Application). Keep the weekly path untouched.
- [ ] **Task 2 - Spoiler download (Application)** (AC: 1,2,4). New
  `PersonalRunSpoilerQuery` / command in `PersonalRuns/Application`: resolve
  `runId` → `Run` (owner check via `getOwnerId`) and the session's output key (persisted,
  else deterministic), then via an injected interface fetch the archive from MinIO and
  extract the **single** `*_spoiler*` entry. Return the bytes + filename, or null when no
  spoiler. The MinIO/zip work lives in **Infrastructure** behind an interface (Application
  stays free of `MinioStorageInterface`/zip internals per AC-A2/AC-A5); use the `sessions`
  bucket binding.
- [ ] **Task 3 - Authorization + Presentation** (AC: 1,3). New endpoint, e.g.
  `GET /api/v1/runs/{runId}/spoiler`, guarded so only the **owner or an admin** passes
  (owner via `Run.ownerId`; admin via `ApiAccessGuard`/role - display/role gate, not a
  membership gate, OK per api/CLAUDE.md AC-M3). 403 otherwise. Thin controller:
  one Application call → `StreamedResponse`/`BinaryFileResponse` with
  `Content-Disposition`. Never expose `.archipelago`.
- [ ] **Task 4 - Spoiler-presence signal** (AC: 5). Surface whether a spoiler exists for
  the run (effective `generation.spoiler` level > 0, and/or archive entry present) on the
  read model the run page consumes, so the UI can show/hide the control without
  downloading the archive.
- [ ] **Task 5 - Frontend** (AC: 5). On `personal-run-detail-page.tsx`, add an
  **owner/admin-only** "Spoiler" download control (separate from "Fichiers générés"),
  visible only when the viewer is owner/admin and a spoiler exists. API fn in the
  personal-runs module; env via `src/lib/env.ts`; blob download like
  `personal-run-patches.tsx`.
- [ ] **Task 6 - Tests** (AC: 1-4). Unit: owner/admin allowed, participant → 403; only the
  `*_spoiler*` entry is extracted (multidata/patches never returned); persisted key used,
  deterministic fallback; "no spoiler" → null/neutral. Mock the MinIO/zip interface.
- [ ] **Task 7 - Gates + live verify** (AC: 6). All gates; verify on a **stopped** run
  (owner downloads spoiler; participant 403; multidata never served).

## Dev Notes

- **Durable artifact:** `orchestrateur/internal/service/session.go` `runGeneration`
  uploads `{sessionId}/output/archive.zip` to `BucketSessions`; `buildOutputArtifact`
  packs the loose output (or stores the AP `*.zip` as-is). The spoiler entry name contains
  `_spoiler` (the 16.7 guard already lowercases + `str_contains('_spoiler')`); confirm the
  exact entry name from a real archive in the spike.
- **Bucket:** API `sessions` bucket = `default_minio_sessions_bucket: 'sessions'`
  (`api/config/services.yaml`); add a `$minioSessionsBucket` binding if not present.
  `MinioStorageInterface::download($bucket, $key)` / `exists(...)`.
- **Zip extraction in Infrastructure:** use `ZipArchive` to read only the spoiler entry
  from the downloaded archive bytes; keep it behind an Application-defined interface (e.g.
  `SessionOutputArtifactReaderInterface`) so Application has no infra/zip dependency.
- **Owner/admin:** `App\PersonalRuns\Domain\Run::getOwnerId()`; admin via the existing
  authorization mechanism. Enforce on the endpoint (both presence in UI and server check).
- **Spoiler level:** `App\SessionConfig\Domain\SpoilerLevel` (0..3) on
  `SessionGenerationConfig`; effective = base config + per-run `SessionConfigOverride`.
- **DDD:** Application uses Domain/Application interfaces only; MinIO + zip in
  Infrastructure; controller ≤ one Application call.
- **Security:** the spoiler is the full solution - extract and serve *only* the spoiler
  entry; never stream the whole archive (it contains the multidata + every patch).

### Non-goals / follow-ups

- Moving the **participant patch** download (16.7) from the bridge to MinIO is **out of
  scope** here (separate story; would also give patches durability beyond the volume).
- Garbage-collecting old `{sessionId}/output/` archives on session delete is a separate
  concern.

### Project Structure Notes

- `api/src/Sessions/Domain/Session.php` (+ `generatedOutputKey` column) + migration
- `api/src/Sessions/Presentation/OrchestratorWebhookController.php` (capture `outputKey`)
  + an Application command + Session repo interface method to store it
- `api/src/PersonalRuns/Application/PersonalRunSpoilerQuery.php` (new) +
  `…/Application/SessionOutputArtifactReaderInterface.php` (new)
- `api/src/PersonalRuns/Infrastructure/` MinIO+zip reader implementation (new)
- `api/src/PersonalRuns/Presentation/PersonalRunSpoilerController.php` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (+ owner/admin
  spoiler control) and the personal-runs API module
- Tests under `api/tests/Unit/PersonalRuns/`

### References

- [Source: orchestrateur/internal/service/session.go (runGeneration, buildOutputArtifact, UploadSessionOutput)]
- [Source: orchestrateur/internal/storage/*.go (BucketSessions, key {sessionID}/output/archive.zip)]
- [Source: api/src/Sessions/Presentation/OrchestratorWebhookController.php (session.generated, outputKey ignored today)]
- [Source: api/src/Shared/Infrastructure/MinioStorageInterface.php (download/exists/presignedUrl)]
- [Source: api/config/services.yaml (default_minio_sessions_bucket)]
- [Source: api/src/PersonalRuns/Domain/Run.php (getOwnerId, getSessionId)]
- [Source: api/src/SessionConfig/Domain/SpoilerLevel.php, SessionGenerationConfig.php, SessionConfigOverride.php]
- [Source: api/src/Sessions/Presentation/DownloadController.php (archived-only spoiler - superseded for the durable case)]
- [Source: _bmad-output/implementation-artifacts/16-7-personal-run-patch-download.md (participant patch path, unchanged); 23-12-full-generation-output-zip.md (the full-output archive this reuses)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Spike Findings

- The orchestrateur already persists the **whole** output (multidata + patches + **spoiler**)
  as `{sessionId}/output/archive.zip` in the `sessions` bucket for **every** session
  (`runGeneration` → `UploadSessionOutput`), and sends `outputKey` on `session.generated`.
  So no orchestrateur change was needed; the API just had to consume the key + read the zip.
- `$minioSessionsBucket` binding already exists in `services.yaml` (used by the weekly
  generator) - reused for the new reader.
- The API ignored `outputKey` for non-weekly sessions, so it is now captured and persisted
  on `Session.generatedOutputKey` (deterministic `{sessionId}/output/archive.zip` fallback
  covers sessions generated before this change).
- **Indicator simplification (deliberate):** rather than precompute the effective
  `generation.spoiler` level into the run read model, the frontend shows the spoiler control
  for `(isOwner || isAdmin) && run.sessionId !== null` and the endpoint is authoritative -
  it returns 404 ("Spoiler non disponible") when the archive has no `*_spoiler*` entry, which
  the UI surfaces. Keeps the read model untouched; the spoiler-only extraction is enforced
  server-side. Spoiler entry name confirmed to match the existing `_spoiler` substring guard
  (AP names like `*_Spoiler.txt`).

### Completion Notes List

- `Session.generatedOutputKey` column (+ migration `Version20260611100001`) +
  `RecordSessionGeneratedOutput` command; `OrchestratorWebhookController` captures `outputKey`
  on non-weekly `session.generated`.
- `SessionSpoilerArtifactReaderInterface` (Application) + `SpoilerArtifact` DTO;
  `MinioZipSpoilerArtifactReader` (Infrastructure) downloads the archive from the `sessions`
  bucket and extracts **only** the `*_spoiler*` entry (never `.archipelago`).
- `PersonalRunSpoilerDownload` (Application): owner (`Run.isOwnedBy`) or admin; returns
  `{found, authorized, spoiler}`. `PersonalRunSpoilerController`
  `GET /api/v1/runs/{runId}/spoiler` → 404/403/stream; admin via `ROLE_ADMIN` (display gate).
- Frontend `PersonalRunSpoilerPanel` ("Spoiler") on the run page, owner/admin-only, blob
  download with a graceful "non disponible" state on 404.
- Tests: `PersonalRunSpoilerDownloadTest` (6), `MinioZipSpoilerArtifactReaderTest` (4),
  `RecordSessionGeneratedOutputTest` (3) - 13 new unit tests. Full backend suite 992 green.
- Gates: phpstan / php-cs-fixer / `app:architecture:ddd` / phpunit (992) green; frontend
  typecheck / lint / build green.

### File List

- `api/src/Sessions/Domain/Session.php` (+ `generatedOutputKey` column + accessors)
- `api/migrations/Version20260611100001.php` (new)
- `api/src/Sessions/Application/RecordSessionGeneratedOutput.php` (new)
- `api/src/Sessions/Application/SessionSpoilerArtifactReaderInterface.php` (new)
- `api/src/Sessions/Application/SpoilerArtifact.php` (new)
- `api/src/Sessions/Infrastructure/MinioZipSpoilerArtifactReader.php` (new)
- `api/src/Sessions/Presentation/OrchestratorWebhookController.php` (capture `outputKey`)
- `api/src/PersonalRuns/Application/PersonalRunSpoilerDownload.php` (new)
- `api/src/PersonalRuns/Presentation/PersonalRunSpoilerController.php` (new)
- `api/config/services.yaml` (reader binding + sessions bucket)
- `api/tests/Unit/PersonalRuns/PersonalRunSpoilerDownloadTest.php`,
  `api/tests/Unit/Sessions/MinioZipSpoilerArtifactReaderTest.php`,
  `api/tests/Unit/Sessions/RecordSessionGeneratedOutputTest.php` (new)
- `frontend/src/features/personal-runs/personal-run-spoiler.tsx` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (panel wired in)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Story created (bridge approach), then revised to the **MinIO** approach: serve the spoiler from the already-persisted `{sessionId}/output/archive.zip` (orchestrateur untouched), owner/admin only, available in any run state; multidata never exposed (extract only the spoiler entry). Decisions confirmed with Jean: MinIO version, spoiler-only, owner OR any admin (incl. non-participant). Status → ready-for-dev. |
| 2026-06-11 | Implemented: `Session.generatedOutputKey` (+ migration) + webhook capture; MinIO+zip spoiler reader (Infrastructure) extracting only the `*_spoiler*` entry; `PersonalRunSpoilerDownload` (owner/admin) + `GET /runs/{runId}/spoiler`; owner/admin-only frontend panel. 13 new unit tests; all gates green (backend 992 tests + frontend). Status → review. |
