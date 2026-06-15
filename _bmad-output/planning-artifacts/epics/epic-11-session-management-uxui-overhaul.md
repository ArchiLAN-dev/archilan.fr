# Epic 11: Session Management UX/UI Overhaul

Admins experience a streamlined session pipeline - fewer manual steps, animated visual pipeline bar, gaming-aesthetic status cards, and a polished terminal for commands/logs. Players see an informative waiting state, prominent connection info, animated progress cards, and a richly styled event feed. All changes are purely frontend: no API contract changes, no new dependencies, Tailwind CSS 4 animations only.

## Requirements (UX Pass 2026-05-07)

**Functional Requirements:**
- FR-UX1: Admin can generate a session without a prior separate "Valider" click (validation is implicit)
- FR-UX2: Admin can generate AND launch a session in a single "Générer & Lancer" action
- FR-UX3: Session pipeline state is visualized as a horizontal step-bar with per-step animation
- FR-UX4: All loading states use skeleton loaders matching the real layout (no plain text spinners)
- FR-UX5: Session builder is a single inline step - no separate preflight screen
- FR-UX6: Session detail has a sticky action bar with per-button inline loading states
- FR-UX7: Status cards use colored glows and animated dots matching session state
- FR-UX8: Connection info displays with per-field copy and unified copy-all button
- FR-UX9: Admin commands panel renders as a terminal (dark bg, monospace, command history)
- FR-UX10: Logs panel renders as a terminal with auto-scroll and pulsing LIVE badge
- FR-UX11: Force-end dialog requires typing "FIN" to unlock the confirm button
- FR-UX12: Player page shows animated waiting state with auto-refresh countdown for unstarted sessions
- FR-UX13: Player page shows prominent running state card with success glow
- FR-UX14: Player connection info has per-field copy and copy-all functionality
- FR-UX15: Player progress cards have smooth animated bars, dynamic sort, and goal celebration
- FR-UX16: Event feed messages have colored left borders, icon badges, and relative timestamps
- FR-UX17: Event feed shows a floating new-message indicator when user has scrolled up

**Non-Functional Requirements:**
- NFR-UX1: Zero new npm dependencies - animations via Tailwind CSS 4 built-ins only
- NFR-UX2: All existing API calls and endpoint contracts remain unchanged
- NFR-UX3: All redesigned components are mobile-responsive (sm:/lg: breakpoints)
- NFR-UX4: Accessibility attributes (aria-label, aria-hidden, role) maintained throughout
- NFR-UX5: Only existing design tokens and CSS variables used (no hardcoded colors)

## Personal Runs Requirements (2026-05-12)

FR-PR1: Authenticated users can create a personal Archipelago run (title, game configuration) independently of any event.
FR-PR2: The system generates a private invitation link with an opaque token for each personal run.
FR-PR3: The run owner can share the invite link (copy-to-clipboard).
FR-PR4: An authenticated user can join a personal run via the invite link.
FR-PR5: The run owner can view and manage their personal runs (list, detail, cancel/delete).
FR-PR6: Once the run is active, participants see server connection details.
FR-PR7: The run owner can trigger the Archipelago server start for their personal run.

NFR-PR1: Invite token must be non-guessable (32+ random bytes, hex-encoded).
NFR-PR2: Personal runs must not appear in any public listing - accessible only via link or direct invite.
NFR-PR3: Personal run containers must be isolated from event-based session containers.

## Session Lifecycle Requirements (2026-05-12)

FR-IT1: The system tracks a last_activity_at timestamp per session, updated by the bridge on each check or item event.
FR-IT2: On inactivity timeout, the bridge kills the Archipelago process inside the container; the container itself and the bridge process remain alive.
FR-IT3: When the AP process is killed due to inactivity, the session transitions to status `idle` (not `completed` - the run is paused, not finished).
FR-IT4: After entering idle mode, the bridge opens a TCP listener on the AP port. The first incoming TCP connection triggers an automatic AP process restart (wake-on-connect). The connecting client receives a connection error, waits, and reconnects once the server is ready.
FR-IT5: Explicit owner-triggered restart via UI ("Reprendre") is also supported as a secondary trigger. It calls a bridge internal endpoint to launch the AP process directly.
FR-IT6: On restart (wake-on-connect or explicit), the Archipelago server reloads from the most recent save file available on disk in the container. The bridge also uploads a `.apsave` snapshot to MinIO as a safety net before stopping the AP process (used if the container is ever fully stopped or crashes).

NFR-IT1: Inactivity detection must run as a background Messenger worker or scheduled task - not on the HTTP request path.
NFR-IT2: Before killing the AP process on inactivity, the bridge must trigger an Archipelago `/save` command and wait for confirmation (or timeout) - ensuring the last game state is preserved.
NFR-IT3: The inactivity threshold must be configurable per-environment via ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS.
NFR-IT4: The TCP listener used for wake-on-connect must be lightweight (single-threaded accept loop) and must not interfere with bridge heartbeat or REST API loops.

---

## Story 11.1: Merge Admin Validation + Generation Steps

As an admin,
I want to generate an Archipelago session with a single click (and optionally chain into launch),
So that I don't have to click "Valider" separately before generating.

**Acceptance Criteria:**

**Given** a session in `draft` or `ready` state
**When** the admin clicks "Générer"
**Then** the system automatically runs validation before generation (no separate "Valider" button required)
**And** if validation errors exist, they are displayed per-slot inline and the pipeline stops

**Given** all slot validations pass
**When** the "Générer" action is triggered
**Then** the session transitions through `validating → ready → generating` automatically without additional user interaction
**And** the pipeline bar reflects each intermediate state in real time via SSE

**Given** a session in `draft` or `ready` state
**When** the admin clicks "Générer & Lancer"
**Then** the system automatically validates, generates, and launches without any additional clicks
**And** the session reaches `running` state if no errors occur at any stage

**Given** a session that has been successfully generated (`generated` state)
**When** the admin views the action bar
**Then** a standalone "Lancer" button is available for manual launch control

**Given** validation errors are returned from the runner
**When** they are displayed in the UI
**Then** each slot name and its list of errors are shown inline, blocking any further progression

---

## Story 11.2: Visual Session Pipeline Bar

As an admin or player,
I want to see the session progress as a visual pipeline bar,
So that I immediately understand which phase the session is in without reading a raw status string.

**Acceptance Criteria:**

**Given** any session detail view
**When** the page renders
**Then** a horizontal pipeline bar displays four labeled steps: Créée · Validée · Générée · En ligne
**And** completed steps show a CheckCircle icon in `text-success`
**And** future steps render in `text-muted-foreground`

**Given** the session is in a transitional state (validating, generating, or launching)
**When** the pipeline bar renders
**Then** the active step node pulses with `animate-pulse` and a glow in `text-accent-warm` color
**And** a Loader2 spin icon (`animate-spin`) appears on the active step node

**Given** the session is in `running` state
**When** the pipeline bar renders
**Then** all four steps display CheckCircle icons in `text-success`
**And** the "En ligne" step shows a `animate-pulse rounded-full bg-success` dot alongside it

**Given** the session is in `failed` or `crashed` state
**When** the pipeline bar renders
**Then** the step at which failure occurred is highlighted with `text-danger` and an XCircle icon
**And** all preceding completed steps remain in `text-success`

**Given** a mobile viewport (< 640px)
**When** the pipeline bar renders
**Then** it remains single-row with abbreviated labels and no horizontal overflow or wrapping

**Given** the sessions list page
**When** each session row renders
**Then** a mini three-dot pipeline indicator shows the state: muted (draft), accent-warm animated (in progress), success (running/finished), danger (failed/crashed)

---

## Story 11.3: Skeleton Loaders for All Loading Zones

As a user (admin or player),
I want to see skeleton placeholders that match the real layout during loading,
So that the interface feels fast and does not shift layout when data arrives.

**Acceptance Criteria:**

**Given** the session builder is loading registrations from the API
**When** the fetch is in flight
**Then** a 3-row skeleton table renders with `animate-pulse` matching the actual table columns (player name, game, slot name input shape)
**And** no text such as "Chargement…" appears

**Given** the session detail page is loading
**When** the fetch is in flight
**Then** a skeleton of the pipeline bar renders (4 ghost nodes connected by lines with pulse)
**And** ghost shapes for the action buttons render below in the correct positions

**Given** the player session connection page is loading
**When** the fetch is in flight
**Then** a skeleton of the connection card renders with 3 ghost rows (label + value ghost shape)
**And** no plain text loading message appears

**Given** the player progress grid is awaiting initial data
**When** the fetch is in flight
**Then** 3 skeleton player cards render in the correct grid layout (sm:2, lg:3 columns)
**And** each card ghost shows a ghost badge, ghost progress bar, and ghost stats row

**Given** the event feed is connecting before the first message
**When** the EventSource is establishing
**Then** 5 skeleton message rows render with a ghost type-badge pill and a ghost text line

**Given** any primary action button is in a loading/pending state
**When** the button is active
**Then** it shows an inline Loader2 `animate-spin` icon, is `disabled`, and maintains its original dimensions

---

## Story 11.4: Inline Session Builder Wizard

As an admin,
I want to create a session from a single inline form without a separate preflight step,
So that I can move from registrations to session creation in fewer interactions.

**Acceptance Criteria:**

**Given** the admin opens the session builder for an event with confirmed registrations
**When** builder data loads
**Then** confirmed registrations are displayed in a single table with an editable slot name input per row
**And** there is no separate "preflight" step or wizard screen transition

**Given** the admin types in a slot name input
**When** the value changes
**Then** a character counter "(X/16)" appears beside the input in real time
**And** if the value exceeds 16 characters, the input border turns `border-danger` and "Maximum 16 caractères" error appears below
**And** if the value duplicates another slot name, "Nom déjà utilisé" error appears below

**Given** all slot names are valid (length ≤ 16, all unique, all non-empty)
**When** the admin clicks "Créer & Générer"
**Then** the session is created via the API and the Story 11.1 generation flow begins immediately
**And** the create button shows an inline spinner during the API call

**Given** the event has no confirmed registrations
**When** the builder renders
**Then** an empty state displays with a Users icon, "Aucune inscription confirmée" heading, and a link to the event registrations list

**Given** the admin wants to reset slot names
**When** they click "Régénérer les noms" in the table header
**Then** all slot name inputs reset to auto-generated values using the existing SlotNameGenerator algorithm
**And** real-time validation runs immediately on the regenerated values

---

## Story 11.5: Admin Session Detail Visual Overhaul

As an admin,
I want a visually polished session detail panel with a sticky action bar, gaming-aesthetic status card, and terminal-style command and log panels,
So that managing a live run feels professional and immersive.

**Acceptance Criteria:**

**Given** the session detail view
**When** it renders
**Then** a sticky action bar at the top displays contextually appropriate buttons based on current status:
- draft/ready: "Générer & Lancer", "Générer"
- generated: "Lancer", "Générer & Lancer"
- running: "Arrêter", "Forcer la fin"
- crashed: "Relancer"

**Given** an action button is clicked
**When** the API call is in progress
**Then** the button shows an inline Loader2 `animate-spin` icon, is `disabled`, and does not change width/height

**Given** the session status card in `running` state
**When** it renders
**Then** the card uses `card-glow border-success/40 bg-success/10` classes
**And** a `animate-pulse rounded-full bg-success size-2.5` dot appears next to the "EN LIGNE" label

**Given** the session status card in `crashed` or `failed` state
**When** it renders
**Then** the card uses `border-danger/40 bg-danger/10` classes and `text-danger` for the status label

**Given** the session is `running` and connection info is displayed
**When** the admin clicks "Tout copier"
**Then** the clipboard receives the string "Adresse: {host}:{port} | Mot de passe: {password}"
**And** the button label briefly changes to "Copié !" with a CheckCircle icon as confirmation

**Given** the admin commands panel
**When** it renders
**Then** the container uses `bg-bg` (or equivalent darkest surface), `font-mono`, `text-success/80` text
**And** previously sent commands are shown as history lines above the input
**And** the text input is auto-focused on mount and clears after submission

**Given** the logs panel is expanded and actively polling
**When** new log content is received
**Then** the panel scrolls to the bottom automatically
**And** a pulsing "LIVE" badge (`animate-pulse`) is visible in the panel header during active polling

**Given** the admin clicks "Forcer la fin"
**When** the confirmation dialog opens
**Then** a danger-colored AlertTriangle icon is shown at the top
**And** a text input requires the user to type "FIN" (case-insensitive match)
**And** the confirm button remains `disabled` until the input matches
**And** on confirm, the force-end API call is dispatched

---

## Story 11.6: Player Session Page Visual Overhaul

As a player,
I want a visually informative session page with a clear waiting state and prominent connection info when the run is live,
So that I always know what is happening and can connect quickly.

**Acceptance Criteria:**

**Given** the session is not yet in `running` state (any of: draft, validating, ready, generating, generated, launching)
**When** the player views the session page
**Then** a centered card displays with an animated Clock icon (`animate-spin` or `animate-pulse`)
**And** the heading "La run démarre bientôt" is shown
**And** a countdown label "Vérification dans Xs…" counts down from 30 to 0 then auto-refreshes session data

**Given** the session is in `running` state
**When** the player views the session page
**Then** the connection card uses `card-glow bg-success/5 border-success/40` classes
**And** a pulsing "EN LIGNE" badge with `animate-pulse rounded-full bg-success` dot is prominent in the card header

**Given** the session is `running` and connection info is shown
**When** the player views the connection section
**Then** Adresse, Port, and Mot de passe are displayed as 3 distinct labeled zones
**And** each zone has an individual copy button with `aria-label`
**And** a "Tout copier" button copies the full connection string

**Given** the session page is loading
**When** the fetch is in flight
**Then** skeleton loaders render per Story 11.3 (no plain text "Chargement…" message)

**Given** the session is `stopped` or `finished`
**When** the player views the page
**Then** a muted card with a static "La run est terminée" message renders
**And** no running glow, pulsing badge, or animated elements appear

---

## Story 11.7: Player Progress Grid Redesign

As a player or admin,
I want an animated, dynamically sorted progress grid with goal celebration visuals,
So that the run state is exciting and immediately readable.

**Acceptance Criteria:**

**Given** any slot with `client_status = 20` (En jeu)
**When** its card renders
**Then** the status badge includes a `animate-pulse` dot (`rounded-full bg-blue-500`) alongside "En jeu"

**Given** any slot with `client_status = 30` (Objectif atteint)
**When** its card renders
**Then** the card uses `border-success ring-2 ring-success/30 bg-success/5` classes
**And** a CheckCircle2 or Trophy icon is displayed in `text-success`
**And** the `goal_reached_at` timestamp is shown as a small muted label below the slot name

**Given** `checks_done` or `checks_total` changes for a slot
**When** the progress bar re-renders
**Then** the bar width updates with `transition-[width] duration-500 ease-in-out` (no instant jump)

**Given** the grid header
**When** the grid renders
**Then** "X/Y objectifs atteints" counter is shown with a global progress bar below it

**Given** the slots array is rendered
**When** the grid renders
**Then** the order is: `client_status=30` first (sorted by `goal_reached_at` asc), then `client_status=20` (sorted by `checks_done` desc), then all others

**Given** the EventSource connection drops (after grace period)
**When** disconnection is detected
**Then** a WifiOff icon and "Reconnexion…" label appear in the grid header
**And** existing player cards remain visible with their last-known data (no empty state shown)

**Given** the grid is loading initial data
**When** the fetch is in flight
**Then** skeleton cards render in the correct column grid (Story 11.3)

---

## Story 11.8: Event Feed Redesign

As a player or admin,
I want a richly styled event feed with colored message borders, icon badges, relative timestamps, and a new-message indicator,
So that the run activity is visually distinct and easy to follow.

**Acceptance Criteria:**

**Given** any feed message
**When** it renders
**Then** a 3px left border displays in a type-specific color:
- hint → `border-l-4 border-amber-500`
- item-received → `border-l-4 border-teal-500`
- location-checked → `border-l-4 border-blue-500`
- system → `border-l-4 border-border`
- chat → `border-l-4 border-foreground`

**Given** the message type badge
**When** it renders
**Then** it is a rounded pill with an inline icon:
- item-received → Gift icon
- location-checked → MapPin icon
- hint → Lightbulb icon
- chat → MessageSquare icon
- system → Info icon

**Given** a message timestamp
**When** it renders
**Then** it shows a relative label ("il y a 2 min", "à l'instant")
**And** the exact ISO timestamp is accessible via the element `title` attribute (tooltip on hover)

**Given** the user has scrolled up in the feed (not at the bottom)
**When** one or more new messages arrive
**Then** a floating pill at the bottom of the feed container shows "N nouveau(x) message(s) ↓"
**And** clicking the pill scrolls the container to the bottom and hides the pill

**Given** the user is at the bottom of the feed
**When** new messages arrive
**Then** the feed auto-scrolls to reveal the new messages
**And** the floating pill is not shown

**Given** the feed is loading before the first message arrives
**When** the EventSource is connecting
**Then** 5 skeleton message rows render with a ghost type-badge pill and a ghost text line (Story 11.3)
