# Story 32.7: Run timeline - checks-over-time curves + exchange log

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-24

## Story

As someone reading a run's recap,
I want to see how the game unfolded over time - each player's checks as a curve, and a log of what was
found when,
so that I can relive the pace and the exchanges, not just the final graph and podium.

## Context

Story 32.6 persists the game feed (`session_feed_event`) and serves it at
`GET /parties/{sessionId}/feed`, gated like the recap. This story turns that feed into the two
visualisations the author asked for, on the existing recap page (`/parties/{sessionId}`):

- **Cumulative-checks curves**, one line per player, bucketed by minute - each item event is a check by
  its finder (the sender), which holds in solo too (a self-find is still a find).
- **A filterable exchange log** - who found what for whom, and when.

The recap page renders for finished runs, so this is the **historical / replay** view. The **live**
view (subscribing to the Mercure `runs/{id}/feed` during a running game) is deferred to a follow-up -
the socle (persistence + the live-feed token fix) is already in 32.6.

### Decisions

- **Recharts** for the line chart (author's choice), pinned for React 19. Axes/tooltip/legend/responsive
  come for free; the dataviz discipline (validated categorical palette, recessive grid, legend for >=2
  series) is applied on top.
- **The transform is a pure, unit-tested function** (`buildChecksSeries`) separate from the chart, so
  the bucketing/cumulative logic is verifiable without rendering Recharts.
- **Colour follows the slot, never the rank.** Players are keyed and coloured by slot order from the
  dataviz-validated eight-hue palette (dark column; the app is dark-only), exposed as
  `--chart-series-1..8` CSS vars. Hiding a player removes its line/rows without repainting the others.
- **The log is capped at the 300 most recent events** with a visible note (no silent truncation) - a
  long multiworld can emit thousands.
- **Empty by construction.** `RunTimeline` renders nothing when there are no item events (a run that
  produced none, or one still generating), so nothing new appears on such recaps.

## Acceptance Criteria

1. The recap page shows a "Déroulé de la partie" section with cumulative-checks curves (one line per
   player) and a filterable exchange log, built from `GET /parties/{sessionId}/feed`.
2. Filtering a player hides its curve and its log rows; the remaining players keep their colours.
3. Solo runs render (self-finds are item events); a run with no item events shows nothing extra.
4. The chart uses the dataviz-validated categorical palette (CVD-safe), a legend for >=2 players, a
   recessive grid, and a dark-surface tooltip.
5. Access is inherited from 32.6 (the feed endpoint), including cookie forwarding so an owner sees a
   private run's timeline.
6. Gates green both sides.

## Tasks / Subtasks

- [x] **Task 1 - Feed API** (AC 1, 5). `feed-api.ts`: `FeedEvent` type + guard + `getSessionFeed` (SSR,
      cookie forwarding).
- [x] **Task 2 - Transform** (AC 2, 3). `buildChecksSeries` (pure) + unit test.
- [x] **Task 3 - Chart** (AC 4). `ChecksChart` (Recharts) + the `--chart-series-*` palette vars.
- [x] **Task 4 - Section** (AC 1, 2). `RunTimeline`: player filter chips, chart, capped exchange log.
- [x] **Task 5 - Wire** (AC 1). Recap page fetches the feed; `SessionRecapView` renders the section.
- [x] **Task 6 - Gates** (AC 6).

## Dev Notes

- `recharts@^3.10` added (React 19 compatible). `pnpm audit --audit-level high` stays clean.
- The chart is a client component; the feed is fetched server-side and passed down as a prop.
- Live mode (Mercure subscription) is the natural next story - the persisted feed makes reload/replay
  work without it, which is why historical shipped first.

### Project Structure Notes

- `frontend/src/features/recap/feed-api.ts`, `build-checks-series.ts` (+ test), `checks-chart.tsx`,
  `run-timeline.tsx`, `session-recap-page.tsx`
- `frontend/src/app/(public)/parties/[sessionId]/page.tsx`, `frontend/src/app/globals.css`,
  `frontend/src/lib/type-guards.ts`

### References

- [Source: _bmad-output/implementation-artifacts/32-6-persist-game-feed.md] - the feed endpoint + access
- dataviz skill - validated categorical palette, mark/legend/tooltip discipline

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- Frontend: typecheck, lint (1 pre-existing warning), 245 tests, build clean; audit clean with recharts.

### File List

- `frontend/src/features/recap/feed-api.ts`
- `frontend/src/features/recap/build-checks-series.ts` (+ `.test.ts`)
- `frontend/src/features/recap/checks-chart.tsx`
- `frontend/src/features/recap/run-timeline.tsx`
- `frontend/src/features/recap/session-recap-page.tsx`
- `frontend/src/app/(public)/parties/[sessionId]/page.tsx`
- `frontend/src/app/globals.css`
- `frontend/src/lib/type-guards.ts`
- `frontend/package.json`, `frontend/pnpm-lock.yaml`
