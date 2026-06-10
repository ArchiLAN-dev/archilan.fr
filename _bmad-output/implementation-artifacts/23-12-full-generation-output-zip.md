# Story 23.12 (bugfix): Persist the Whole Generation Output (zip), Not Just the Multidata

## Status

done

## Context

23.8 captured the generation output with `docker.CopyOutputFromVolume(sessionID, outputFile)` -
**a single file** (the one `generate_multiworld.py` prints: `*.zip` else `*.archipelago`). In
practice Archipelago writes **loose files** into `/data/output` (the `.archipelago` multidata,
**per-player patches**, the spoiler) without a zip, so only the `.archipelago` was persisted.
Consequences:

1. The admin per-template download (`/admin/weekly-runs/template/{id}`) only yielded the
   `.archipelago` - the other generated files were never uploaded to MinIO. ← user report
2. **Member patches broken**: `LaunchFromFile` injected only the `.archipelago` into
   `/data/output`; the member patch download reads that volume via the bridge `/output`, so
   ROM-game patches (e.g. Pokémon Emerald) were unavailable.

**Fix (single artifact = whole output, zipped):** the orchestrator now captures all of
`/data/output`, zips it, and stores it as the artifact (`outputKey` = the zip). On launch it
unzips the artifact back into `/data/output`. The AP server still loads the `.archipelago`,
patches return to the volume (member patch download works), and the admin download serves the
full zip. Backward compatible: legacy runs keep a `.archipelago` key (single file) - launch has
a non-zip branch and the admin download serves the object as-is.

## Acceptance Criteria

**AC1 (orchestrator):** generation persists the entire `/data/output` as one zip at
`sessions/{sessionId}/output/archive.zip`; `session.generated` `outputKey` points to it.
A copy/zip/upload failure crashes the session (no artifact-less "generated").

**AC2 (orchestrator):** `LaunchFromFile` unzips the artifact into `/data/output` (multidata +
patches + spoiler) when it is a zip; legacy single-file artifacts keep the old inject path.
yamls/worlds staging (23.10) is unchanged.

**AC3 (API/front):** the admin per-template download now serves the full zip (no API contract
change - it already streams `outputKey`); the per-run action is relabelled **"Fichiers générés"**
and the download filename is `weekly-run-{runId}.zip` for zip artifacts.

**AC4:** Gates green - orchestrator (`go build/vet/test`), API (`phpstan`, `php-cs-fixer`,
`phpunit`, `app:architecture:ddd`), frontend (`pnpm typecheck/lint/build`).

## Tasks / Subtasks

- [x] Task 1: Orchestrator - `CopyOutputDirFromVolume`; `tarToZip`/`zipToOutputTar`;
  `runGeneration` stores the zip; `LaunchFromFile` extracts it (backward-compat branch);
  remove the now-unused single-file `CopyOutputFromVolume`; Go round-trip tests.
- [x] Task 2: Frontend - relabel the download action "Seed" → "Fichiers générés".
- [x] Task 3: API - readable `Content-Disposition` (`weekly-run-{runId}.zip`) for zip artifacts.
- [x] Task 4: Gates (orchestrator + API + frontend).

## Dev Notes

- `generate_multiworld.py` prints one filename (`*.zip` else `*.archipelago`); the loose
  patches/spoiler in `/data/output` were previously dropped. Capturing the whole dir fixes it.
- `launchFromFile` is weekly-only - changing its semantics to unzip is safe; the non-zip branch
  preserves legacy behaviour.
- The member patch endpoint keeps filtering out `.archipelago` and `_spoiler`; the admin zip
  includes the spoiler (admin-only).

## File List

### Orchestrator (`archilan-orchestrateur`)
- `internal/docker/client.go` - `CopyOutputDirFromVolume` (+ removed `CopyOutputFromVolume`)
- `internal/service/session.go` - `tarToZip`/`zipToOutputTar`/`isZipArtifact`; gen stores zip; launch extracts
- `internal/service/session_test.go` - round-trip tests

### API / Frontend (`api/`)
- `src/WeeklyRuns/Presentation/Admin/AdminWeeklyRunOutputDownloadController.php` - readable zip filename
- `tests/Functional/AdminWeeklyRunOutputDownloadTest.php` - filename assertion
- `frontend/src/features/admin/admin-weekly-run-cards.tsx` - relabel action

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-08 | Bugfix story created and implemented - capture the full generation output as a zip (admin download of all files; restores member ROM patches). Orchestrateur PR #3 (master). |
| 2026-06-08 | Follow-up - avoid zip-in-zip: when generation already produced a single `AP_*.zip` bundle, store it as-is instead of re-zipping (orchestrateur PR #4, master). |
| 2026-06-08 | Follow-up - download filename: the artifact is a zip but was saved as `*.archipelago` because the cross-origin `fetch` could not read `Content-Disposition` (not CORS-exposed) and fell back to a hardcoded name. Fixed by exposing `Content-Disposition` (`nelmio_cors`) + `.zip` frontend fallback (PR #32, develop). Verified live end-to-end: generation → admin "Fichiers générés" → `weekly-run-{id}.zip` containing `.archipelago` + `.aplm` + spoiler; seed loads in `ArchipelagoServer` (Luigi's Mansion). |
| 2026-06-08 | Status → done. Pending prod deploy of orchestrateur `master` + API CORS config. |
