# Story 18.5: Frontend - Public Run Results Page (`/runs/{id}/resultats`)

## Story

**As a** visitor or player,
**I want** to view a richly formatted results page for a finished run,
**So that** I can celebrate achievements, identify who completed what, and understand the run's outcome.

## Status

review

## Acceptance Criteria

**AC1:** Navigating to `/runs/{id}/resultats` renders a page header with event name, run date (formatted in French), and total duration formatted as `Xh Ym`.

**AC2:** A results grid displays one card per slot with: player name, game, checks done, items received, goal status badge, and completion time if goal was reached.

**AC3:** Slots are grouped in three labelled sections: "Objectifs atteints" (sorted by completion time asc - handled by API), "Incomplets" (no goal, not invalidated), "Forfaits" (isInvalidated: true).

**AC4:** "Forfait" slot cards display an amber "Forfait" badge, checks and items are shown in muted style, and a tooltip explains "Statistiques exclues des classements (slot relâché)".

**AC5:** When the API returns 404, a "Résultats non disponibles" placeholder is shown with a back link to `/runs/{id}`.

**AC6:** Page has `<title>` and `og:title` meta (e.g. "Résultats de la run ArchiLAN #12").

**AC7:** A "Voir le classement communautaire" link points to `/classements`. A back link points to `/runs/{id}`.

**AC8:** Grid collapses to single-column on mobile (default grid, `sm:grid-cols-2 lg:grid-cols-3`).

**AC9:** Page is SSR via Next.js async server component. No auth required.

## Tasks / Subtasks

- [x] Task 1: `run-results-api.ts` - types + `getRunResults` with `React.cache()`
  - [x] 1a: Define `SlotResult` and `RunResults` types
  - [x] 1b: Implement `isRunResultsPayload` type guard
  - [x] 1c: Implement `getRunResults = cache(async (runId) => fetch(...))`
- [x] Task 2: `run-results-page.tsx` - server component
  - [x] 2a: `RunResultsPage` - header (event name, date, duration, links) + 3 sections
  - [x] 2b: `RunResultsNotFound` - 404 placeholder with back link
  - [x] 2c: `SlotCard` - player, game, stats, `StatusBadge`
  - [x] 2d: `StatusBadge` - green/amber/gray badge
  - [x] 2e: `formatDuration(seconds)` → "Xh Ym"; `formatDate(iso)` → French format
- [x] Task 3: Route `frontend/src/app/(public)/runs/[runId]/resultats/page.tsx`
  - [x] 3a: `generateMetadata` with dynamic title + og:title
  - [x] 3b: Default export async server component, delegates to `RunResultsPage` or `RunResultsNotFound`
- [x] Task 4: Quality gates (typecheck + lint)

## Dev Notes

### Data Model

Consumed from `GET /api/v1/runs/{id}/results`:
```json
{
  "data": {
    "sessionId": "...",
    "eventName": "...",
    "startedAt": "2026-05-01T10:00:00Z",
    "finishedAt": "2026-05-01T22:00:00Z",
    "durationSeconds": 43200,
    "slots": [
      {
        "slotId": "...",
        "playerName": "...",
        "game": "...",
        "checksDone": 42,
        "itemsReceived": 15,
        "goalReachedAt": "2026-05-01T14:00:00Z",
        "completionSeconds": 14400,
        "wasReleased": false,
        "isInvalidated": false
      }
    ]
  }
}
```

Slots already sorted by API: completed (time ASC), incomplete, invalidated.

### `React.cache()` Deduplication

`getRunResults` is wrapped in `React.cache()` so `generateMetadata` and the page component share one fetch per request.

### 404 Handling

API returns 404 when session not found or not finished. `getRunResults` returns `null` on any non-ok response. The page component renders `RunResultsNotFound` without calling Next.js `notFound()` (avoids triggering the app-level not-found page, preserving the back link to `/runs/{id}`).

## Dev Agent Record

### Completion Notes

- `typecheck` (tsc --noEmit) → 0 errors
- `lint` (eslint) → 0 violations
- `React.cache()` used for request deduplication between `generateMetadata` and page component
- Grid: 1 col mobile → `sm:grid-cols-2` → `lg:grid-cols-3` (no horizontal scroll)
- Forfait tooltip via `title` attribute on `<p>` (no JS required)
- Duration format: `0min` edge case handled (h=0 → `Xmin`), `Xh` if m=0

## File List

- `frontend/src/features/runs/run-results-api.ts` - new
- `frontend/src/features/runs/run-results-page.tsx` - new
- `frontend/src/app/(public)/runs/[runId]/resultats/page.tsx` - new

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implemented |
