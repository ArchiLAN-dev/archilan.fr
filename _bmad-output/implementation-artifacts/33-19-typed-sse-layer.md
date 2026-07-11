# Story 33.19: Typed SSE Layer (frontend/)

Status: ready-for-dev

## Story

As a maintainer of the frontend's realtime surfaces,
I want the SSE payloads validated by shared type guards through a guard-aware `useSse` (and `useOverlayStream`),
so that the ~21 `JSON.parse(event.data) as DomainType` casts disappear, the 3 duplicate declarations of the same payload shapes collapse into one, and malformed frames are dropped explicitly instead of trusted.

## Context (audit 2026-07-11, worklist of record)

Full audit in the session record; the essentials:

- **Plumbing**: `hooks/use-sse.ts` (`useSSE<T>` - generic `as T` at :101, reconnect 5s, polling
  fallback 30s, disconnect grace 3s) and `features/overlay/use-overlay-stream.ts` (`as T` at :49,
  JWT re-mint per reconnect). Both silently swallow malformed frames.
- **14 cast sites** (21 payload casts + 15 `event.data as string`): the two hooks + 12 raw-EventSource
  consumers: `PlayerProgressGrid.tsx:115`, `weekly-run-slot-page.tsx:250,330,385`,
  `admin-slot-reachability-page.tsx:190,270,324`, `personal-run-slot-detail-page.tsx:334,414,468`,
  `event-feed.tsx:118`, `admin-session-page.tsx:1553`.
- **12 identical token-endpoint casts** `as { data: { token; hubUrl; topic } }` (same files) - a shared
  `fetchSubscribeToken` + guard removes them too (ceiling: 48 casts).
- **Shape duplication**: `FeedEvent` declared 3x (`overlay/overlay-api.ts:21` exported,
  `events/event-feed.tsx:14` private dup, `admin-session-page.tsx:1553` inline subset);
  players-state declared 3 ways (`PlayersState`/`PlayersSlot` in overlay-api, private
  `SlotsMap`/`SlotData` in PlayerProgressGrid, anonymous inline `{ slots?: ... }` x3);
  `ReachabilityData`/`HintsData` centralized in `features/reachability/types.ts` (good) but consumed
  cast-style on 4/3 surfaces.
- **Exemplar patterns already in-tree**: guard `isGoalReachedEvent` (`weekly-runs-api.ts:240`),
  `parseRegistrationFeedItem` (`admin-registration-dashboard.tsx:570`), `parseReachableNames`
  (`reachable-overlay.tsx:16`), primitives in `lib/type-guards.ts`. ESLint `assertionStyle: "never"`
  currently scoped to `src/features/**/*-api.ts` + `lib/type-guards.ts` only - hooks/components
  escape it.

## Acceptance Criteria

1. **AC1 - Guard-aware plumbing.** `useSse` accepts a `guard: (v: unknown) => v is T` (or a parse
  function) and calls back only with validated payloads; invalid frames are counted/logged in dev,
  dropped in prod - no cast left inside the hook. Same for `useOverlayStream`. Reconnect/polling/
  grace semantics byte-identical (they are load-bearing on the slot pages).
2. **AC2 - Shared guards + single shapes.** One module per payload family with `is*` guards
  colocated per house style (AC-TS3/TS4): reachability (`isReachabilityData`, `isHintsUpdate`),
  players (`isPlayersState` - ONE shape replacing SlotsMap/PlayersState/anonymous),
  feed (`isFeedEvent` - ONE `FeedEvent`), plus `isSubscribeTokenResponse` for the 12 token casts
  via a shared `fetchSubscribeToken`. Duplicate type declarations deleted.
3. **AC3 - All 14 SSE cast sites migrated.** Raw-EventSource consumers either adopt the guard-aware
  hooks or keep raw ES with `parse`-guard calls (slot pages' 3-stream setup may keep raw ES if
  hook-ifying distorts them - dev's call, no casts either way). Zero `as` at SSE frame or
  token-endpoint sites (grep-verifiable).
4. **AC4 - Lint lock.** The eslint `assertionStyle: "never"` scope extends to `src/hooks/use-sse.ts`,
  `src/features/overlay/use-overlay-stream.ts` and the new guard modules, so regressions fail lint.
5. **AC5 - `pnpm gates` green; zero UX change.** typecheck/lint/jest/build; slot pages, overlays,
  event feed and admin console behave identically (manual smoke on one page per family).

## Tasks / Subtasks

- [ ] Task 1: Shared guard modules + `fetchSubscribeToken` + unify FeedEvent/PlayersState shapes
  (delete dups, update importers) (AC: 2)
- [ ] Task 2: Guard-aware `useSse` + `useOverlayStream` (signature change, all existing callers
  updated: admin-session-page useSSE<Session>, session-connection-gate (already guard-style),
  live-seat-counter, 3 overlay widgets) (AC: 1)
- [ ] Task 3: Migrate the 12 raw-EventSource cast sites (slot-page trios x3, PlayerProgressGrid,
  event-feed, admin console feed) (AC: 3)
- [ ] Task 4: ESLint scope extension (AC: 4)
- [ ] Task 5: `pnpm gates` + smoke; PR to develop; adversarial review; merge on green
  (pre-authorized) (AC: 5)

## Dev Notes

- Do this BEFORE 33.18 (decided 2026-07-11): `session-connection-gate`, `reachable-overlay`,
  slot pages are in both scopes; 33.18 migrates them onto this layer.
- The `(overlay)` route group has NO QueryProvider - overlay code must not grow TanStack deps here.
- `weekly-run-card`/`notification-center` (SSE -> invalidateQueries) are the house realtime+TanStack
  pattern; do not disturb them beyond guard adoption if touched.
- Guards live with their api module per AC-TS4 (reachability guards likely in a new
  `features/reachability/reachability-guards.ts` or colocated in `types.ts`'s module family).
- Frontend gates: `pnpm gates` from frontend/. Windows lessons apply; explicit-path staging
  (33.22 WIP on disk).

### References

- 33.7 worklist C3/D (`33-7-audit-worklist.md`); SSE audit of record 2026-07-11 (session);
  `frontend/AGENTS.md` AC-TS3/TS4, AC-API2.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.19). Audit: 14 SSE cast sites (21 payload + 15 string casts) + 12 token casts; 3 duplicate shape families; exemplar guards identified; eslint scope gap identified; sequenced before 33.18. Status: ready-for-dev. |
