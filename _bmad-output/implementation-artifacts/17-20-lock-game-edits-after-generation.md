# Story 17.20: Lock game selection / YAML / game config once a run is generated

**Status:** review
**Epic:** 17 - Session lifecycle & restart
**Date:** 2026-06-27

## Story

As a personal-run owner/participant,
once the run has been generated (launched at least once, including while **paused/idle**),
I want game selection, slot YAML and the owner's game config to be locked,
so that I don't waste time editing or inviting into a partie whose multiworld is already fixed - resume
always replays the existing generated game, so those edits never take effect.

## Context

A personal run's multiworld is generated on first launch (`sessionId` set); pause→resume relaunches the
**existing** saved session. The edit-blocking only covered `ACTIVE_STATUSES` (`starting`/`active`/
`stopping`), so once a run was **paused (`idle`)** the owner could re-open game selection and slot YAML
and change them - with no effect, since resume ignores the new config. (Reported by Jean.)

The cutoff is "once generated" = the run has left `draft` (idle/active/stopping/restarting/completed all
included). Editing is only meaningful in `draft`, before the first generation. (Invitation/join was
intentionally left out of scope for this story.)

## Acceptance Criteria

1. Backend blocks (422, `run_generated`) when the run is **not `draft`**, for: participant game
   selection (`PUT /runs/{id}/participants/me/games`), slot YAML (`PUT …/slots/{slotId}/yaml`), and
   owner game config (`PATCH /runs/{id}/games`). A paused (`idle`) run is blocked too.
2. `draft` runs remain fully editable (including a run reverted to draft after a failed generation).
3. The run game-selection GET response carries the run `status` so the frontend can gate.
4. Frontend:
   - Run detail "Mes jeux" edit entry is shown only for `draft` (hidden once generated/paused).
   - Game-selection page: a "déjà générée" banner; add/remove and save disabled when locked.
   - Slot-YAML page: when locked, renders the read-only `YamlOptionsView` + banner instead of the editor.
5. Gates green: API `phpstan` / `php-cs-fixer` / `phpunit` / `ddd`; frontend `typecheck` / `lint` / `build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2). `Run::isLockedForEditing()` (= not `draft`); use it in `PersonalRunGameSelection`
  (`saveMyGames`, `saveSlotYaml`) and `PersonalRunGameConfig::configure`, blockReason `run_generated`.
- [x] **Task 2** (AC 3). Add `status` to `getMySlots` result + the game-selection GET JSON.
- [x] **Task 3** (AC 4). Detail-page entry → draft only; game-selection banner + disabled add/remove/save;
  slot-YAML read-only `YamlOptionsView` + banner when locked.
- [x] **Task 4** (AC 1,2,5). Functional tests: idle blocks game config / game selection / slot YAML
  (`run_generated`); active/starting still blocked. Gates green.

## Dev Notes

- `isLockedForEditing()` is status-based (`STATUS_DRAFT !== status`) rather than `sessionId`-based: it
  stays correct for a run reverted to `draft` after a validation failure (editing must be re-allowed),
  and is trivially testable without a real session.
- `run_generated` replaces the former `run_active` / `run_not_configurable` reasons for these three edit
  endpoints (single, accurate reason covering idle/active/completed). DELETE keeps its own `run_active`
  (deletion of an *active* run) - unchanged, out of scope.
- Invitation/join is intentionally **not** blocked in this story (per product decision) - can be added
  later if a post-generation join proves confusing (a new joiner has no slot in the fixed multiworld).
- Slot-YAML read-only reuse: `YamlOptionsView` (story 4.19 made it render literal dicts correctly), so
  the locked view shows `game_options` etc. properly.

### Project Structure Notes

- `api/src/PersonalRuns/Domain/Run.php` (`isLockedForEditing`)
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php` (saveMyGames, saveSlotYaml, getMySlots status)
- `api/src/PersonalRuns/Application/PersonalRunGameConfig.php` (configure)
- `api/src/PersonalRuns/Presentation/PersonalRunController.php` (expose status)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (entry gate)
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx` (banner + disabled)
- `frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx` (read-only view)
- `api/tests/Functional/PersonalRunGameConfigTest.php`

### References

- [Source: memory restart_architecture - resume relaunches from save, multiworld fixed]
- [Source: api/src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php (sessionId set on first launch)]
- [Source: _bmad-output/implementation-artifacts/4-19-yaml-view-literal-dict-rendering.md (read-only YamlOptionsView)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Edit-blocking cutoff broadened from `ACTIVE_STATUSES` to "not draft" via `Run::isLockedForEditing()`,
  closing the idle (paused) gap; single `run_generated` reason for the three edit endpoints.
- GET exposes `status`; frontend hides the edit entry for non-draft runs and renders locked banners /
  read-only YAML view.
- Functional tests updated/added (idle now blocked); API + frontend gates green.

### File List

- `api/src/PersonalRuns/Domain/Run.php`
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php`
- `api/src/PersonalRuns/Application/PersonalRunGameConfig.php`
- `api/src/PersonalRuns/Presentation/PersonalRunController.php`
- `api/tests/Functional/PersonalRunGameConfigTest.php`
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx`
- `frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-27 | Created + implemented. Blocked game selection / slot YAML / owner game config once a run leaves draft (idle/paused included), since resume replays the fixed multiworld. New `Run::isLockedForEditing()`, `run_generated` reason, status in GET, frontend entry gate + locked banners + read-only YAML view. Invitation left out of scope. Tested; gates green. Status → review. |
