# Story 32.8: Live run timeline (Mercure)

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-24

## Story

As someone watching a run in progress,
I want the timeline and check curves to update live as items are found,
so that I can follow the game as it happens, not only replay it afterwards.

## Context

Story 32.7 built the timeline viz (checks curves + exchange log) for the **finished** run, on the recap
page. The persistence socle (32.6) also broadcasts every event live on Mercure `runs/{id}/feed` and
fixed the feed-token gate so a personal-run owner/participant can subscribe. This story wires the two
together: a **live** timeline on the run detail page while the game runs.

The plumbing already exists - `EventFeed` (`features/events`) subscribes to the same topic via
`fetchSubscribeToken('/sessions/{id}/feed-token')` + `EventSource` with reconnect. This story reuses
that pattern for the timeline viz rather than a new message list.

### Decisions

- **History + live, merged.** `LiveRunTimeline` first loads the persisted feed (so a reload or a late
  join keeps the history), then subscribes and merges each new item find in. Without the initial load a
  reload would blank the timeline mid-game.
- **Dedup by finder + origin check, not by time.** The persisted `occurred_at` is second-precision and
  the live `timestamp` is not, so time can't dedup an event present in both the snapshot and the live
  stream. One item lives at one location, found once by one finder, so `sender.slot + location.id` is
  the natural unique key.
- **Item finds only.** Non-item live frames (hints, chat, deaths) carry no finder slot; they are
  dropped, matching what 32.6 persists, so the live and historical views agree.
- **Reuse the 32.7 viz.** `LiveRunTimeline` feeds the merged events straight into `RunTimeline`; the
  curves and log recompute as events arrive. No duplicate chart.
- **On the run's Progression tab.** The live timeline sits under the existing `PlayerProgressGrid`,
  visible whenever the run is active/idle - the natural live-progress surface. The recap page keeps the
  historical view for finished runs.

## Acceptance Criteria

1. On a personal run's Progression tab, a live timeline shows the persisted history and updates as new
   item finds arrive on Mercure `runs/{sessionId}/feed`.
2. A find already in the initial snapshot is not double-counted when it also arrives live.
3. Only item finds appear; non-item frames are ignored. Access is inherited from the feed-token
   (owner/participant/registrant/admin - the 32.6 fix).
4. A dropped connection shows a "reconnecting" state and retries; an empty run shows a waiting message.
5. The finished-run recap page (32.7) is unchanged.
6. Gates green.

## Tasks / Subtasks

- [x] **Task 1 - Client feed fetch** (AC 1). Split `feed-api.ts` into a client-safe module (type,
      guards, `fetchSessionFeed`) and `feed-api.server.ts` (the SSR `getSessionFeed` with cookies).
- [x] **Task 2 - Live component** (AC 1-4). `LiveRunTimeline`: initial load + Mercure subscription
      (reusing `fetchSubscribeToken` + `EventSource`), normalise live frames, merge/dedup, render
      `RunTimeline`.
- [x] **Task 3 - Wire** (AC 1, 5). Mount on the run detail Progression tab, under `PlayerProgressGrid`.
- [x] **Task 4 - Gates** (AC 6).

## Dev Notes

- The live frame shape is the overlay `FeedEvent` (`features/overlay/overlay-api`), with `timestamp`
  and optional structured origin; `normalize()` maps it to the persisted `FeedEvent` (`occurredAt`,
  nullable fields) and returns null for non-item frames.
- No backend change: this consumes 32.6's `GET /parties/{id}/feed`, `GET /sessions/{id}/feed-token`,
  and the Mercure topic already published by `FeedPushController`.

### Project Structure Notes

- `frontend/src/features/recap/feed-api.ts` (now client-safe), `feed-api.server.ts` (new),
  `live-run-timeline.tsx` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`,
  `frontend/src/app/(public)/parties/[sessionId]/page.tsx`

### References

- [Source: _bmad-output/implementation-artifacts/32-6-persist-game-feed.md] - feed endpoint, token fix
- [Source: _bmad-output/implementation-artifacts/32-7-run-timeline-viz.md] - the viz it drives live
- [Source: frontend/src/features/events/event-feed.tsx] - the subscription pattern reused

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- Frontend: typecheck, lint (1 pre-existing warning), 245 tests, build clean. No API change.

### File List

- `frontend/src/features/recap/feed-api.ts`
- `frontend/src/features/recap/feed-api.server.ts`
- `frontend/src/features/recap/live-run-timeline.tsx`
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`
- `frontend/src/app/(public)/parties/[sessionId]/page.tsx`
