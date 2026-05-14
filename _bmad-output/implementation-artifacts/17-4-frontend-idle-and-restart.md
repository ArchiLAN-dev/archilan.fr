# Story 17.4: Frontend - Idle Session Status and Restart UI

**Status:** review
**Epic:** 17 - Session Lifecycle - Inactivity Timeout and Restart
**Date:** 2026-05-12

## Story

As a run owner or admin,
I want to see clearly when a session is paused and restart it with one click,
So that I can resume a game without technical knowledge.

## Acceptance Criteria

1. Personal run card / detail page with `idle` status:
   - Amber "En pause" badge.
   - "Reprendre la partie" button.
   - Subtitle "Inactif depuis Xh Ymin" computed from `lastActivityAt` (camelCase from API).
   - If `pausedWithoutSave = true`: button disabled with tooltip "Reprise impossible : aucune sauvegarde disponible".

2. Click "Reprendre":
   - Calls `POST /api/v1/sessions/{sessionId}/restart` (expects 202).
   - Button shows spinner and is disabled while request is in flight.
   - On 202, status badge updates to "Redémarrage en cours..." and polling begins.

3. While `status === 'restarting'`: TanStack Query polls `GET /api/v1/runs/{runId}` every 3 seconds.

4. On transition to `active` / `running`:
   - Status badge → green "En cours".
   - Connection details (`connectionHost`, `connectionPort`, `connectionPassword`) appear.
   - Polling stops.
   - Toast: "Partie reprise avec succès".

5. Admin backoffice session list: `idle` sessions in a dedicated "Sessions en pause" section (above "Terminées") with inline "Reprendre" button calling the same restart endpoint.

6. Polling stops when status reaches a terminal state: `completed`, `cancelled`.

## Tasks / Subtasks

- [x] Task 1: Personal run detail page (AC: 1–4)
  - [x] `PersonalRunStatusBadge`: add `idle` → amber and `restarting` → amber animated (same as `starting`)
  - [x] `InactivityBadge`: displays "Inactif depuis Xh Ymin" computed from `lastActivityAt`
  - [x] `RestartButton`: handles loading / disabled-with-tooltip states; calls `POST .../restart`; expects 202
  - [x] Polling: `POLLING_STATUSES = ["starting", "stopping", "restarting"]` - polls every 3s when restarting
  - [x] Connection details appear when `status === 'active'`
  - [x] Success toast on `restarting → active` transition (tracked via `prevStatusRef`)

- [x] Task 2: Admin session list (AC: 5)
  - [x] Idle sessions shown in dedicated "Sessions en pause" section above the history
  - [x] Inline "Reprendre" button in the idle section (disabled + tooltip when `pausedWithoutSave`)

- [x] Task 3: Payload fields required (must be confirmed present in API responses)
  - [x] `PersonalRunDrafts::payload()` includes: `lastActivityAt`, `pausedWithoutSave`, `sessionId`
  - [x] Admin session DTO includes: `lastActivityAt`, `pausedWithoutSave` (already in `Session::payload()`)

## Dev Notes

- **Status source**: the personal run payload (`GET /api/v1/runs/{runId}`) returns `personal_runs.status` directly - the frontend never reads `sessions.status` separately. The two are kept in sync by the runner callbacks (Stories 17.2, 17.3).
- **`sessionId` in payload**: needed because the restart endpoint is `POST /api/v1/sessions/{sessionId}/restart` (scoped to sessions, not runs). `PersonalRunDrafts::payload()` must expose the linked `session_id`.
- "Inactif depuis" format: `lastActivityAt` is an ISO8601 string. Compute delta client-side: `Math.floor(deltaMs / 3_600_000)` hours and `Math.floor((deltaMs % 3_600_000) / 60_000)` minutes. Show "Ymin" if < 60 min.
- Terminal states (polling stops): `completed`, `cancelled`. Failed restart (if watchdog resets `restarting` → `idle`) also stops polling - the status will leave `restarting`.
- `siteUrl` from `src/lib/env.ts` as always.

### References

- Story 16.5: `PersonalRunStatusBadge`, polling pattern (3s on transitional states)
- Story 17.3: restart endpoint contract
- `frontend/src/features/admin/` - admin session management (Epic 11)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `PersonalRunDrafts::payload()` does a lazy EntityManager lookup of the linked `Session` to retrieve `lastActivityAt` and `pausedWithoutSave` only when the personal run status is `idle` or `restarting`. No new DB columns needed.
- The existing `Session::payload()` already exposed `lastActivityAt` and `pausedWithoutSave` - admin DTO confirmed present with no API change needed.
- `restarting` status added to `PersonalRunStatus` type and to `POLLING_STATUSES` (polls every 3s).
- Success toast detection uses a `prevStatusRef` compared inside `fetchRun()` before setState - avoids useEffect race conditions.
- Admin session list separates idle sessions into a "Sessions en pause" section; non-idle sessions remain in an "Historique" sub-section (labelled only when both groups are present).
- TypeScript errors in `reachability/fanfare-picker.tsx` are pre-existing and unrelated to this story.

### Debug Log

(no issues encountered)

### File List

- `api/src/PersonalRuns/Application/PersonalRunDrafts.php` (modified)
- `frontend/src/features/personal-runs/types.ts` (modified)
- `frontend/src/features/personal-runs/personal-run-status-badge.tsx` (modified)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (modified)
- `frontend/src/features/admin/admin-session-page.tsx` (modified)

### Change Log

- `PersonalRunDrafts::payload()`: expose `sessionId`, `lastActivityAt`, `pausedWithoutSave` (lazy session lookup for idle/restarting runs)
- `PersonalRunStatus` type: added `"restarting"`
- `PersonalRun` type: added `sessionId`, `lastActivityAt`, `pausedWithoutSave` fields
- `PersonalRunStatusBadge`: added `restarting` → amber pulsing config
- `PersonalRunDetailPage`: `POLLING_STATUSES` includes `restarting`; `InactivityBadge` component; working restart button with `handleRestart()`; `restarting` panel; success toast on transition
- `AdminSessionPage`: `SessionStatus` includes `idle`/`restarting`; `Session` type includes `lastActivityAt`/`pausedWithoutSave`; "Sessions en pause" section with inline "Reprendre" button; `formatInactivity()` helper; `STATUS_LABELS`/`STATUS_CLASSES` entries for `idle`/`restarting`
