# Story 3.9: Admin Backoffice Shell and Dashboard

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin,
I want a dedicated backoffice shell with persistent sidebar navigation and a dashboard home page,
so that I can reach every admin section quickly and operate under event-day pressure without navigating through the public site.

## Acceptance Criteria

1. Given an admin opens any `/admin/*` route, they see a dedicated admin shell - no public navbar, no Twitch mini-player, no public footer, no cookie consent banner.
2. The admin shell sidebar (desktop: 240px fixed left, tablet: 48px icon-only with tooltip labels, mobile: bottom tab bar - 5 tabs max) shows links to: Événements, Actualités, Jeux, Utilisateurs, with active-route highlighting (`bg-surface-2 text-foreground border-l-2 border-accent` on desktop/tablet; active tab accent on mobile).
3. The sidebar header includes the ArchiLAN logo, the label "Administration", a "← Site public" link back to `/`, and a logout button that calls `POST /api/v1/auth/logout`, clears auth state via `setUser(null)`, and redirects to `/connexion`.
4. The `/admin` route renders a dashboard home page (not a redirect) showing a welcome heading and a tile grid with one tile per section (Événements, Actualités, Jeux, Utilisateurs) - each tile has an icon, section name, one-line description, and navigates to the section on click.
5. The dashboard tiles include at-a-glance stat counters (published event count, total confirmed registrations, game library count) fetched from the API. Loading state uses skeleton placeholders; errors show a neutral fallback (counter hidden, not broken page).
6. Unauthenticated users accessing any `/admin/*` route are redirected to `/connexion?returnTo=/admin` (or the specific path they attempted).
7. Authenticated non-admin users see a clear "Accès réservé aux admins" message with a link to return to the public site - not a broken page or 404.
8. Sections not yet implemented (Actualités, Jeux, Utilisateurs) show a placeholder page ("Bientôt disponible") instead of a 404.
9. On mobile, the bottom tab bar is always visible at the bottom of the screen (position: fixed); the content area scrolls above it with appropriate bottom padding.
10. Keyboard navigation: all sidebar/tab nav items are focusable, active route is announced to screen readers (`aria-current="page"`).

## Tasks / Subtasks

- [x] **Route group restructure** - separate admin and public shells (AC: 1)
  - [x] Create `frontend/src/app/(public)/layout.tsx` wrapping children in `<PublicShell>` - identical to current `app/layout.tsx` inner content.
  - [x] Move all existing public routes into `app/(public)/`: `page.tsx`, `evenements/`, `actualites/`, `compte/`, `connexion/`, `inscription/`, `confidentialite/`, `mentions-legales/`, `cgu/`, `cgv/`, `not-found.tsx` (if applicable).
  - [x] Update `app/layout.tsx` to be a bare HTML shell (`<html><body>{children}</body></html>`) with only `globals.css` import - no `PublicShell`.
  - [x] Create `frontend/src/app/(admin)/layout.tsx` wrapping children in `<AdminShell>` (to be created).
  - [x] Move `app/admin/` into `app/(admin)/admin/` preserving all nested routes.
  - [x] Verify all existing routes still resolve (Next.js route groups are transparent to the URL).

- [x] **`AdminShell` component** - `frontend/src/components/admin-shell.tsx` (AC: 1, 2, 3, 6, 7, 9, 10)
  - [x] Client component (`"use client"`). Import `useAuth` from `@/features/auth/auth-context`, `usePathname`, `useRouter` from `next/navigation`.
  - [x] Auth guard: if `authLoading` → show full-page skeleton; if `!user` → `router.push('/connexion?returnTo=' + pathname)`; if `!user.roles.includes('ROLE_ADMIN')` → render access-denied view.
  - [x] Desktop sidebar (≥1024px): fixed left, 240px wide, `bg-surface border-r border-border h-screen`. Contains: logo block, nav links with labels, spacer, logout button.
  - [x] Tablet sidebar (768–1023px): fixed left, 48px wide, icon-only. Each icon wrapped in a `title` tooltip (native HTML) or Radix `Tooltip`. No labels visible.
  - [x] Mobile bottom tab bar (<768px): fixed bottom, full-width, flex row of 5 tabs with icon + short label. `bg-surface border-t border-border`. Content area has `pb-16` to avoid overlap.
  - [x] Use Tailwind responsive prefixes (`md:`, `lg:`) to toggle between layouts - single component, no separate files.
  - [x] Nav items array: `{ href, icon, label, shortLabel }` - `shortLabel` used in mobile tab bar (≤8 chars). Icons from `lucide-react`: Calendar (Événements), Newspaper (Actualités), Gamepad2 (Jeux), Users (Utilisateurs).
  - [x] Active route: use `usePathname()`, match with `pathname.startsWith(href)`. Apply `bg-surface-2 border-l-2 border-accent text-foreground` on desktop; accent icon color on mobile.
  - [x] "← Site public" link: `href="/"`, ghost style, at bottom of desktop sidebar above logout.
  - [x] Logout button: `onClick` → `fetch POST /api/v1/auth/logout` (credentials: include, catch errors silently) → `setUser(null)` → `router.push('/connexion')`. Use `useAuth()` for `setUser`.
  - [x] Wrap children in a layout div: sidebar + `<main>` content area (`flex-1 overflow-auto`).

- [x] **Dashboard home page** - `frontend/src/app/(admin)/admin/page.tsx` (AC: 4, 5)
  - [x] Remove existing `redirect("/admin/evenements")`.
  - [x] Client component. Render welcome heading: "Bonjour, [user.displayName || user.email]" + subline "Que veux-tu faire aujourd'hui ?".
  - [x] Section tile grid: 2-up on tablet, 4-up on desktop. Each tile: icon (large, accent color), section name, one-line description, full tile is a `<Link>` to section href.
  - [x] Tile descriptions: Événements → "Créer, publier et gérer les événements ArchiLAN."; Actualités → "Rédiger et publier les articles et récaps."; Jeux → "Gérer la bibliothèque de jeux Archipelago."; Utilisateurs → "Consulter et gérer les comptes membres.".
  - [x] Stats counters: fetch `GET /api/v1/admin/events` (reuse existing), count events where `status !== 'draft'` for published count. Fetch `GET /api/v1/admin/dashboard-stats` for `totalRegistrations` and `gameCount` (new endpoint below). Show skeleton during load; hide counter value (show `-`) on error.
  - [x] Tiles for unimplemented sections (Actualités, Jeux, Utilisateurs) are visually present but optionally dimmed with a "Bientôt disponible" badge.

- [x] **Placeholder pages** for not-yet-implemented sections (AC: 8)
  - [x] `frontend/src/app/(admin)/admin/actualites/page.tsx` - "Bientôt disponible" page with back link.
  - [x] `frontend/src/app/(admin)/admin/jeux/page.tsx` - already exists with AdminGameLibraryDashboard, preserved.
  - [x] `frontend/src/app/(admin)/admin/utilisateurs/page.tsx` - already exists with AdminUserDirectory, preserved.

- [x] **Backend: `GET /api/v1/admin/dashboard-stats`** (AC: 5)
  - [x] New endpoint returning `{ data: { publishedEvents: int, totalConfirmedRegistrations: int, gameCount: int } }`.
  - [x] Admin-only (`ROLE_ADMIN`), credentials required.
  - [x] `publishedEvents`: count events where status in (`published`, `in-progress`, `completed`).
  - [x] `totalConfirmedRegistrations`: count all registrations where status = `reserved` (active, non-cancelled).
  - [x] `gameCount`: count games in library (ArchipelagoGame entity exists and used).
  - [x] Application service in `api/src/Events/Application/AdminDashboardStats.php`. Controller method in existing `AdminEventController`.
  - [x] Add to `RbacEnforcementTest`: anonymous and lambda access must return 401/403.

- [x] **Backend tests** (AC: 6, 7 - enforced server-side by existing RBAC)
  - [x] `AdminDashboardStatsTest`: admin gets valid stats shape; anonymous/lambda blocked.

- [x] **Validate and handoff**
  - [x] Run `composer test` (backend) - 6/6 new tests pass; 4 pre-existing failures in AdminPaymentStatusTest unrelated to this story.
  - [x] Run `composer phpstan` - 0 errors on new files (244 pre-existing errors in other files).
  - [x] Run `composer cs-fixer` dry-run - no changes needed on new files.
  - [x] Run `pnpm lint` - 4 pre-existing errors in twitch-mini-player.tsx, none in new files.
  - [x] Run `pnpm typecheck` - ✓ no errors.
  - [x] Run `pnpm build` - ✓ all 28 routes compile correctly.

## Dev Notes

### Critical: Route Group Restructure (Biggest Risk)

The root layout `frontend/src/app/layout.tsx` currently wraps **all** pages in `PublicShell`. Admin pages must not render inside `PublicShell` (public navbar, Twitch mini-player, TwitchPlayerProvider, TwitchStatusProvider, CookieConsentBanner).

**Required approach - Next.js Route Groups:**

```
frontend/src/app/
├── layout.tsx              ← bare HTML shell only (html, body, globals.css)
├── (public)/
│   ├── layout.tsx          ← wraps in <PublicShell>
│   ├── page.tsx            ← home "/"
│   ├── evenements/
│   ├── actualites/
│   ├── compte/
│   ├── connexion/
│   ├── inscription/
│   ├── confidentialite/
│   ├── mentions-legales/
│   ├── cgu/
│   └── cgv/
└── (admin)/
    ├── layout.tsx          ← wraps in <AdminShell>
    └── admin/
        ├── page.tsx        ← dashboard home
        └── evenements/
            ├── page.tsx
            └── [eventId]/
                └── inscriptions/
                    └── page.tsx
```

Route groups (parenthesized folders) are invisible to the URL - `/admin/evenements` still works. All existing URLs are preserved.

**Root `layout.tsx` after restructure:**
```tsx
import "./globals.css";
export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fr" className="h-full antialiased">
      <body className="min-h-full">{children}</body>
    </html>
  );
}
```

The `export const metadata` from root layout must also move to `(public)/layout.tsx` (or stay in root - Next.js merges metadata from all layouts in the tree).

### AdminShell Auth Guard Pattern

Use the existing `useAuth()` from `@/features/auth/auth-context`. The pattern already established in `account-registrations.tsx` and `registration-eligibility-gate.tsx`:

```tsx
const { user, loading: authLoading } = useAuth();
const pathname = usePathname();
const router = useRouter();

useEffect(() => {
  if (authLoading) return;
  if (!user) {
    router.push(`/connexion?returnTo=${encodeURIComponent(pathname)}`);
  }
}, [authLoading, user, router, pathname]);

if (authLoading) return <AdminShellSkeleton />;
if (!user) return null; // redirect in progress
if (!user.roles.includes('ROLE_ADMIN')) return <AdminAccessDenied />;
```

`AuthProvider` must be present in the `(admin)/layout.tsx` wrapping `AdminShell`. Check whether `AdminShell` needs its own `AuthProvider` or whether it can receive auth from a parent. Since `(admin)/layout.tsx` is outside `(public)/layout.tsx`, it does NOT have `PublicShell`'s `AuthProvider`. **Solution:** Wrap `AdminShell` with `AuthProvider` directly in `(admin)/layout.tsx`, or include `AuthProvider` inside `AdminShell` itself.

### UX-DR26 Sidebar Specification (from UX Design Spec)

From the UX spec (section "Navigation Patterns"):
- **Desktop (≥1024px):** Fixed left sidebar, 240px. Active: `bg-surface-2 text-foreground border-l-2 border-accent`.
- **Tablet (768–1023px):** Icon-only, 48px. Tooltip labels on hover.
- **Mobile (<768px):** Bottom tab bar (5 tabs max) - NOT a sidebar. This is the spec's explicit requirement. Use `fixed bottom-0` with `bg-surface border-t border-border`.

Backoffice max-width: **1440px** (not 1280px like public). Content area padding: 16–24px between elements, 32px section padding.

### Stats Counter - Data Sourcing Without Over-Fetching

For the dashboard stats:
- **Published events:** Reuse the existing `GET /api/v1/admin/events` response (already fetched for Événements page). But on the dashboard home, fetch independently since we don't have events data yet. Count client-side: `events.filter(e => e.status !== 'draft').length`.
- **Total registrations + game count:** New `GET /api/v1/admin/dashboard-stats` endpoint. Keep it lightweight - no pagination, just aggregate counts.

If `GET /api/v1/admin/dashboard-stats` is not yet implemented, the frontend should handle a 404 gracefully (show `-` counters) to allow frontend-first development.

### Existing Files That Must NOT Break

The route group restructure touches nearly every file under `src/app/`. Verify these patterns still work:
- `app/admin/evenements/[eventId]/inscriptions/` route - must move to `(admin)/admin/evenements/[eventId]/inscriptions/`.
- `app/evenements/[eventSlug]/inscription/[registrationId]/jeux/` - must move to `(public)/evenements/...`.
- `not-found.tsx` global fallback - stays at root `app/not-found.tsx` (not inside route groups) for the global 404.
- `middleware.ts` (if it exists) - check for path matching logic that might need updating.

### Existing Admin Dashboard

`frontend/src/features/admin/admin-event-dashboard.tsx` is untouched by this story - it's a feature component, not a page. The page that uses it (`app/admin/evenements/page.tsx`) moves to `(admin)/admin/evenements/page.tsx` with no content changes.

### API Endpoint Reference

Existing admin endpoints (for reference, do not modify):
- `GET /api/v1/admin/events` - returns `{ data: AdminEvent[] }` with fields: `id, title, type, status, startsAt, endsAt, venue, capacity, confirmedRegistrations, isAtCapacity, registrationOpensAt, registrationClosesAt, isPublic, visibility, hasPrivateAccessPassword, gameSelectionEnabled, vodUrl, recapPostSlug, hasRecap, createdAt, updatedAt`
- `POST /api/v1/admin/events` - create draft
- `PATCH /api/v1/admin/events/{id}` - update
- `PATCH /api/v1/admin/events/{id}/status` - lifecycle transition
- `PATCH /api/v1/admin/events/{id}/private-access` - password config
- `PATCH /api/v1/admin/events/{id}/game-selection` - game selection config
- `PATCH /api/v1/admin/events/{id}/recap` - recap attachment
- `POST /api/v1/auth/logout` - logout

New endpoint for this story:
- `GET /api/v1/admin/dashboard-stats` → `{ data: { publishedEvents: int, totalConfirmedRegistrations: int, gameCount: int } }`

### Design Tokens in Use

From `globals.css` (all are Tailwind-resolvable after Story 3.9's globals.css fix):
- `bg-surface` = `#0f1e38` (sidebar background)
- `bg-surface-2` = `#162440` (active nav item background)
- `bg-background` = `#0a1629` (main content area)
- `border-border` = `#1e2f4f`
- `text-accent` = `#3730a3` (active state accent)

### Project Structure Notes

- All frontend admin feature components stay in `frontend/src/features/admin/` - no changes.
- New `AdminShell` goes in `frontend/src/components/admin-shell.tsx` (shared component, not feature-specific).
- New dashboard home page: `frontend/src/app/(admin)/admin/page.tsx`.
- New admin layout: `frontend/src/app/(admin)/layout.tsx`.
- New public layout: `frontend/src/app/(public)/layout.tsx`.
- Metadata export (`export const metadata`) for the site-wide defaults should stay in the root `app/layout.tsx` - Next.js applies root layout metadata to all routes regardless of route groups.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#UX-DR26] - Backoffice navigation spec (sidebar 240px desktop, 48px tablet, bottom tab bar mobile)
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Navigation-Patterns] - Active state tokens, backoffice max-width 1440px
- [Source: _bmad-output/planning-artifacts/architecture.md#Frontend-Architecture] - App Router, shadcn/ui, TanStack Query, `useAuth` pattern
- [Source: _bmad-output/implementation-artifacts/3-8-attach-recap-or-vod-to-completed-event.md] - Established patterns: API fetch, `useAuth`, `ROLE_ADMIN`, Tailwind tokens
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-3-Story-3.9] - Story requirements

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

Route group restructure completed using bash+PowerShell mv (evenements/actualites/admin required PowerShell Move-Item due to git-bash permission issue on Windows). All 28 routes compile. Pre-existing test failures (AdminPaymentStatusTest - missing events_private_access_logs table) and lint errors (twitch-mini-player.tsx) are unrelated to this story.

Registration "confirmed" mapped to `STATUS_RESERVED` (not cancelled) - the Registration entity has no "confirmed" status.

### File List

**Frontend:**
- `frontend/src/app/layout.tsx` - stripped to bare HTML shell (removed PublicShell)
- `frontend/src/app/(public)/layout.tsx` - new, wraps public routes in PublicShell
- `frontend/src/app/(admin)/layout.tsx` - new, wraps admin routes in AdminShell
- `frontend/src/components/admin-shell.tsx` - new, full AdminShell component
- `frontend/src/app/(admin)/admin/page.tsx` - replaced redirect with dashboard
- `frontend/src/app/(admin)/admin/actualites/page.tsx` - new placeholder page
- All other files moved into route groups via `mv` (no content changes):
  - `(public)/`: page.tsx, evenements/, actualites/, adhesion/, boutique/, compte/, connexion/, inscription/, confidentialite/, mentions-legales/, cgu/, cgv/
  - `(admin)/admin/`: layout.tsx, page.tsx, evenements/, jeux/, utilisateurs/, contenu/, not-found.tsx

**Backend:**
- `api/src/Events/Application/AdminDashboardStats.php` - new service
- `api/src/Events/Presentation/AdminEventController.php` - added dashboardStats() route + AdminDashboardStats dependency
- `api/tests/Functional/AdminDashboardStatsTest.php` - new test (6 tests, 37 assertions)
- `api/tests/Functional/RbacEnforcementTest.php` - added /api/v1/admin/dashboard-stats to adminRequests()
