# Story 9.34: Let players set hint priority (Archipelago hint status) on the indices page

**Status:** review
**Epic:** 9 - Sessions, bridge & hints
**Date:** 2026-06-27

## Story

As a player (or admin) on the "indices" (hints) page of a running session,
I want to set the priority/status of each pending hint myself (Prioritaire / Faible prio. / Éviter /
Non classé),
so that I can organise my hint strategy - exactly Archipelago's hint status feature, which the bridge
already exposes but the app never wired up.

## Context

Archipelago hints carry a status (`HintStatus`: unspecified 0 / no_priority 10 / avoid 20 / priority 30 /
found 40). The bridge client already implements `SlotsClient::updateHint(slot, locationId, HintStatus)`
(`PATCH /slots/{slot}/hints/{locationId}`), and the frontend `HintsPanel` already **displays** the
status with colour-coded badges - but there was no way to **change** it. This wires that capability end
to end ("faire le lien" with the existing AP feature).

## Acceptance Criteria

1. Backend: `PATCH /api/v1/sessions/{sessionId}/slots/{slotIndex}/hints/{locationId}` with `{status}` sets
   the hint status via the bridge. Auth = slot owner or admin (mirrors the hint-request endpoints);
   session must be running; only settable statuses (0/10/20/30) accepted - `found` (40) is bridge-managed
   and rejected (422).
2. Backend: the weekly equivalent `PATCH /api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/hints/{locationId}`
   with the same contract and the weekly entry's auth.
3. Frontend: `HintsPanel` shows a priority `<select>` on each **pending** (not found) hint; changing it
   calls the endpoint and optimistically updates the row. Found hints keep the read-only badge.
4. Wired on all three hints surfaces: personal run progression, weekly "ma-run", and admin slot
   reachability - each with the correct base URL.
5. Gates green: API `phpstan` / `php-cs-fixer` / `phpunit` / `ddd`; frontend `typecheck` / `lint` / `build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). `updateHintStatus` endpoint in `PlayerStateController` (auth + running check +
  status validation + `$bridge->slots()->updateHint(...)`), `locationId` constrained to `\d+`.
- [x] **Task 2** (AC 2). Same in `WeeklyRunSlotStateController` using `findLaunchedEntryInfo` auth.
- [x] **Task 3** (AC 3,4). `SETTABLE_HINT_STATUSES` / `HINT_STATUS_NAMES` in reachability types; priority
  `<select>` in `HintRow`; `onSetStatus` threaded through `HintsPanel`; handlers in the 3 pages (optimistic
  update, SSE reconciles).
- [x] **Task 4** (AC 5). PlayerStateTest: invalid status (40) → 422, non-registrant → 403. All gates green.

## Dev Notes

- "Found" (40) is excluded from the settable set: it's set automatically by Archipelago when the item is
  collected. The picker offers Prioritaire (30) / Faible prio. (10) / Éviter (20) / Non classé (0).
- No happy-path functional test for the bridge forwarding: the hint endpoints go through `BridgeClientPool`
  (not the directly-injected `HttpClientInterface` that `/players` uses), and the pool isn't routed through
  the test `MockHttpClient` - so existing hint endpoints have no happy-path test either. Covered here:
  status validation + auth (no bridge), mirroring the proven `requestHint` forwarding pattern.
- Updates are optimistic; the bridge's hint push (Mercure → SSE) reconciles the authoritative state.

### Project Structure Notes

- `api/src/Sessions/Presentation/PlayerStateController.php` (+ `WeeklyRuns/.../WeeklyRunSlotStateController.php`)
- `api/vendor/archilan/bridge-client` `SlotsClient::updateHint` / `Enum\HintStatus` (already existed)
- `frontend/src/features/reachability/types.ts` + `hints-panel.tsx`
- `frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx`
- `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx`
- `frontend/src/features/admin/admin-slot-reachability-page.tsx`
- `api/tests/Functional/PlayerStateTest.php`

### References

- [Source: api/vendor/archilan/bridge-client/src/Slots/SlotsClient.php (updateHint) + Enum/HintStatus.php]
- [Source: api/src/Sessions/Presentation/PlayerStateController.php (requestHint auth/bridge pattern)]
- [Source: frontend/src/features/reachability/hints-panel.tsx (status display)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Wired Archipelago's existing hint-status capability: new PATCH endpoints (session + weekly) calling the
  bridge `updateHint`; interactive priority picker in `HintsPanel` on pending hints; handlers on all three
  hints surfaces with optimistic update.
- `found` excluded from settable statuses. Validation + auth tested; gates green.

### File List

- `api/src/Sessions/Presentation/PlayerStateController.php`
- `api/src/WeeklyRuns/Presentation/WeeklyRunSlotStateController.php`
- `api/tests/Functional/PlayerStateTest.php`
- `frontend/src/features/reachability/types.ts`
- `frontend/src/features/reachability/hints-panel.tsx`
- `frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx`
- `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx`
- `frontend/src/features/admin/admin-slot-reachability-page.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-27 | Created + implemented. Wired AP hint priority/status (the bridge `updateHint` was unused): PATCH endpoints (session + weekly), priority picker in HintsPanel on pending hints, handlers on the 3 hints pages. `found` not settable. Validation/auth tests; gates green. Status → review. |
