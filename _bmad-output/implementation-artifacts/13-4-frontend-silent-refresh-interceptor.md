# Story 13.4: Frontend Silent Refresh Interceptor

**Status:** review
**Epic:** 13 - Secure Token Lifecycle - Refresh Token
**Date:** 2026-05-05

---

## Story

As a user with an expired access token,
I want the frontend to transparently refresh my session,
So that I am not interrupted mid-action by an unexpected logout.

---

## Acceptance Criteria

1. `POST /auth/refresh` is called once when any non-bypass API call returns 401.
2. If refresh succeeds (204), the original request is retried automatically and the user sees no disruption.
3. If refresh fails (401), the authenticated client state is cleared and the user is redirected to `/connexion` with the current path as `?returnTo=` query param.
4. Requests to `/auth/refresh` and `/auth/login` are never retried by the interceptor to avoid infinite loops and false refreshes on bad credentials.
5. Concurrent 401 responses during a single refresh operation trigger only one refresh call (queued retry pattern) - all waiting requests retry after the single refresh.
6. All existing fetch calls across the frontend are migrated to the centralised `apiFetch` utility.

---

## Tasks / Subtasks

- [x] Create `apiFetch` utility with silent refresh interceptor (AC: 1, 2, 3, 4, 5)
  - [x] Create `frontend/src/lib/apiFetch.ts`
  - [x] Module-level `registerUnauthenticatedHandler(fn)` + `_onUnauthenticated` singleton
  - [x] `attemptRefresh()` with `isRefreshing` flag and `refreshQueue` array (queue pattern)
  - [x] `apiFetch(input, init?)`: adds `credentials: 'include'`, intercepts 401, skips bypass paths
  - [x] Bypass paths: `/auth/refresh`, `/auth/login`

- [x] Wire unauthenticated handler into `AuthContext` (AC: 3)
  - [x] Add `wasEverAuthenticated` ref to `AuthProvider`
  - [x] Register handler in `useEffect`: if `wasEverAuthenticated.current`, call `setUser(null)` + `router.push('/connexion?returnTo=...')`
  - [x] Set `wasEverAuthenticated.current = true` when `setUser` is called with a non-null user
  - [x] Replace `fetch` with `apiFetch` for the `/account/profile` call in AuthProvider

- [x] Migrate all API fetch calls to `apiFetch` (AC: 6)
  - [x] `src/components/admin-shell.tsx` (logout)
  - [x] `src/components/public-shell.tsx` (logout)
  - [x] `src/features/admin/admin-event-dashboard.tsx`
  - [x] `src/features/admin/admin-event-edit-page.tsx`
  - [x] `src/features/admin/admin-event-create-page.tsx`
  - [x] `src/features/admin/admin-event-game-selection-page.tsx`
  - [x] `src/features/admin/admin-game-editor.tsx`
  - [x] `src/features/admin/admin-game-library-dashboard.tsx`
  - [x] `src/features/admin/admin-user-directory.tsx`
  - [x] `src/features/admin/admin-content-dashboard.tsx`
  - [x] `src/features/admin/admin-post-form.tsx`
  - [x] `src/features/admin/admin-registration-detail.tsx`
  - [x] `src/features/admin/admin-session-page.tsx`
  - [x] `src/features/auth/account-profile.tsx`
  - [x] `src/features/auth/account-registrations.tsx`
  - [x] `src/features/auth/login-form.tsx`
  - [x] `src/features/auth/signup-form.tsx`
  - [x] `src/features/events/event-registration-cta.tsx`
  - [x] `src/features/events/game-selection-gate.tsx`
  - [x] `src/features/events/live-seat-counter.tsx`
  - [x] `src/features/events/registration-eligibility-gate.tsx`
  - [x] `src/features/events/registration-recap-gate.tsx`
  - [x] `src/features/events/session-connection-gate.tsx`
  - [x] `src/features/events/slot-yaml-gate.tsx`
  - [x] `src/features/streaming/twitch-status-context.tsx`
  - [x] `src/features/content/public-posts-api.ts`
  - [x] `src/features/events/public-events-api.ts`
  - [x] `src/features/payments/shop-api.ts`
  - [x] `src/features/payments/membership-api.ts`
  - [x] `src/app/(admin)/admin/jeux/nouveau/page.tsx`

- [x] Run quality checks
  - [x] `pnpm typecheck`
  - [x] `pnpm lint`

---

## Dev Notes

### Architecture: No Existing Centralized Fetch

The frontend currently has no centralized fetch utility - all 30 files make direct `fetch(${env.apiBaseUrl}/...)` calls. This story creates `src/lib/apiFetch.ts` as the single API client.

### `apiFetch.ts` - Core Design

```ts
// Module-level state - one refresh in-flight at a time
let isRefreshing = false;
let refreshQueue: Array<(ok: boolean) => void> = [];
let _onUnauthenticated: ((nextPath: string) => void) | null = null;

export function registerUnauthenticatedHandler(fn: (nextPath: string) => void): void {
  _onUnauthenticated = fn;
}

const BYPASS_PATHS = ['/auth/refresh', '/auth/login'];

async function attemptRefresh(): Promise<boolean> {
  if (isRefreshing) {
    return new Promise<boolean>((resolve) => { refreshQueue.push(resolve); });
  }
  isRefreshing = true;
  try {
    const res = await fetch(`${env.apiBaseUrl}/auth/refresh`, {
      method: 'POST',
      credentials: 'include',
    });
    const ok = res.status === 204;
    refreshQueue.splice(0).forEach((cb) => cb(ok));
    return ok;
  } catch {
    refreshQueue.splice(0).forEach((cb) => cb(false));
    return false;
  } finally {
    isRefreshing = false;
  }
}

export async function apiFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  const url = typeof input === 'string' ? input
    : input instanceof URL ? input.href
    : (input as Request).url;
  const opts: RequestInit = { credentials: 'include', ...init };
  const response = await fetch(input, opts);

  if (response.status !== 401 || BYPASS_PATHS.some((p) => url.includes(p))) {
    return response;
  }

  const refreshOk = await attemptRefresh();
  if (!refreshOk) {
    const nextPath = typeof window !== 'undefined' ? window.location.pathname : '/';
    _onUnauthenticated?.(nextPath);
    return response;
  }
  return fetch(input, opts);
}
```

### `auth-context.tsx` - Handler Registration

```tsx
export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);
  const wasEverAuthenticated = useRef(false);
  const router = useRouter();

  useEffect(() => {
    registerUnauthenticatedHandler((nextPath: string) => {
      setUser(null);
      if (wasEverAuthenticated.current) {
        router.push(`/connexion?returnTo=${encodeURIComponent(nextPath)}`);
      }
    });
  }, [router]);

  useEffect(() => {
    apiFetch(`${env.apiBaseUrl}/account/profile`)
      .then((r) => (r.ok ? r.json() : null))
      .then((payload: { data: AuthUser } | null) => {
        if (payload?.data) {
          setUser(payload.data);
          wasEverAuthenticated.current = true;
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  // wrap setUser to also track wasEverAuthenticated
  function handleSetUser(u: AuthUser | null) {
    if (u !== null) wasEverAuthenticated.current = true;
    setUser(u);
  }

  return (
    <AuthContext.Provider value={{ user, loading, setUser: handleSetUser }}>
      {children}
    </AuthContext.Provider>
  );
}
```

### Why `wasEverAuthenticated` Ref

Prevents the unauthenticated handler from redirecting a non-logged-in user who visits a public page. The `account/profile` 401 on initial load would otherwise redirect them to `/connexion` because `apiFetch` calls the interceptor.

### Bypass Paths Rationale

- `/auth/refresh` - required: prevents infinite loop (refresh fails → intercept → refresh again)
- `/auth/login` - prevents false session-restore on invalid credentials (wrong password 401 should show error, not trigger refresh)

### Query Param: `returnTo` vs `next`

The existing codebase uses `?returnTo=` (see `admin-shell.tsx`, `login-form.tsx`). The story spec says `?next=` but we use `returnTo` for consistency. The `LoginForm` component already reads `returnTo` from the URL search params.

### No Tests

The frontend has no test framework configured (no jest/vitest). Quality gates are `pnpm typecheck` and `pnpm lint` only.

### References

- `frontend/src/lib/env.ts` - `env.apiBaseUrl`
- `frontend/src/features/auth/auth-context.tsx` - AuthProvider, setUser
- `frontend/src/features/auth/login-form.tsx` - example of API call (keep using apiFetch, but BYPASS_PATHS prevents 401 interception)
- `frontend/src/components/admin-shell.tsx` - logout handler example
- Existing pattern: `await fetch(`${env.apiBaseUrl}/...`, { credentials: "include" })` → replace with `apiFetch(`${env.apiBaseUrl}/...`)`

---

## Addendum — Multi-tab proactive-refresh bugfix (2026-06-08)

### Symptom

Opening several tabs within a few milliseconds of each other caused the whole session to
be logged out roughly one access-token lifetime later (~13–15 min).

### Root cause

A proactive silent refresh was later added to `AuthProvider` (a `setInterval` firing every
`PROACTIVE_REFRESH_MS = 13 min`) that called `apiFetch(POST /auth/refresh)` **directly**.
`/auth/refresh` is a `BYPASS_PATH`, so that call never went through the coordinated
`attemptRefresh` path and thus had **no cross-tab coordination** (no Web Lock, no recent-ts
skip) — unlike the reactive 401 interceptor, which is coordinated.

When N tabs are opened near-simultaneously, each arms its own 13-min interval at roughly the
same instant. ~13 min later they all fire near-simultaneously and each `POST /auth/refresh`
with the **same** `refresh_token` cookie (the first tab's rotation `R1→R2` hasn't landed in the
shared cookie jar yet). Server-side (`api/src/Identity/Application/RotateRefreshToken.php:30-39`):
the first request rotates `R1→R2`; the others arrive with the now-revoked `R1` → reuse detection
→ `revokeAllForUser()` → the whole session is killed. No grace window (by design, story 13.3).

### Fix

Route the proactive refresh through the **same** coordinated path as the reactive one.
`attemptRefresh` was renamed to **`coordinatedRefresh`** and exported from `apiFetch.ts`; the
`AuthProvider` interval now calls `void coordinatedRefresh()` instead of a direct
`POST /auth/refresh`. The in-tab queue + cross-tab Web Lock (`navigator.locks`) + the
`archilan_refresh_ts` recent-ts skip (5 s window) then guarantee exactly **one** network refresh
wins across all tabs; the others no-op. No server-side change; reuse detection stays strict.

### Residual limitation

The cross-tab guard relies on the Web Locks API. In the rare fallback where `navigator.locks`
is unavailable (very old browsers / non-secure contexts), `doRefreshUnderLock` runs without a
lock and simultaneous tabs can still race. Acceptable: Web Locks is supported in all current
browsers and on `localhost`/HTTPS.

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 (original) · claude-opus-4-8 (2026-06-08 multi-tab bugfix)

### Debug Log References

None.

### Completion Notes List

- Created `src/lib/apiFetch.ts` as the single centralized API client for the entire frontend (30 files migrated from raw `fetch`).
- Queue pattern (`isRefreshing` flag + `refreshQueue` array) ensures exactly one `POST /auth/refresh` is made regardless of how many concurrent 401s arrive simultaneously.
- `wasEverAuthenticated` ref in `AuthProvider` prevents non-authenticated users (e.g., visiting a public page) from being redirected to `/connexion` when the profile fetch returns 401 and the subsequent refresh also fails.
- Bypass paths `/auth/refresh` and `/auth/login` prevent infinite loops and false session-restores on invalid credentials.
- Pre-existing lint issues exist in the codebase (`react/no-unescaped-entities` in French JSX strings, `setState-in-effect` in `twitch-mini-player.tsx`). Zero new lint errors introduced by this story.
- Fixed a pre-existing TypeScript bug in `admin-registration-dashboard.tsx:440`: `key={g.id}` → `key={g.gameId}` (type `SelectedGame` has `gameId`, not `id`).
- Query param used: `?returnTo=` (consistent with existing codebase convention in `admin-shell.tsx` and `login-form.tsx`), not `?next=` as written in the epic spec.

### Validation Results

- `pnpm typecheck`: 0 errors
- `pnpm lint`: 0 new errors introduced (pre-existing issues in `twitch-mini-player.tsx` and other files)

### File List

- `frontend/src/lib/apiFetch.ts` (new)
- `frontend/src/features/auth/auth-context.tsx` (modified - added `wasEverAuthenticated` ref, `registerUnauthenticatedHandler`, `useRouter`, migrated profile fetch to `apiFetch`)
- `frontend/src/components/admin-shell.tsx` (modified)
- `frontend/src/components/public-shell.tsx` (modified)
- `frontend/src/features/admin/admin-event-dashboard.tsx` (modified)
- `frontend/src/features/admin/admin-event-edit-page.tsx` (modified)
- `frontend/src/features/admin/admin-event-create-page.tsx` (modified)
- `frontend/src/features/admin/admin-event-game-selection-page.tsx` (modified)
- `frontend/src/features/admin/admin-game-editor.tsx` (modified)
- `frontend/src/features/admin/admin-game-library-dashboard.tsx` (modified)
- `frontend/src/features/admin/admin-user-directory.tsx` (modified)
- `frontend/src/features/admin/admin-content-dashboard.tsx` (modified)
- `frontend/src/features/admin/admin-post-form.tsx` (modified)
- `frontend/src/features/admin/admin-registration-detail.tsx` (modified)
- `frontend/src/features/admin/admin-session-page.tsx` (modified)
- `frontend/src/features/admin/admin-registration-dashboard.tsx` (modified - bug fix `g.id` → `g.gameId`)
- `frontend/src/features/auth/account-profile.tsx` (modified)
- `frontend/src/features/auth/account-registrations.tsx` (modified)
- `frontend/src/features/auth/login-form.tsx` (modified)
- `frontend/src/features/auth/signup-form.tsx` (modified)
- `frontend/src/features/events/event-registration-cta.tsx` (modified)
- `frontend/src/features/events/game-selection-gate.tsx` (modified)
- `frontend/src/features/events/live-seat-counter.tsx` (modified)
- `frontend/src/features/events/registration-eligibility-gate.tsx` (modified)
- `frontend/src/features/events/registration-recap-gate.tsx` (modified)
- `frontend/src/features/events/session-connection-gate.tsx` (modified)
- `frontend/src/features/events/slot-yaml-gate.tsx` (modified)
- `frontend/src/features/streaming/twitch-status-context.tsx` (modified)
- `frontend/src/features/content/public-posts-api.ts` (modified)
- `frontend/src/features/events/public-events-api.ts` (modified)
- `frontend/src/features/payments/shop-api.ts` (modified)
- `frontend/src/features/payments/membership-api.ts` (modified)
- `frontend/src/app/(admin)/admin/jeux/nouveau/page.tsx` (modified)

### Change Log

- **2026-05-05**: Story implemented in full. Created centralized `apiFetch` utility with silent 401 refresh interceptor and queue pattern. Migrated all 30 fetch-using files. TypeScript clean, no new lint errors.
- **2026-06-08**: Multi-tab bugfix (see Addendum). The proactive `AuthProvider` interval bypassed the cross-tab coordination and raced on refresh-token rotation, tripping server reuse detection and logging out all tabs ~13 min after opening several tabs near-simultaneously. Exported `attemptRefresh` as `coordinatedRefresh` from `apiFetch.ts` and routed the proactive interval through it. Files: `frontend/src/lib/apiFetch.ts`, `frontend/src/features/auth/auth-context.tsx`. Gates green (typecheck, lint, build).
