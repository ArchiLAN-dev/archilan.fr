---
story: "11.5"
title: "Admin Session Detail Visual Overhaul"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: ["11.1", "11.2", "11.3"]
---

# Story 11.5: Admin Session Detail Visual Overhaul

As an admin,
I want a visually polished session detail panel with a sticky action bar, gaming-aesthetic status card, and terminal-style command and log panels,
So that managing a live run feels professional and immersive.

## Context

The current session detail is a single 1300-line component with actions scattered throughout. This story redesigns the visual layout and interaction patterns without changing any API calls. The component can be decomposed into sub-components during this work.

**Design language reference:**
- Status card: `card-glow` + dynamic border/bg per state
- Running dot: `size-2.5 animate-pulse rounded-full bg-success` (matches landing page pattern)
- Terminal: `bg-bg font-mono text-success/80` (or `#050d1a` explicit)
- Buttons: inline `<Loader2 className="size-4 animate-spin" />` during loading

**Key redesign zones:**

| Zone | Current | New |
|---|---|---|
| Action buttons | Scattered in detail view | Sticky top bar, context-aware |
| Status card | Simple badge | card-glow with colored border + animated dot |
| Connection info | Field list with copy buttons | Prominent 3-zone display + "Tout copier" |
| Commands panel | Basic input | Terminal-style with history |
| Logs panel | Collapsible pre block | Terminal-style with LIVE badge + auto-scroll |
| Force-end dialog | Simple confirm | Typed "FIN" confirmation |

**Files likely affected:**
- `src/features/admin/admin-session-page.tsx`
- Possibly split into: `AdminSessionDetail.tsx`, `SessionActionBar.tsx`, `SessionStatusCard.tsx`, `SessionTerminal.tsx`, `SessionLogsPanel.tsx`

## Acceptance Criteria

**Given** the session detail view
**When** it renders
**Then** a sticky action bar at the top shows contextually appropriate primary buttons:
- draft/ready: "Générer & Lancer" (primary), "Générer" (secondary)
- generated: "Lancer" (primary), "Générer & Lancer" (secondary)
- running: "Arrêter" (warning), "Forcer la fin" (danger)
- crashed: "Relancer" (primary)
- stopped/finished: no primary action

**Given** any action button is clicked
**When** the API call is in progress
**Then** the button shows `<Loader2 className="size-4 animate-spin" />` inline, has `disabled` attribute
**And** button dimensions do not change (no layout shift)

**Given** the status card in `running` state
**When** it renders
**Then** it uses `card-glow border-success/40 bg-success/10` classes
**And** a `size-2.5 animate-pulse rounded-full bg-success` dot appears next to "EN LIGNE" text

**Given** the status card in `crashed` or `failed` state
**When** it renders
**Then** it uses `border-danger/40 bg-danger/10` classes and `text-danger` for the label

**Given** the status card in a transitional state (validating, generating, launching)
**When** it renders
**Then** it uses `border-accent-warm/40 bg-accent-warm/10` classes
**And** a `size-2.5 animate-pulse rounded-full bg-accent-warm` dot appears

**Given** the session is `running` and connection info is visible
**When** the admin clicks "Tout copier"
**Then** clipboard receives "Adresse: {host}:{port} | Mot de passe: {password}"
**And** the button label briefly changes to "Copié !" with a CheckCircle icon for ~2 seconds

**Given** the admin commands panel renders
**When** viewed
**Then** container background is the darkest available surface (`bg-bg` or equivalent)
**And** font is `font-mono`, text color is `text-success/80`
**And** previously sent commands appear above the input as history lines prefixed with ">"
**And** the text input auto-focuses on component mount

**Given** the logs panel is expanded and actively polling
**When** new log content is received
**Then** the scroll position moves to the bottom automatically
**And** a "LIVE" label with `animate-pulse` animation is visible in the panel header during active polling

**Given** the admin clicks "Forcer la fin"
**When** the confirmation dialog opens
**Then** an `AlertTriangle` icon in `text-danger` is shown at the top
**And** a text input is present with placeholder "Tapez FIN pour confirmer"
**And** the confirm button is `disabled` until input value matches "FIN" (case-insensitive)
**And** on confirm, the force-end API call is dispatched and dialog closes

## Tasks / Subtasks

- [ ] Task 1: Add sticky action bar at top of `SessionDetail`
  - [ ] Add `<div className="sticky top-0 z-10 bg-surface/90 backdrop-blur-sm border-b border-border px-4 py-3 flex flex-wrap gap-2">` before the status card
  - [ ] Move all `ActionButton` elements from the status card (lines ~782–846) into this sticky bar
  - [ ] Apply context-aware button visibility per state (draft/ready, generated, running, crashed, stopped/finished)
  - [ ] Keep `DownloadZipButton` accessible in the sticky bar for applicable states

- [ ] Task 2: Redesign status card with `card-glow` and dynamic border/bg
  - [ ] Apply `card-glow` class to the status card container (line ~721)
  - [ ] Replace static `border-border bg-surface` with dynamic classes based on session status:
    - running: `border-success/40 bg-success/10`
    - crashed/failed: `border-danger/40 bg-danger/10`
    - validating/generating/launching: `border-accent-warm/40 bg-accent-warm/10`
    - draft/ready/generated/stopped/finished: `border-border bg-surface`
  - [ ] Add animated dot next to status label: `size-2.5 animate-pulse rounded-full bg-success` for running, `bg-accent-warm` for transitional
  - [ ] Replace `StatusBadge` in status card with `SessionPipelineBar` (Story 11.2) if 11.2 is complete, else enhance the badge

- [ ] Task 3: Redesign connection info as 3 prominent zones + "Tout copier"
  - [ ] Replace the `grid-cols-3` `ConnectionField` layout (lines ~855–876) with 3 visually distinct zones: Adresse, Port, Mot de passe
  - [ ] Each zone: large monospace value, labeled in small caps, individual copy button with `aria-label`
  - [ ] Add "Tout copier" button that calls `navigator.clipboard.writeText("Adresse: {host}:{port} | Mot de passe: {password}")`
  - [ ] "Tout copier" shows "Copié !" + CheckCircle2 for ~2 seconds after click (use `copied === "all"` key)

- [ ] Task 4: Redesign `CommandPanel` as terminal
  - [ ] Change container bg to `bg-[#050d1a]` or `bg-bg` (darkest surface)
  - [ ] Apply `font-mono text-success/80` to text elements inside
  - [ ] Add `commandHistory: string[]` state to `CommandPanel`
  - [ ] After each successful `sendCommand()`, push `command` to `commandHistory` before clearing input
  - [ ] Render `commandHistory` above the input as `<p className="font-mono text-sm text-success/60">&gt; {cmd}</p>` lines
  - [ ] Add `autoFocus` attribute to the `<input>` element
  - [ ] Add scrollable history area with `max-h-32 overflow-y-auto` container

- [ ] Task 5: Redesign `LogPanel` as terminal with LIVE badge and auto-scroll
  - [ ] Change `<pre>` container bg from `bg-background` to `bg-[#050d1a]`
  - [ ] Apply `text-success/80` to log text
  - [ ] Add `useRef<HTMLPreElement>(null)` and `useEffect` that scrolls ref to bottom when `logs` changes
  - [ ] Add "LIVE" badge in panel header when `active && open`: `<span className="animate-pulse text-xs font-semibold text-success">LIVE</span>`
  - [ ] Remove `loadingLogs` text fallback, replace with skeleton (one ghost line, Story 11.3 pattern)

- [ ] Task 6: Redesign `ForceEndDialog` with typed "FIN" confirmation
  - [ ] Add `AlertTriangle` icon at the top of the dialog in `text-danger`
  - [ ] Add `confirmInput: string` state to `ForceEndDialog`
  - [ ] Add `<input>` with placeholder "Tapez FIN pour confirmer" that updates `confirmInput`
  - [ ] Disable confirm button unless `confirmInput.toLowerCase() === "fin"`
  - [ ] Keep cancel and confirm API call logic unchanged

## Dev Notes

**Primary file:** `frontend/src/features/admin/admin-session-page.tsx`

- `SessionDetail` component: lines 655–943. The action buttons block is at lines 780–846 - these move to the sticky bar.
- Current connection section: lines 849–876. The `ConnectionField` component: lines 1014–1045.
- `CommandPanel` component: lines 1048–1113. `LogPanel` component: lines 1117–1192.
- `ForceEndDialog` component: lines 1196–1234. Currently no input - add `confirmInput` state and input element.
- The `copied` state in `SessionDetail` (line 665) already supports keyed copy tracking - add `"all"` key for "Tout copier".
- `bg-bg` CSS variable: verify in `globals.css` that `--color-bg: #0a1629` is defined. Use `bg-[var(--color-bg)]` or `bg-bg` if Tailwind config maps it.
- The sticky bar z-index: use `z-10` to stay above card content. Add `border-b border-border` to visually separate it.
- `autoFocus` on input: use the `autoFocus` JSX boolean attribute on the `<input>` in `CommandPanel`.
- LogPanel auto-scroll: `useEffect(() => { if (logRef.current) logRef.current.scrollTop = logRef.current.scrollHeight; }, [logs])`.
- **Validation errors** : le bloc d'affichage des erreurs de validation (lignes 755–778) est actuellement imbriqué dans le status card, juste sous les boutons d'action. Après migration des boutons vers la sticky bar, ces erreurs **restent dans le status card** - ne pas les déplacer. Le dev ne doit pas confondre "déplacer les boutons" avec "déplacer tout le contenu du status card".
- Do NOT change any `apiFetch` calls, API endpoints, or session state logic. Only visual/layout changes.
- Keep `DownloadZipButton` (line 979) unchanged, just move it into the sticky action bar.
- `AlertTriangle` is already imported (check existing imports before adding).

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
