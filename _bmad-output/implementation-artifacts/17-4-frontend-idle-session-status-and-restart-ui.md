# Story 17.4: Frontend - Idle Session Status and Restart UI

Status: ready-for-dev

## Story

As a run owner or admin,
I want to see clearly when a session is paused and have the option to restart it from the UI,
So that I can resume a game without waiting for a player connection.

## Acceptance Criteria

1. **Given** a personal run has status `idle`
   **When** the owner views `/runs/{runId}`
   **Then** an amber "En pause" status badge is displayed
   **And** a subtitle shows time since last activity (e.g. "Inactif depuis 1h 23min")
   **And** an info callout reads: "La partie redémarre automatiquement dès qu'un joueur tente de se connecter. Vous pouvez aussi la relancer manuellement."
   **And** a "Reprendre manuellement" button is visible
   **And** if `pausedWithoutSave: true`, the button is disabled with tooltip "Reprise impossible : aucune sauvegarde disponible"

2. **Given** the owner clicks "Reprendre manuellement"
   **When** `POST /api/v1/sessions/{sessionId}/restart` is in flight
   **Then** the button shows a loading spinner and is disabled
   **And** status badge updates to "Redémarrage en cours..."

3. **Given** session status transitions to `restarting` (triggered by UI OR wake-on-connect)
   **When** frontend detects the change via polling
   **Then** status badge shows "Redémarrage en cours..." with a spinner
   **And** polling uses 5-second interval (TanStack Query refetchInterval)

4. **Given** session transitions back to `running`
   **When** frontend detects the change
   **Then** badge shows "En cours" (active state)
   **And** connection details are shown again
   **And** success toast "Partie reprise avec succès" appears
   **And** polling stops

5. **Given** an admin views the backoffice session list
   **When** one or more sessions have status `idle`
   **Then** idle sessions appear in a "Sessions en pause" section above completed sessions
   **And** a "Reprendre" inline action is available per session

## Tasks / Subtasks

- [ ] Task 1: `personal-run-detail-page.tsx` - idle state section (AC: 1, 2)
  - [ ] Locate the idle status section in `frontend/src/features/personal-runs/personal-run-detail-page.tsx`
  - [ ] Add amber badge component for `idle` status (reuse existing badge pattern from the file)
  - [ ] Compute time since last activity: `lastActivityAt` field from API response → `formatDistanceToNow(new Date(run.lastActivityAt), { locale: fr })`
    - Install `date-fns` if not present; check `package.json` first
  - [ ] Add info callout `<div className="rounded border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">...</div>` (adapt to dark theme tokens)
  - [ ] Add "Reprendre manuellement" button: secondary style, loading state, disabled when `creating` or `pausedWithoutSave`
  - [ ] Handler `handleRestart()`: `POST {apiBaseUrl}/sessions/{run.sessionId}/restart` → on 202: set polling; on error: show error toast

- [ ] Task 2: Polling for `restarting → running` transition (AC: 3, 4)
  - [ ] Add `restarting` to the set of statuses that trigger active polling
  - [ ] TanStack Query `refetchInterval`: 5000ms when `run.status === 'restarting'`, `false` otherwise
  - [ ] On transition to `running`: stop polling, show toast "Partie reprise avec succès" (use existing toast pattern)
  - [ ] Check `personal-run-detail-page.tsx` for existing polling logic (used for `starting` status) and replicate the pattern

- [ ] Task 3: `personal-runs-list-page.tsx` - idle group display (AC: 1 list view)
  - [ ] File: `frontend/src/features/personal-runs/personal-runs-list-page.tsx`
  - [ ] `STATUS_ORDER` already has `idle` - verify it's in the correct position (after `active`, before `draft`)
  - [ ] `GROUP_LABELS` - verify `idle: "En pause"` is set
  - [ ] Add amber color indicator to `PersonalRunCard` when status is `idle`

- [ ] Task 4: `personal-run-card.tsx` - idle visual treatment (AC: 1 card)
  - [ ] File: `frontend/src/features/personal-runs/personal-run-card.tsx`
  - [ ] Add amber/yellow status badge for `idle` status
  - [ ] Show `lastActivityAt` as relative time on the card if available

- [ ] Task 5: `types.ts` - add `idle` and `restarting` if missing (AC: all)
  - [ ] File: `frontend/src/features/personal-runs/types.ts`
  - [ ] Ensure `PersonalRunStatus` union includes `"idle"` and `"restarting"` (check existing type)
  - [ ] Ensure `PersonalRun` type has `lastActivityAt: string | null`, `pausedWithoutSave: boolean`, `sessionId: string | null`

- [ ] Task 6: Backoffice session list - idle section (AC: 5)
  - [ ] Locate admin session list component (likely in `frontend/src/features/admin/` or `sessions/`)
  - [ ] Add "Sessions en pause" section rendering `idle` sessions above completed
  - [ ] Add inline "Reprendre" button calling the same `POST /sessions/{id}/restart` endpoint

- [ ] Task 7: Tests (AC: all)
  - [ ] Verify TypeScript compiles: `npx tsc --noEmit`
  - [ ] Manual smoke test: create a run, simulate idle status (or mock), verify badge and button appear

## Dev Notes

### Existing patterns to follow

**Status badge pattern** - look at how `active`, `starting`, `stopping` statuses are displayed in `personal-run-detail-page.tsx`. Replicate the exact pattern for `idle` (amber color) and `restarting` (spinner + muted color).

**Polling for `starting` status** - the page already polls when status is `starting`. In `personal-run-detail-page.tsx`, find the `useEffect` or TanStack Query `refetchInterval` logic and extend it to also trigger on `restarting`.

**Toast pattern** - check existing success/error toasts in the file. Likely uses a `useToast` hook or similar.

**Date formatting** - check if `date-fns` is already in `frontend/package.json`. If yes, use `formatDistanceToNow`. If not, implement a simple `formatRelativeTime()` helper (e.g. "1h 23min"):
```ts
function formatIdleTime(isoString: string): string {
  const diffMs = Date.now() - new Date(isoString).getTime();
  const totalMinutes = Math.floor(diffMs / 60000);
  const hours = Math.floor(totalMinutes / 60);
  const minutes = totalMinutes % 60;
  return hours > 0 ? `${hours}h ${minutes}min` : `${minutes}min`;
}
```

**`sessionId` on PersonalRun** - the API returns `sessionId` in the run payload (`PersonalRunDrafts::payload()` includes it). Confirm `PersonalRun` frontend type has this field.

**`lastActivityAt` and `pausedWithoutSave`** - `PersonalRunDrafts::payload()` includes `lastActivityAt` and `pausedWithoutSave` for `idle` and `restarting` statuses (see `api/src/PersonalRuns/Application/PersonalRunDrafts.php` - already reads from Session entity).

### Design tokens for idle state
- Amber badge: use `text-amber-600 bg-amber-50 border-amber-200` or equivalent from design tokens
- Info callout: use `text-muted-foreground bg-surface border-border` to stay within dark theme
- Disabled button: `disabled:opacity-50 cursor-not-allowed`

### API endpoint for restart
`POST ${env.apiBaseUrl}/sessions/{sessionId}/restart` - note this is on **sessions** not on **runs**. The `sessionId` is available in the run payload.

### Status order in list page
Current `STATUS_ORDER` in `personal-runs-list-page.tsx`:
```ts
const STATUS_ORDER: PersonalRunStatus[] = ["active", "starting", "stopping", "idle", "draft"];
```
`idle` should already be there - verify and add if missing. `restarting` should also be in the order (after `idle`).

### Quality gates
```bash
cd frontend
npx tsc --noEmit
npx next build  # or npm run build
```

### References
- Personal run detail page: `frontend/src/features/personal-runs/personal-run-detail-page.tsx`
- Personal runs list: `frontend/src/features/personal-runs/personal-runs-list-page.tsx`
- Personal run card: `frontend/src/features/personal-runs/personal-run-card.tsx`
- Types: `frontend/src/features/personal-runs/types.ts`
- API base URL: use `env.apiBaseUrl` from `src/lib/env.ts` (never use `process.env` directly)
- `PersonalRunDrafts::payload()`: `api/src/PersonalRuns/Application/PersonalRunDrafts.php:311` (check what `lastActivityAt` and `pausedWithoutSave` return)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
