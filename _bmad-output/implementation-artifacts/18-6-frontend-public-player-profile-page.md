# Story 18.6: Frontend - Public Player Profile Page (`/joueurs/{slug}`)

## Story

**As a** visitor,
**I want** to view a player's profile with their run history and personal stats,
**So that** I can understand their involvement in ArchiLAN runs.

## Status

done

## Acceptance Criteria

**AC1:** Profile header shows: display name, join date ("Membre depuis…"), and aggregated stats row: total runs, total checks done, goal completions, goal completion rate (as a percentage).

**AC2:** Below the stats, a "Historique des runs" section lists the player's finished runs in reverse-chronological order, each showing: event name, date, game played, checks done, items received, and a status badge ("Objectif atteint", "Forfait", or "Incomplet"). Each run row links to `/runs/{id}/resultats`.

**AC3:** Invalidated runs (`isInvalidated: true`) display a "Forfait" amber badge and muted stats.

**AC4:** When the API returns 404 (unknown slug), the Next.js 404 page is rendered via `notFound()`.

**AC5:** When the player has no finished run history, an empty state is shown: "Aucune run terminée pour l'instant".

**AC6:** Page uses Next.js SSR (async server component). No auth required.

**AC7:** `og:title` is set to the player's display name (e.g. "Jean - Profil ArchiLAN").

## Tasks / Subtasks

- [x] Task 1: `player-profile-api.ts` - types + `getPlayerProfile` + `getPlayerHistory` with `React.cache()`
  - [x] 1a: Define `PlayerProfile`, `PlayerStats`, `RunHistoryEntry`, `PlayerHistory` types
  - [x] 1b: Implement type guards `isPlayerProfilePayload`, `isPlayerHistoryPayload`
  - [x] 1c: Implement `getPlayerProfile = cache(async (slug) => fetch(...))`
  - [x] 1d: Implement `getPlayerHistory = cache(async (slug) => fetch(...))`
- [x] Task 2: `player-profile-page.tsx` - server components
  - [x] 2a: `PlayerProfilePage` - header with display name + join date + stats, history section
  - [x] 2b: `RunHistoryRow` - event name, date, game, checks, items, status badge, link to results
  - [x] 2c: `StatusBadge` - green/amber/gray
  - [x] 2d: `StatCard` - reusable stat display
  - [x] 2e: helpers `formatDate(iso)` → French long date, `formatPct(rate)` → "X%"
- [x] Task 3: Route `frontend/src/app/(public)/joueurs/[playerSlug]/page.tsx`
  - [x] 3a: `generateMetadata` with dynamic title + og:title, `notFound()` metadata on 404
  - [x] 3b: Default export async server component, `notFound()` on 404, else `PlayerProfilePage`
- [x] Task 4: Quality gates (typecheck + lint)

## Dev Notes

### API Response Shapes

`GET /api/v1/players/{slug}`:
```json
{
  "data": {
    "slug": "jean",
    "displayName": "Jean",
    "joinedAt": "2026-01-01T00:00:00+00:00",
    "stats": {
      "runsParticipated": 5,
      "goalCompletions": 3,
      "goalCompletionRate": 0.6,
      "totalChecksDone": 420,
      "totalItemsReceived": 180
    }
  }
}
```

`GET /api/v1/players/{slug}/history?limit=100`:
```json
{
  "data": [
    {
      "sessionId": "...",
      "eventName": "ArchiLAN #12",
      "finishedAt": "2026-05-01T22:00:00+00:00",
      "game": "A Link to the Past",
      "checksDone": 42,
      "itemsReceived": 15,
      "goalReachedAt": "2026-05-01T14:00:00+00:00",
      "wasReleased": false,
      "isInvalidated": false
    }
  ],
  "meta": { "page": 1, "limit": 100, "total": 5 }
}
```

Returns 404 `{ error: { code: "player_not_found" } }` if slug not found.

### `React.cache()` Deduplication

Both `getPlayerProfile` and `getPlayerHistory` are wrapped in `React.cache()` so `generateMetadata` and the page component share one fetch per request.

### Status Badge Logic

- `isInvalidated: true` → "Forfait" amber
- `goalReachedAt !== null` → "Objectif atteint" green
- Otherwise → "Incomplet" gray

### Goal Completion Rate

API returns float `0.0–1.0`. Format as `Math.round(rate * 100)%`. If `runsParticipated === 0`, display "-".

## Dev Agent Record

### Completion Notes

- `typecheck` (tsc --noEmit) → 0 errors
- `lint` (eslint) → 0 violations
- History fetched with `limit=100` (covers all realistic history sizes; no pagination UI required per spec)
- `notFound()` used for 404 case (renders app-level not-found page)
- `React.cache()` deduplicates both API calls between `generateMetadata` and the page component
- Join date formatted with French "long" date style: "1 janvier 2026"

## File List

- `frontend/src/features/players/player-profile-api.ts` - new
- `frontend/src/features/players/player-profile-page.tsx` - new
- `frontend/src/app/(public)/joueurs/[playerSlug]/page.tsx` - new

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implemented |
| 2026-05-14 | Fixed review findings: composite key `${sessionId}-${game}` (F1), truncation disclosure note when total > 100 (F2), distinct error state when history fetch fails (F3) |
