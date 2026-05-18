# Story 18.7: Frontend - Community Leaderboard Page + Stats Widget

## Story

**As a** visitor,
**I want** to see global community stats on the homepage and browse a ranked leaderboard,
**So that** I can appreciate the scale of the community and find where I stand.

## Status

done

## Acceptance Criteria

**AC1:** The homepage (`/`) displays a "Nos stats globales" section with animated count-up counters for: runs terminées, checks complétés, objectifs atteints. Counters animate when scrolled into view.

**AC2:** A `/classements` page shows a ranked leaderboard with three tabs: Objectifs, Checks, Vitesse. Default tab is Objectifs.

**AC3:** An event filter dropdown filters the leaderboard to a single event.

**AC4:** The leaderboard supports "Voir plus" pagination (loads 20 more entries per click).

**AC5:** The leaderboard is server-side rendered with initial data for the Objectifs tab (no extra client fetch on first load).

**AC6:** Each entry links to `/joueurs/{slug}` and shows a rank, avatar initial, display name, and value with unit.

## Tasks / Subtasks

- [x] Task 1: `community-api.ts` - types + `fetchLeaderboard` + `fetchCommunityStats` + format helpers
- [x] Task 2: `query-provider.tsx` - `QueryProvider` wrapping `QueryClientProvider`
- [x] Task 3: `public-shell.tsx` - wrap with `<QueryProvider>`
- [x] Task 4: `leaderboard-client.tsx` - TanStack Query client component with tabs, filter, pagination
- [x] Task 5: `community-stats-widget.tsx` - IntersectionObserver + requestAnimationFrame count-up
- [x] Task 6: Route `frontend/src/app/(public)/classements/page.tsx` - SSR with initialData
- [x] Task 7: Homepage `page.tsx` - add `<CommunityStatsWidget />`
- [x] Task 8: Quality gates (typecheck + lint + build)

## Dev Notes

### Lint Issues Encountered and Fixed

**`react-hooks/set-state-in-effect`** in `useCountUp` (community-stats-widget.tsx):
- Synchronous `setCount(target)` at the top of the effect body was forbidden.
- Fix: removed the synchronous setState; effect simply returns early when `!enabled || target <= 0`. Count initializes at 0 and only changes via `requestAnimationFrame` callbacks.

**`react-hooks/purity`** - `Date.now()` in `useQuery` options (leaderboard-client.tsx):
- `initialDataUpdatedAt: Date.now()` called conditionally during every render.
- Fix: added `initialDataFetchedAt: number` prop to `LeaderboardClient`. The server component (`classements/page.tsx`) computes `Date.now()` once (server-side, with `// eslint-disable-next-line react-hooks/purity` since this is an async server function, not a React hook) and passes it as a prop.

### TanStack Query Strategy

- `initialData` for the SSR "goals/20/all-events" query avoids a duplicate client fetch on first load.
- `initialDataUpdatedAt` (set to server-side `Date.now()`) keeps the data fresh for `staleTime` (60s) - no refetch on mount.
- `placeholderData: keepPreviousData` keeps the previous tab/filter visible while the next is loading.
- `keepPreviousData` is correctly imported from `@tanstack/react-query` (v5 export).

### Stats Widget Animation

- `IntersectionObserver` (threshold: 0.3) fires once on viewport entry, sets `triggered = true`.
- `useCountUp(target, enabled)`: when `enabled && target > 0`, runs a 1500ms ease-out cubic animation via `requestAnimationFrame`. Returns `0` until triggered.
- Displays `"-"` when `target === null` (loading or API error).

## Dev Agent Record

### Completion Notes

- `typecheck` → 0 errors
- `lint` → 0 violations
- `build` → clean, `/classements` renders as dynamic (ƒ)

## File List

- `frontend/src/features/community/community-api.ts` - new
- `frontend/src/features/community/leaderboard-client.tsx` - new
- `frontend/src/features/community/community-stats-widget.tsx` - new
- `frontend/src/lib/query-provider.tsx` - new
- `frontend/src/components/public-shell.tsx` - modified (added QueryProvider)
- `frontend/src/app/(public)/classements/page.tsx` - new
- `frontend/src/app/(public)/page.tsx` - modified (added CommunityStatsWidget)

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implemented |
| 2026-05-14 | Fixed review findings: `formatDuration` now shows seconds for values < 60s (F1); leaderboard entries use `flex-col sm:flex-row` stacked card layout on mobile (F2) |
