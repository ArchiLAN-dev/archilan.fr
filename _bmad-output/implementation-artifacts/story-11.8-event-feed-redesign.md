---
story: "11.8"
title: "Event Feed Redesign"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: ["11.3"]
---

# Story 11.8: Event Feed Redesign

As a player or admin,
I want a richly styled event feed with colored message borders, icon badges, relative timestamps, and a new-message indicator,
So that the run activity is visually distinct and easy to follow.

## Context

The current `event-feed.tsx` shows messages as simple rows with a type badge and timestamp. This story redesigns each row to have a colored left border, an icon-enriched badge, relative timestamps with hover tooltips, and a floating "new messages" pill for when the user has scrolled up.

**Key file:** `src/features/events/event-feed.tsx`

**Message type → border color + icon:**
```
hint             → border-amber-500    + Lightbulb icon
item-received    → border-teal-500     + Gift icon
location-checked → border-blue-500     + MapPin icon
system           → border-border       + Info icon
chat             → border-foreground   + MessageSquare icon
```

**Relative timestamp logic:**
```ts
function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  if (diff < 60_000) return "à l'instant";
  if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
  return `il y a ${Math.floor(diff / 3_600_000)} h`;
}
```
The exact ISO string goes in the `title` attribute of the timestamp element for tooltip-on-hover.

**New message indicator:**
- Track scroll position of the feed container with a scroll event listener
- When `scrollTop + clientHeight < scrollHeight - threshold (e.g., 50px)`: user is "up"
- When new messages arrive while "up": increment a counter
- Show floating pill: `"N nouveau(x) message(s) ↓"` absolutely positioned at the bottom of the container
- Click → scroll to bottom + reset counter

**Implementation note:** The 100-message buffer and EventSource reconnect logic remain unchanged.

## Acceptance Criteria

**Given** any feed message
**When** it renders
**Then** a 4px left border displays in the type-specific color using `border-l-4 border-{color}` classes
**And** the background has a very subtle tint matching the border color (e.g., `bg-amber-500/5` for hints)

**Given** the message type badge
**When** it renders
**Then** it is a rounded pill (`rounded-full px-2 py-0.5 text-xs`) containing:
- An inline icon (size-3) matching the type
- The type label text (hint, item reçu, etc.)

**Given** a message timestamp
**When** it renders
**Then** it shows a relative label: "à l'instant", "il y a Xmin", or "il y a Xh"
**And** the element has a `title` attribute with the full ISO timestamp string

**Given** the user has scrolled up (more than 50px from the bottom of the feed container)
**When** one or more new messages arrive
**Then** a floating pill appears at the bottom of the container: "N nouveau(x) message(s) ↓"
**And** the pill uses `absolute bottom-3 left-1/2 -translate-x-1/2` positioning with `bg-accent text-accent-text` styling and `cursor-pointer`

**Given** the floating pill is visible
**When** the user clicks it
**Then** the feed container scrolls to the bottom smoothly (`scrollIntoView` or `scrollTop = scrollHeight`)
**And** the new-message counter resets to 0 and the pill disappears

**Given** the user is at the bottom of the feed
**When** new messages arrive
**Then** the feed container auto-scrolls to reveal new messages
**And** the floating pill is not shown

**Given** the feed is loading before the first message arrives
**When** the EventSource is establishing
**Then** 5 skeleton message rows render (Story 11.3): each row has a ghost pill + a ghost text line with `animate-pulse bg-surface-2`
**And** no "Aucun message" or empty state text appears during this initial connecting phase

## Tasks / Subtasks

- [ ] Task 1: Add colored left border and background tint to each message row
  - [ ] Define `TYPE_BORDER_CLASSES` map: `hint → "border-amber-500 bg-amber-500/5"`, `item-received → "border-teal-500 bg-teal-500/5"`, `location-checked → "border-blue-500 bg-blue-500/5"`, `system → "border-border bg-transparent"`, `chat → "border-foreground/30 bg-transparent"`
  - [ ] Apply `border-l-4 border-l-[color]` and `bg-[color]` to each message row container
  - [ ] Verify `border-l-4` renders as 4px left border in Tailwind 4 (use `border-l-[4px]` if shorthand doesn't work)

- [ ] Task 2: Redesign `TypeBadge` with icon + pill style
  - [ ] Define `TYPE_ICONS` map: `hint → Lightbulb`, `item-received → Gift`, `location-checked → MapPin`, `system → Info`, `chat → MessageSquare`
  - [ ] Import those icons from `lucide-react`
  - [ ] Update `TypeBadge` to render: `<span className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {colorClass}"><Icon className="size-3" aria-hidden="true" />{label}</span>`
  - [ ] Keep existing `TYPE_LABELS` and `TYPE_CLASSES` for the badge colors or derive new ones

- [ ] Task 3: Implement `relativeTime()` and replace `formatTime()`
  - [ ] Add `function relativeTime(iso: string): string` with the exact logic from Context section
  - [ ] In each message row, replace the `formatTime(msg.timestamp)` call with `relativeTime(msg.timestamp)`
  - [ ] Wrap the timestamp in `<time title={msg.timestamp} className="text-xs text-muted-foreground shrink-0">{relativeTime(msg.timestamp)}</time>`
  - [ ] Remove or keep `formatTime()` - if removed, verify no other usages

- [ ] Task 4: Add scroll position tracking with `useRef`
  - [ ] **Important - orientation** : les messages sont PREPEND (`[msg, ...prev.messages]`) donc le plus récent est en **haut** du DOM. Quand l'utilisateur scrolle vers le bas, il voit les anciens messages. "Scrollé vers le bas" = `scrollTop > threshold`.
  - [ ] Add `containerRef = useRef<HTMLDivElement>(null)` to the feed component
  - [ ] Add `isScrolledDown = useRef(false)` (ref not state, to avoid re-renders)
  - [ ] Add `useEffect` that registers a `scroll` event listener on `containerRef.current`:
    - Compute `isDown = containerRef.current.scrollTop > 50`
    - Update `isScrolledDown.current = isDown`
  - [ ] Clean up listener on unmount

- [ ] Task 5: Track new messages counter and show floating pill
  - [ ] Add `newCount` state (number, initialized to 0)
  - [ ] Dans le handler SSE (quand un nouveau message arrive), vérifier `isScrolledDown.current`: si true (utilisateur regarde les anciens messages), incrémenter `newCount`
  - [ ] Rendre le floating pill quand `newCount > 0` - la flèche est **↑** (nouveaux messages sont EN HAUT) :
    ```tsx
    <div
      className="absolute top-3 left-1/2 -translate-x-1/2 cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-text shadow"
      onClick={scrollToTop}
    >
      {newCount} nouveau{newCount > 1 ? "x" : ""} message{newCount > 1 ? "s" : ""} ↑
    </div>
    ```
  - [ ] `scrollToTop` function: `if (containerRef.current) containerRef.current.scrollTop = 0; setNewCount(0)`
  - [ ] Positionner le pill en `top-3` (pas `bottom-3`) car les nouveaux messages arrivent en haut
  - [ ] Apply `relative` to the container div so `absolute` positioning of the pill works

- [ ] Task 6: Auto-scroll to top when user is not scrolled down
  - [ ] Après chaque nouveau message, si `isScrolledDown.current === false` (utilisateur est en haut, voit les messages récents) → appeler `scrollToTop()` pour maintenir la vue sur les nouveaux messages
  - [ ] Au premier message (initial mount) : `scrollTop = 0` est déjà la position par défaut - rien à faire

- [ ] Task 7: Add 5 skeleton rows for initial connecting state
  - [ ] Cibler `state.kind === "loading"` (ligne ~141) - c'est l'état pendant le fetch du token/hub, avant que l'EventSource soit créé. Remplacer le texte "Connexion au feed en direct…" par les 5 ghost rows.
  - [ ] Ne PAS cibler `state.kind === "active" && messages.length === 0` - ce cas affiche "Les messages apparaîtront en direct" et doit rester (feed connecté, silence normal).
  - [ ] Rendre 5 skeleton rows quand `state.kind === "loading"` :
    ```tsx
    <div aria-hidden="true" className="flex items-center gap-3 px-4 py-3 border-l-4 border-surface-2">
      <div className="h-4 w-16 animate-pulse rounded-full bg-surface-2" />
      <div className="h-3 w-3/4 animate-pulse rounded bg-surface-2" />
    </div>
    ```
  - [ ] Add `<span className="sr-only">Chargement du feed…</span>` outside the aria-hidden skeleton container
  - [ ] Do NOT show "Aucun message" text while `isConnecting` is true

## Dev Notes

**Primary file:** `frontend/src/features/events/event-feed.tsx`

- File is 224 lines. `FeedMessage` type at top: `{ id, type, text, timestamp }` (verify field names).
- `TYPE_LABELS` map (~line 15) and `TYPE_CLASSES` map (~line 25): use existing keys as basis for `TYPE_BORDER_CLASSES` and `TYPE_ICONS`.
- `TypeBadge` function component (~line 40): currently renders `<span className={...}>{TYPE_LABELS[type]}</span>`. Extend to include icon.
- `formatTime()` function (~line 180): currently returns `HH:MM`. Replace usages with `relativeTime()`.
- The messages array: stored newest-first (`[msg, ...prev.messages].slice(0, 100)` - newest à l'index 0, rendu en haut du DOM). Les fonctions `scrollToBottom`/`scrollToTop` dans les tasks utilisent `scrollTop = 0` (aller au haut = voir les nouveaux messages).
- EventSource `readyState`: available on the `EventSource` instance. Access it via a `ref` stored when creating the EventSource, or track state changes.
- Container `ref` attachment: apply `ref={containerRef}` to the outer scrollable div (check which div has `overflow-y-auto` or `overflow-auto`).
- Lucide icons to import: `Lightbulb`, `Gift`, `MapPin`, `Info`, `MessageSquare`. Check existing imports to avoid duplicates.
- Tailwind 4 `border-l-4`: this is a standard Tailwind utility - should work. If not, use `border-l-[4px]`.
- The `accent-text` CSS variable: mapped to `text-accent-text` Tailwind class (verify in globals.css that `--color-accent-text` is defined).
- **Direction confirmée** (ligne 79 du code) : `[msg, ...prev.messages]` → newest en **haut** du DOM. "Utilisateur a scrollé vers les anciens messages" = `scrollTop > 50`. Nouvelles arrivées en haut → pill ↑ avec `scrollTop = 0`.
- `relativeTime()` ne se met pas à jour automatiquement - recalculé seulement au render provoqué par un nouveau message. C'est intentionnel et acceptable. Ajouter un commentaire dans le code pour éviter qu'on rajoute un `setInterval` inutile.

## Dev Agent Record

### Implementation Plan
_To be filled during implementation._

### Debug Log
_Issues encountered and resolutions._

### Completion Notes
_Summary of what was implemented and tested._

## File List

- `frontend/src/features/events/event-feed.tsx`

## Change Log

| Date | Change | Author |
|------|--------|--------|
