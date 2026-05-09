---
story: "11.6"
title: "Player Session Page Visual Overhaul"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: ["11.3"]
---

# Story 11.6: Player Session Page Visual Overhaul

As a player,
I want a visually informative session page with a clear waiting state and prominent connection info when the run is live,
So that I always know what is happening and can connect quickly.

## Context

The current player session page (`session-connection-gate.tsx`) shows a plain status label with a small pulsing dot when running, and a bare list of connection fields. Players often don't know what to do when the run hasn't started. This story redesigns the page for two key states: waiting (pre-launch) and running.

**Key files:**
- `src/features/events/session-connection-gate.tsx`
- `src/app/(public)/evenements/[eventSlug]/inscription/[registrationId]/session/page.tsx`

**State machine for player view:**
```
loading        → skeleton (Story 11.3)
not-found      → error card (unchanged)
error          → error card (unchanged)
pre-launch     → animated waiting card (new)
  statuses: draft, validating, ready, generating, generated, launching
running        → prominent connection card with glow (redesigned)
stopped        → muted "terminée" card (new)
finished       → muted "terminée" card (new)
crashed/failed → error card with message (minor update)
```

**Design tokens:**
- Running card: `card-glow bg-success/5 border-success/40`
- Waiting card: `card-glow bg-surface border-border`
- Stopped card: `bg-surface border-border text-muted-foreground`

## Acceptance Criteria

**Given** the session is in any pre-launch state (draft, validating, ready, generating, generated, launching)
**When** the player views the session page
**Then** a centered card is shown with an animated Clock or Hourglass icon (`animate-spin` or size-8 pulsing)
**And** the heading "La run démarre bientôt" is shown in large text
**And** a countdown label "Vérification dans Xs…" counts down from 30 to 0 using a client-side interval
**And** when the countdown reaches 0 the session data is re-fetched automatically

**Given** the session is in `running` state
**When** the player views the page
**Then** the connection card uses `card-glow bg-success/5 border-success/40` classes
**And** a `size-2.5 animate-pulse rounded-full bg-success` dot is visible next to the "EN LIGNE" badge in the card header

**Given** the session is `running` and connection info is shown
**When** the player views the connection section
**Then** Adresse, Port, and Mot de passe display as 3 distinct labeled zones with visual separation
**And** each zone has an individual copy button with `aria-label="Copier l'adresse"` / "Copier le port" / "Copier le mot de passe"
**And** a "Tout copier" button copies the formatted string "Adresse: {host}:{port} | Mot de passe: {password}"

**Given** the session page is loading
**When** the initial fetch is in flight
**Then** skeleton loaders render per Story 11.3
**And** no plain text "Chargement…" appears

**Given** the session is `stopped` or `finished`
**When** the player views the page
**Then** a muted card renders with a static "La run est terminée" message
**And** no pulsing, glow, or animated elements appear in this state

## Tasks / Subtasks

- [ ] Task 1: Replace text loading state with skeleton in `SessionConnectionGate`
  - [ ] Locate `gateState.kind === "loading"` block (~line 108): currently shows plain text
  - [ ] Replace with skeleton connection card (Story 11.3 Task 3 skeleton): ghost heading + 3 ghost field rows
  - [ ] Add `aria-hidden` skeleton + `sr-only` "Chargement…" text

- [ ] Task 2: Detect pre-launch statuses and render `WaitingCard` component
  - [ ] Define `PRE_LAUNCH_STATUSES = ["draft", "validating", "ready", "generating", "generated", "launching"]`
  - [ ] In `SessionStatusBanner` (or `ConnectionView`), check if `session.status` is in pre-launch list
  - [ ] Create `WaitingCard` component with: animated Clock icon (`Clock` from lucide, `animate-spin` or `size-8 animate-pulse`), heading "La run démarre bientôt", countdown label

- [ ] Task 3: Implement countdown with auto-refetch in `WaitingCard`
  - [ ] Add `countdown: number` state initialized to 30
  - [ ] Add `useEffect` with `setInterval` (1s): decrement countdown, when reaches 0 call `onRefetch()` prop and reset to 30
  - [ ] Clean up interval on unmount via `return () => clearInterval(id)`
  - [ ] Also clean up when `session.status` is no longer pre-launch (pass `isWaiting` prop)
  - [ ] Render "Vérification dans {countdown}s…" below the heading in `text-muted-foreground text-sm`
  - [ ] **Prop chain explicite** : `fetchConnection` est défini dans `SessionConnectionGate` (ligne ~50). Passer en props : `SessionConnectionGate` → ajouter `onRefetch={fetchConnection}` à `<ConnectionView>` → ajouter `onRefetch` dans `ConnectionView` props → passer à `<WaitingCard onRefetch={onRefetch}>`. Mettre à jour le type de props de `ConnectionView` en conséquence.

- [ ] Task 4: Compléter le redesign du `running` state connection card
  - [ ] Note : `border-success/40 bg-success/5 rounded-lg` **existent déjà** dans `SessionStatusBanner` (ligne ~272) - ne pas les réécrire
  - [ ] Ajouter la classe `card-glow` au container (c'est la seule classe manquante pour l'effet glow)
  - [ ] Ajouter `<span className="size-2.5 animate-pulse rounded-full bg-success" aria-hidden="true" />` à gauche dans le header
  - [ ] Ajouter le badge "EN LIGNE" : `<span className="text-xs font-semibold text-success uppercase tracking-wide">EN LIGNE</span>` à côté du dot

- [ ] Task 5: Redesign connection info as 3 distinct zones with "Tout copier"
  - [ ] Modify `ConnectionView` section at lines ~207–219: replace the stacked `ConnectionField` components with 3 styled zones
  - [ ] Each zone: `<div className="rounded border border-border bg-surface p-3">` with label in small caps and value in large mono
  - [ ] Keep individual copy button on each zone (existing `ConnectionField` logic) with `aria-label="Copier l'adresse"` etc.
  - [ ] Add "Tout copier" button below the 3 zones: copies "Adresse: {host}:{port} | Mot de passe: {password}"
  - [ ] "Tout copier" shows "Copié !" feedback for 2s using local `copiedAll` state

- [ ] Task 6: Add muted "terminée" card for stopped/finished states
  - [ ] In `SessionStatusBanner`, add a branch for `session.status === "stopped" || session.status === "finished"`
  - [ ] Render: `<div className="bg-surface border-border rounded-lg border p-5">` with `<p className="text-muted-foreground">La run est terminée.</p>`
  - [ ] No pulse dot, no glow, no animations

- [ ] Task 7: Update crashed/failed state card
  - [ ] In `SessionStatusBanner`, ensure `crashed`/`failed` states show `AlertCircle` icon + error message in `text-danger`
  - [ ] This exists already at line ~267 - verify it's correct and enhance if needed

## Dev Notes

**Primary file:** `frontend/src/features/events/session-connection-gate.tsx`

- `SessionConnectionGate`: lines 42–149. Loading state at line 108.
- `ConnectionView`: lines 153–237. Connection section at lines 207–219. Currently renders `ConnectionField` components stacked.
- `SessionStatusBanner`: lines 255–292. Currently handles: null session, running, failed/crashed, others. Add pre-launch and stopped/finished branches.
- `ConnectionField` component: lines 297–327. Has individual copy + "Copié" feedback. Reuse or extend for zone-style rendering.
- `fetchConnection` function: line 50 in `SessionConnectionGate`. Pass it down to `ConnectionView` → `WaitingCard` via props.
- `isLive` check at line 163: `!["running", "stopped", "failed", "crashed"].includes(data.session.status)` - this controls SSE subscription. Pre-launch sessions ARE live (SSE active). Keep this unchanged.
- `Clock` icon from lucide-react: import from `lucide-react`. Use `<Clock className="size-8 animate-spin text-accent-warm" aria-hidden="true" />` for the waiting animation.
- For the "Tout copier" formatting: `navigator.clipboard.writeText(\`Adresse: \${session.host}:\${session.port} | Mot de passe: \${session.password}\`)`.
- The `PlayerProgressGrid` and `EventFeed` are rendered below in `ConnectionView` (lines 233–235) - leave them in place.
- Do NOT change the `parseConnectionData`, `parseSession`, `parseSlot` functions at the bottom of the file.
- The auto-refetch in `WaitingCard` calls `fetchConnection()` which updates state via `setGateState`. When SSE delivers `running`, the countdown stops naturally because `session.status` changes.

## Dev Agent Record

### Implementation Plan
_To be filled during implementation._

### Debug Log
_Issues encountered and resolutions._

### Completion Notes
_Summary of what was implemented and tested._

## File List

- `frontend/src/features/events/session-connection-gate.tsx`

## Change Log

| Date | Change | Author |
|------|--------|--------|
