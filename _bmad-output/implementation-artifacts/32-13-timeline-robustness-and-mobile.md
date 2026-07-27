# Story 32.13: Timeline robustness & mobile

**Status:** draft
**Epic:** 32 - Recaps
**Date:** 2026-07-28

## Story

As anyone using the run timeline on a long or busy run, or on a phone,
I want it to stay smooth and usable,
so that hovering, a big log, a live reconnect, and touch zoom all behave.

## Context

The timeline (stories 32.7/32.8) works but has known rough edges flagged during implementation:

- **Hover re-render cost**: hovering the chart updates a `hoverBucket` state on every `mousemove`, which
  re-renders the log (up to 300 rows) for the cross-highlight. Fine on small runs, potentially janky on a
  busy one. Throttle the hover state (rAF or a small debounce).
- **Log virtualization**: the log is capped at 300 rows and rendered as plain DOM. For a long day this is
  a lot of nodes, re-rendered on hover/filter. Virtualize (windowed list) so only visible rows mount.
- **Live reconnect catch-up**: `LiveRunTimeline`'s `EventSource` reconnects after an outage, but events
  during the outage are missed until a full reload (Mercure is pub/sub, no replay). Re-fetch the
  persisted feed on reconnect and merge (the dedup by finder+location already handles overlap) so the gap
  fills automatically.
- **Touch zoom**: drag-to-zoom is desktop mouse only. Add a Recharts `Brush` (a range selector under the
  chart) as a touch-friendly zoom, complementary to the drag.

### Decisions

- These are independent hardenings; pick up whichever bites first in real use. None changes the API.
- Throttle + virtualization are pure frontend perf; reconnect catch-up reuses the existing
  `fetchSessionFeed` + merge; Brush is a Recharts add.

## Acceptance Criteria (sketch)

1. Chart hover no longer causes visible jank on a busy day's log (throttled + virtualized).
2. A live outage of the SSE feed is filled on reconnect (persisted re-fetch + merge), no manual reload.
3. The chart is zoomable by touch on mobile (Brush or equivalent), the drag-zoom kept for desktop.
4. Gates green.

## Notes

- Files: `frontend/src/features/recap/run-timeline.tsx` (throttle, virtualization),
  `live-run-timeline.tsx` (reconnect re-fetch), `checks-chart.tsx` (Brush).
- Virtualization: no library is installed today; either add a small windowing lib or hand-roll, weighing
  the project's lean-deps stance.
