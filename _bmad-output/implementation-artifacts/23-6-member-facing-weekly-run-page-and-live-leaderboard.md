# Story 23.6: Member-Facing Weekly Run Page & Live Leaderboard

## Story

**As a** member,
**I want** a weekly runs page where I can opt in, launch my individual run on demand, and watch the leaderboard update in real time,
**So that** I can participate in the week's Archipelago challenge and compare my performance with other members.

## Status

done

## Acceptance Criteria

**AC1:** `/runs-hebdo` is a Next.js page under `app/(public)/runs-hebdo/`. Auth is enforced client-side in `WeeklyRunsClientPage` via `useAuth()` from `src/features/auth/auth-context.tsx`: if `!loading && !user`, redirect to `/connexion?returnTo=/runs-hebdo`. While `loading === true`, render a spinner. Only `ROLE_MEMBER` or above may proceed past the check.

**AC2:** The page fetches `GET /api/v1/weekly-runs/current` and renders one card per active run. Each card shows: game name, optional template name, week number (`Semaine {N}`), countdown to Sunday 23:59 UTC, and the leaderboard panel.

**AC3:** A member who has not yet opted in sees an "Opt-in" button. On click → `POST /api/v1/weekly-runs/{weeklyRunId}/entries` → on `201` the button is replaced by a "Lancer ma partie" button. On `422 max_attempts_reached` a toast is shown. The opt-in state persists across page reloads (inferred from the `participants` list in the leaderboard response).

**AC4:** A member with a confirmed entry but no active session sees the "Lancer ma partie" button. On click → `POST /api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/launch` → the backend generates the player's individual Archipelago session and returns `{ data: { entryId, externalSessionId, connectionInfo: { host, port, password } } }`. The page shows a connection panel with copy-to-clipboard for host/port/password.

**AC5:** A member with an already-launched session sees the connection panel directly (connection info retrieved from the run state in the API response).

**AC6:** Three leaderboard tabs: "Meilleur temps" (sorted by `completionTimeSeconds ASC`), "Moins de checks" (sorted by `checksTotal ASC`), "Moins d'items" (sorted by `itemsTotal ASC`). Only entries with `goalReachedAt` non-null appear on leaderboards. A "Participants en cours" section shows all entries with name and `goalReachedAt` status.

**AC7:** A Mercure EventSource subscribes to `weekly-runs/{weeklyRunId}/leaderboard` and updates the leaderboard in real time when a goal event arrives. The EventSource listener is cleaned up in the `useEffect` return function. The member's own entry is highlighted in each tab.

**AC8:** When no run is active this week, the page shows "Aucun run cette semaine - revenez lundi !". All three frontend quality gates pass.

## Tasks / Subtasks

- [ ] Task 1: Create `frontend/src/features/weekly-runs/weekly-runs-api.ts` with fetch functions and type guards
- [ ] Task 2: Create `WeeklyRunCard` component (status, countdown, opt-in/launch/connection UI)
- [ ] Task 3: Create `WeeklyRunLeaderboard` component (three tabs + participants section)
- [ ] Task 4: Create `app/(public)/runs-hebdo/page.tsx` thin server component (renders `<WeeklyRunsClientPage />` only - no auth check or data fetching here)
- [ ] Task 5: Create `WeeklyRunsClientPage` client component (auth guard via `useAuth()`, TanStack Query + Mercure)
- [ ] Task 6: Add "Runs hebdo" link to `frontend/src/components/public-shell.tsx` in `AuthNavDesktop` and `AuthNavMobile` (inside the `if (user)` branch, visible to authenticated users only)
- [ ] Task 8: Run all three frontend quality gates

## Dev Notes

### API types

```ts
export type WeeklyRunLeaderboardEntry = {
  entryId: string;
  userId: string;
  displayName: string;
  completionTimeSeconds: number | null;
  checksTotal: number | null;
  itemsTotal: number | null;
  goalReachedAt: string | null;
};

export type WeeklyRunParticipant = {
  entryId: string;
  userId: string;
  displayName: string;
  attemptNumber: number;
  goalReachedAt: string | null;
  connectionInfo: { host: string; port: number; password?: string } | null;
};

export type CurrentWeeklyRun = {
  weeklyRunId: string;
  templateName: string | null;
  gameName: string;
  weekNumber: number;
  weekYear: number;
  status: 'active' | 'finished';
  startedAt: string | null;
  finishedAt: string | null;
  leaderboard: {
    fastest: WeeklyRunLeaderboardEntry[];
    fewestChecks: WeeklyRunLeaderboardEntry[];
    fewestItems: WeeklyRunLeaderboardEntry[];
  };
  participants: WeeklyRunParticipant[];
  myEntry: WeeklyRunParticipant | null; // null if not opted in
};
```

### Auth pattern

There is no `middleware.ts` in this project. Auth is client-side via `AuthContext`. `compte/page.tsx` (the reference page for member-only content) does NOT do server-side auth - it delegates entirely to client components. Follow the same pattern for `/runs-hebdo`:

```tsx
// WeeklyRunsClientPage.tsx
const { user, loading } = useAuth();
const router = useRouter();

const isMember = user?.roles.includes('ROLE_MEMBER') || user?.roles.includes('ROLE_ADMIN');

useEffect(() => {
  if (!loading && !user) {
    router.push(`/connexion?returnTo=${encodeURIComponent('/runs-hebdo')}`);
  }
}, [loading, user, router]);

if (loading) return <LoadingSpinner />;
if (!user) return null; // redirect in flight
if (!isMember) return <p>Accès réservé aux membres ArchiLAN.</p>;
```

`useAuth` is exported from `src/features/auth/auth-context.tsx`. The `AuthContext` auto-redirects on 401 from any `apiFetch` call only when the user was previously authenticated; the explicit `useEffect` above handles first-visit protection. `ROLE_ADMIN` implies membership (same pattern as `AuthNavDesktop` in `public-shell.tsx` which checks `user.roles.includes("ROLE_ADMIN")`).

### Data fetching pattern

`app/(public)/runs-hebdo/page.tsx` is a thin Server Component that renders `<WeeklyRunsClientPage />` (no data fetching here - auth is client-side). `WeeklyRunsClientPage` handles auth check (see above), then uses TanStack Query:

```ts
const { data: runs } = useQuery({
  queryKey: ['weekly-runs', 'current'],
  queryFn: fetchCurrentWeeklyRuns,
  staleTime: 30_000,
  refetchInterval: 60_000, // fallback polling in case Mercure misses an event
});
```

### Countdown to Sunday 23:59 UTC

The countdown is computed client-side from the current time, not from a server value. This is allowed (countdown to a known fixed time) - it's not a random/impure value read during render. Use a `useEffect` with `setInterval` to update a `timeLeft` state every second:

```ts
const [timeLeft, setTimeLeft] = useState(() => computeTimeLeft()); // initialised outside render

useEffect(() => {
  const id = setInterval(() => setTimeLeft(computeTimeLeft()), 1000);
  return () => clearInterval(id);
}, []);
```

`computeTimeLeft()` is defined outside the component (pure function of `Date.now()`) to satisfy AC-HK3.

### Mercure EventSource

The `weekly-runs/{weeklyRunId}/leaderboard` topic is **public** (no authentication needed on Mercure - the goal event contains no private data). No token endpoint is required; `withCredentials: false` is sufficient.

```ts
useEffect(() => {
  const url = new URL(env.mercurePublicUrl); // from src/lib/env.ts - mercurePublicUrl, NOT mercureHubUrl
  url.searchParams.append('topic', `weekly-runs/${weeklyRunId}/leaderboard`);
  const es = new EventSource(url.toString()); // public topic - no credentials needed

  es.onmessage = (event) => {
    const data: unknown = JSON.parse(event.data);
    if (isGoalReachedEvent(data)) {
      queryClient.invalidateQueries({ queryKey: ['weekly-runs', 'current'] });
    }
  };

  return () => es.close(); // cleanup
}, [weeklyRunId, queryClient]);
```

### Opt-in + launch flow

The `WeeklyRunCard` component maintains local state for the current member's entry status (derived from `run.myEntry`):

```
null → opt-in button
{ entryId, connectionInfo: null } → "Lancer ma partie" button
{ entryId, connectionInfo: { host, port, password } } → connection panel
```

Each state transition triggers a query invalidation after the API call succeeds, causing the card to re-render with the updated state from the server.

### Connection panel

After a successful launch, show:
```
Serveur Archipelago
Host:     archipelago.archilan.fr
Port:     12345     [copier]
Password: abc123    [copier] (if applicable)
```

Use the browser `navigator.clipboard.writeText()` API for copy-to-clipboard. The copy button should be in an event handler (not during render, AC-HK3).

### Leaderboard tabs

Use the existing tab/card UI pattern from the codebase (check other multi-tab components like `account-tabs.tsx`). Each tab shows a ranked list with:
- Rank number (1, 2, 3...)
- Member displayName (bolded if it's the current user - compare with `myEntry.userId`)
- Metric value: time formatted as `HH:mm:ss`, or integer (checks/items)

Format completion time:
```ts
function formatTime(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
```

### Member navigation

Add a "Runs hebdo" `<Link href="/runs-hebdo">` in `frontend/src/components/public-shell.tsx`, inside both `AuthNavDesktop` and `AuthNavMobile`, in the `if (user)` branch - alongside the existing "Mon espace" link. Follow the same Tailwind classes as "Mon espace".

## File List

- `frontend/src/features/weekly-runs/weekly-runs-api.ts` - new
- `frontend/src/features/weekly-runs/weekly-run-card.tsx` - new
- `frontend/src/features/weekly-runs/weekly-run-leaderboard.tsx` - new
- `frontend/src/features/weekly-runs/weekly-runs-client-page.tsx` - new
- `frontend/src/app/(public)/runs-hebdo/page.tsx` - new (thin Server Component, renders WeeklyRunsClientPage)
- `frontend/src/components/public-shell.tsx` - modified (add "Runs hebdo" link in AuthNavDesktop + AuthNavMobile)

## Change Log

| Date       | Change                                                                                              |
|------------|-----------------------------------------------------------------------------------------------------|
| 2026-05-17 | Story created                                                                                       |
| 2026-05-17 | Revised: route `(member)/weekly-runs` → `(public)/runs-hebdo`, auth in Server Component (no middleware). AC4 `connectionUrl` → `connectionInfo: { host, port, password }`. Removed middleware task. File list corrected. |
| 2026-05-17 | Revised: auth pattern corrected - useAuth() hook in WeeklyRunsClientPage (same as AccountTabs pattern; compte/page.tsx does NO server auth). env.mercureHubUrl → env.mercurePublicUrl. Mercure topic declared public. Data fetching simplified (no initialData from Server Component). |
| 2026-05-17 | Revised: Task 4 corrected to thin Server Component (no auth, no data). Auth guard includes ROLE_MEMBER check + ROLE_ADMIN fallback. Nav file named explicitly: public-shell.tsx AuthNavDesktop/AuthNavMobile. |
