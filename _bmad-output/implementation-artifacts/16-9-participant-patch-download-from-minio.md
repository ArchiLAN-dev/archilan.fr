# Story 16.9: Private run - participant patch download from MinIO (durable)

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-06-11

## Story

As a participant in a private run,
I want to download my own slot's patch(es) **at any time** (running, idle, stopped),
so that I can (re)patch my ROM without depending on the run being live.

## Context

Story 16.7 serves participant patches by proxying the **live** session bridge `/output`,
filtered to the participant's own slot (`belongsToOwnSlot`), excluding `.archipelago` and
`*_spoiler*`. That only works **while the run is running** - patches disappear once the run
is idle/stopped (the bridge port is released), and they are lost entirely if the session
volume is reclaimed.

Story 16.8 established the durable path: the orchestrateur already persists the **whole**
generated output (multidata + **all per-player patches** + spoiler) as
`{sessionId}/output/archive.zip` in the `sessions` MinIO bucket, the API captures the key
on `session.generated` (`Session.generatedOutputKey`, deterministic fallback), and a
MinIO+zip reader extracts entries from that archive
(`MinioZipSpoilerArtifactReader` / `SessionSpoilerArtifactReaderInterface`).

This story moves the **participant patch** download onto that same durable archive, so a
player can fetch their patch whatever the run's state, with the **exact same own-slot
filtering** as 16.7.

### Decisions (confirmed with Jean)

- **Source of truth = MinIO archive** (not the live bridge). The participant patch path
  drops its bridge dependency entirely (patches never change after generation, so the
  generation archive is authoritative).
- **Filtering unchanged:** a participant only ever sees/downloads **their own slot's**
  patch(es); `.archipelago` and `*_spoiler*` are never exposed (defence in depth on list and
  download) - reuse `belongsToOwnSlot`.
- **Auth unchanged:** authenticated **participants** of the run (owner included);
  non-participants get 403/404. (This story does not touch the owner/admin **spoiler** path
  from 16.8.)

## Acceptance Criteria

1. A participant lists and downloads their own slot's patch(es) from the durable MinIO
   archive (`{sessionId}/output/archive.zip`), **regardless of run state**
   (running / idle / stopped).
2. The own-slot filter is unchanged: `.archipelago` and any `*_spoiler*` are never listed
   nor downloadable, and a participant can never access another player's patch
   (`belongsToOwnSlot` enforced on **both** list and download).
3. Only authenticated **participants** of the run (owner included) may list/download; others
   get 403/404. No regression vs 16.7 on the filtering/authorization behaviour.
4. The participant patch path no longer depends on the live bridge `/output`
   (no `localhost:{bridgePort}` call for patches).
5. When `Session.generatedOutputKey` is absent (sessions generated before 16.8), fall back to
   the deterministic `{sessionId}/output/archive.zip` key.
6. Quality gates green - API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`;
   frontend: typecheck / lint / build (the existing `PersonalRunPatchPanel` should keep
   working; relax/adjust its `enabled` gate so patches show when the run has been generated,
   not only when `active`). Verified live: a participant downloads their patch on a
   **stopped** run.

## Tasks / Subtasks

- [ ] **Task 1 - Generalize the artifact reader** (AC: 1,2). In `Sessions`, introduce a
  general output-archive reader interface, e.g.
  `SessionOutputArtifactReaderInterface { listEntries(string $outputKey): list<string>;
  extractEntry(string $outputKey, string $entryName): ?SessionOutputArtifact }`
  (DTO `{filename, contents}`), implemented in Infrastructure over MinIO + `ZipArchive`
  (reuse the temp-file/zip approach from `MinioZipSpoilerArtifactReader`). Refactor the 16.8
  spoiler reader to build on it (the spoiler reader becomes "find the `*_spoiler*` entry then
  `extractEntry`"), keeping its behaviour and tests green.
- [ ] **Task 2 - Patch query on the archive** (AC: 1,2,3,5). Update
  `PersonalRunPatchQuery` (or add a query) to resolve `runId` + `userId` →
  `{ outputKey, slotNames }` (run → `Session`; participant slot names via
  `findByRegistrationAndSession`; output key from `Session.generatedOutputKey` or
  deterministic). Drop `bridgePort` from this path. Return null when not a participant / no
  session / no output key resolvable.
- [ ] **Task 3 - Controller switches to MinIO** (AC: 1-4). In `PersonalRunPatchController`,
  replace `listFromBridge` / `downloadFromBridge` with the archive reader: **list** = filter
  archive entries by `belongsToOwnSlot` (own slot, excluding `.archipelago`/`*_spoiler*`);
  **download** = re-check `belongsToOwnSlot` then `extractEntry` and stream. Keep the routes
  (`GET /runs/{runId}/patches`, `GET /runs/{runId}/patches/{filename}`) and the
  `requireAuthenticatedUser` + participation guard. Remove the injected `HttpClientInterface`
  if no longer used here.
- [ ] **Task 4 - Frontend** (AC: 6). Adjust `PersonalRunPatchPanel`'s `enabled` so patches
  are offered once the run has been generated (e.g. `run.sessionId !== null`) rather than
  only when `run.status === "active"` - patches are now durable. No other UI change.
- [ ] **Task 5 - Tests** (AC: 1-5). Unit: own-slot filter against archive entry names
  (own slot listed; other slots, `.archipelago`, `*_spoiler*` excluded); deterministic-key
  fallback; non-participant → null; reader `listEntries`/`extractEntry` over an in-memory zip.
  Update/retire 16.7 bridge-path tests that no longer apply.
- [ ] **Task 6 - Gates + live verify** (AC: 6). All gates; verify a participant downloads
  their own patch on a **stopped** run, and that `.archipelago`/spoiler/other slots are not
  exposed.

## Dev Notes

- **Reuse 16.8 infra:** `App\Sessions\Infrastructure\MinioZipSpoilerArtifactReader` (MinIO
  download + temp-file `ZipArchive`), `Session.generatedOutputKey`, the `sessions` bucket
  binding. Generalize rather than duplicate.
- **Filename matching:** keep `PersonalRunPatchController::belongsToOwnSlot` as-is (AP names
  `AP_{seed}_P{n}_{slotName}.{ext}`, exact slot match; excludes `.archipelago` and
  `*_spoiler*`). It now matches **archive entry basenames** instead of bridge `/output`
  names - same shape.
- **Performance:** listing/extracting downloads the whole archive from MinIO per request.
  Archives are small (a few MB); acceptable. A `{sessionId}/manifest.json` exists in the
  sessions bucket (written by the orchestrateur) and *might* allow listing without the full
  download - investigate as an optional optimisation, not a dependency.
- **DDD:** Application uses the reader interface (defined in Application) + Domain repos; the
  MinIO/zip work stays in Infrastructure; controller ≤ one Application call.
- **Security:** unchanged guarantees - own-slot only, never multidata/spoiler/other patches.

### Non-goals / follow-ups

- The **weekly-run** patch path (`WeeklyEntryPatchController`) still proxies the bridge; moving
  it to MinIO (weekly already has `weekly_runs.generated_output_key`) is a separate story.
- Caching the archive / serving via pre-signed URLs, and garbage-collecting
  `{sessionId}/output/` on run delete, are out of scope.

### Project Structure Notes

- `api/src/Sessions/Application/SessionOutputArtifactReaderInterface.php` (new) +
  `SessionOutputArtifact.php` (new); refactor `SessionSpoilerArtifactReaderInterface` /
  `MinioZipSpoilerArtifactReader` onto it
- `api/src/PersonalRuns/Application/PersonalRunPatchQuery.php` (MinIO key + slot names; drop
  bridge port)
- `api/src/PersonalRuns/Presentation/PersonalRunPatchController.php` (bridge → archive)
- `frontend/src/features/personal-runs/personal-run-patches.tsx` /
  `personal-run-detail-page.tsx` (relax `enabled`)
- Tests under `api/tests/Unit/PersonalRuns/` and `api/tests/Unit/Sessions/`

### References

- [Source: _bmad-output/implementation-artifacts/16-7-personal-run-patch-download.md (current bridge path being replaced)]
- [Source: _bmad-output/implementation-artifacts/16-8-private-run-owner-spoiler-download.md (MinIO reader + Session.generatedOutputKey to reuse)]
- [Source: api/src/PersonalRuns/Presentation/PersonalRunPatchController.php (belongsToOwnSlot, bridge proxy)]
- [Source: api/src/PersonalRuns/Application/PersonalRunPatchQuery.php (forParticipant)]
- [Source: api/src/Sessions/Infrastructure/MinioZipSpoilerArtifactReader.php]
- [Source: orchestrateur/internal/storage/*.go ({sessionId}/output/archive.zip, {sessionId}/manifest.json)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Spike Findings

- `{sessionId}/manifest.json` only holds the **input apworld refs** (`storage.Manifest{Apworlds}`),
  not the output file list - useless for listing patches. So we list by reading the zip
  (download + `ZipArchive`). Acceptable (archives are small).
- Archive entries are flat basenames matching `belongsToOwnSlot` (AP names
  `AP_{seed}_P{n}_{slotName}.{ext}`); the reader matches entries by **basename** for robustness.
- Decided **not** to refactor the 16.8 spoiler reader onto the new general reader: 16.8 just
  shipped and was validated in prod, so churning it adds risk for little gain. The two
  Infrastructure readers share a small temp-file/zip scaffold (isolated, ~15 lines) - accepted.

### Completion Notes List

- New general reader `SessionOutputArtifactReaderInterface` (`listEntries` + `extractEntry`) +
  `SessionOutputArtifact` DTO; `MinioZipOutputArtifactReader` (Infrastructure) downloads the
  archive from the `sessions` bucket and lists/extracts entries by basename.
- `PersonalRunPatchQuery.forParticipant` now returns `{outputKey, slotNames}` (output key from
  `Session.generatedOutputKey` or deterministic fallback); the bridge port is gone.
- `PersonalRunPatchController` reads from the archive: list = entries filtered by
  `belongsToOwnSlot` (own slot, `.archipelago`/`*_spoiler*` excluded); download = re-check +
  `extractEntry` + stream. No more `HttpClientInterface` / bridge `/output` on this path.
- Frontend: `PersonalRunPatchPanel` `enabled` relaxed from `status === "active"` to
  `run.sessionId !== null` (patches are now durable).
- Tests: rewrote `PersonalRunPatchQueryTest` (6, new `{outputKey,...}` shape incl. deterministic
  fallbacks); new `MinioZipOutputArtifactReaderTest` (5, list/extract over an in-memory zip);
  `PersonalRunPatchFilterTest` unchanged. Full backend suite 998 green.
- Gates: phpstan / php-cs-fixer / `app:architecture:ddd` / phpunit (998) green; frontend
  typecheck / lint / build green.

### File List

- `api/src/Sessions/Application/SessionOutputArtifact.php` (new)
- `api/src/Sessions/Application/SessionOutputArtifactReaderInterface.php` (new)
- `api/src/Sessions/Infrastructure/MinioZipOutputArtifactReader.php` (new)
- `api/src/PersonalRuns/Application/PersonalRunPatchQuery.php` (bridge → MinIO key + slots)
- `api/src/PersonalRuns/Presentation/PersonalRunPatchController.php` (bridge → archive reader)
- `api/config/services.yaml` (output reader binding + sessions bucket)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (relax patch panel `enabled`)
- `api/tests/Unit/PersonalRuns/PersonalRunPatchQueryTest.php` (rewritten),
  `api/tests/Unit/Sessions/MinioZipOutputArtifactReaderTest.php` (new)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Story created. Follow-up to 16.8: move the participant patch download (16.7) from the live bridge to the durable MinIO output archive, so patches survive idle/stop and volume loss. Reuse the 16.8 MinIO+zip reader (generalized) + `Session.generatedOutputKey`. Same own-slot filtering/auth. Status → ready-for-dev. |
| 2026-06-11 | Implemented: general `SessionOutputArtifactReaderInterface` (list/extract) + `MinioZipOutputArtifactReader`; `PersonalRunPatchQuery`/`Controller` switched from bridge to the MinIO archive (own-slot filter unchanged); frontend patch panel enabled once generated. 16.8 spoiler reader left standalone (just-shipped, low-risk). Tests rewritten/added; all gates green (backend 998 + frontend). Status → review. |
