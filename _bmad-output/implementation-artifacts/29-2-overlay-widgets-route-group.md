# Story 29.2: overlay widgets - bare route group + three widgets

Status: review

## Story

As a streamer or caster,
I want a set of **transparent browser-source URLs** (one per widget) that render a session's item
notifications, goal celebrations, and live event log,
so that I can add them as OBS browser sources composited over my gameplay capture.

## Context

This story builds the OBS-facing surface on top of the overlay token from story 29.1. Everything renders
from **existing Mercure topics** and **reuses existing components** - no new realtime plumbing:

- **notifications** - topic `runs/{id}/feed`, events `type === "item-received"` → enqueue an
  `ItemToast`. The feed gives discrete events (one event = one toast), cleaner than the per-slot
  reachability diff used by `personal-run-slot-detail-page`.
- **goals** - topic `runs/{id}/players`, `client_status === 30` (+ `goal_reached_at`) → render
  `GoalCelebration`. Dedupe by slot so each goal fires once.
- **log** - topic `runs/{id}/feed` directly → reuse the `EventFeed` rendering (type badges, relative
  time), restyled for an overlay (transparent, larger type, no scroll chrome / auto-trim).

The page is a **bare route group** `app/(overlay)/` with its own minimal `layout.tsx`: transparent body,
**no `PublicShell`**, no header/footer. Token flow mirrors the existing SSE components: fetch the
subscribe payload from 29.1's public endpoint, open an `EventSource` on `hubUrl?topic=...&authorization=...`,
and on `onerror` re-mint + reconnect (the opaque token is passed in the URL; the Mercure JWT is
short-lived and re-fetched).

Customization is **query-param driven** (OBS-friendly, no rebuild): `slot` (filter to one slot - for a
player streaming their own run; default = whole session), `scale`, `pos`, `duration`, `theme`, `sound`
(plays the existing fanfare asset), `spoilers` (default `0` - item names show but hint/location text is
suppressed in the log), and `bg` (chroma-key fallback, e.g. `bg=00ff00`). `demo=1` fires a fake
notification client-side so the source can be positioned without waiting for a real event.

## Acceptance Criteria

1. **Bare layout.** `app/(overlay)/layout.tsx` renders a transparent document (`background: transparent`,
   the only place a non-token color may be set via the `bg` param), with **no** `PublicShell`/nav/footer.
   It does not pull global chrome. (Default export allowed - it is a Next.js route layout, AC-CO3.)
2. **Route.** `app/(overlay)/o/[sessionId]/[widget]/page.tsx` resolves `params` (awaited, Next.js 15) and
   renders the widget for `widget ∈ {notifications, goals, log}`; an unknown widget calls `notFound()`.
3. **Subscribe flow.** A client component fetches `/public/overlay/{sessionId}/subscribe?t={token}` (token
   read from the query string), validates the response with an `is*` type guard (no `as` at the boundary),
   then opens an `EventSource`. On `onerror`: close, re-fetch the subscribe payload, reconnect (same
   resilience pattern as `PlayerProgressGrid`/`EventFeed`). Missing/invalid token → render nothing
   (transparent), not an error card.
4. **notifications widget.** Each incoming `feed` event with `type === "item-received"` enqueues an
   `ItemToast` (reuse the component as-is; map the feed event to `{ itemName, flags }` - derive `flags`
   from the event payload/color, falling back to filler). Toasts play sequentially via the existing
   `onDone` queue. `?slot=` filters to events for that slot when the feed carries slot info.
5. **goals widget.** On `players` updates, when a slot reaches `client_status === 30` with
   `goal_reached_at`, render `GoalCelebration` once for that slot (dedupe via a ref set of seen slots).
   `?slot=` limits to a single slot.
6. **log widget.** Renders the `feed` stream using the `EventFeed` visual vocabulary (type badge, icon,
   relative time, text), restyled for overlay legibility on transparent background; newest first; trimmed
   to a sane cap (e.g. last 30). When `spoilers=0` (default), `hint`/`location-checked` text is masked
   (badge kept, text replaced) so casters don't leak locations.
7. **Query params.** `scale` (CSS transform/size), `pos` (anchor: e.g. `top-left|top-right|bottom-*`),
   `duration` (toast lifetime), `theme`, `sound` (1 = play the existing fanfare on item-received),
   `spoilers`, `bg` (solid chroma background) are all honored. Invalid/missing params fall back to sane
   defaults; impure values (e.g. `Date.now()`) are not read during render (AC-HK3).
8. **demo mode.** `?demo=1` injects a fake event of the widget's kind on mount (and optionally on an
   interval) without contacting the backend, so the source can be sized/positioned. Demo data is computed
   outside render (event handler / `useRef` init), never via `Math.random()` in render.
9. **Gates green.** `pnpm typecheck`, `pnpm lint` (0 warnings), `pnpm build` all clean. No `process.env`
   (use `src/lib/env.ts`); all fetches return typed results or `null`.

## Tasks / Subtasks

- [x] Task 1 - Route group + layout (AC 1, 2).
  - [x] `app/(overlay)/layout.tsx` (no chrome, `robots: noindex`) + `o/[sessionId]/[widget]/page.tsx`
        (client; validates widget → `notFound()`; sets document background transparent/chroma).
- [x] Task 2 - Overlay API + subscribe hook (AC 3, 9).
  - [x] `features/overlay/overlay-api.ts`: `fetchOverlaySubscribe` (plain `fetch`, no creds) + `is*`
        guard (no `as` - `in`-narrowing per lint rule).
  - [x] `useOverlayStream(sessionId, opaque, kind, onEvent)` - EventSource + re-mint/reconnect, callback
        held in a ref so it never re-opens the stream.
- [x] Task 3 - Widgets (AC 4, 5, 6, partial 7).
  - [x] `NotificationsOverlay` (feed `item-received` → `ItemToast` queue, flags derived from color),
        `GoalsOverlay` (players `client_status===30` → `GoalCelebration`, baseline-suppressed on load),
        `LogOverlay` (feed → restyled `EventFeed` vocabulary, spoiler masking of hint/location).
  - [x] `overlay-params.ts` parses scale/pos/duration/theme/sound/spoilers/bg/slot/demo (see param
        coverage note in Dev Agent Record - some are wired, some deferred).
- [x] Task 4 - Demo mode (AC 8).
  - [x] `?demo=1` fake-event injection per widget (works without a token → drives the 29.3 preview).
- [x] Task 5 - Gates (AC 9).
  - [x] `pnpm typecheck` / `pnpm lint` (0/0) / `pnpm build` clean; route `/o/[sessionId]/[widget]` built.
  - [ ] Manual OBS transparency check deferred to a real Mercure-configured environment (SpyHub returns
        an empty hub URL in dev, so the stream is inert locally; demo mode renders regardless).

## Dev Notes

### Project Structure Notes

- New `frontend/src/features/overlay/` for the API, hook, and widget components. Reuse - do not fork -
  `features/reachability/item-toast.tsx`, `features/reachability/goal-celebration.tsx`, and the rendering
  from `features/events/event-feed.tsx` (extract the row/badge rendering into a shared piece if cleaner,
  without changing the existing `EventFeed`).
- The `(overlay)` group must be isolated from the public/admin layouts; confirm no parent `layout.tsx`
  injects chrome.

### References

- `frontend/src/components/session/PlayerProgressGrid.tsx` - canonical EventSource + reconnect pattern
  (players topic).
- `frontend/src/features/events/event-feed.tsx` - feed event shape (`{ type, text, color?, timestamp }`)
  + type badge/icon/relative-time rendering to reuse.
- `frontend/src/features/reachability/item-toast.tsx` - toast component + flag→variant mapping.
- `frontend/src/features/reachability/goal-celebration.tsx` - goal celebration component.
- `frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx` - existing toast-queue +
  goal-detection wiring to mirror (but sourced from `feed`/`players`, not per-slot reachable).
- Story 29.1 - public subscribe endpoint + opaque-token query contract.

## Dev Agent Record

- **Feed is the toast source for the whole session** (one `item-received` event = one toast), avoiding
  per-slot reachability subscriptions. The feed event shape is `{ type, text, color?, timestamp }`
  (confirmed via story 9.13) - it carries **no numeric item flags and no slot field**, so:
  - toast variant is **derived from the AP color name** (`salmon`→trap, `plum`→progression,
    `slateblue/cyan`→useful, else filler) - best-effort with a safe filler fallback;
  - `?slot=` filtering is only meaningful for **goals** (the `players` topic is slot-keyed); it is a
    no-op for the feed-sourced notifications/log widgets in v1.
- **One JWT, two topics.** The 29.1 subscribe endpoint returns a JWT scoped to `feed` + `players`; each
  widget opens its own EventSource for the single topic it needs (the hook picks the topic by suffix).
- **Goals baseline suppression.** The first `players` snapshot lists every goal already reached before
  the overlay connected; those are recorded silently so loading the source mid-session doesn't replay a
  flood of celebrations. Only goals arriving *after* the baseline celebrate. Goals auto-dismiss after
  `?duration` (default 12 s) since nobody clicks "continuer" inside OBS.
- **Transparency** is applied at runtime by the page (sets `document.body`/`documentElement` background
  to `transparent`, or `#<bg>` for a chroma key) - the App Router has a single root `<html>/<body>`, so
  a route-group layout cannot replace the body; overriding the inline background is the clean escape.
- **Public fetch, not `apiFetch`**: the overlay subscribe call uses plain `fetch` (no credentials) - the
  opaque token is the credential, and `apiFetch` would wrongly attempt a token refresh/redirect on 401.

### v1 query-param coverage (honest)

| Param | v1 status |
|-------|-----------|
| `bg`, `spoilers`, `demo` | fully wired |
| `scale` | wired for notifications + log (CSS transform); goals is a full-screen takeover (n/a) |
| `pos` | wired for log; notifications stays top-center (`ItemToast`'s fixed design); goals full-screen |
| `duration` | wired for goals auto-dismiss; toast lifetime is `ItemToast`'s fixed 3 s |
| `slot` | wired for goals; no-op for feed widgets (feed has no slot field) - see above |
| `sound` | **deferred** - no audio on item toasts yet; `GoalCelebration` plays its own chiptune regardless |
| `theme` | **deferred** - single default theme in v1 |

Deferring `sound`/`theme` and per-widget `pos/duration` avoids modifying the shared `ItemToast`
(used by the slot detail page) - a follow-up can extend `ItemToast` with position/duration/sound props.

### Quality gates (all green)

- `pnpm typecheck` → 0 errors. `pnpm lint` → 0 errors / 0 warnings. `pnpm build` → clean, route
  `/o/[sessionId]/[widget]` emitted (dynamic).

### Files

- `frontend/src/app/(overlay)/layout.tsx`, `frontend/src/app/(overlay)/o/[sessionId]/[widget]/page.tsx`
- `frontend/src/features/overlay/overlay-api.ts`, `use-overlay-stream.ts`, `overlay-params.ts`
- `frontend/src/features/overlay/notifications-overlay.tsx`, `goals-overlay.tsx`, `log-overlay.tsx`

## Change Log

- 2026-06-16 - Story created (status: planned).
- 2026-06-16 - Implemented: bare `(overlay)` route group + 3 widgets (notifications/goals/log) reusing
  `ItemToast`/`GoalCelebration`/`EventFeed` vocabulary over the 29.1 public subscribe endpoint; query
  params + demo mode. All frontend gates green (status: review). Param coverage partial - see note.
- 2026-06-16 - Refinement: each widget's `useOverlayStream` now subscribes to its primary topic **and**
  `overlay-test` (one EventSource, multiple `topic` params, same JWT). The goals widget honors a
  `__test__` marker to celebrate immediately (bypassing baseline suppression) for operator tests.
- 2026-06-16 - Refinement (user feedback): widgets now render **large by default** (notifications base
  scale 2.6, log 1.9, goals already full-screen) so a full-size OBS browser source is well filled and
  can be **shrunk in OBS without blur** (downscaling a high-res CSS render stays crisp); `?scale`
  multiplies on top. Log scales from its `pos` anchor (`posToTransformOrigin`). The 29.3 panel preview
  renders the overlay at a true 1280×720 viewport and CSS-downscales it into the thumbnail to match.
- 2026-06-16 - Refinement (user feedback): added a `bare` prop to `GoalCelebration` (default false,
  so the in-app modal is unchanged). In `bare` mode the goals overlay drops the opaque dark backdrop,
  the white flash, and the full-screen `GravWave` field - only the card + foreground particles
  (confetti/sparks/shooting stars) render, over a transparent page for OBS compositing.
- 2026-06-16 - Refinement (user feedback): widgets now **fill the OBS source responsively** via a new
  `useViewport` hook (SSR-safe, rAF-deferred first measure) instead of fixed base scales.
  • **notifications**: new `ItemToast` `variant="fill"` (default `"toast"` keeps the in-app top-slide
  unchanged) - the toast pops in **centered and scaled** (`fillScale`, bounded on both axes) to occupy
  the whole page; added the `item-toast-pop` keyframe. • **log**: rows are now **full-width** (dropped
  `max-w-md`/transform-scale); the list is sized in `em` off a width-driven `fontSize`, so a taller
  source simply shows **more rows** (cap raised to 60, overflow clipped). `?scale` multiplies both.
- 2026-06-16 - Refinement (user feedback): log text size is now **fixed** (`BASE_FONT_PX` × `?scale`),
  no longer tied to the source width - shrinking the OBS source keeps the text readable and just fits
  fewer rows instead of scaling everything down. Long lines **wrap** onto multiple lines
  (`min-w-0 break-words` on the message). `useViewport` dropped from the log (still used by
  notifications).
