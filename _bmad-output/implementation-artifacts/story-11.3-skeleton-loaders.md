---
story: "11.3"
title: "Skeleton Loaders for All Loading Zones"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: []
---

# Story 11.3: Skeleton Loaders for All Loading Zones

As a user (admin or player),
I want to see skeleton placeholders that match the real layout during loading,
So that the interface feels fast and does not shift layout when data arrives.

## Context

Currently every loading state in the session management UI shows plain text ("Chargement…", "Chargement des joueurs…", etc.) or a generic spinner. This causes layout shift and feels unpolished. This story replaces all loading states with pulse skeleton elements that match the real layout.

**Skeleton pattern (from globals.css design system):**
```tsx
<div className="h-4 w-32 animate-pulse rounded bg-surface-2" />
```
Always use `bg-surface-2` for skeleton fill and `animate-pulse` for the animation.

**Zones to replace:**

| Zone | Current | Replace with |
|---|---|---|
| Session builder table | Text "Chargement…" | 3-row ghost table |
| Session detail initial | Spinner + text | Ghost pipeline bar + ghost action buttons |
| Player connection page | Text "Chargement des informations…" | Ghost connection card (3 field rows) |
| Player progress grid | Text "Chargement des joueurs…" | 3 ghost player cards in grid layout |
| Event feed | Nothing / jumps in | 5 ghost message rows |
| Action buttons (loading) | Icon changes but button may resize | Inline spinner, button stays same size |

## Acceptance Criteria

**Given** the session builder is loading registrations from the API
**When** the fetch is in flight
**Then** a 3-row skeleton table renders with `animate-pulse bg-surface-2` shapes matching the actual table columns (player name column, game column, slot name input shape)
**And** no text such as "Chargement…" appears

**Given** the session detail page is loading
**When** the fetch is in flight
**Then** a skeleton of the pipeline bar renders (4 ghost nodes connected by 3 ghost lines, all with pulse)
**And** 2-3 ghost shapes for the action buttons render below in the correct positions

**Given** the player session connection page is loading
**When** the fetch is in flight
**Then** a skeleton of the connection card renders with a ghost header row and 3 ghost field rows (label-width ghost + value-width ghost)
**And** no plain text loading message appears

**Given** the player progress grid is awaiting initial data
**When** the fetch is in flight
**Then** 3 skeleton player cards render in the correct grid layout (1 col on mobile, 2 on sm:, 3 on lg:)
**And** each ghost card shows: a ghost badge at top-right, a ghost progress bar, and 2 ghost stat labels

**Given** the event feed is connecting before the first message arrives
**When** the EventSource is establishing
**Then** 5 skeleton message rows render, each with a small ghost pill (type badge shape) and a longer ghost text line

**Given** any primary action button (Générer, Lancer, Générer & Lancer, Arrêter, Relancer) is in a loading/pending state
**When** the button is active
**Then** it shows an inline `<Loader2 className="size-4 animate-spin" />` replacing or alongside the default icon
**And** the button has `disabled` attribute
**And** the button does not change its width or height (no layout shift)

## Tasks / Subtasks

- [ ] Task 1: Replace builder loading text with skeleton table in `admin-session-page.tsx`
  - [ ] Locate `builderLoading` branch in `WizardBuilder` (~line 479): currently shows `<Loader2>` + text
  - [ ] Replace with a 3-row ghost table: ghost cells matching real column widths (w-32 for player name, w-48 for game, w-20 for slot input shape)
  - [ ] Add `aria-hidden="true"` on skeleton container and `<span className="sr-only">Chargement des inscriptions…</span>` sibling

- [ ] Task 2: Replace "creating" state loading in admin page main render
  - [ ] Locate `state.kind === "creating"` block (~line 345): currently shows Loader2 + "Création de la session..."
  - [ ] Replace with a skeleton card: ghost pipeline bar (4 ghost circles + 3 ghost lines) + 2 ghost button shapes below
  - [ ] Add `aria-hidden` + sr-only pattern

- [ ] Task 3: Replace session-connection-gate loading state
  - [ ] Locate `gateState.kind === "loading"` block in `session-connection-gate.tsx` (~line 108): currently shows plain text "Chargement des informations de connexion…"
  - [ ] Replace with a ghost connection card: ghost heading row (h-6 w-48) + 3 ghost field rows (h-12 w-full each with label ghost + value ghost)
  - [ ] Add `aria-hidden` + sr-only pattern

- [ ] Task 4: Replace player progress grid loading state
  - [ ] Locate loading state in `PlayerProgressGrid.tsx` - currently shows text or nothing on initial load
  - [ ] Add a `loading` prop (or use existing `entries === null` check) to render 3 skeleton cards
  - [ ] Each skeleton card: match grid columns (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`), show ghost badge (h-4 w-16), ghost progress bar (h-2 w-full), 2 ghost stat lines
  - [ ] Add `aria-hidden` + sr-only pattern

- [ ] Task 5: Add 5 skeleton rows to event feed pendant le chargement initial
  - [ ] Dans `event-feed.tsx`, cibler `state.kind === "loading"` (ligne ~141) - c'est l'état qui affiche actuellement "Connexion au feed en direct…" : le remplacer par 5 ghost rows
  - [ ] Ne PAS cibler `state.kind === "active" && messages.length === 0` - ce cas affiche "Les messages apparaîtront en direct" et doit rester tel quel (feed connecté mais pas encore de messages)
  - [ ] Chaque ghost row : `animate-pulse bg-surface-2` pill shape (h-4 w-14 pour le type badge) + ghost line (h-3 w-3/4 pour le texte)
  - [ ] Add `aria-hidden` + sr-only pattern

- [ ] Task 6: Verify action button loading states don't resize (admin-session-page.tsx)
  - [ ] Inspect `ActionButton` component (~line 948): confirm inline spinner replaces icon without changing button width
  - [ ] Add `min-w-[...px]` to prevent width collapse if needed (measure the button text width)
  - [ ] Verify `disabled` attribute is set when `loading` prop is true

## Dev Notes

**Files affected:**
- `frontend/src/features/admin/admin-session-page.tsx` - Tasks 1, 2, 6
- `frontend/src/features/events/session-connection-gate.tsx` - Task 3
- `frontend/src/components/session/PlayerProgressGrid.tsx` - Task 4
- `frontend/src/features/events/event-feed.tsx` - Task 5

**Skeleton base pattern:**
```tsx
<div aria-hidden="true" className="h-4 w-32 animate-pulse rounded bg-surface-2" />
```
Verify `bg-surface-2` is defined in globals.css. If not, use `bg-muted` as fallback.

**WizardBuilder** loading branch: line 479 in admin-session-page.tsx. The `builderLoading` prop controls it.

**Ghost table row structure** (for builder skeleton):
```tsx
<tr>
  <td className="px-4 py-3"><div className="h-4 w-32 animate-pulse rounded bg-surface-2" /></td>
  <td className="px-4 py-3"><div className="h-4 w-48 animate-pulse rounded bg-surface-2" /></td>
</tr>
```

**PlayerProgressGrid** (`frontend/src/components/session/PlayerProgressGrid.tsx`): Currently the grid renders when `entries` has data. When `entries` is loading (before first SSE message), it shows nothing. Add a `loading` boolean state triggered initially before the first SSE message arrives.

**EventFeed** (`frontend/src/features/events/event-feed.tsx`): Le `FeedState` a trois kinds: `"loading"` (fetch du token en cours), `"unavailable"` (feed indispo), `"active"` (connecté). Le skeleton remplace uniquement le rendu du `kind === "loading"` (ligne ~141). Le cas `kind === "active" && messages.length === 0` produit "Les messages apparaîtront en direct" - ne pas toucher.

**Action button no-resize tip:** Use `min-w-[content-width]` or set an explicit `px-` value that accommodates the icon + text. The current `ActionButton` at line 948 uses dynamic icon. Adding `shrink-0` to the icon wrapper prevents icon from causing text wrap.

## Dev Agent Record

### Implementation Plan
_To be filled during implementation._

### Debug Log
_Issues encountered and resolutions._

### Completion Notes
_Summary of what was implemented and tested._

## File List

- `frontend/src/features/admin/admin-session-page.tsx`
- `frontend/src/features/events/session-connection-gate.tsx`
- `frontend/src/components/session/PlayerProgressGrid.tsx`
- `frontend/src/features/events/event-feed.tsx`

## Change Log

| Date | Change | Author |
|------|--------|--------|
