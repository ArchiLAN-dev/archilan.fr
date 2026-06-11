# Story 27.10: contextual help (ⓘ tooltips) for session config options

Status: done

Repo: `archilan.fr` (monorepo, `frontend/`) — branch from `develop`.

## Story

As an admin (and as a private-run owner using the override panel),
I want a short explanation next to each Archipelago session-config option,
so that I understand what each setting does without knowing the AP jargon.

## Context

The session-config options (`releaseMode`, `collectMode`, `remainingMode`, `hintCost`,
`locationCheckPoints`, `countdownMode`, `disableItemCheat`, `compatibility`, `autoShutdown`,
`joinPassword`, `plandoOptions`, `race`, `spoiler`) are exposed on two surfaces: the per-type profile
editor (`admin-session-config-page`, which had terse inline hints on some fields) and the shared
override form (`session-config-override-form`, which had none). User chose an **ⓘ icon + accessible
popover** (hover + keyboard focus + tap) as the unified help mechanism, with reworked wording.

## Acceptance Criteria

1. A reusable `InfoTooltip` component: an ⓘ button that reveals a short description on hover, on
   keyboard focus, and on tap/click (mobile). Accessible: `aria-label`, the popover is `role="tooltip"`
   referenced via `aria-describedby` while open.
2. A single shared help map keyed by option (`sessionConfigHelp`) — one source of truth for the
   wording, reused by both surfaces.
3. The override form shows an ⓘ next to every field label, with reworked, jargon-light labels +
   help text.
4. The per-type admin page uses the same ⓘ + shared help (replacing the ad-hoc inline hints), incl.
   the fields that previously had none (compatibility, spoiler).
5. Gates green: `pnpm typecheck`, `pnpm lint`, `pnpm build`.

## Tasks / Subtasks

- [x] Task 1 — `components/info-tooltip.tsx` (hover/focus/tap, accessible) (AC 1).
- [x] Task 2 — `features/admin/session-config-help.ts` shared map (AC 2).
- [x] Task 3 — wire into the override form (AC 3).
- [x] Task 4 — wire into the per-type admin page (AC 4).
- [x] Task 5 — gates (AC 5).

## Dev Notes

- Help text is descriptive and value-agnostic (the per-value meaning stays in the option labels, e.g.
  "Après l'objectif atteint"). Kept short (1–2 lines) so the popover stays light.
- `autoShutdown` help notes it's the idle mechanism (epic-17) and admin-only for private runs (27.9).

### References

- `frontend/src/features/admin/session-config-override-form.tsx`
- `frontend/src/features/admin/admin-session-config-page.tsx`

## Dev Agent Record

## Change Log

- 2026-06-10 — Story created and implemented (status: review).
