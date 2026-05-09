# Story 1.1: Public Shell, Navigation and Design Tokens

Status: done

## Story

As a visitor,
I want a polished public site shell with clear navigation,
so that I immediately understand the site is credible and can reach key public sections.

## Acceptance Criteria

1. Given the frontend starter exists, when the public shell is implemented, then the site uses the approved ArchiLAN color tokens, typography, spacing, and focus ring patterns.
2. Public navigation contains links for events, news/content, Twitch, Discord, login/signup, and legal footer links.
3. Navigation is sticky, responsive, keyboard accessible, and includes a mobile full-screen menu.
4. Public pages expose semantic landmarks and `lang="fr"`.
5. No authenticated-only backoffice navigation is visible to public visitors.

## Tasks / Subtasks

- [x] Implement design token foundation (AC: 1)
  - [x] Map approved ArchiLAN color tokens in `globals.css`.
  - [x] Configure dark public surface, typography tokens, spacing-safe base styles, and focus ring patterns.
  - [x] Avoid remote font downloads so builds remain reproducible.
- [x] Implement public shell landmarks (AC: 3, 4, 5)
  - [x] Set root language to French.
  - [x] Add skip link to main content.
  - [x] Add semantic `<header>`, `<nav>`, `<main>`, and `<footer>` structure.
  - [x] Ensure no admin/backoffice links are present.
- [x] Implement responsive public navigation (AC: 2, 3, 5)
  - [x] Add public links: events, news/content, Twitch, Discord, login/signup.
  - [x] Add legal links in footer.
  - [x] Add sticky desktop navigation.
  - [x] Add keyboard-accessible mobile full-screen menu.
- [x] Replace starter placeholder safely (AC: 1, 4, 5)
  - [x] Remove Next/Vercel starter content.
  - [x] Add shell-safe public placeholder content without implementing the full landing page from Story 1.2.
- [x] Validate and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no backend files were changed.
  - [x] Confirm no backoffice navigation is visible in public shell.
  - [x] Update this story file with commands run, validation results, and file list.
- [x] Review follow-ups (AI)
  - [x] Replace mobile menu `hidden` attribute with `aria-hidden` and pointer-events gating.
  - [x] Remove static Twitch badge until realtime/live status is wired by a later story.
  - [x] Document future `app/admin/layout.tsx` requirement for AdminShell isolation.
  - [x] Close mobile menu on route changes without setState-in-effect lint violations.
  - [x] Derive external link behavior automatically from URL protocol.
  - [x] Align `.dark` sidebar variables with ArchiLAN tokens.
  - [x] Centralize external public links in `src/lib/external-links.ts`.

## Dev Notes

This story creates the public shell and design token layer only. It must not implement event listings, landing-page storytelling, registration flows, auth behavior, API clients, backoffice navigation, or protected routes.

### Design Tokens

Approved colors from UX:

- `--color-bg`: `#0A1629`
- `--color-surface`: `#0F1E38`
- `--color-surface-2`: `#162440`
- `--color-border`: `#1E2F4F`
- `--color-accent`: `#16C4D4`
- `--color-accent-hover`: `#20D9EA`
- `--color-accent-warm`: `#E89420`
- `--color-danger`: `#C4220C`
- `--color-special`: `#E0246A`
- `--color-text`: `#E8EDF4`
- `--color-text-muted`: `#7A8BA8`
- `--color-success`: `#1ABD8C`

Typography target is Space Grotesk for headings and Inter for body/UI, with local fallback stacks until fonts are self-hosted in a later asset/performance story.

### Navigation Requirements

Public site navigation:

- Logo left.
- Primary links center: events, news/content, Twitch, Discord.
- Login/signup action right.
- Mobile hamburger opens a full-screen overlay, not a sidebar.
- Footer contains legal links.
- No backoffice/admin links.

### Accessibility Requirements

- `<html lang="fr">`.
- Skip link text: `Passer au contenu principal`.
- Main landmark has `id="main-content"`.
- Focus ring uses accent color with visible offset.
- Minimum touch target: 44px.
- Native buttons/links preferred.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.1-Public-Shell-Navigation-and-Design-Tokens]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Visual-Foundation]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Navigation-Patterns]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Accessibility-Considerations]
- [Source: _bmad-output/planning-artifacts/architecture.md#Frontend-Organization]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Implemented the shell as a client component because the mobile menu needs local open/close state and body scroll locking.
- Kept fonts as local fallback stacks (`Space Grotesk`, `Inter`, system fallbacks) without `next/font/google` to preserve reproducible offline builds.
- Replaced generated Next/Vercel starter content with a minimal shell-safe public placeholder. Full landing-page content remains deferred to Story 1.2.
- Adjusted desktop CTA text from `Connexion` to `Connexion / inscription` to satisfy the login/signup navigation requirement exactly.
- Review follow-up: mobile menu no longer uses `hidden`, preserving close transitions while remaining inaccessible through `aria-hidden` and `pointer-events-none` when closed.
- Review follow-up: menu open state is derived from the pathname it was opened on, so route changes close the overlay without a synchronous `setState` effect.
- Review follow-up: removed the static Twitch status badge to avoid implying live status before realtime/Twitch integration exists.
- Review follow-up: external links are centralized in `src/lib/external-links.ts`; `NavLink` derives external behavior from `http(s)` URLs.
- Review follow-up: documented that future backoffice routes need `app/admin/layout.tsx` and `AdminShell`.
- Review follow-up: `.dark` sidebar variables now map to ArchiLAN tokens instead of shadcn preset OKLCH values.

### Completion Notes List

- Added ArchiLAN design tokens to `frontend/src/app/globals.css`: dark navy surfaces, cyan accent, amber secondary accent, muted text, semantic danger/success, radius, and focus ring styling.
- Updated root layout metadata and set `<html lang="fr">`.
- Added `PublicShell` with skip link, sticky header, semantic navigation, responsive mobile full-screen menu, main landmark, and legal footer.
- Added public navigation links for events, news/content, Twitch, Discord, and login/signup.
- Added footer legal links for mentions legales, confidentiality, and conditions.
- Removed Next/Vercel starter page content.
- No authenticated-only admin/backoffice navigation is present.
- No backend implementation files were intentionally changed by this story.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Post-review `pnpm lint` - passed.
- Post-review `pnpm typecheck` - passed.
- Post-review `pnpm build` - passed.
- Search confirmed public shell text/landmarks: `Passer au contenu principal`, `Navigation principale`, `Navigation mobile`, `main-content`, and `Liens légaux`.
- Search confirmed public navigation labels: `Événements`, `Actualités`, `Twitch`, `Discord`, `Connexion / inscription`, `Mentions légales`, `Confidentialité`, and `Conditions`.
- Search for `admin|backoffice|dashboard|utilisateurs|gestion` in public shell/app files returned no matches.

### File List

- `frontend/src/app/globals.css`
- `frontend/src/app/layout.tsx`
- `frontend/src/app/page.tsx`
- `frontend/src/components/public-shell.tsx`
- `frontend/src/app/README.md`
- `frontend/src/lib/external-links.ts`
- `_bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md`

### Change Log

- 2026-04-25: Implemented public shell, ArchiLAN design tokens, responsive navigation, semantic landmarks, legal footer links, and starter placeholder replacement.
- 2026-04-25: Addressed 1.1 review follow-ups for mobile menu behavior, static Twitch status, admin layout documentation, external link centralization, and dark sidebar tokens.
