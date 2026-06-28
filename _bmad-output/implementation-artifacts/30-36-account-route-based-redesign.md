# Story 30.36: Route-based account space ("Mon espace") with an overview dashboard

**Status:** review
**Epic:** 30 - Community & account
**Date:** 2026-06-28

## Story

As a member,
I want each section of "Mon espace" to be its own real page under `/compte/...`, navigated from a settings
sidebar, with `/compte` itself showing an at-a-glance overview,
so that sections are deep-linkable and reload-safe natively, load fast (SSR + code-split per section),
and the navigation reads like a proper account area instead of one monolithic tab component.

## Context

Today `/compte` renders a single client component (`AccountTabs`) that fetches the profile up front and
conditionally renders **all 8 sections**' code, with a two-level tab nav. Story 30.35 added a `?tab=`
query + `history.replaceState` to persist the active tab - a workaround for the lack of real routes.

This story replaces that model with **route-based sections + a shared layout** (the GitHub/Discord/Stripe
"settings" pattern) and an **overview** landing. It supersedes the `?tab=` mechanism (30.35) with native
URLs.

### Section → component mapping (today, to preserve)

| Section | Route | Component |
|---|---|---|
| Profil | `/compte/profil` | `CommunityProfileCustomizationForm` |
| Amis | `/compte/amis` | `CommunityFriendsPanel` |
| Activité | `/compte/activite` | `CommunityFeedPanel` |
| Inscriptions | `/compte/inscriptions` | `AccountRegistrations` |
| Mes parties | `/compte/parties` | `PersonalRunsListPage` (embedded) |
| Adhésion | `/compte/adhesion` | `MembershipSection` |
| Confidentialité | `/compte/confidentialite` | `PrivacySection` |
| Connexions & sécurité | `/compte/securite` | `DiscordSection` + `SteamSection` + `DangerSection` |

Shared chrome (today in `AccountTabs`): the user header (avatar/name/email/role from `/account/profile`
+ community profile) and the `EmailVerificationBanner`.

## Acceptance Criteria

1. **Routing.** Each section is a real route under `app/(public)/compte/` (`profil`, `amis`, `activite`,
   `inscriptions`, `parties`, `adhesion`, `confidentialite`, `securite`). `/compte` renders the overview.
2. **Shared layout.** `compte/layout.tsx` wraps all of them: `RequireAuth`, the user header + email-verif
   banner, and the navigation (sidebar). The active item is derived from the pathname (no client tab
   state, no `?tab=`).
3. **Navigation UI.** Desktop: left sidebar grouped (Communauté / Jeux / Compte), each item a `Link`,
   active state from pathname; "Connexions & sécurité" keeps its danger accent. Mobile: a dropdown/menu
   at the top (no horizontal scroll). Sidebar sticky on desktop when content is long.
3b. (Polish, from 30.35 discussion) optional counters/badges on items (e.g. Inscriptions count, pending
   friend requests) - include if cheap, else note as follow-up.
4. **Overview (`/compte`).** A dashboard with: membership status + expiry, registrations count, friends /
   pending requests, recent activity snippet, and quick links into each section. Reuses existing
   queries; no new heavy aggregation endpoint unless justified.
5. **SSR + data per section.** Each page is a server component where possible, fetching only its own data
   (code-split), instead of the single up-front fetch. Section components stay where they are; pages just
   compose them.
6. **Parity & redirects.** Every current section is reachable and behaves as before. The Discord OAuth
   callback lands on `/compte/securite` (update the callback redirect target and/or redirect
   `?discord_linked` from `/compte` to `/compte/securite`). Back/forward and reload work natively.
7. **Cleanup.** `AccountTabs` (monolith) and the `?tab=` / `replaceState` logic (30.35) are removed;
   any inbound `/compte?tab=<x>` is redirected to the matching route (back-compat for old links).
8. Gates green: frontend `typecheck` / `lint` / `build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2). `compte/layout.tsx` (RequireAuth + header) + `AccountShell` (client: profile
  fetch once, header/avatar/role, email-verif banner, nav + `{children}`).
- [x] **Task 2** (AC 1,5). The 8 section route pages composing the existing section components (securite via
  a self-contained `AccountSecuritySection` that reads the Discord callback params).
- [x] **Task 3** (AC 3,3b). `AccountNav`: grouped sidebar (active-from-pathname, danger accent, badges for
  Inscriptions/Amis) + mobile `<select>` that navigates.
- [x] **Task 4** (AC 4). `AccountOverview` at `/compte`: Adhésion (live status + expiry), Inscriptions
  (count), Amis (count + pending), Activité - cards linking to each section.
- [x] **Task 5** (AC 6,7). Discord callback → `/compte/securite`; `/compte?tab=<x>` and old
  `?discord_linked` redirected from the overview page; `AccountTabs` + the `?tab=` code deleted.
- [x] **Task 6** (AC 8). typecheck / lint / build green; verified live (overview, sections, sidebar active,
  legacy redirect, titles).

## Dev Notes

- The section components are mostly `"use client"`; server route pages can render them directly. Where a
  section needs server data, fetch in the page and pass as props (or keep its current client fetch).
- The user header needs `/account/profile` + community avatar; put it in the layout as a small client
  component (or server-fetch + pass down) so it renders once across all sections.
- **Supersedes story 30.35** (`?tab=` + replaceState): once routes exist, that mechanism is removed; keep
  a redirect from `/compte?tab=<x>` for old bookmarks/links.
- Decision to confirm at build time: `/compte` = overview (chosen) vs redirect to `/compte/profil`. This
  story takes the overview.
- Watch the route group: lives under `app/(public)/` like the current page; `RequireAuth` stays the gate.
- Effort: ~half a day (routing + layout + overview + cleanup).

### Project Structure Notes

- New: `frontend/src/app/(public)/compte/layout.tsx`, `compte/page.tsx` (overview), and
  `compte/{profil,amis,activite,inscriptions,parties,adhesion,confidentialite,securite}/page.tsx`.
- New: a sidebar nav component (e.g. `frontend/src/features/auth/account-nav.tsx`) + the overview
  feature component.
- Removed: `frontend/src/features/auth/account-tabs.tsx` (monolith) and 30.35's `?tab=` handling.

### References

- [Source: _bmad-output/implementation-artifacts/30-21-account-navigation-grouping.md (current grouping)]
- [Source: _bmad-output/implementation-artifacts/30-35-account-nav-url-tab.md (interim ?tab=, superseded)]
- [Source: frontend/src/features/auth/account-tabs.tsx (section → component mapping to preserve)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `/compte` is now route-based: a `layout.tsx` (RequireAuth + header) wraps `AccountShell` (profile
  fetched once + sidebar `AccountNav` + `{children}`); 8 section pages compose the existing components.
- `/compte` is an overview dashboard (Adhésion live status/expiry, Inscriptions/Amis counts, Activité).
- Native per-section URLs (deep-link / reload / back-forward); legacy `/compte?tab=<x>` and the old
  `?discord_linked` redirect to the new routes; Discord callback target updated to `/compte/securite`.
- `AccountTabs` monolith + the 30.35 `?tab=`/`replaceState` deleted. Frontend gates green; one backend
  line changed (Discord redirect) - phpstan/cs-fixer green, no test asserts the old target.
- Note: header profile is fetched in the shell and again in `AccountSecuritySection` (needs
  discord/steam) when on that route - a small, accepted duplication (avoids a client profile context).

### File List

- `frontend/src/app/(public)/compte/layout.tsx` (new), `compte/page.tsx` (overview + redirects)
- `compte/{profil,amis,activite,inscriptions,parties,adhesion,confidentialite,securite}/page.tsx` (new)
- `frontend/src/features/auth/account-shell.tsx`, `account-nav.tsx`, `account-overview.tsx`,
  `account-security-section.tsx` (new)
- `frontend/src/features/auth/account-tabs.tsx` (deleted)
- `api/src/Identity/Presentation/DiscordLinkController.php` (redirect → `/compte/securite`)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-28 | Created (draft). Route-based "Mon espace": each section a real page under `/compte/...` with a shared sidebar layout + an overview dashboard at `/compte`; supersedes the 30.35 `?tab=` mechanism with native routing. Scoped. |
| 2026-06-28 | Implemented. Layout + AccountShell + AccountNav (sidebar + mobile select + badges), 8 section pages, AccountOverview, security section; legacy `?tab=`/discord redirects; deleted AccountTabs + `?tab=`. Gates green; verified live. Status → review. |
