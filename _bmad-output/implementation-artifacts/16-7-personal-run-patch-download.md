# Story 16.7: Personal run — participant patch download

**Status:** done
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

claude-opus-4-8 (Claude Code).

### Spike Findings

The participant's resolved slot name is **persisted in `SessionSlot`** at launch
(`LaunchPersonalRunJobHandler` → `SlotNameGenerator` → `SessionSlot::create(…, userId,
…, slotName, …)`), where the `registration_id` column holds the user id for personal
runs. So no YAML parsing: `findByRegistrationAndSession(userId, sessionId)` →
`getSlotName()`. Verified live: run → session bridge_port 25000, slot "masterkafei_LM".

Bridge `/output` filenames are **`AP_{seed}_P{slotNumber}_{slotName}.{ext}`** (the slot
name is a suffix and may contain underscores), e.g.
`AP_32336784011536737200_P2_masterkafei_LM.aplm` — NOT just `{slotName}.{ext}`. So the
matcher extracts the slot name after `_P\d+_` and compares **exactly** (a plain-stem
fallback covers non-AP names). Exact match prevents a player whose slot is a *suffix*
of another's (e.g. "LM" vs "masterkafei_LM") from grabbing the wrong patch.

### Completion Notes List

- `PersonalRunPatchQuery.forParticipant(runId, userId)` → `{bridgePort, slotNames}` or
  null (run missing / not launched / no bridge / user has no slot). Reuses Sessions
  Domain repos (no EM/Connection in Application).
- `PersonalRunPatchController`: `GET /runs/{runId}/patches` (list) + `.../patches/{filename}`
  (download), proxied through the bridge `/output` (mirrors `WeeklyEntryPatchController`),
  filtered to the participant's own slot via `belongsToOwnSlot()` which excludes
  `.archipelago` + `*_spoiler*` and matches the slot name exactly.
- Frontend `PersonalRunPatchPanel` ("Fichiers générés") on the run page, before the
  progress grid; renders nothing when there are no files; one download button per patch.
- Verified live on a running private run: owner sees and the endpoint serves their own
  `AP_…_masterkafei_LM.aplm`; `.archipelago`/spoiler/other slots are not exposed.
- Tests: query (5: null paths + resolution) + matcher (6: AP-name match, plain fallback,
  suffix-not-matched, other player's patch, multidata/spoiler excluded) — 11 green.

### File List

- `api/src/PersonalRuns/Application/PersonalRunPatchQuery.php` (new)
- `api/src/PersonalRuns/Presentation/PersonalRunPatchController.php` (new)
- `api/tests/Unit/PersonalRuns/PersonalRunPatchQueryTest.php`, `PersonalRunPatchFilterTest.php` (new)
- `frontend/src/features/personal-runs/personal-run-patches.tsx` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (panel wired in)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-10 | Story created. Gap confirmed (no personal-run patch download; weekly has it). Decisions: per-participant own-slot patch, on the run page; .archipelago/spoiler excluded. Spike-first on participant→slot-name resolution. Status → ready-for-dev. |
| 2026-06-10 | Implemented: query + controller (bridge proxy, exact own-slot match) + "Fichiers générés" panel + 11 unit tests. Spike resolved (SessionSlot stores slotName; AP filename = AP_{seed}_P{n}_{slotName}.ext). Verified live. Gates green. Status → review. |
| 2026-06-10 | Merged via PR #97 (CI green incl. full backend suite). Status → done. |
