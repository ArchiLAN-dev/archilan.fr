---
story: "11.2"
title: "Visual Session Pipeline Bar"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: []
---

# Story 11.2: Visual Session Pipeline Bar

As an admin or player,
I want to see the session progress as a visual pipeline bar,
So that I immediately understand which phase the session is in without reading a raw status string.

## Context

The current status badge is a small colored pill with a text label. It communicates state but provides no sense of progress or direction. This story replaces it with a horizontal pipeline bar showing all steps and the current position, using animations and glows from the existing design system.

**Status → Pipeline step mapping:**
```
draft        → step 1 active (Créée)
validating   → step 2 active, animated (Validée)
ready        → step 2 complete
generating   → step 3 active, animated (Générée)
generated    → step 3 complete
launching    → step 4 active, animated (En ligne)
running      → all steps complete, step 4 pulsing green
failed       → step where failure occurred → danger color
crashed      → step 4 (En ligne) → danger color
stopped      → step 4 complete (muted)
finished     → all steps complete (muted/success)
```

**New component to create:** `SessionPipelineBar` (reusable, used in both admin detail and player page)

**Mini variant:** Used in session list rows - 3 colored dots only (no labels), same state logic.

**Design tokens to use:**
- Active/transitional: `text-accent-warm`, glow via inline style or `card-glow` variant
- Complete: `text-success`
- Error: `text-danger`
- Future: `text-muted-foreground`
- Animations: `animate-pulse`, `animate-spin` (Loader2)

## Acceptance Criteria

**Given** any session detail view
**When** the page renders
**Then** a horizontal pipeline bar displays four labeled nodes: Créée · Validée · Générée · En ligne
**And** completed steps show a CheckCircle icon in `text-success`
**And** future steps render in `text-muted-foreground` with a plain circle node

**Given** the session is in a transitional state (validating, generating, or launching)
**When** the pipeline bar renders
**Then** the active step node pulses with `animate-pulse` and `text-accent-warm` color
**And** a Loader2 icon with `animate-spin` appears on the active step node

**Given** the session is in `running` state
**When** the pipeline bar renders
**Then** all four steps display CheckCircle icons in `text-success`
**And** the "En ligne" step shows a `size-2.5 animate-pulse rounded-full bg-success` dot alongside its label

**Given** the session is in `failed` or `crashed` state
**When** the pipeline bar renders
**Then** the step at which failure occurred shows an XCircle icon in `text-danger`
**And** all preceding completed steps remain in `text-success`

**Given** a mobile viewport (< 640px)
**When** the pipeline bar renders
**Then** it remains single-row with abbreviated labels (or icons only) and no horizontal overflow

**Given** the sessions list page
**When** each session row renders
**Then** a mini three-dot indicator shows: muted (not started), accent-warm with pulse (in progress), success (running/finished), danger (failed/crashed)

## Tasks / Subtasks

- [ ] Task 1: Create `SessionPipelineBar` component inside `admin-session-page.tsx`
  - [ ] Define `PIPELINE_STEPS` array: `[{label: "Créée", shortLabel: "1"}, {label: "Validée", shortLabel: "2"}, {label: "Générée", shortLabel: "3"}, {label: "En ligne", shortLabel: "4"}]`
  - [ ] Define `getStepState(status, stepIndex)` helper returning `"complete" | "active" | "error" | "future"` based on status-to-step mapping in Context section
  - [ ] Render container with `role="status"` and `aria-label` with current status
  - [ ] Render each step node: CheckCircle for complete, Loader2+animate-spin for active transitional, XCircle for error, plain circle for future
  - [ ] Render connecting lines between nodes using `<div className="flex-1 h-px bg-{color} self-center" />` (ne pas utiliser `border-t-2` - ça nécessite height=0 explicite et produit un rendu instable)
  - [ ] Apply color classes per state: `text-success` complete, `text-accent-warm animate-pulse` active, `text-danger` error, `text-muted-foreground` future

- [ ] Task 2: Handle `running` state specially on "En ligne" step
  - [ ] When status is `running`, render `<span className="size-2.5 animate-pulse rounded-full bg-success" aria-hidden="true" />` next to "En ligne" label
  - [ ] All four steps show CheckCircle in `text-success`

- [ ] Task 3: Handle `stopped`/`finished` states
  - [ ] When status is `stopped` or `finished`, all steps complete but use `text-muted-foreground` instead of `text-success` (run is over, no active glow)

- [ ] Task 4: Add mobile-responsive layout
  - [ ] Use `hidden sm:inline` for full labels, show only step number or icon on small screens
  - [ ] Ensure no horizontal overflow with `overflow-x-auto` or `min-w-0` constraints

- [ ] Task 5: Create mini `SessionPipelineDots` variant for session list
  - [ ] Render 3 dots (since list doesn't need 4 full steps): dot 1 = created, dot 2 = generated, dot 3 = running
  - [ ] Each dot: `size-2 rounded-full` with color matching step state
  - [ ] Active transitional dot: `animate-pulse`

- [ ] Task 6: Integrate `SessionPipelineBar` into `SessionDetail`
  - [ ] Replace or supplement the `StatusBadge` usage in the status card section (line ~723) with `SessionPipelineBar`
  - [ ] Keep `StatusBadge` as a small secondary label below the pipeline bar if desired

- [ ] Task 7: Integrate `SessionPipelineDots` into sessions list
  - [ ] Replace or supplement `StatusBadge` in the sessions list map (line ~412) with the mini dots variant

## Dev Notes

**Primary file:** `frontend/src/features/admin/admin-session-page.tsx`

- `StatusBadge` component: line 1266. The `STATUS_LABELS` and `STATUS_CLASSES` maps are at lines 1238–1264. These can remain for backward compat but the pipeline bar replaces the badge in the detail view.
- Sessions list rendering: lines 410–428. Each `<button>` currently renders `<StatusBadge status={session.status} />` at line 422.
- Status card in `SessionDetail`: lines 720–737. The `StatusBadge` renders at line 724. Replace this with `SessionPipelineBar`.
- Import `CheckCircle2`, `XCircle`, `Loader2` from `lucide-react` - `Loader2` is already imported (line ~8).
- The step-to-status mapping for `failed`: `failed` maps to step 2 error (validation failed). `crashed` maps to step 4 error (server crashed after launch). Verify this mapping is correct for the product.
- **Connecting line** : utiliser `<div className="flex-1 h-px bg-{color} self-center" />` entre les nœuds (pas `border-t-2` qui crée une top border sur une div - problème de hauteur et d'alignement vertical).
- **Mapping `failed`** : le status `failed` n'indique pas explicitement quelle étape a échoué. Convention à adopter : `failed` → step 3 error (génération échouée), `crashed` → step 4 error (crash après lancement). Documenter ce choix dans un commentaire dans le code.
- Place `SessionPipelineBar` and `SessionPipelineDots` as inline function components near the bottom of the file with other sub-components (after line 946).
- Lucide icons already imported: check existing imports to avoid duplicates.

## Dev Agent Record

### Implementation Plan
_To be filled during implementation._

### Debug Log
_Issues encountered and resolutions._

### Completion Notes
_Summary of what was implemented and tested._

## File List

- `frontend/src/features/admin/admin-session-page.tsx`

## Change Log

| Date | Change | Author |
|------|--------|--------|
