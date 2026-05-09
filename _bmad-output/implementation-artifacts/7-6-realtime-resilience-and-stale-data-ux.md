# Story 7.6 - Realtime Resilience and Stale Data UX

Status: done

## Review findings

- `useSSE` marked a connection as active only after the first message, not when the SSE connection opened.
- Public seat-counter disconnected messaging could appear before the hook grace state was reached.
- Public stale state was not cleared on reconnect unless a fresh message/poll changed the timestamp.
- Admin session detail used SSE without a polling fallback.
- No disruptive modal was present; existing degraded states are inline badges/messages.

## Corrections

- `useSSE` now exposes `connected`, `disconnected`, and `polling` states.
- `useSSE` marks `connected` on `EventSource.onopen`, delays `disconnected` until the grace period, and tracks polling fallback state.
- `LiveSeatCounter` now shows the reconnect/polling message only after the grace-period `disconnected` state.
- `LiveSeatCounter` clears stale state automatically when SSE reconnects.
- Admin session detail now polls `/admin/sessions/{id}` as fallback when SSE is unavailable and shows a subtle polling badge.

## Validation

- `pnpm lint -- src/hooks/use-sse.ts src/features/events/live-seat-counter.tsx src/features/admin/admin-session-page.tsx src/features/admin/admin-registration-dashboard.tsx`
- `pnpm typecheck`
