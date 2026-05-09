---
story: "11.7"
title: "Player Progress Grid Redesign"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: ["11.3"]
---

# Story 11.7: Player Progress Grid Redesign

As a player or admin,
I want an animated, dynamically sorted progress grid with goal celebration visuals,
So that the run state is exciting and immediately readable.

## Context

The current `PlayerProgressGrid.tsx` shows slot cards with a static progress bar, static badges, and no sorting. The cards do not animate on state change and the goal state has minimal visual distinction. This story redesigns the component with smooth animations, dynamic sorting, and goal celebration.

**Key file:** `src/components/session/PlayerProgressGrid.tsx`

**Client status values:**
```
0  → Hors ligne    (muted)
5  → Connecté      (gray)
10 → Prêt          (yellow)
20 → En jeu        (blue + pulse dot)
30 → Objectif !    (success + ring + trophy)
```

**Sort order:** `client_status=30` (by `goal_reached_at` asc) → `client_status=20` (by `checks_done` desc) → all others (by `checks_done` desc)

**Smooth progress bar:** CSS `transition-[width]` applied to the bar element. React state update triggers the transition automatically.

**New header element:** Global progress counter "X/Y objectifs atteints" + global progress bar above the grid.

## Acceptance Criteria

**Given** any slot with `client_status = 20` (En jeu)
**When** its card renders
**Then** the status badge includes a `size-2 animate-pulse rounded-full bg-blue-500` dot alongside "En jeu" text

**Given** any slot with `client_status = 30` (Objectif atteint)
**When** its card renders
**Then** the card container uses `border-success ring-2 ring-success/30 bg-success/5` classes
**And** a `CheckCircle2` or `Trophy` lucide icon is displayed in `text-success`
**And** the `goal_reached_at` timestamp is shown as a small muted `time` element below the slot name

**Given** `checks_done` or `checks_total` changes for a slot
**When** the progress bar re-renders
**Then** the bar width changes with `className="transition-[width] duration-500 ease-in-out"` (no instant jump)
**And** the numeric label "X/Y" updates immediately

**Given** the grid header
**When** any player data is available
**Then** "X/Y objectifs atteints" counter is displayed
**And** a global progress bar shows `(goals / total) * 100` percent fill with `transition-[width] duration-300`

**Given** the slots array
**When** the grid renders or updates
**Then** the display order is:
1. `client_status=30` sorted by `goal_reached_at` ascending
2. `client_status=20` sorted by `checks_done` descending
3. All others (0, 5, 10) sorted by `checks_done` descending

**Given** the EventSource connection drops (after the 3s grace period)
**When** disconnection is confirmed
**Then** a row with `WifiOff` icon and "Reconnexion en cours…" text appears in the grid header area
**And** existing player cards remain visible with their last-known data (no empty state override)

**Given** the grid is awaiting initial data
**When** the fetch is in flight
**Then** skeleton cards render in the correct grid layout matching the actual responsive columns (Story 11.3)

## Tasks / Subtasks

- [ ] Task 1: Add `transition-[width] duration-500 ease-in-out` to progress bar element
  - [ ] Locate the progress bar `<div>` in `SlotCard` (~line 200 in PlayerProgressGrid.tsx)
  - [ ] Add `className="transition-[width] duration-500 ease-in-out"` to the inner bar div (the one with `style={{ width: \`${pct}%\` }}`)
  - [ ] Verify the outer bar has `overflow-hidden` so the transition doesn't bleed outside

- [ ] Task 2: Add pulse dot to `En jeu` (status 20) badge
  - [ ] In `SlotCard` status badge rendering, detect `client_status === 20`
  - [ ] Add `<span className="size-2 animate-pulse rounded-full bg-blue-500 shrink-0" aria-hidden="true" />` inside the "En jeu" badge, before the text

- [ ] Task 3: Apply goal celebration styling to `client_status = 30` cards
  - [ ] In `SlotCard`, detect `client_status === 30`
  - [ ] Apply `border-success ring-2 ring-success/30 bg-success/5` to the card container
  - [ ] Add `<Trophy aria-hidden="true" className="size-4 text-success shrink-0" />` (or `CheckCircle2`) in the card header
  - [ ] Render `goal_reached_at` as `<time className="text-xs text-muted-foreground mt-0.5 block">` using `new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(slot.goal_reached_at))` if not null

- [ ] Task 4: Vérifier `SlotData` type (rapide - déjà fait)
  - [ ] `goal_reached_at: string | null` est **déjà présent** dans `SlotData` (ligne 17) - rien à ajouter
  - [ ] Vérifier que le parser SSE inclut bien le champ dans les mises à jour

- [ ] Task 5: Update `sortedEntries()` to match spec sort order
  - [ ] Verify/update the sort function: status=30 first (by `goal_reached_at` asc, nulls last), then status=20 (by `checks_done` desc), then all others (by `checks_done` desc)
  - [ ] Verify `goal_reached_at` is parsed as Date for comparison: `new Date(a.goal_reached_at).getTime()`

- [ ] Task 6: Add global progress header ("X/Y objectifs atteints" + global bar)
  - [ ] Above the grid `<div className="grid ...">`, add a header section
  - [ ] Compute `goalCount = entries.filter(e => e.client_status === 30).length` and `total = entries.length`
  - [ ] Render: `<p className="text-sm font-semibold text-foreground">{goalCount}/{total} objectifs atteints</p>`
  - [ ] Render global bar: `<div className="h-1.5 w-full rounded-full bg-surface-2 overflow-hidden"><div className="h-full bg-success transition-[width] duration-300 rounded-full" style={{ width: \`\${(goalCount/total)*100}%\` }} /></div>`

- [ ] Task 7: Add `WifiOff` disconnect indicator in header area
  - [ ] `PlayerProgressGrid.tsx` utilise son **propre EventSource** (même pattern que `EventFeed`) - ne PAS utiliser `useSSE`. La déconnexion est détectée via `state.kind === "active" && !state.connected`
  - [ ] `WifiOff` est **déjà importé** (ligne 3) - pas d'import à ajouter
  - [ ] Quand `state.kind === "active" && !state.connected`, rendre: `<div className="flex items-center gap-2 text-xs text-accent-warm"><WifiOff className="size-3" aria-hidden="true" />Reconnexion en cours…</div>` dans la zone header
  - [ ] Garder les cards existantes visibles - uniquement ajouter l'indicateur, ne pas vider les données

- [ ] Task 8: Add skeleton cards for initial loading state
  - [ ] Cibler `state.kind === "loading"` (le `GridState` a déjà ce kind - ligne 69) - c'est l'état avant la première connexion SSE
  - [ ] Rendre 3 skeleton cards dans le même layout grid que la grille réelle (vérifier les classes `grid-cols-` sur le conteneur existant)
  - [ ] Chaque skeleton : ghost badge (h-4 w-16), ghost name line (h-4 w-28), ghost progress bar (h-2 w-full)
  - [ ] `CheckCircle2` est **déjà importé** (ligne 3) - pas d'import à ajouter pour Task 3 (goal icon)
  - [ ] Add `aria-hidden` + sr-only "Chargement…" pattern

## Dev Notes

**Primary file:** `frontend/src/components/session/PlayerProgressGrid.tsx`

- File is 282 lines. `SlotData` type: top of file (check lines 1–30 for type definition).
- `sortedEntries()` function: currently present (line ~70). The sort logic may already partially match - verify and update if needed.
- `SlotCard` component renders: slot name, status badge (`STATUS_LABELS`/`STATUS_CLASSES` maps), progress bar with `style={{ width }}`, checks_done/total label.
- The progress bar inner div needs `transition-[width] duration-500 ease-in-out` - Tailwind 4 supports arbitrary transition properties with `transition-[width]`.
- `Trophy` and `CheckCircle2` from lucide-react - import both, use `Trophy` for goal state.
- `WifiOff` et `CheckCircle2` sont **déjà importés** (ligne 3) - aucun import à ajouter.
- `goal_reached_at: string | null` est **déjà dans `SlotData`** (ligne 17) - Task 4 sera une vérification rapide.
- `PlayerProgressGrid` utilise son **propre EventSource** (lignes 70+), pas `useSSE`. La déconnexion se lit via `state.kind === "active" && !state.connected`.
- `GridState` a déjà `{ kind: "loading" }` (ligne 22) - utiliser ce kind pour le skeleton (Task 8).
- The grid layout class: find the existing `grid-cols-` classes on the grid container and replicate for skeleton cards.
- `Intl.DateTimeFormat` for `goal_reached_at`: `new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(date)` - returns e.g. "14:32".
- For the `disconnected` boolean: check `useSSE` hook signature. If it's named differently (e.g., `sseStatus.disconnected`), use that.
- Skeleton cards should only show when `entries.length === 0` and the grid is in its initial load state. Add a local `isLoading` state initialized to `true`, set to `false` after first SSE message.

## Dev Agent Record

### Implementation Plan
_To be filled during implementation._

### Debug Log
_Issues encountered and resolutions._

### Completion Notes
_Summary of what was implemented and tested._

## File List

- `frontend/src/components/session/PlayerProgressGrid.tsx`

## Change Log

| Date | Change | Author |
|------|--------|--------|
