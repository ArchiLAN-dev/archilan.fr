# Story 13.9: Frontend — keep the session & tracker alive on passive pages

**Status:** review
**Epic:** 13 - Auth, refresh tokens & cleanup
**Date:** 2026-06-11

## Story

As a user who leaves a long-running page open (the run tracker) without interacting,
I don't want my session's access token to silently die and the realtime feed to stop,
so that I come back to a live page instead of stale data / a 401 logout.

## Context

Companion to story 13.8 (which stopped a benign refresh race from *persistently* logging the
user out of every device). 13.8 fixed the server-side blast radius; this story fixes the two
client-side causes that let the access token die on a passive page in the first place:

1. **Proactive refresh is a throttled `setInterval`.** `auth-context.tsx` refreshes via
   `setInterval(coordinatedRefresh, 13min)`. Browsers heavily throttle/freeze timers on
   inactive/background tabs and across sleep, so the 13-min tick can miss the 15-min
   access-token window → the token expires on a passive tab.
2. **The tracker SSE never refreshes its subscriber token.** `PlayerProgressGrid` reconnects
   `onerror` with the **same** Mercure subscriber token captured in the closure; once it
   expires the EventSource loops forever on a stale token → "no more info" on the tracker.

## Acceptance Criteria

1. The access token is refreshed when a passive tab becomes active again: on
   `visibilitychange` → visible and on window `focus`, a (coordinated, deduped) refresh runs
   when the last refresh is older than a short threshold. The 13-min interval stays as a
   backstop for long-active tabs.
2. The tracker realtime survives subscriber-token expiry: on the EventSource `onerror`, the
   grid **re-fetches a fresh subscriber token** (and the current state) before reconnecting,
   instead of reusing the stale token. This also recovers an expired access token (the fetch
   goes through `apiFetch`'s 401→refresh path).
3. No regression: multi-tab refresh stays coordinated (Web Lock + recent-ts), no tight
   reconnect loops (keep the backoff), and the unauthenticated handler still fires when a
   refresh genuinely fails.
4. Quality gates green - typecheck / lint / build.

## Tasks / Subtasks

- [x] **Task 1 - Refresh on visibility/focus** (AC: 1,3). In `auth-context.tsx`, add
  `visibilitychange` + `focus` listeners that call `coordinatedRefresh` when stale (read the
  shared `archilan_refresh_ts` to avoid refreshing on every tab switch). Keep the interval.
- [x] **Task 2 - SSE re-fetch token on error** (AC: 2,3). In `PlayerProgressGrid.tsx`, make
  the `onerror` reconnect re-run the init (re-fetch `/players` + `/players-token`) after the
  backoff, rather than reconnecting with the captured (possibly expired) token.
- [x] **Task 3 - Gates** (AC: 4). `pnpm typecheck` / `lint` / `build`.

## Dev Notes

- `coordinatedRefresh` (`src/lib/apiFetch.ts`) already dedupes via in-tab queue + Web Lock +
  the `archilan_refresh_ts` localStorage key (5 s skip) - reuse it from the visibility/focus
  handlers; gate with a longer staleness threshold so a quick tab-switch doesn't refresh.
- `apiFetch` reactively refreshes on 401, so re-running the grid's `init()` (which uses
  `apiFetch`) also recovers an expired access token.
- Other SSE consumers (slot detail hints, weekly run, admin reachability) share the same
  reconnect-with-stale-token shape; out of scope here (tracker is the reported case) but the
  same pattern applies - follow-up if needed.

### Non-goals

- Reading the access-token expiry in JS (it's an httpOnly cookie) - we use visibility/focus +
  a staleness threshold instead of an exact expiry schedule.
- Backend (covered by 13.8).

### References

- [Source: frontend/src/features/auth/auth-context.tsx (PROACTIVE_REFRESH_MS interval)]
- [Source: frontend/src/lib/apiFetch.ts (coordinatedRefresh, REFRESH_TS_KEY, reactive 401)]
- [Source: frontend/src/components/session/PlayerProgressGrid.tsx (EventSource onerror reconnect)]
- Builds on story 13.8 (per-family revocation + reuse grace); 13.4 (multi-tab refresh).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `auth-context.tsx`: added `visibilitychange` + `focus` refresh (coordinated, gated by a
  staleness threshold read from `archilan_refresh_ts`); kept the 13-min interval backstop.
- `PlayerProgressGrid.tsx`: `onerror` now re-runs `init()` after the backoff (re-fetches the
  subscriber token + state) instead of reconnecting with the stale token; `apiFetch` recovers
  an expired access token in passing.
- Gates: typecheck / lint / build green.

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Implemented the frontend half of the passive-page session fix: refresh on visibility/focus (timers throttle on inactive tabs) + the tracker SSE re-fetching a fresh subscriber token on error. Pairs with 13.8 (server-side per-family revocation + reuse grace). Status → review. |
