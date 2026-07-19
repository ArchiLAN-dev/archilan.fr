# Story 33.19: Typed SSE Layer (frontend/)

Status: done

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

- [x] Task 1: Shared guard modules + `fetchSubscribeToken` + unify FeedEvent/PlayersState shapes
  (delete dups, update importers) (AC: 2)
- [x] Task 2: Guard-aware `useSse` + `useOverlayStream` (signature change, all existing callers
  updated: admin-session-page useSSE<Session>, session-connection-gate (already guard-style),
  live-seat-counter, 3 overlay widgets) (AC: 1)
- [x] Task 3: Migrate the 12 raw-EventSource cast sites (slot-page trios x3, PlayerProgressGrid,
  event-feed, admin console feed) (AC: 3)
- [x] Task 4: ESLint scope extension (AC: 4)
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

claude-fable-5 (foundations by the orchestrator; consumer migration fanned out to 2 parallel
subagents on disjoint file sets, verified by orchestrator grep + full gates).

### Debug Log References

- AC1's "invalid frames counted/logged in dev" clause was NOT implemented as logging: AC-ENV1 bans
  direct `process.env` reads outside `env.ts`, so a NODE_ENV-gated console.warn would violate the
  house rules for marginal value. Frames failing the guard are silently dropped (same observable
  behaviour as the old silent catch), with explanatory comments at both hook sites. Recorded as a
  deliberate deviation.
- One cast site survived the fan-out (neither agent owned it): `reachable-overlay.tsx:70`
  `event.data as string` - caught by the orchestrator's residual grep, fixed with the standard
  frame-narrowing pattern. Grep now returns zero SSE/token casts tree-wide.

### Completion Notes List

- Foundations: `features/realtime/realtime-api.ts` (SubscribeTokenPayload + guard +
  `fetchSubscribeToken`, apiFetch-based so 401-refresh is preserved); `isReachabilityData` +
  `isHintsUpdate` in `reachability/types.ts`; `isFeedEvent` + `isPlayersState` in `overlay-api.ts`;
  guard params on `useSSE` (3rd) and `useOverlayStream` (3rd), guards held in refs.
- 56 casts removed at SSE/token sites (Auditor count over removed diff lines: 36 on the 3 slot
  pages incl. the same-cast REST pre-checks, 20 across hooks/grid/feed/console/gate/seat-counter -
  the story's 48 "ceiling" was an undercount); duplicate FeedEvent (x2) and SlotData/anonymous
  players shapes deleted - one shape per payload family now.
- Notable semantic tightenings (all in the story's spirit, no UX change): junk frames that parsed
  but had the wrong shape used to flow through mistyped (AdminTerminal buffered undefined fields) -
  now dropped; the hints-merge lying cast replaced by explicit defaults under `...prev, ...data`
  (consumers already coalesce with `?? 0`).
- session-connection-gate wired with a real structural guard (`isSessionFrame` matching exactly
  parseSession's rejection conditions) - no identity-guard trick.
- ESLint `assertionStyle: "never"` scope extended to use-sse.ts, use-overlay-stream.ts,
  features/realtime/**.
- Gates: `pnpm gates` green (typecheck 0, lint 0, jest 172, build clean).

### File List

- frontend/src/features/realtime/realtime-api.ts (new)
- frontend/src/hooks/use-sse.ts, frontend/src/features/overlay/use-overlay-stream.ts (guard param)
- frontend/src/features/reachability/types.ts, frontend/src/features/overlay/overlay-api.ts (guards)
- frontend/src/features/weekly-runs/weekly-run-slot-page.tsx,
  frontend/src/features/admin/admin-slot-reachability-page.tsx,
  frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx (slot-page trios)
- frontend/src/components/session/PlayerProgressGrid.tsx, frontend/src/features/events/event-feed.tsx,
  frontend/src/features/admin/admin-session-page.tsx, frontend/src/features/events/session-connection-gate.tsx,
  frontend/src/features/events/live-seat-counter.tsx
- frontend/src/features/overlay/notifications-overlay.tsx, log-overlay.tsx, goals-overlay.tsx,
  reachable-overlay.tsx
- frontend/eslint.config.mjs

### Review Findings

Adversarial review 2026-07-11 (Blind Hunter / Edge Case Hunter, PR #308). The ECH replayed every
guard against the actual publishers (api RealtimePublisher/SessionLifecycleManager, bridge
ap_client.py/state.py/reachable.py) - that evidence settled the Blind Hunter's shape-mismatch HIGHs.

- [x] [Review][Patch] REAL BUG: bridge `death_link` feed frames carry no `text` and were dropped by
  `isFeedEvent` on all 4 feed surfaces - `FeedEvent.text` made optional, guard accepts absent text,
  consumers render `?? ""` (pre-33.19 parity: blank text, type badge + timestamp)
- [x] [Review][Patch] `isReachabilityData` verified 2 of the 6 arrays the pages hard-dereference -
  all 6 now checked (bridge always publishes them together; zero added drop risk, and a passing
  frame can no longer throw mid-handler/mid-render)
- [x] [Review][Patch] Duplicate byte-identical session guards - unified as `isSessionStatusFrame`
  in realtime-api; admin page keeps its documented full-Session refinement as a delegating wrapper
- [x] [Review][Patch] AdminTerminal `setConnected(true)` had moved behind the guard - restored to
  fire on any received frame (stream-liveness signal, pre-33.19 parity)
- [x] [Review][Patch] AC4 lint-lock gap (Auditor): `reachability/types.ts` hosts guards but matched
  no `assertionStyle: never` glob - added to the eslint files array
- [x] [Review][Dismissed] Hints default-seeding + array-strictness (BH HIGH) - refuted by publisher
  evidence: ap_client.py always sends the hints array plus the fields the defaults cover
- [x] [Review][Dismissed] seat-counter/session guards dropping real frames - refuted: publisher
  matrix verified exact (RealtimePublisher.php:53, Session::payload())
- [x] [Review][Dismissed] `fetchSubscribeToken` requiring `topic` - callers always used
  `payload.topic` to connect; a topicless payload was already broken (worse: silently)
- [x] [Review][Accepted] Guards check discriminants, not leaves ("the lie moved from as to is") -
  documented tradeoff (single-publisher contract), comment blocks at the guard families
- [x] [Review][Accepted] No dev logging of dropped frames (AC1 deviation) - AC-ENV1 bans direct
  process.env and env.ts exposes no dev flag; silent drop = pre-33.19 parity
- [x] [Review][Accepted] guardRef sync mechanisms differ between hooks (useEffect vs
  useLayoutEffect) - each consistent with its own file's pre-existing onMessage ref pattern; all
  call sites pass module-constant guards

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.19). Audit: 14 SSE cast sites (21 payload + 15 string casts) + 12 token casts; 3 duplicate shape families; exemplar guards identified; eslint scope gap identified; sequenced before 33.18. Status: ready-for-dev. |
| 2026-07-11 | Implemented: realtime-api module, 4 guard families, guard-aware hooks, 48 casts removed, shape dups deleted, eslint scope extended. Dev-log deviation recorded (no dev logging - AC-ENV1). pnpm gates green (172 tests). Status: ready-for-review (Task 5 pending PR/review/merge). |
