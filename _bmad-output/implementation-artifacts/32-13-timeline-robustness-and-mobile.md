# Story 32.13: Timeline robustness & mobile

**Status:** review
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

## Dev Notes (implementation, 2026-07-28)

All four hardenings shipped, frontend-only, no API change:

- **Hover throttle (AC 1)**: `RunTimeline` coalesces the chart's per-mousemove hover reports to at
  most one state commit per animation frame (rAF + latest-value ref, cancelled on unmount).
- **Log cost (AC 1)**: rows extracted to a memoized `LogRow` - across a highlight change only the
  rows entering/leaving the highlight re-render, not all 300. Instead of a windowing library, each
  row gets `content-visibility: auto` + `contain-intrinsic-size` (arbitrary-property Tailwind
  classes): the browser skips layout/paint for off-screen rows - native windowing, zero new deps
  (the lean-deps call the story asked to weigh). Unsupporting browsers just render normally.
- **Reconnect catch-up (AC 2)**: on a *re*connect `onopen` (first open is tracked), the live
  timeline re-pulls the persisted feed and merges; the 32.12 type-aware dedup keys absorb the
  overlap, so an SSE outage fills without a reload.
- **Touch zoom (AC 3)**: a recharts `Brush` (22 px) under the chart drives the same committed
  `zoom` as the desktop drag (full-range brush maps to `zoom: null`), and mirrors external zoom
  changes back as a controlled index window - the two mechanisms stay in sync and the reset button
  clears both. Drag-zoom kept as-is for desktop.

### Review (2026-07-28, two independent passes + recharts 3.10.1 source verification)

Two confirmed Brush/zoom bugs found and fixed before merge (recharts 3's Brush *slices* the chart
data to [startIndex, endIndex], it does not merely mirror the domain):

- Bucket/measure switch under an active zoom could invert the two index lookups (start > end) and
  slice the chart empty - the controlled window is now order-clamped.
- Collapsing the travellers onto one index sliced the chart to a single point while `onBrush`
  silently ignored the event (recharts' internal dispatch runs before the app callback) - the
  handler now re-imposes a two-point window and commits it as the zoom.

Also hardened on review: the reconnect refetch now runs on *every* `onopen` (not only reconnects),
closing the pre-existing first-open window between the initial fetch and the subscription handshake
(one redundant GET, absorbed by dedup). Refuted as not-a-bug: duplicate rows for events without
`location.id` cannot occur - the bridge omits the whole structured origin when the location is
unresolvable, so such frames never pass `normalize()`; goals dedup by slot. Known residual risks,
accepted: a one-frame race can start a phantom drag-select when clicking the brush < 16 ms after
leaving the plot (recharts throttles mousemove but not mousedown; needs a precise pointer dance to
mis-zoom), and collapsing the travellers inside an exactly-two-point zoom leaves the internal slice
at one point until the next brush interaction (controlled props only resync on value change).
`contain-intrinsic-size: 34px` can make the scrollbar twitch when rows wrap to two lines (cosmetic).

### Mobile layout pass (2026-07-28, user-reported, verified in-browser at 375 px)

The chart and log controls broke on a phone: the six-option facet group (Tous/Reçus/Envoyés/
Locaux/Indices/Objectifs) overflowed the viewport and got clipped, and the X-axis ticks collided
at narrow widths. Fixes, checked live at 375 px (no horizontal scroll, facets fold onto two rows):
`Segmented`'s option group wraps internally (`flex-wrap` + `max-w-full`), the X axis gets
`minTickGap={24}`, and the chart is taller on phones (`h-80 sm:h-72`) to compensate for the
legend + brush share of the box. Goal-marker labels can still clip at the plot edge (recharts
`Label` has no collision avoidance) - cosmetic, the "Chronologie des objectifs" list has the data.

### Post-merge fix (2026-07-28, reported live)

Console errors "Received NaN for the `x` attribute" on the Brush rects, four per incoming live
check. Root cause traced in recharts 3.10.1 sources: the chart copies its `data` prop into its
internal store **in an effect** (`chartDataContext.js`) - one commit late - and a controlled Brush
index that overruns that stale copy resolves through the old brush scale to `undefined`
(`Brush.js` `getDerivedStateFromProps`, controlled branch), which `Math.min/max` turns into NaN
rect coordinates. Every live check that opens a new bucket grows `rows` and hit the mismatch for
one commit. Fix: the controlled window is computed from `useDeferredValue(rows)` - deferred by the
same one commit, so the indices are always resolvable by the scale recharts actually holds, and
they catch up in the deferred re-render (a ref-based previous-count clamp was rejected by the
`react-hooks/refs` lint). Residual: a coarsening bucket switch under an active zoom can still log
one transient warning through recharts' own internal stale `dataStartIndex` (its `setChartData`
reducer only re-clamps `dataEndIndex`) - internal to the lib, not reachable from props.
