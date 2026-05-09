# Story 10.8: Gaming atmosphere design refresh

Status: done

## Story

As a visitor,
I want the public site to feel visually immersive and gaming-adjacent,
so that the atmosphere matches the cooperative LAN gaming culture of ArchiLAN.

## Acceptance Criteria

1. Given any public page is open, when a visitor views the page, then a subtle repeating grid pattern is visible on the background, adding depth without cluttering.
2. The navigation bar has a faint teal glow line below it.
3. All interactive cards (feature cards, event cards, community links) emit a soft teal glow on hover.
4. The active navigation link's bottom border has a teal glow (text-shadow).
5. The primary CTA button ("Voir les événements") has a resting teal glow that intensifies on hover.
6. Given the homepage hero is displayed, when a visitor views the headline, then the h1 text renders as a gradient from white to teal.
7. The overline "Association Archipelago en France" uses the magenta brand colour (`--color-special`) instead of warm orange.
8. No new colours are introduced - only existing tokens (`--color-accent`, `--color-special`) are used.

## Tasks / Subtasks

- [x] Define CSS custom properties for glow shadows (AC: 2, 3, 5)
  - [x] Add `--glow-accent-sm`, `--glow-accent-md`, `--glow-accent-lg` to `:root` in `globals.css` using `color-mix`.
- [x] Add subtle background grid texture (AC: 1)
  - [x] Apply SVG data-URI grid pattern to `body` in `globals.css` using `background-image` at ~35% opacity using `--color-border`.
- [x] Add `.btn-glow` CSS component class (AC: 5)
  - [x] Resting: `box-shadow: var(--glow-accent-sm)`. Hover: `box-shadow: var(--glow-accent-md)`.
- [x] Add `.card-glow` CSS component class (AC: 3)
  - [x] Hover: border shifts to `color-mix(in oklab, var(--color-accent) 35%, transparent)` + `box-shadow: var(--glow-accent-sm)`.
- [x] Add `.nav-active-glow` CSS component class (AC: 4)
  - [x] `text-shadow` on the active nav link using the accent colour.
- [x] Update navbar (AC: 2, 4, 5)
  - [x] Add `box-shadow` glow line to `<header>` via `style` prop.
  - [x] Apply `.nav-active-glow` to active `NavLink` state.
  - [x] Apply `.btn-glow` to the "Connexion / inscription" button.
- [x] Update homepage hero (AC: 6, 7)
  - [x] Wrap h1 content in `<span>` with `bg-gradient-to-r from-foreground via-foreground to-accent bg-clip-text text-transparent`.
  - [x] Change overline colour from `text-accent-warm` to `style={{ color: "var(--color-special)" }}`.
- [x] Apply `.btn-glow` to primary hero CTA (AC: 5)
- [x] Apply `.card-glow` to feature cards and community link cards (AC: 3)
- [x] Validate and handoff (AC: 1–8)
  - [x] Run frontend type-check.
  - [x] Update this story file.

## Dev Notes

The background grid uses a 40×40px SVG tile via data-URI to avoid an extra HTTP request. Grid lines are drawn with `--color-border` (`#1e2f4f`) at 35% opacity - visible but non-distracting on the dark navy background.

`color-mix(in oklab, ...)` is used for all glow alphas instead of `rgba()` so that the glow colour automatically tracks the CSS token if the accent is ever updated.

The h1 gradient uses `via-foreground` as a midpoint so the left side of the text reads as pure white (legible against the dark hero background) and the teal only emerges towards the right. This avoids the gradient from feeling washed out on the first word.

The `--color-special` magenta (`#e0246a`) matches the ArchiLAN logo's dominant circular world colour and was already defined but unused. No new colour is introduced.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.8]
- [Source: _bmad-output/implementation-artifacts/10-1-logo-officiel-navbar-footer.md]
- [Source: _bmad-output/implementation-artifacts/10-2-hero-immersif-homepage.md]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Added `--glow-accent-sm/md/lg` CSS custom properties using `color-mix`.
- Added SVG grid pattern to `body` background.
- Added `.btn-glow`, `.card-glow`, `.nav-active-glow` component classes.
- Updated navbar header `box-shadow` glow, active nav text-shadow, Connexion button.
- Hero h1 gradient text (white → teal). Overline switched to magenta `--color-special`.
- Primary CTA `.btn-glow`. Feature cards and community link cards `.card-glow`.
- Review correction: changed `--color-accent` from purple to teal so the glow, nav border, and hero gradient match the acceptance criteria.
- Review correction: moved the public grid pattern into a token-based `.gaming-grid-bg` class applied by `PublicShell`.
- Review correction: updated the animated grid canvas to derive its RGB value from `--color-accent` instead of hardcoded teal RGBA values.

### Validation Results

- `npx tsc --noEmit` - 0 errors.
- `pnpm lint -- src/components/grid-background.tsx src/components/public-shell.tsx src/app/(public)/page.tsx` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.

### File List

- `frontend/src/app/globals.css`
- `frontend/src/app/page.tsx`
- `frontend/src/components/public-shell.tsx`
- `frontend/src/components/grid-background.tsx`
- `_bmad-output/planning-artifacts/epics.md`
- `_bmad-output/implementation-artifacts/10-8-gaming-design-refresh.md`

### Change Log

- 2026-05-02: Implemented gaming atmosphere design refresh.
- 2026-05-03: Review fixes for teal token consistency, public grid background, and removal of hardcoded canvas glow colour.
