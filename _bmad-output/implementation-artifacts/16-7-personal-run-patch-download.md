# Story 16.7: Personal run — participant patch download

**Status:** ready-for-dev
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-06-10

## Story

As a participant in a private run,
I want to download the patch generated for my own slot from the run page,
so that I can play games that require a patched ROM (the personal-run equivalent of the weekly-run patch download, which doesn't exist for private runs today).

## Context

Weekly runs expose per-entry patch download (`WeeklyEntryPatchController` +
`WeeklyEntryPatchQuery`): list/download proxied through the session's bridge `/output`
endpoint, excluding `.archipelago` and `*_spoiler` files. **Private runs have no
equivalent** — `PersonalRuns/Presentation` only has games config, archive/unarchive,
callback and config-override. So participants in a private run cannot retrieve their
patch (confirmed live on a running run: the run page shows invite link, config,
connection infos, stop, progression, participants — no download).

Key difference vs weekly: a weekly entry is a **per-user** session, so its bridge
`/output` only holds that user's files and the weekly "bridge" path returns them
unfiltered. A private run is a **shared** session (one bridge, all participants'
slots), so `/output` holds **everyone's** patches → we MUST filter to the requesting
participant's own slot(s) to avoid handing out other players' patches.

### Decisions (confirmed with Jean)

- **Content:** each participant downloads **their own slot's patch(es)** only.
  `.archipelago` and `*_spoiler*` files stay excluded. (No full-archive/owner mode.)
- **Location:** a "Fichiers générés" section on the run page `/runs/{runId}`.

## Acceptance Criteria

1. A new API lists the patch files belonging to the **requesting participant's** slot(s)
   for a running private run, and downloads a single file — proxied via the session
   bridge `/output` (mirroring the weekly bridge path).
2. Files are filtered to the participant's own slot name(s); `.archipelago` and any
   `*_spoiler*` file are never listed nor downloadable (defence in depth on both list
   and download).
3. Only authenticated **participants** of the run may list/download (owner is a
   participant too); non-participants get 403/404.
4. The run page `/runs/{runId}` shows a "Fichiers générés" section with the
   participant's downloadable patches when the run is running/generated; nothing (or a
   neutral empty state) otherwise.
5. Quality gates green — API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`;
   frontend: typecheck / lint / build. Verified live on a running private run.

## Tasks / Subtasks

- [ ] **Task 1 — Resolve participant slot name(s) (the crux)** (AC: 2). Determine the
  **resolved** slot name(s) for a participant so we can match bridge `/output`
  filenames (patches are named `{SlotName}.{ext}`). Inputs available:
  `RunParticipant.getGameSlots()` → each slot has `playerYaml` (+ gameId/slotOrder).
  The slot name lives in the player YAML `name:` and **may be templated** (e.g.
  `{NAME}`/numbered) and resolved at generation. Investigate the reliable source:
  the player YAML `name`, the generated multiworld's slot list, or the callback that
  recorded slots. Document the chosen mapping. If the YAML name is canonical and
  un-templated for private runs, parse it; otherwise resolve via the generation
  output/callback. (Spike — write findings in the Dev Agent Record before coding.)

- [ ] **Task 2 — Application query** (AC: 1–3): `PersonalRunPatchQuery` resolving
  `runId` + `userId` → `{ bridgePort, slotNames: list<string> }` (run→`sessionId`→
  `Session.getBridgePort()`; participant via `RunParticipant`; guard ownership/
  participation). Return null when not a participant / no session / not running.

- [ ] **Task 3 — Presentation controller** (AC: 1–3): `PersonalRunPatchController`
  with `GET /api/v1/runs/{runId}/patches` (list) and
  `GET /api/v1/runs/{runId}/patches/{filename}` (download). Mirror
  `WeeklyEntryPatchController`'s bridge proxy (`http://localhost:{bridgePort}/output`
  + `/output/{filename}`), but **filter the file list to the participant's slot
  name(s)** and reject `.archipelago` / `*_spoiler*` on both list and download. Guard
  with `requireAuthenticatedUser` + participation check.

- [ ] **Task 4 — Frontend** (AC: 4): on `personal-run-detail-page.tsx`, add a
  "Fichiers générés" section (icon + title) that fetches the participant's patches and
  renders download buttons (reuse the weekly patch download UX / `downloadPatch`
  pattern). Show only when the run is running and files exist. Frontend API fns in the
  personal-runs feature.

- [ ] **Task 5 — Tests** (AC: 1–3): unit-test the query/filtering (a participant only
  sees their slot's files; `.archipelago`/spoiler excluded; non-participant → null).
  Mock the bridge HTTP for controller-level coverage where practical.

- [ ] **Task 6 — Gates + live verify** (AC: 5): run all gates; verify on a running
  private run that the owner/participant sees and can download their patch, and that
  `.archipelago`/spoiler are not exposed.

## Dev Notes

- **Mirror:** `api/src/WeeklyRuns/Presentation/WeeklyEntryPatchController.php`
  (`listFromBridge` / `downloadFromBridge`, the `.archipelago` + `_spoiler` guards) and
  `api/src/WeeklyRuns/Application/WeeklyEntryPatchQuery.php` (bridge-vs-local context).
  Private runs are orchestrator-managed → only the **bridge** path is needed.
- **Resolution:** `App\PersonalRuns\Domain\Run` → `getSessionId()`;
  `SessionRepositoryInterface::findById($sessionId)` → `getBridgePort()`;
  `RunParticipantRepositoryInterface` (or however participants are loaded) →
  `getGameSlots()` for the user. The page already resolves the session
  (`PersonalRunDrafts` ~l.319-351 reads `getSessionId()` + connection host) — reuse
  that wiring.
- **DDD:** controller deserialize→validate→one Application call→serialize; Application
  uses Domain repo interfaces (no Connection/EM); bridge HTTP via injected
  `HttpClientInterface` in Presentation (as the weekly controller does).
- **Security:** never expose `.archipelago` (multidata = full spoiler) or `*_spoiler*`;
  filter list to the requester's slot so participants can't grab each other's patches.

### Project Structure Notes

- `api/src/PersonalRuns/Application/PersonalRunPatchQuery.php` (new)
- `api/src/PersonalRuns/Presentation/PersonalRunPatchController.php` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (+ section) and the
  personal-runs API module (+ list/download fns)
- Tests under `api/tests/Unit/PersonalRuns/` (+ functional if a bridge stub is feasible)

### References

- [Source: api/src/WeeklyRuns/Presentation/WeeklyEntryPatchController.php]
- [Source: api/src/WeeklyRuns/Application/WeeklyEntryPatchQuery.php]
- [Source: api/src/PersonalRuns/Domain/Run.php (getSessionId), RunParticipant.php (getGameSlots)]
- [Source: api/src/Sessions/Domain/Session.php (getBridgePort)]
- [Source: frontend/src/features/weekly-runs/weekly-run-game-client.tsx (patch download UX), weekly-runs-api.ts (fetchWeeklyEntryPatches/downloadPatch)]

## Dev Agent Record

### Agent Model Used

_TBD_

### Spike Findings

_TBD — record how a participant's resolved slot name(s) are obtained (YAML name vs generation output) before coding Task 2/3._

### Completion Notes List

_TBD_

### File List

_TBD_

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-10 | Story created. Gap confirmed (no personal-run patch download; weekly has it). Decisions: per-participant own-slot patch, on the run page; .archipelago/spoiler excluded. Spike-first on participant→slot-name resolution. Status → ready-for-dev. |
