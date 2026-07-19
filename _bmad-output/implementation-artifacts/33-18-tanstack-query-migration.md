# Story 33.18: TanStack Query Migration of Fetch-in-Effect Pages (frontend/)

Status: done

## Story

As a maintainer of the frontend's data layer,
I want the remaining fetch-in-useEffect surfaces converted to TanStack Query over feature api modules,
so that AC-NX1/AC-API1/AC-API4/AC-ST2 hold across the tree, server state gets caching/dedup/invalidation for free, and the 33.7 audit's C1/C2/C4 acceptances are finally paid down.

## Context (audit 2026-07-11, worklist of record - full detail in session audit)

- TanStack v5 installed; `lib/query-client.ts` exports staleTime constants (DEFAULT/REALTIME/STATIC/
  SESSION); QueryProvider mounted in public-shell + admin-shell; **the `(overlay)` route group has NO
  QueryProvider** - overlays stay out of scope.
- 49 files fetch in effects; 28 files already on useQuery (5 mixed); 52 components import apiFetch
  directly vs 33 sanctioned `*-api.ts` modules.
- House patterns to follow: `admin-membership-dashboard` (queryKey w/ filters, DEFAULT_STALE_TIME,
  invalidateQueries after mutations), `community-directory` (`enabled:` auth gating), `join-page`
  (errors-in-result + `retry: false` parity), `weekly-run-card`/`notification-center` (SSE onmessage
  -> invalidateQueries), `weekly-run-slot-page` (conditional `refetchInterval` 3s/60s).
- apiFetch's 401-coordinated-refresh is the auth backbone: every queryFn keeps calling apiFetch
  (moved into `*-api.ts` functions returning typed-or-null per AC-API2).

## Scope decisions (locked at story creation)

- **OUT: `auth-context.tsx`** - the auth bootstrap + proactive refresh is infrastructure every page
  gates on; converting it is its own story if ever (recorded).
- **OUT: `(overlay)` group** (`reachable-overlay`, `use-overlay-stream` internals) - no QueryProvider,
  OBS browser sources; the 33.19 typed layer already cleaned them.
- **OUT: raw `fetch` in server-side `*-api.ts` modules** (by design) and Mercure/SSE plumbing (33.19).
- **IN: mutation handlers stay hand-rolled** (house pattern) but every "refetch after mutation"
  (reload()/loadKey bumps/refreshKey) becomes `invalidateQueries` on the page's key.
- **C1 (direct apiFetch in components) resolves organically**: each migrated page's fetches move into
  its feature `*-api.ts` (typed-or-null + guards). No separate 52-file sweep - files not otherwise
  migrated keep their handler-time apiFetch calls (accepted, again).
- **C4**: `admin-event-edit-page.tsx:169` `as AdminEventFormData` cast falls with its page.

## Acceptance Criteria

1. **AC1 - Worklist executed batch by batch.** Every file in the batches below converted: initial
   data via `useQuery` (explicit staleTime from query-client constants, keys as domain arrays,
   `enabled:` for auth/param gating), fetches relocated to `*-api.ts` with guards, refetch-after-
   mutation via invalidation. Per-page semantics preserved (loading/denied/not-found/error states,
   redirects, polling cadences, SSE-triggered refreshes, AbortController semantics subsumed by
   query cancellation).
2. **AC2 - Realtime pages keep their behaviour.** Polling pages use `refetchInterval` (incl.
   conditional callbacks for adaptive cadences - personal-run-detail 3s/30s/off); SSE-refresh pages
   use the invalidate pattern; staleness badges and disconnect grace keep working.
3. **AC3 - No regressions in the funnel gates.** The 5 registration gates keep their exact
   auth-probe -> redirect -> resource flow and French error strings (the auth probe MAY be
   normalized onto `useAuth()` where behaviour-identical; recorded per gate).
4. **AC4 - `pnpm gates` green; zero UX change.** Plus grep-verifiable: no `apiFetch`/`fetch` call
   inside a `useEffect` in the migrated files.

## Worklist (batches; semantics keywords from the audit of record)

- **B1 Community/account (9)**: community-activity (x2 fetchers), community-friends-panel,
  community-profile-customization-form (parallel seed -> form), profile-comments (forbidden
  sentinel), profile-relationship-actions (null for anon/self), account-shell (parallel + badges
  best-effort), account-overview, account-registrations (useAuth redirect), account-security-section,
  profile-slug-editor (cooldown at load).
- **B2 Events funnel (7)**: registration-eligibility-gate (auth probe + 30s repoll -> refetchInterval),
  game-selection-gate (loadKey -> invalidate), registration-recap-gate, slot-yaml-gate,
  session-connection-gate (memoized fetch + useSSE fallbackPoll -> queryFn + invalidate),
  event-registration-cta (guest-graceful), live-seat-counter (fallbackPoll -> refetch).
- **B3 Personal runs (6)**: personal-runs-list-page (mutations -> invalidate),
  personal-run-detail-page (ADAPTIVE POLLING 3s/30s/off via refetchInterval callback; restart toast
  from status transition preserved), personal-run-game-selection-page,
  personal-run-participant-detail-page (401 vs 403 vs 404 distinction),
  personal-run-slot-yaml-page (+ yaml templates reload -> invalidate), join-page (residual auto-join
  effect stays - one-shot navigation, recorded).
- **B4 Admin simple (10)**: admin/page.tsx (Promise.all -> two queries), admin-content-dashboard
  (refreshKey -> invalidate), admin-event-dashboard (in-place mutation updates -> setQueryData or
  invalidate), admin-event-edit-page (+C4 cast), admin-event-game-selection-page (seed-into-form),
  admin-game-editor (refreshGame(response) -> setQueryData), admin-post-form (enabled: edit-mode),
  admin-user-directory (filters-in-key), admin-guided-game-creation (IGDB search: query w/
  keepPreviousData), archipelago-client-settings + archipelago-guide-settings,
  admin-weekly-template-form (finish the mixed file).
- **B5 Admin complex (3)**: admin-registration-dashboard (SSE -> invalidate + 30s polling fallback +
  staleness badge), admin-catalogue-sync-page (reloadAll -> invalidate, 503 message),
  admin-session-page (list/detail queries; container 5s + logs 10s polls -> refetchInterval;
  console SSE untouched).
- **B6 Widgets (3)**: twitch-status-context (poll -> useQuery refetchInterval inside provider),
  igdb-game-search (debounced handler -> useQuery keepPreviousData), weekly-run-slot-page residual
  players-list effect.
- **B7**: grep sweep (no fetch-in-effect in migrated files; no direct `fetch` losing 401-refresh
  outside sanctioned sites), gates, PR.

## Dev Notes

- Branch AFTER PR #308 (33.19) merges - session-connection-gate, slot pages, event surfaces overlap.
- `useQuery` + null-returning api fns: encode not-found/denied in the RESULT (discriminated union or
  null + status field) per join-page precedent, since api fns never throw (AC-API2). `retry: false`
  or 1 to match old single-shot semantics; document per page when old code had implicit retries (none
  did - effects ran once).
- Query keys: follow existing domain-array convention. Seed-into-form pages: `useQuery` + a
  form-hydration effect keyed on data identity (the ONE sanctioned setState-in-effect shape, C8) -
  do not force-fit form state into query state (AC-ST2 separation).
- Adaptive polling: `refetchInterval: (query) => ...` v5 signature (query.state.data).
- 33.19 lessons: fan out big batches to parallel subagents on DISJOINT files with exact specs;
  orchestrator greps residuals + runs gates; publisher/consumer evidence beats plausibility in
  review. Explicit-path staging (33.22 WIP on disk). Windows: -F/--body-file.

### References

- 33.7 worklist C1/C2/C4/D; TanStack audit of record 2026-07-11 (session); frontend/AGENTS.md
  AC-NX1/API1-5/ST2/HK2; `lib/query-client.ts` constants.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.18, sequenced after 33.19). Scope locked: 38 files across 6 batches; auth-context + overlay group + server-side fetch OUT (recorded); C1 resolves organically per migrated page; mutations stay hand-rolled + invalidation. House patterns and adaptive-polling/SSE-invalidate targets identified. Status: ready-for-review. |
