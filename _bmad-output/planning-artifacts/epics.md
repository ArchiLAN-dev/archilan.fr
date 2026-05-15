---
stepsCompleted: ["step-01-validate-prerequisites", "step-02-design-epics", "step-03-create-stories", "step-04-final-validation", "step-01-validate-prerequisites-archipelago-run", "step-02-design-epics-archipelago-run", "step-03-create-stories-archipelago-run", "step-04-final-validation-archipelago-run", "step-01-validate-prerequisites-ux-sessions", "step-02-design-epics-ux-sessions", "step-03-create-stories-ux-sessions", "step-04-final-validation-ux-sessions", "step-01-validate-prerequisites-personal-runs", "step-02-design-epics-personal-runs", "step-03-create-stories-personal-runs", "step-04-final-validation-personal-runs", "step-amendment-wake-on-connect"]
inputDocuments:
  - "_bmad-output/planning-artifacts/prd.md"
  - "_bmad-output/planning-artifacts/architecture.md"
  - "_bmad-output/planning-artifacts/ux-design-specification.md"
workflowType: "epics-and-stories"
project_name: "archilan.fr"
user_name: "Jean"
date: "2026-04-24"
lastStep: 4
status: "complete"
completedAt: "2026-04-24"
archipelagoRunPass:
  date: "2026-05-05"
  scope: "Revise Epic 9 stories 9.3-9.6 for new architecture + add stories 9.11-9.16"
  newRequirements:
    - FR-R1 through FR-R23
    - NFR-R1 through NFR-R6
uxSessionsPass:
  date: "2026-05-07"
  scope: "Epic 11 - Session UX/UI overhaul: pipeline bar, skeleton loaders, merged admin steps, gaming aesthetic"
  newRequirements:
    - FR-UX1 through FR-UX17
    - NFR-UX1 through NFR-UX5
personalRunsPass:
  date: "2026-05-12"
  scope: "Epic 16 - User personal runs (private Archipelago games outside events, invite link) + Epic 17 - Session lifecycle (inactivity timeout, graceful stop, restart from save)"
  newRequirements:
    - FR-PR1 through FR-PR7
    - NFR-PR1 through NFR-PR3
    - FR-IT1 through FR-IT5
    - NFR-IT1 through NFR-IT3
wakeOnConnectAmendment:
  date: "2026-05-13"
  scope: "Amend Epic 17 - Option A architecture: kill AP process (not container), bridge TCP listener wake-on-connect. Stories 17.2, 17.3, 17.4 revised; Story 17.5 added."
  newRequirements:
    - FR-IT6 (added)
    - NFR-IT4 (added)
    - FR-IT2, FR-IT4, FR-IT5 revised
historyLeaderboardsPass:
  date: "2026-05-13"
  scope: "Epic 18 - Run history, player profiles, community leaderboards + slot invalidation rule for released/forfeited players"
  newRequirements:
    - FR-HC1 through FR-HC9
    - NFR-HC1 through NFR-HC4
codeQualityPass:
  date: "2026-05-14"
  scope: "Epic 19 - Code quality: centralize test entity factories, extract controller auth guard, application service entity resolution, DBAL pagination helper, message handler error pattern"
  newRequirements:
    - NFR-CQ1 through NFR-CQ5
---

# archilan.fr - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for archilan.fr, decomposing the requirements from the PRD, UX Design, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: Visitors can view the association's identity, mission, and an explanation of Archipelago on the landing page.
FR2: Visitors can browse past event listings with recaps and key statistics.
FR3: Visitors can browse upcoming event listings with dates, type, and availability status.
FR4: Visitors can watch an embedded live Twitch stream when a broadcast is active.
FR5: Visitors can access a link to the ArchiLAN Twitch channel when no stream is active.
FR6: Visitors can access the official global Archipelago Discord from the landing page.
FR7: Visitors can read news posts, event recaps, and association announcements in the content section.
FR8: Admins can create, edit, publish, and unpublish news posts and event recaps.
FR9: Visitors can share event and news pages with correct title, description, and image previews on social media and Discord.
FR10: Admins can create events with title, description, type, dates, venue, capacity, registration window dates, and public/private access flag.
FR11: Admins can save events as drafts before publishing.
FR12: Admins can publish events, making them immediately visible on the public listing.
FR13: Admins can configure game selection intake per event, including enable/disable, available games, and visible options.
FR14: Admins can set and update a password for private events.
FR15: Admins can transition event status through draft, published, in-progress, and completed.
FR16: Admins can view all registrations for an event, including each participant's game selections.
FR17: Admins can export participant and game selection data for a given event.
FR18: Admins can cancel or modify individual participant registrations.
FR19: Admins can manage the association's Archipelago game library, including games and configurable randomizer options.
FR20: Admins can attach a recap article or VOD link to a completed event.
FR21: Authenticated users can register for public events during the open registration window.
FR22: Authenticated users can register for private events by providing the correct event password.
FR23: Registrants can select one or more games from the event's Archipelago game library during registration.
FR24: Registrants can configure key randomizer options for each selected game using plain-language descriptions.
FR25: Registrants can view and update their game selections before the registration window closes.
FR26: Registrants can cancel their own event registration.
FR27: The system prevents registration when an event has reached its declared capacity.
FR28: The event registration page displays remaining seat count updated in real time.
FR29: Visitors can create a lambda user account with email and password.
FR30: Authenticated users can view and edit their profile information.
FR31: Authenticated users can delete their account and all associated personal data.
FR32: Admins can view, search, and filter all user accounts.
FR33: Admins can promote a lambda user to membre.
FR34: Admins can demote a membre to lambda user.
FR35: Admins can create other admin accounts.
FR36: The system enforces role-based access: lambda users restricted from backoffice; membres can access member-only events; admins have full backoffice access.
FR37: Authenticated users can exercise RGPD rights through their account or a dedicated contact process.
FR38: Visitors can purchase event tickets via embedded HelloAsso checkout without leaving the site.
FR39: Visitors can pay association membership fees via embedded HelloAsso checkout.
FR40: Visitors can browse and purchase merchandise via embedded HelloAsso boutique checkout.
FR41: The system automatically syncs HelloAsso order and member data into the internal ERP.
FR42: Admins can view HelloAsso payment status associated with event registrations.
FR43: Event pages display remaining seat count updated in real time without page reload.
FR44: The site automatically shows the Twitch embed when a stream is live and a channel link when it is not, without user action.
FR45: The admin backoffice registration dashboard updates in real time as new registrations arrive.
FR46: Registrants receive a confirmation email upon successful registration, including event details and next steps.
FR47: Admins receive a notification when an event reaches capacity.
FR48: Admins can send a message to individual registrants from the backoffice.
FR49: The site displays a Mentions Legales page linked from the footer on every page.
FR50: The site displays a Politique de Confidentialite page linked from the footer on every page.
FR51: The site presents CGV before any transactional action.
FR52: The site presents CGU during account creation.
FR53: The site displays a cookie consent banner on first visit and respects the user's consent choices.
FR54: Users can withdraw or update cookie consent at any time from a persistent footer control.

### NonFunctional Requirements

NFR1: Public pages must meet LCP < 2.5s, CLS < 0.1, and INP < 200ms on desktop and mobile.
NFR2: Symfony public API endpoints must meet p95 < 200ms.
NFR3: Symfony authenticated API endpoints must meet p95 < 500ms.
NFR4: SSE seat counter propagation must reflect new registrations within 1 second.
NFR5: Twitch embed must be lazy-loaded with zero initial page render impact.
NFR6: Registration form submission must return confirmation or error within 3 seconds.
NFR7: HTTPS must be enforced site-wide, with HTTP redirect and HSTS.
NFR8: Passwords must be hashed with Argon2id and never stored or logged in plain text.
NFR9: Authentication tokens must use httpOnly, Secure, SameSite cookies and never localStorage.
NFR10: RBAC must be enforced at the Symfony API layer on every endpoint.
NFR11: Registration capacity check must be atomic server-side.
NFR12: CSRF protection must apply to all state-changing forms and API mutations.
NFR13: Symfony API CORS must be restricted to the Next.js origin only.
NFR14: Credentials and API keys must not appear in frontend bundles or client-accessible env vars.
NFR15: All user input must be validated and sanitized server-side before persistence.
NFR16: The system must handle 50+ simultaneous registration attempts without data corruption.
NFR17: SSE connections must scale proportionally to active users without resource exhaustion.
NFR18: Symfony API must be stateless and horizontally scalable without session affinity.
NFR19: Database schema must support thousands of users and hundreds of events without structural rework.
NFR20: Domain services must be isolated from delivery layer so v3 multiworld service can be added without touching v1 logic.
NFR21: Public-facing pages must meet WCAG 2.1 AA.
NFR22: Text/background combinations must meet AA contrast ratios.
NFR23: Interactive elements must be keyboard-navigable with visible focus indicators.
NFR24: Form fields must have associated labels and programmatically linked errors.
NFR25: Twitch embed must provide fallback text/link for users unable to interact with iframe.
NFR26: HelloAsso downtime must degrade gracefully without broken pages or silent failures.
NFR27: HelloAsso sync failures must auto-retry and notify admins if persistent, without blocking registration by sync delay.
NFR28: Email delivery failure must be logged and flagged to admins without rolling back registration records.
NFR29: SSE drops must fall back to 30-second polling.
NFR30: Twitch API downtime must fall back to a channel link.
NFR31: Site availability target is 99.5% excluding scheduled maintenance.
NFR32: Registration must complete fully or roll back entirely, with no partial states.
NFR33: No registration or payment data may be lost due to server errors.
NFR34: Scheduled maintenance must occur outside active registration windows.

### Additional Requirements

- Epic 0 setup must initialize the Next.js frontend in `frontend/` and Symfony 7.4 LTS API in `api/` before any business code.
- The monorepo must keep `frontend/` and `api/` independently buildable and deployable.
- Backend must use PostgreSQL 18, Doctrine ORM, and Doctrine Migrations.
- Backend must expose a REST API under `/api/v1`; GraphQL is out of scope for v1.
- API Platform is deferred unless a future architecture update approves it.
- Authentication must use Symfony Security with LexikJWTAuthenticationBundle and httpOnly cookies.
- Backend must use bounded contexts: Identity, Events, Registrations, GameSelection, Content, Payments, Realtime, Communications, Legal, and Shared.
- Controllers must remain thin; business rules must live in domain/application services.
- API responses must use `{ data, meta }` for success and `{ error: { code, message, details } }` for errors.
- JSON exposed to the frontend must use camelCase; database fields must use snake_case.
- Domain events must use past-tense names and must not expose Doctrine entities.
- Messenger must handle async jobs such as emails, HelloAsso retries, and notifications.
- Mercure/SSE must support public seat counters, admin registration feed, and optional Twitch live state, with polling fallback.
- PostgreSQL row-level locking or an equivalent transactional service must enforce event capacity.
- Next.js public pages must use SSR/SSG/ISR where appropriate for SEO and performance.
- TanStack Query must own frontend server state; no global frontend store unless architecture is updated.
- HelloAsso must be integrated through backend adapters and BFF proxy only where needed; the browser must not own sensitive API logic.
- Twitch iframe must be gated by cookie consent and lazy-loaded.
- CI must run backend Composer validation, PHPStan, PHP CS Fixer, PHPUnit, frontend install, ESLint, TypeScript check, tests, and build.
- Architecture deviations must be documented before implementation.
- Existing markdown planning artifacts show encoding corruption in some rendered characters; publication-quality docs should be normalized to UTF-8.

### UX Design Requirements

UX-DR1: Implement design tokens for colors extracted from the ArchiLAN logo: deep navy background, lifted surfaces, cyan accent, amber warm accent, danger red, magenta special badge, off-white text, muted slate text, and success teal-green.
UX-DR2: Implement typography with Space Grotesk for headings/display and Inter for body/UI, including the documented type scale.
UX-DR3: Use Tailwind's 4px-based spacing scale with public max-width 1280px and backoffice max-width 1440px.
UX-DR4: Restrict border radius to sharp technical values, with no rounded-xl or rounded-2xl on content surfaces.
UX-DR5: Implement focus rings as 2px solid accent with 2px offset across all interactive elements.
UX-DR6: Implement shadcn/ui primitives themed through the project design tokens, not default theme values.
UX-DR7: Build GameCard with selectable states, disabled and limit-reached states, keyboard support, and checkbox semantics.
UX-DR8: Build GameOptionPanel for per-game randomizer options with base options, advanced accordion, mobile Sheet variant, and incomplete state.
UX-DR9: Build WorldSummaryPanel with desktop sidebar and mobile sticky bar variants, live summary updates, and empty/in-progress/complete states.
UX-DR10: Build RegistrationProgressIndicator for the 3-step registration flow with current, complete, upcoming, and error states.
UX-DR11: Build SeatCounter with available, low, full, and disconnected states, progress bar, live update indicator, and aria-live support.
UX-DR12: Build EventCard with open, upcoming, full, completed, and members-only states.
UX-DR13: Build RegistrationFeedItem for admin realtime monitoring with new highlight, password-access, cancelled, and action dropdown states.
UX-DR14: Build LiveTwitchBadge with live, offline, loading, and error states.
UX-DR15: Build PasswordAccessGate for private events using disclosure, password input, success, and inline error handling.
UX-DR16: Build CapacityFullScreen as a graceful capacity-full terminal state.
UX-DR17: Build ArchipelagoExplainerBlock to deliver the first-scroll "aha moment" for newcomers.
UX-DR18: Enforce one primary action per screen or section; secondary, tertiary, and destructive actions must follow the documented hierarchy.
UX-DR19: Implement immediate, specific, actionable feedback; generic error text is not acceptable.
UX-DR20: Implement toast notifications with one visible toast at a time and position/duration rules from UX spec.
UX-DR21: Implement inline validation on blur, never relying on color alone.
UX-DR22: Implement multi-step forms with persistent progress indicator, independent step validation, preserved back navigation, and motion respecting reduced-motion preferences.
UX-DR23: Implement progressive disclosure for advanced randomizer options with stable layout and accessible accordion behavior.
UX-DR24: Implement mobile form layout with full-width fields, sticky CTA bar, and keyboard-aware scrolling.
UX-DR25: Implement public navigation with sticky top bar, active link styling, compressed scroll behavior, and full-screen mobile overlay.
UX-DR26: Implement backoffice navigation with desktop sidebar, tablet icon-only sidebar, and mobile bottom tab bar.
UX-DR27: Implement defined empty states for public event list, admin event list, registration feed, game library grid, and user search.
UX-DR28: Implement skeleton loading states that match final layout and avoid layout shift.
UX-DR29: Implement scoped button spinners for submissions and avoid full-page spinners.
UX-DR30: Implement optimistic updates for game selection and admin role promotion with rollback on API error.
UX-DR31: Implement SSE disconnect, reconnect, stale-data, seat-counter, registration-feed, and Twitch-badge realtime UX patterns.
UX-DR32: Implement destructive confirmation flows through AlertDialog for account deletion, registration cancellation by admin, event unpublishing, and role demotion.
UX-DR33: Implement non-destructive Dialog and mobile Sheet patterns with escape/overlay/explicit close behavior and no nested dialogs.
UX-DR34: Implement responsive layouts for landing page, event grid, registration wizard, game library grid, GameOptionPanel, WorldSummaryPanel, backoffice nav, backoffice tables, and navigation bar.
UX-DR35: Implement WCAG 2.1 AA accessibility: contrast, keyboard navigation, skip link, semantic landmarks, labels, linked errors, no keyboard traps, and no flashing content.
UX-DR36: Implement component-specific accessibility for GameCard, game selection grid, WorldSummaryPanel, SeatCounter, RegistrationFeedItem, and Twitch badge.
UX-DR37: Add automated accessibility support using axe-core in development and Lighthouse accessibility auditing in CI once frontend testing is configured.
UX-DR38: Add manual pre-launch validation coverage for keyboard navigation, screen readers, responsive devices, network throttling, and color-independent state recognition.

### FR Coverage Map

FR1: Epic 1 - Public association identity and Archipelago explanation.
FR2: Epic 1 - Past event listings with recaps and statistics.
FR3: Epic 1 - Upcoming event listings with dates, type, and availability.
FR4: Epic 7 - Embedded live Twitch stream.
FR5: Epic 1 - Twitch channel link when stream is offline.
FR6: Epic 1 - Official global Archipelago Discord link.
FR7: Epic 1 - News posts, event recaps, and association announcements.
FR8: Epic 1 - Admin creation, editing, publication, and unpublication of public content.
FR9: Epic 1 - Social sharing metadata for events and news.
FR10: Epic 3 - Event creation with all required event fields.
FR11: Epic 3 - Draft event saving.
FR12: Epic 3 - Event publication to public listing.
FR13: Epic 3 - Per-event game selection intake configuration.
FR14: Epic 3 - Private event password configuration.
FR15: Epic 3 - Event lifecycle status transitions.
FR16: Epic 5 - Admin view of registrations and game selections.
FR17: Epic 5 - Participant and game selection export.
FR18: Epic 5 - Admin modification/cancellation of participant registrations.
FR19: Epic 3 - Archipelago game library administration.
FR20: Epic 3 - Recap article or VOD attachment to completed events.
FR21: Epic 4 - Public event registration during open windows.
FR22: Epic 4 - Private event registration with password.
FR23: Epic 4 - Game selection during registration.
FR24: Epic 4 - Plain-language randomizer option configuration.
FR25: Epic 4 - Registration game selection update before close.
FR26: Epic 4 - User self-cancellation of registration.
FR27: Epic 4 - Capacity enforcement.
FR28: Epic 4 - Remaining seat count on registration page.
FR29: Epic 2 - Lambda account creation.
FR30: Epic 2 - Authenticated profile viewing and editing.
FR31: Epic 2 - Account deletion and personal data removal.
FR32: Epic 2 - Admin user search/filter.
FR33: Epic 2 - Lambda to membre promotion.
FR34: Epic 2 - Membre to lambda demotion.
FR35: Epic 2 - Admin account creation.
FR36: Epic 2 - Role-based access enforcement.
FR37: Epic 2 - RGPD rights process.
FR38: Epic 6 - Embedded HelloAsso event ticket checkout.
FR39: Epic 6 - Embedded HelloAsso membership fee checkout.
FR40: Epic 6 - Embedded HelloAsso boutique checkout.
FR41: Epic 6 - HelloAsso order/member sync into ERP.
FR42: Epic 6 - Admin payment status visibility.
FR43: Epic 7 - Realtime remaining seat count without reload.
FR44: Epic 7 - Automatic Twitch live/offline switching.
FR45: Epic 5 - Realtime admin registration dashboard.
FR46: Epic 4 - Registration confirmation email.
FR47: Epic 5 - Admin capacity notification.
FR48: Epic 5 - Admin message to individual registrants.
FR49: Epic 8 - Mentions Legales page.
FR50: Epic 8 - Privacy policy page.
FR51: Epic 8 - CGV before transactional actions.
FR52: Epic 8 - CGU during account creation.
FR53: Epic 8 - Cookie consent banner.
FR54: Epic 8 - Cookie consent withdrawal/update.
FR-PR1: Epic 16 - Personal run creation by authenticated user.
FR-PR2: Epic 16 - Private invite link generation (opaque token).
FR-PR3: Epic 16 - Invite link sharing (copy-to-clipboard).
FR-PR4: Epic 16 - Join personal run via invite link.
FR-PR5: Epic 16 - Personal run management (list, detail, cancel).
FR-PR6: Epic 16 - Connection details displayed to participants once run is active.
FR-PR7: Epic 16 - Owner-triggered server start for personal run.
FR-IT1: Epic 17 - Last-activity timestamp tracked per session.
FR-IT2: Epic 17 - On inactivity timeout, the Archipelago process is killed inside the container; the container and bridge remain alive.
FR-IT3: Epic 17 - Idle session status distinct from completed.
FR-IT4: Epic 17 - Idle session restarts automatically when the first TCP connection arrives on the AP port (wake-on-connect).
FR-IT5: Epic 17 - Explicit owner-triggered restart via UI also supported as secondary trigger.
FR-IT6: Epic 17 - On restart, the Archipelago server reloads from the last save file available in the container (MinIO backup as safety net).
FR-HC1: Epic 18 - Visitors can view a public run results page showing per-slot stats grouped by outcome (goal reached / incomplete / invalidated).
FR-HC2: Epic 18 - A slot's stats are invalidated when an admin or personal-run owner triggers release/collect on a slot without a reached goal. A completed slot can never be invalidated.
FR-HC3: Epic 18 - A `was_released` boolean column on `session_slots` is set atomically within the release/collect transaction.
FR-HC4: Epic 18 - Invalidated slots display a "Forfait" badge, are excluded from all leaderboard aggregations, but are still counted in `runsParticipated`.
FR-HC5: Epic 18 - Any visitor can view a public player profile. A new unique `slug` field is introduced on the `User` entity in this epic.
FR-HC6: Epic 18 - The player profile displays aggregated personal stats excluding invalidated slots from performance numerators.
FR-HC7: Epic 18 - A public community leaderboard with three axes (goal completions, checks done, fastest completion) and optional event filter.
FR-HC8: Epic 18 - A public global stats widget showing figures from sessions with status `finished`.
FR-HC9: Epic 18 - Stats and leaderboards use only sessions with status `finished`; all other statuses expose no public stats.

## Epic List

### Epic 0: Project Foundation & Quality Gates
Set up the monorepo, Next.js frontend, Symfony API, dependencies, CI, and quality gates so implementation can proceed in a coherent, repeatable state.
**FRs covered:** None directly; supports architecture requirements and quality NFRs.

### Epic 1: Public Community Hub & Archipelago Discovery
Visitors can understand ArchiLAN, discover Archipelago, browse public events and content, and access Twitch/Discord/community entry points.
**FRs covered:** FR1, FR2, FR3, FR5, FR6, FR7, FR8, FR9

### Epic 2: Accounts, Authentication & Role-Based Access
Visitors can create accounts, authenticated users can manage their profiles and data rights, and admins can manage users and roles.
**FRs covered:** FR29, FR30, FR31, FR32, FR33, FR34, FR35, FR36, FR37

### Epic 3: Event Lifecycle Backoffice
Admins can create, configure, publish, protect, manage, and complete events, including game library and game-selection setup.
**FRs covered:** FR10, FR11, FR12, FR13, FR14, FR15, FR19, FR20

### Epic 4: Event Registration & Archipelago Game Selection
Authenticated users can register for events, access private events, select and configure Archipelago games, update/cancel registrations, and receive confirmation.
**FRs covered:** FR21, FR22, FR23, FR24, FR25, FR26, FR27, FR28, FR46

### Epic 5: Admin Registration Operations
Admins can monitor registrations, inspect game selections, export data, handle capacity notifications, modify registrations, and contact participants.
**FRs covered:** FR16, FR17, FR18, FR45, FR47, FR48

### Epic 6: Payments, Ticketing & HelloAsso Sync
Visitors can use embedded HelloAsso checkout for tickets, memberships, and merchandise while admins see synchronized payment/order status in the ERP.
**FRs covered:** FR38, FR39, FR40, FR41, FR42

### Epic 7: Live Experience, Twitch & Realtime Presence
The site displays Twitch live/offline state and realtime activity signals, including live seat counters and resilient fallback behavior.
**FRs covered:** FR4, FR43, FR44

### Epic 8: Legal Compliance, Consent & Trust
The site satisfies required French legal/RGPD/CNIL surfaces, displays legal documents in the right flows, and manages cookie consent lifecycle.
**FRs covered:** FR49, FR50, FR51, FR52, FR53, FR54

### Epic 9: Archipelago Session Management
Admins can generate Archipelago multiworld sessions from confirmed event registrations, launch dedicated server containers automatically via Symfony Messenger workers on runner servers, and players receive real-time event feeds, progress dashboards, and connection details - fully automated, without manual file uploads. Run results are archived with statistics for post-event analysis.
**FRs covered:** New scope - not in original PRD. FR-R1–FR-R23, NFR-R1–NFR-R6 (Archipelago Run Generation architecture, 2026-05-05).

### Epic 11: Session Management UX/UI Overhaul
Admins experience a streamlined session pipeline - fewer manual steps, animated visual pipeline bar, gaming-aesthetic status cards, and a polished terminal for commands/logs. Players see an informative waiting state, prominent connection info, animated progress cards, and a richly styled event feed.
**FRs covered:** FR-UX1–FR-UX17, NFR-UX1–NFR-UX5 (UX sessions pass, 2026-05-07).

### Epic 13: Secure Token Lifecycle - Refresh Token
The authentication system is upgraded from a single long-lived JWT cookie to a short-lived access token + long-lived refresh token pair, both httpOnly Secure SameSite cookies. The API handles token rotation with reuse detection; the frontend silently refreshes expired sessions without user action.
**FRs covered:** NFR9 (strengthened), NFR18 (stateless refresh). New security scope.

### Epic 16: Personal Runs - Private User-Created Archipelago Games
Authenticated users can create private Archipelago runs outside of any ArchiLAN event, configure game worlds, invite friends via an opaque shareable link, and start the server - reusing the runner infrastructure from Epic 9.
**FRs covered:** FR-PR1, FR-PR2, FR-PR3, FR-PR4, FR-PR5, FR-PR6, FR-PR7, NFR-PR1, NFR-PR2, NFR-PR3.

### Epic 17: Session Lifecycle - Inactivity Timeout & Restart
Sessions (event-based and personal) auto-stop after 1 hour of inactivity, with graceful state save to MinIO, a distinct `idle` status, and a one-click restart that resumes from the saved state.
**FRs covered:** FR-IT1, FR-IT2, FR-IT3, FR-IT4, FR-IT5, NFR-IT1, NFR-IT2, NFR-IT3.

### Epic 18: Run History, Player Profiles & Community Leaderboards
Players and visitors can explore completed run results, personal history across all runs, and community-wide leaderboards. Slot stats are automatically invalidated when a player forfeits and their slot is released/collected, ensuring leaderboard integrity.
**FRs covered:** FR-HC1, FR-HC2, FR-HC3, FR-HC4, FR-HC5, FR-HC6, FR-HC7, FR-HC8, FR-HC9, NFR-HC1, NFR-HC2, NFR-HC3, NFR-HC4.

## Epic 0: Project Foundation & Quality Gates

Set up the monorepo, Next.js frontend, Symfony API, dependencies, CI, and quality gates so implementation can proceed in a coherent, repeatable state.

### Story 0.1: Initialize Monorepo Baseline

As a developer,
I want the repository baseline configured,
So that frontend and API setup can proceed in a predictable project structure.

**Acceptance Criteria:**

**Given** the existing repository contains BMAD planning artifacts
**When** the baseline setup is applied
**Then** the repository contains root-level `README.md`, `.editorconfig`, `.gitignore`, `.env.example`, and `docker-compose.yml` placeholders aligned with the architecture
**And** no business-domain code is introduced
**And** existing `_bmad`, `_bmad-output`, `.agents`, and `.claude` content is preserved

### Story 0.2: Initialize Next.js Frontend Starter

As a developer,
I want a Next.js frontend initialized in `frontend/`,
So that public pages and application UI can be built on the approved stack.

**Acceptance Criteria:**

**Given** the repository baseline exists
**When** the frontend starter is initialized
**Then** `frontend/` contains a Next.js App Router project with TypeScript, Tailwind, ESLint, `src/`, and `@/*` import alias
**And** shadcn/ui initialization is ready through `components.json`
**And** `next-themes` and `@tanstack/react-query` are installed
**And** `pnpm build`, lint, and type-check commands are available
**And** no public product UI beyond starter-safe placeholders is implemented

### Story 0.3: Initialize Symfony API Starter

As a developer,
I want a Symfony LTS API initialized in `api/`,
So that backend use cases can be implemented on the approved DDD/N-Tier stack.

**Acceptance Criteria:**

**Given** the repository baseline exists
**When** the Symfony API starter is initialized
**Then** `api/` contains a Symfony 7.4 LTS skeleton project
**And** required bundles are installed: ORM pack, security bundle, Lexik JWT auth bundle, serializer pack, Messenger, Mailer, PHPStan, PHP CS Fixer, and test pack
**And** `composer validate`, PHPUnit, PHPStan, and CS Fixer commands are available
**And** no business-domain code beyond starter-safe framework files is implemented

### Story 0.4: Establish Project Structure and DDD Boundaries

As a developer,
I want the approved frontend and backend directories created,
So that future stories place code consistently.

**Acceptance Criteria:**

**Given** frontend and API starters exist
**When** project structure is established
**Then** `frontend/src/app`, `frontend/src/features`, `frontend/src/components`, `frontend/src/lib`, `frontend/src/providers`, and `frontend/src/types` exist
**And** `api/src/Shared`, `Identity`, `Events`, `Registrations`, `GameSelection`, `Content`, `Payments`, `Realtime`, `Communications`, and `Legal` exist with intended DDD subdirectories
**And** placeholder files do not introduce business behavior
**And** architecture boundaries are documented in the local README or equivalent developer notes

### Story 0.5: Configure Quality Gates and CI

As a developer,
I want automated quality gates configured,
So that every implementation batch can be verified before handoff.

**Acceptance Criteria:**

**Given** frontend and API starters exist
**When** quality gates are configured
**Then** frontend CI runs install, lint, type-check, tests where available, and build
**And** backend CI runs Composer validation, PHPStan, PHP CS Fixer dry-run, and PHPUnit
**And** CI workflow files exist under `.github/workflows/`
**And** commands are documented for local execution
**And** the initial CI-equivalent local checks pass or any unavailable checks are explicitly documented

### Story 0.6: Configure Local Development Environment

As a developer,
I want local services and environment examples configured,
So that future stories can run consistently on developer machines.

**Acceptance Criteria:**

**Given** frontend and API starters exist
**When** local development config is added
**Then** root `docker-compose.yml` defines PostgreSQL and optional Mercure service placeholders
**And** `frontend/.env.example` and `api/.env.example` document required environment variables without secrets
**And** database connection defaults are development-safe
**And** setup instructions describe how to start frontend, API, and local services
**And** no production credentials or real API secrets are committed

## Epic 1: Public Community Hub & Archipelago Discovery

Visitors can understand ArchiLAN, discover Archipelago, browse public events and content, and access Twitch/Discord/community entry points.

### Story 1.1: Public Shell, Navigation and Design Tokens

As a visitor,
I want a polished public site shell with clear navigation,
So that I immediately understand the site is credible and can reach key public sections.

**Acceptance Criteria:**

**Given** the frontend starter exists
**When** the public shell is implemented
**Then** the site uses the approved ArchiLAN color tokens, typography, spacing, and focus ring patterns
**And** the public navigation contains links for events, news/content, Twitch, Discord, login/signup, and legal footer links
**And** the navigation is sticky, responsive, keyboard accessible, and includes a mobile full-screen menu
**And** public pages expose semantic landmarks and `lang="fr"`
**And** no authenticated-only backoffice navigation is visible to public visitors

### Story 1.2: Landing Page with ArchiLAN Identity and Archipelago Explainer

As a first-time visitor,
I want the landing page to explain ArchiLAN and Archipelago clearly,
So that I understand the community and want to participate.

**Acceptance Criteria:**

**Given** the public shell exists
**When** a visitor opens the landing page
**Then** they see ArchiLAN identity, mission, and association context
**And** they see an Archipelago explainer section designed for newcomers
**And** the explainer communicates the cross-game item mechanic visually or narratively before the end of the first scroll
**And** the page includes clear CTAs toward upcoming events, Twitch, and Discord
**And** the layout is mobile-first and remains readable at 375px width

### Story 1.3: Public Event Listings and Event Cards

As a visitor,
I want to browse upcoming and past events,
So that I can understand what ArchiLAN organizes and decide whether to join.

**Acceptance Criteria:**

**Given** event listing data is available from the API or mocked public endpoint
**When** a visitor opens the event listing or landing event section
**Then** upcoming events display title, type, date, location, availability state, and CTA
**And** past events display recap availability and key statistics where available
**And** EventCard supports open, upcoming, full, completed, and members-only states
**And** empty event lists show the documented empty-state copy and Twitch action
**And** event cards are responsive as 1-up mobile, 2-up tablet, and 3-up desktop

### Story 1.4: Event Detail Public Page with SEO Metadata

As a visitor,
I want a detailed public event page,
So that I can understand an event before registering or reading its recap.

**Acceptance Criteria:**

**Given** an event exists and is published
**When** a visitor opens its public detail page
**Then** the page shows event title, type, dates, location, description, availability, and appropriate CTA
**And** completed events can show recap and VOD links when available
**And** unpublished draft events are not publicly accessible
**And** the page includes Open Graph, Twitter Card, canonical metadata, and `schema.org/Event` where applicable
**And** invalid or missing event slugs return a proper not-found state

### Story 1.5: Public News and Recap Reading

As a visitor,
I want to browse and read public news posts and event recaps,
So that I can follow ArchiLAN activity outside registration windows.

**Acceptance Criteria:**

**Given** public content exists
**When** a visitor opens the news section
**Then** they can browse published posts with title, excerpt, publication date, and type
**And** they can open a post detail page with readable long-form content
**And** unpublished content is not publicly visible
**And** post pages include social sharing metadata
**And** the empty news state gives a clear path to Twitch or upcoming events

### Story 1.6: Admin Content Publishing for News and Recaps

As an admin,
I want to create, edit, publish, and unpublish news posts and recaps,
So that the public hub can stay active between events.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they use the content backoffice
**Then** they can create a draft post with title, slug, excerpt, body, type, and optional VOD/event association
**And** they can edit existing drafts or published posts
**And** they can publish and unpublish content
**And** public pages only show published content
**And** non-admin users cannot access content management endpoints or UI

### Story 1.7: Public Twitch and Discord Entry Points

As a visitor,
I want quick access to ArchiLAN Twitch and the official Archipelago Discord,
So that I can follow or join the broader community.

**Acceptance Criteria:**

**Given** the public shell and landing page exist
**When** a visitor browses public pages
**Then** Twitch and Discord entry points are visible in appropriate navigation or CTA areas
**And** Twitch shows a channel link when no live embed is active
**And** Discord links to the official global Archipelago Discord
**And** outbound links are accessible, clearly labeled, and open safely
**And** this story does not implement live Twitch detection, which belongs to Epic 7

## Epic 2: Accounts, Authentication & Role-Based Access

Visitors can create accounts, authenticated users can manage their profiles and data rights, and admins can manage users and roles.

### Story 2.1: Lambda Account Registration

As a visitor,
I want to create a lambda account with email and password,
So that I can register for public events.

**Acceptance Criteria:**

**Given** the public signup page is available
**When** a visitor submits a valid email and password
**Then** a lambda user account is created with no member/admin privileges
**And** the password is hashed with the configured secure hasher
**And** duplicate email registration is rejected with a field-level error
**And** CGU acceptance is required during account creation
**And** the signup form has labels, linked validation errors, and keyboard support

### Story 2.2: Login, Logout and Authenticated Session

As a registered user,
I want to log in and out securely,
So that I can access authenticated features.

**Acceptance Criteria:**

**Given** a lambda account exists
**When** the user logs in with valid credentials
**Then** the API issues authentication using httpOnly Secure SameSite cookies
**And** no token is stored in localStorage or JS-accessible storage
**And** invalid credentials return a generic authentication error
**And** logout clears the authenticated session cookie
**And** authenticated frontend state updates without exposing token contents

### Story 2.3: Profile View and Edit

As an authenticated user,
I want to view and update my profile information,
So that my account details stay accurate.

**Acceptance Criteria:**

**Given** a user is authenticated
**When** they open their account page
**Then** they can view their email, display name, role, and relevant account metadata
**And** they can update editable profile fields
**And** email uniqueness and field validation are enforced server-side
**And** form errors are shown inline and specifically
**And** role fields cannot be changed by the user

### Story 2.4: Account Deletion and Personal Data Erasure

As an authenticated user,
I want to delete my account and associated personal data,
So that I can exercise my RGPD erasure rights.

**Acceptance Criteria:**

**Given** a user is authenticated
**When** they request account deletion
**Then** they must confirm the destructive action through AlertDialog
**And** personal data associated with the account is removed or anonymized according to legal retention rules
**And** the user is logged out after deletion
**And** the system preserves non-personal aggregate event data where legally allowed
**And** the deletion action is auditable without retaining unnecessary personal data

### Story 2.5: Admin User Directory

As an admin,
I want to search and filter user accounts,
So that I can manage community access efficiently.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they open the user backoffice
**Then** they can view users with email, display name, role, and account status
**And** they can search and filter users by role and text query
**And** no non-admin can access the user directory UI or API
**And** empty and no-result states follow the UX spec
**And** API responses do not expose password hashes or sensitive auth internals

### Story 2.6: Admin Role Promotion and Demotion

As an admin,
I want to promote and demote users between lambda and membre,
So that member-only access is controlled by the association.

**Acceptance Criteria:**

**Given** an admin is viewing a user profile
**When** they promote a lambda user to membre
**Then** the user's role changes to membre after explicit confirmation
**And** the action is logged for auditability
**And** demoting a membre to lambda also requires explicit confirmation
**And** admins cannot accidentally remove their own last admin capability
**And** role changes are reflected in the UI with optimistic update and rollback on API failure

### Story 2.7: Admin Account Creation

As an admin,
I want to create other admin accounts,
So that the association board can share backoffice responsibility.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they create a new admin account
**Then** the account receives admin role only through an admin-only API action
**And** required account fields are validated server-side
**And** the action is logged
**And** non-admin users cannot create admins
**And** the system prevents privilege escalation through client-side payload manipulation

### Story 2.8: API RBAC Enforcement

As the system,
I want every protected API endpoint to enforce roles server-side,
So that frontend route guards are never the security boundary.

**Acceptance Criteria:**

**Given** protected endpoints exist for account, admin users, content, events, and role changes
**When** unauthenticated or under-privileged users call them
**Then** the API returns the correct unauthorized/forbidden response
**And** RBAC is enforced in Symfony, not only in Next.js
**And** frontend redirects are treated as UX only
**And** functional tests cover at least lambda, membre, admin, and anonymous access paths
**And** error responses follow the documented API error format

### Story 2.9: RGPD Rights Request Support

As an authenticated user,
I want a clear way to exercise RGPD rights,
So that I can request access, rectification, erasure, portability, or opposition.

**Acceptance Criteria:**

**Given** a user is authenticated
**When** they open account privacy settings
**Then** they see the available RGPD rights and how to exercise them
**And** they can initiate or access the documented process for each right
**And** privacy policy links are available from the flow
**And** admin/contact handling requirements are recorded for follow-up
**And** the implementation does not promise automated portability unless that capability exists

## Epic 3: Event Lifecycle Backoffice

Admins can create, configure, publish, protect, manage, and complete events, including game library and game-selection setup.

### Story 3.1: Admin Event List and Draft Creation

As an admin,
I want to create and view event drafts,
So that I can prepare events before publishing them publicly.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they open the event backoffice
**Then** they can view an event list with title, type, status, dates, capacity, and visibility
**And** the empty state invites them to create the first event
**And** they can create a draft event with title, description, type, dates, venue, capacity, registration window dates, and public/private access flag
**And** required fields are validated server-side and displayed inline in the UI
**And** non-admin users cannot access event management

### Story 3.2: Edit Event Details and Registration Window

As an admin,
I want to edit event details and registration windows,
So that event information stays accurate before and during publication.

**Acceptance Criteria:**

**Given** an event draft or published event exists
**When** an admin edits event details
**Then** they can update title, description, type, dates, venue, capacity, and registration open/close dates
**And** invalid date ranges are rejected server-side
**And** capacity cannot be lowered below confirmed registration count without a clear validation error
**And** changes are persisted and reflected in public pages where applicable
**And** the edit form remains accessible and keyboard navigable

### Story 3.3: Publish, Unpublish and Lifecycle Transitions

As an admin,
I want to transition events through their lifecycle,
So that public visibility and operational status are controlled.

**Acceptance Criteria:**

**Given** an event exists
**When** an admin changes its status
**Then** supported transitions include draft, published, in-progress, and completed
**And** publishing makes the event visible on public listings
**And** unpublishing or reverting to draft hides it from public listings
**And** destructive or visibility-changing actions require confirmation
**And** invalid lifecycle transitions are rejected with a clear error

### Story 3.4: Private Event Password Configuration

As an admin,
I want to configure private event access passwords,
So that member-only or invitation-only events can be protected.

**Acceptance Criteria:**

**Given** an event exists
**When** an admin marks it private
**Then** they can set or update an event password
**And** the password is never exposed in plain text after saving
**And** public event cards show a members-only/private access state where appropriate
**And** only admins can configure private access
**And** this story only manages admin password configuration and does not implement registrant password entry

### Story 3.5: Archipelago Game Library Management

As an admin,
I want to manage the association's Archipelago game library,
So that events can offer accurate game choices and options.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they open the game library backoffice
**Then** they can add, edit, and remove games with name, slug, description, cover image metadata, availability state, and supported event types
**And** removal is blocked or safely handled when a game is already used by existing registrations
**And** game list empty states and validation follow UX patterns
**And** non-admin users cannot manage the game library
**And** game records are available for event-specific selection configuration

### Story 3.6: Randomizer Option Definition for Games

As an admin,
I want to define configurable randomizer options per game,
So that participants can configure selected games during registration.

**Acceptance Criteria:**

**Given** a game exists in the library
**When** an admin adds configurable options
**Then** each option can define label, key, input type, plain-language description, required flag, default value, and advanced/basic visibility
**And** invalid option schemas are rejected server-side
**And** advanced options are distinguishable from base options
**And** option definitions are version-safe enough not to break existing registrations silently
**And** configured options are available to event game selection setup

### Story 3.7: Event Game Selection Intake Configuration

As an admin,
I want to configure game selection intake per event,
So that participants only see relevant games and options for that event.

**Acceptance Criteria:**

**Given** an event and game library entries exist
**When** an admin configures game selection for the event
**Then** they can enable or disable game selection intake
**And** they can choose available games for the event
**And** they can choose which game options are visible for the event
**And** the configuration is saved independently per event
**And** disabling game selection clearly affects the future registration flow

### Story 3.8: Attach Recap or VOD to Completed Event

As an admin,
I want to attach a recap article or VOD link to a completed event,
So that past events become useful public community content.

**Acceptance Criteria:**

**Given** an event is completed
**When** an admin attaches a recap article or VOD link
**Then** the completed event public page links to that recap or VOD
**And** the event listing reflects recap availability
**And** invalid VOD URLs are rejected or clearly marked
**And** only completed events can be marked with final recap content unless explicitly overridden
**And** public users can access the recap through the event page

## Epic 4: Event Registration & Archipelago Game Selection

Authenticated users can register for events, access private events, select and configure Archipelago games, update/cancel registrations, and receive confirmation.

### Story 4.1: Registration Eligibility and Start Flow

As an authenticated user,
I want to start registration only when an event is available,
So that I understand whether I can register before entering details.

**Acceptance Criteria:**

**Given** a published event exists
**When** an authenticated user opens the registration page
**Then** the system checks publication state, registration window, capacity, and access type
**And** open public events allow the registration flow to start
**And** closed, unpublished, completed, or unavailable events show a clear terminal or informational state
**And** anonymous users are redirected to login/signup with return path preserved
**And** all eligibility decisions are enforced server-side

### Story 4.2: Private Event Password Access

As an authenticated user,
I want to enter a private event password,
So that I can register for an invitation-only event when I have access.

**Acceptance Criteria:**

**Given** an event is private and registration is open
**When** a user opens the registration page
**Then** the PasswordAccessGate is available behind a "J'ai un code d'accès" disclosure
**And** valid passwords unlock the registration flow
**And** invalid passwords show an inline field error without revealing whether the event password format is correct
**And** successful password access is recorded for admin visibility
**And** password access does not promote the user to membre

### Story 4.3: Atomic Event Registration Reservation

As an authenticated user,
I want my registration to reserve a seat reliably,
So that I do not lose my place because of concurrent submissions.

**Acceptance Criteria:**

**Given** an event has remaining capacity and registration is open
**When** a user submits the initial registration step
**Then** the backend creates or confirms the registration transactionally
**And** capacity is checked atomically server-side
**And** two concurrent requests cannot both claim the final seat
**And** full events return a graceful capacity-full response
**And** registration creation either completes fully or rolls back without partial state

### Story 4.4: Game Selection Grid

As a registrant,
I want to choose one or more games from the event library,
So that my Archipelago world can be prepared.

**Acceptance Criteria:**

**Given** an event has game selection enabled and available games configured
**When** the registrant reaches the game selection step
**Then** they see a responsive GameCard grid with game title, cover art, and selection state
**And** selected, disabled, and limit-reached states are represented visually and accessibly
**And** keyboard users can select/deselect games with focus, Enter, and Space
**And** selection limits are enforced both in UI and backend
**And** selected games are persisted to the registration draft or registration record

### Story 4.5: Per-Game Randomizer Option Configuration

As a registrant,
I want to configure key options for each selected game,
So that admins receive usable setup data for the multiworld.

**Acceptance Criteria:**

**Given** a registrant has selected a game with configured options
**When** they open that game's option panel
**Then** they can configure required base options with plain-language descriptions
**And** advanced options are collapsed by default and available through an accessible accordion
**And** incomplete required options are clearly marked
**And** option values are validated server-side against the event/game option schema
**And** invalid or stale options return field-addressable errors

### Story 4.6: World Summary and Registration Progress

As a registrant,
I want to see my selected games and completion progress,
So that I know what remains before submitting.

**Acceptance Criteria:**

**Given** the registration flow is in progress
**When** the user selects or configures games
**Then** the WorldSummaryPanel updates with selected games, key options, and completion state
**And** the RegistrationProgressIndicator shows the current step, completed steps, and errors
**And** the continue/submit CTA remains disabled until required information is valid
**And** mobile users see the summary as a sticky bottom bar with expandable details
**And** screen readers receive polite updates when games are added or removed

### Story 4.7: Review and Submit Registration

As a registrant,
I want to review my event and game choices before final submission,
So that I can confirm the registration data is correct.

**Acceptance Criteria:**

**Given** the registrant completed required event and game selection steps
**When** they reach the review step
**Then** they see event details, selected games, and configured options
**And** they can return to earlier steps without losing entered data
**And** submitting validates the full registration server-side
**And** success shows a personalized confirmation screen with next steps
**And** submission returns confirmation or actionable error within the NFR target

### Story 4.8: Update Game Selections Before Registration Closes

As a registrant,
I want to update my game selections before registration closes,
So that I can correct or refine my setup.

**Acceptance Criteria:**

**Given** a user has an existing registration and the registration window is still open
**When** they reopen their registration
**Then** their previous selections and option values are pre-filled
**And** they can update games and options subject to current event configuration
**And** updates are validated and persisted server-side
**And** the system prevents updates after registration closes
**And** admins can later see the latest saved selections

### Story 4.9: User Registration Cancellation

As a registrant,
I want to cancel my own registration,
So that I can release my place if I cannot attend.

**Acceptance Criteria:**

**Given** a user has an active registration
**When** they request cancellation
**Then** they must confirm through a destructive confirmation dialog
**And** the registration is marked cancelled or removed according to backend rules
**And** event capacity is made available again where applicable
**And** cancellation is blocked or clearly handled after configured cutoff rules
**And** the user receives clear confirmation of cancellation

### Story 4.10: Registration Confirmation Email

As a registrant,
I want to receive a confirmation email after registration,
So that I have event details and next steps outside the site.

**Acceptance Criteria:**

**Given** a registration is successfully submitted
**When** the backend completes the registration transaction
**Then** a confirmation email job is queued with event details, selected games, and next steps
**And** email delivery failure is logged and flagged without rolling back the registration
**And** the email content does not include sensitive auth tokens or private passwords
**And** duplicate submissions do not send duplicate confirmation emails unless intentionally retriggered
**And** the confirmation screen does not depend on email delivery success

### Story 4.11: Seat Counter and Capacity-Full Registration UX

As a registrant,
I want to see remaining seat availability during registration,
So that I understand urgency and capacity changes.

**Acceptance Criteria:**

**Given** an event has capacity configured
**When** a user views the event or registration page
**Then** they see the remaining seat count and capacity state
**And** available, low, full, and disconnected states follow the SeatCounter UX contract
**And** if the event becomes full during registration, the user sees a graceful CapacityFullScreen or equivalent terminal state
**And** frontend seat count is never trusted for final registration authority
**And** realtime behavior is integrated later with Epic 7, with polling or static fallback acceptable in this story

## Epic 5: Admin Registration Operations

Admins can monitor registrations, inspect game selections, export data, handle capacity notifications, modify registrations, and contact participants.

### Story 5.1: Admin Registration Dashboard

As an admin,
I want to view registrations for an event,
So that I can monitor participant status and game selection completeness.

**Acceptance Criteria:**

**Given** an admin is authenticated and an event has registrations
**When** they open the event registration dashboard
**Then** they see each registration with participant identity, timestamp, status, access type, and selected games summary
**And** they can filter or search registrations where useful
**And** password-access registrations are visibly marked
**And** cancelled registrations are visually distinct
**And** non-admin users cannot access registration dashboard endpoints or UI

### Story 5.2: Registration Detail and Game Selection Inspection

As an admin,
I want to inspect a participant's registration details,
So that I can validate their game choices before generating the multiworld.

**Acceptance Criteria:**

**Given** a registration exists with game selections
**When** an admin opens registration detail
**Then** they see selected games, configured option values, completion status, and any validation warnings
**And** incomplete or unusual selections are clearly highlighted
**And** the view distinguishes current selection data from historical/cancelled states
**And** no sensitive auth data is exposed
**And** the data matches the latest saved participant selections

### Story 5.3: Export Participant and Game Selection Data

As an admin,
I want to export event registration and game selection data,
So that I can prepare the Archipelago multiworld outside the site.

**Acceptance Criteria:**

**Given** an event has registrations
**When** an admin requests an export
**Then** the system exports participant, registration, selected game, and option data
**And** export supports at least CSV or JSON according to implementation choice
**And** cancelled registrations are excluded or clearly marked based on selected export option
**And** exports are only available to admins
**And** the export action is logged

### Story 5.4: Admin Registration Modification and Cancellation

As an admin,
I want to modify or cancel participant registrations,
So that I can resolve participant issues without direct database access.

**Acceptance Criteria:**

**Given** an admin is viewing a registration
**When** they modify allowed registration fields or cancel the registration
**Then** the action is validated server-side
**And** destructive cancellation requires confirmation
**And** capacity is updated correctly if cancellation releases a seat
**And** the action is logged with admin identity and timestamp
**And** the participant-facing state reflects the admin change

### Story 5.5: Admin Capacity Notifications

As an admin,
I want to be notified when an event reaches capacity,
So that I can react quickly to demand and communicate next steps.

**Acceptance Criteria:**

**Given** an event has a defined capacity
**When** the final seat is claimed
**Then** an admin notification is queued or recorded
**And** duplicate capacity notifications are avoided for the same capacity-reached event
**And** notification failure is logged without affecting the participant registration
**And** the backoffice clearly shows capacity reached
**And** this works even if the final seat is claimed under concurrent load

### Story 5.6: Admin Message to Participant

As an admin,
I want to send a message to an individual registrant,
So that I can resolve issues with their registration or game selection.

**Acceptance Criteria:**

**Given** an admin is viewing a registration
**When** they send a message to the participant
**Then** the message is sent through the configured communication channel
**And** the message body is required and validated
**And** delivery failure is logged and surfaced to the admin
**And** the action is recorded in the registration history
**And** users cannot send admin messages through this endpoint

### Story 5.7: Realtime Registration Feed

As an admin,
I want the registration dashboard to update in real time,
So that I can monitor registrations without refreshing during an opening window.

**Acceptance Criteria:**

**Given** the admin registration dashboard is open
**When** a new registration arrives
**Then** a RegistrationFeedItem appears without page refresh
**And** the new item receives the documented short highlight animation
**And** SSE disconnect shows a subtle stale-state indicator and falls back to polling
**And** reconnect resumes live updates without user action
**And** realtime updates never bypass server-side authorization

## Epic 6: Payments, Ticketing & HelloAsso Sync

Visitors can use embedded HelloAsso checkout for tickets, memberships, and merchandise while admins see synchronized payment/order status in the ERP.

### Story 6.1: HelloAsso Integration Configuration

As an admin/developer,
I want HelloAsso credentials and integration settings configured safely,
So that payments can be embedded and synchronized without exposing secrets.

**Acceptance Criteria:**

**Given** the API and frontend environment examples exist
**When** HelloAsso configuration is added
**Then** required client IDs, secrets, organization slugs, and environment modes are documented in `.env.example` without real secrets
**And** secrets are read only server-side
**And** frontend code never exposes private HelloAsso API credentials
**And** sandbox and production modes can be distinguished
**And** missing configuration fails with a clear operational error

### Story 6.2: Embedded Event Ticket Checkout

As a visitor,
I want to purchase event tickets through embedded HelloAsso checkout,
So that I can complete payment without leaving archilan.fr.

**Acceptance Criteria:**

**Given** a public event has ticketing enabled
**When** a visitor opens the ticketing action
**Then** the HelloAsso checkout is embedded in the site where supported
**And** the user is not forced through an external redirect for the primary flow
**And** checkout loading and unavailable states follow UX feedback patterns
**And** CGV acceptance is presented before the transactional action
**And** no payment is treated as confirmed until HelloAsso confirmation/sync validates it

### Story 6.3: Embedded Membership Fee Checkout

As a visitor,
I want to pay association membership fees through HelloAsso,
So that public membership payments can be supported when enabled.

**Acceptance Criteria:**

**Given** membership payment is enabled in configuration
**When** a visitor opens the membership checkout
**Then** the HelloAsso membership form is embedded or surfaced through the approved embedded flow
**And** CGV/CGU/legal context is available before payment
**And** payment does not automatically promote the user to membre in v1 unless an admin-controlled process explicitly does so
**And** unavailable HelloAsso state degrades gracefully
**And** the transaction can later be synchronized into the ERP

### Story 6.4: Embedded Merchandise Boutique Checkout

As a visitor,
I want to browse or access merchandise checkout through HelloAsso,
So that I can buy ArchiLAN merchandise from the site.

**Acceptance Criteria:**

**Given** HelloAsso boutique configuration exists
**When** a visitor opens the boutique section
**Then** the HelloAsso boutique checkout is embedded or linked according to supported embedded capabilities
**And** the page clearly distinguishes merchandise checkout from event registration
**And** unavailable HelloAsso state shows a retryable message
**And** CGV are accessible before purchase
**And** no local inventory management is introduced in v1

### Story 6.5: HelloAsso OAuth/API Sync Adapter

As the system,
I want to synchronize HelloAsso orders and member/payment data,
So that the internal ERP reflects payment status without manual work.

**Acceptance Criteria:**

**Given** HelloAsso API credentials are configured
**When** the sync job runs or a payment update is requested
**Then** the backend retrieves relevant order/payment/member data through a server-side adapter
**And** data is mapped into internal payment records without exposing raw API secrets
**And** transient failures are retried through Messenger
**And** persistent failures are logged and surfaced to admins
**And** sync delay does not block a registration record from existing

### Story 6.6: Payment Status Visibility in Admin Registration View

As an admin,
I want to see HelloAsso payment status for registrations,
So that I can verify whether participant payments are complete.

**Acceptance Criteria:**

**Given** a registration is associated with a HelloAsso payment/order
**When** an admin views registration details or the registration dashboard
**Then** payment status is displayed with clear labels such as pending, confirmed, failed, refunded, or unknown
**And** stale or unsynced payment data is clearly marked
**And** admins can trigger or request a sync retry where appropriate
**And** payment status is not editable manually unless a specific audited override exists
**And** payment data access is restricted to admins

### Story 6.7: HelloAsso Graceful Degradation

As a visitor or admin,
I want clear feedback when HelloAsso is unavailable,
So that payment issues do not look like broken site behavior.

**Acceptance Criteria:**

**Given** HelloAsso checkout or API is unavailable
**When** a visitor opens a payment surface
**Then** the UI shows a specific, retryable degradation message
**And** no silent failure or blank embed is shown
**And** admins can see persistent sync failures in the backoffice
**And** errors follow the documented API error format
**And** payment outages do not corrupt registrations or local records

## Epic 7: Live Experience, Twitch & Realtime Presence

The site displays Twitch live/offline state and realtime activity signals, including live seat counters and resilient fallback behavior.

### Story 7.1: Realtime Infrastructure and Topic Authorization

As the system,
I want a realtime delivery layer for public and admin updates,
So that live site signals can update without full page reload.

**Acceptance Criteria:**

**Given** the backend and frontend are running
**When** realtime infrastructure is configured
**Then** the backend can publish public seat-counter events
**And** the backend can publish admin-only registration feed events
**And** frontend clients can subscribe to permitted topics
**And** admin topics require authenticated authorization
**And** SSE falls back to polling when the connection is unavailable

### Story 7.2: Public Realtime Seat Counter

As a visitor,
I want event seat availability to update live,
So that I can see registration momentum without refreshing.

**Acceptance Criteria:**

**Given** a published event has capacity
**When** registrations are created or cancelled
**Then** public event pages receive updated remaining seat counts
**And** updates are reflected within the target propagation window where SSE is available
**And** the SeatCounter animates count changes without disrupting accessibility
**And** disconnected state shows subtle fallback messaging
**And** final registration authority remains server-side

### Story 7.3: Twitch Live Status Detection

As a visitor,
I want the site to know whether ArchiLAN is live on Twitch,
So that I can open the stream when it is active.

**Acceptance Criteria:**

**Given** Twitch integration is configured
**When** the site checks channel status
**Then** the backend or server-side integration determines live/offline state
**And** Twitch API failure falls back to a static channel link
**And** no Twitch API secrets are exposed to the browser
**And** live/offline state can be consumed by the frontend navigation and landing page
**And** status refresh does not overload Twitch API limits

### Story 7.4: LiveTwitchBadge in Public Navigation

As a visitor,
I want a visible Twitch live/offline badge in navigation,
So that I can quickly join the stream when ArchiLAN is live.

**Acceptance Criteria:**

**Given** Twitch live status is available
**When** a visitor views public navigation
**Then** the LiveTwitchBadge shows live, offline, loading, or error state
**And** live state includes a visible live indicator and accessible label
**And** offline/error state links to the Twitch channel
**And** state transitions are visually smooth and not jarring
**And** screen readers receive appropriate live/offline state labels

### Story 7.5: Consent-Gated Twitch Embed

As a visitor,
I want to watch the Twitch stream on the site when I consent,
So that I can follow ArchiLAN events without an unexpected tracker load.

**Acceptance Criteria:**

**Given** Twitch is live and the visitor has not consented to non-functional embeds
**When** the landing page or stream section renders
**Then** the Twitch iframe is not loaded before consent
**And** the visitor sees a clear consent-aware placeholder with channel link fallback
**And** after consent, the Twitch embed lazy-loads responsively
**And** very small viewports degrade to a Twitch channel link if the embed is unusable
**And** the iframe has accessible title/fallback text

### Story 7.6: Realtime Resilience and Stale Data UX

As a visitor or admin,
I want realtime failures to degrade clearly,
So that I can trust whether displayed data is current.

**Acceptance Criteria:**

**Given** an SSE connection is active
**When** the connection drops
**Then** the UI waits briefly before showing a subtle disconnected indicator
**And** polling starts automatically on the documented interval
**And** stale data older than the threshold shows an action to refresh
**And** reconnect removes the stale indicator without user action
**And** no disruptive modal is shown for realtime interruptions

## Epic 8: Legal Compliance, Consent & Trust

The site satisfies required French legal/RGPD/CNIL surfaces, displays legal documents in the right flows, and manages cookie consent lifecycle.

### Story 8.1: Legal Footer and Static Legal Page Shell

As a visitor,
I want legal pages linked from every public page,
So that I can access required association and policy information.

**Acceptance Criteria:**

**Given** the public shell exists
**When** a visitor views any public page
**Then** footer links to Mentions Legales, Politique de Confidentialite, CGV, and CGU are visible
**And** each legal route renders a readable static page shell
**And** pages use semantic headings and accessible document structure
**And** legal routes are public and crawlable
**And** missing legal content is clearly marked as content-required rather than silently empty

### Story 8.2: Mentions Legales Page

As a visitor,
I want to read ArchiLAN's legal notice,
So that I know who publishes and hosts the site.

**Acceptance Criteria:**

**Given** legal content is configured
**When** a visitor opens Mentions Legales
**Then** the page includes association name, address, phone/email contact, directeur de publication, and hosting provider identity fields
**And** missing required fields are visible to admins or fail validation before publication if managed dynamically
**And** the page is linked from the footer on every page
**And** content does not misrepresent ArchiLAN's loi 1901 nonprofit status
**And** the page is accessible without authentication

### Story 8.3: Privacy Policy and RGPD Information

As a visitor or user,
I want to read the privacy policy,
So that I understand how my personal data is processed and what rights I have.

**Acceptance Criteria:**

**Given** privacy policy content is configured
**When** a visitor opens the privacy page
**Then** it describes data controller identity, processing purposes, legal bases, retention periods, user rights, and CNIL complaint right
**And** it references account deletion and RGPD rights request paths where applicable
**And** the page is linked from the footer and relevant account flows
**And** content is readable on mobile and desktop
**And** the site does not collect non-functional tracking consent before showing this page

### Story 8.4: CGU Presentation During Account Creation

As a visitor creating an account,
I want to see and accept the CGU,
So that account creation is governed by clear usage terms.

**Acceptance Criteria:**

**Given** the signup flow exists
**When** a visitor creates an account
**Then** CGU acceptance is required before account creation succeeds
**And** the CGU are linked from the signup form
**And** the acceptance timestamp/version is stored where required
**And** the checkbox is not pre-checked
**And** the user receives a field-level validation error if they submit without acceptance

### Story 8.5: CGV Presentation Before Transactional Actions

As a visitor making a purchase,
I want to access and accept CGV before payment,
So that ticketing, membership, or merchandise purchases are legally framed.

**Acceptance Criteria:**

**Given** a transactional HelloAsso action is available
**When** a visitor starts ticket, membership, or merchandise checkout
**Then** CGV are linked and presented before the transactional action
**And** acceptance is required where the flow requires it
**And** acceptance is not pre-checked
**And** the CGV page is available from the footer
**And** checkout cannot silently bypass CGV presentation

### Story 8.6: Cookie Consent Banner

As a visitor,
I want to choose whether to allow non-functional cookies and embeds,
So that Twitch and analytics are not loaded without consent.

**Acceptance Criteria:**

**Given** a visitor has no stored consent choice
**When** they first visit the site
**Then** a cookie consent banner appears before non-functional trackers or embeds load
**And** the visitor can accept, reject, or configure consent
**And** rejection is as easy as acceptance
**And** the consent choice is stored and respected on future visits
**And** session/functional cookies are handled separately from non-functional consent

### Story 8.7: Persistent Consent Management

As a visitor,
I want to update or withdraw cookie consent later,
So that my choices remain under my control.

**Acceptance Criteria:**

**Given** a visitor has previously made a consent choice
**When** they use the persistent footer consent control
**Then** they can view and update current consent settings
**And** withdrawing consent prevents future non-functional embed/tracker loading
**And** the UI confirms the updated choice
**And** consent state changes are reflected without requiring account login
**And** Twitch embed components react correctly to withdrawn consent

### Story 8.8: Legal Compliance Review Checklist

As an admin,
I want a launch checklist for legal/compliance content,
So that missing required legal data is caught before launch.

**Acceptance Criteria:**

**Given** legal pages and consent flows exist
**When** admins prepare for launch
**Then** there is a documented checklist covering Mentions Legales, privacy policy, CGV, CGU, cookie consent, footer links, and transaction/account insertion points
**And** checklist items reference where each item is implemented
**And** missing association-specific legal content is explicitly marked as requiring human/legal review
**And** the checklist is stored in project docs or implementation artifacts
**And** this story does not claim to provide formal legal advice

## Epic 9: Archipelago Session Management

Admins can generate Archipelago multiworld sessions from confirmed event registrations, launch dedicated server containers automatically, and players receive their connection details in real time - end to end, without manual file uploads.

### Story 9.1: Multi-Slot Registration Model

As a registered player,
I want to select the same game multiple times with different configurations,
So that I can participate with multiple independent slots in the same multiworld session.

**Acceptance Criteria:**

**Given** a player is configuring their game selection for an event
**When** they add a game that is already in their selection
**Then** a new independent slot is created for that game with its own option configuration
**And** each slot for the same game can have different option values
**And** slot order is preserved and displayed consistently
**And** existing registrations are migrated without data loss - each selected game becomes slot index 1
**And** the admin registration detail view shows the full slot breakdown including index and per-slot options
**And** the export includes one row per slot rather than one row per game
**And** functional tests cover multi-slot selection for the same game by a single player

### Story 9.2: Game YAML Template Management

As an admin,
I want to define a default YAML template for each game in the library,
So that session generation can produce valid Archipelago YAML without requiring manual file uploads.

**Acceptance Criteria:**

**Given** a game exists in the Archipelago game library
**When** an admin edits the game
**Then** they can set the exact Archipelago game name (the string the generator expects, e.g. "Hollow Knight")
**And** they can define default YAML option values as key-value pairs matching the game's Archipelago option schema
**And** the system shows a live preview of the YAML that would be generated for a slot using those defaults
**And** a game with no Archipelago game name set is flagged as "not ready for session generation"
**And** saving an empty Archipelago game name on a previously configured game is rejected with a validation error
**And** functional tests cover template persistence and preview YAML output

### Story 9.3: Runner Message Consumer Foundation

As the system,
I want Symfony Messenger consumers deployable on runner servers that can manage Docker containers,
So that Archipelago run operations are distributed across multiple runners without a separate microservice.

**Acceptance Criteria:**

**Given** a runner server has the Symfony API codebase deployed and RabbitMQ credentials configured
**When** `php bin/console messenger:consume run_generation run_server` is executed on the runner
**Then** the worker connects to the central RabbitMQ and processes jobs from the assigned queues
**And** the runner has access to the Docker daemon via the Docker CLI (`docker` installed and socket accessible)
**And** the `GenerateRunJob`, `StartRunJob`, `StopRunJob`, and `RestartRunJob` message classes are defined in `src/Sessions/Application/Message/`
**And** each message carries a `sessionId` and relevant parameters for the operation
**And** upon job completion or failure, the handler POSTs a callback to the central API at `POST /api/v1/internal/sessions/{id}/runner-callback` authenticated by shared secret (`CENTRAL_API_SECRET` env var)
**And** the runner's identity is configured via `RUNNER_ID` env var and included in every callback payload
**And** Docker port allocation uses a pool derived from `PORT_RANGE_START` and `PORT_RANGE_END` env vars, with in-process tracking of currently allocated ports
**And** on worker startup, the port pool is reconstructed by running `docker ps --filter name=archipelago-run- --format '{{.Ports}}'` to identify ports already bound to existing run containers
**And** only one Messenger worker consuming from the `run_server` queue must run per runner - running multiple workers on the same runner would cause port pool conflicts; this is documented as an operational constraint
**And** all handler log output uses `LoggerInterface` with structured context: `session_id`, `runner_id`, `action`
**And** functional tests cover message dispatch from the central API and handler invocation with a mocked Docker client

### Story 9.4: Pre-flight Validation and YAML Generation

As an admin,
I want to validate all player slots and generate their YAML files before committing to a run,
So that configuration errors are caught early and generation never fails silently.

**Acceptance Criteria:**

**Given** confirmed registrations exist for an event, each with at least one slot
**When** an admin clicks "Valider et préparer la génération" in the admin run UI
**Then** the API creates a `Run` entity in status `validating`, associates it with the event, and dispatches a `GenerateRunJob{phase: validate}` via Messenger
**And** the Messenger handler on the runner validates every slot: game has an Archipelago game name, all required options have a value or default, slot name is ≤ 16 characters and unique within the session
**And** if any slot fails validation, the handler POSTs an error callback containing a structured list of errors per slot; the API transitions the Run to `draft` and stores the errors
**And** the admin sees the error list per slot in the backoffice and can correct slot names (within the 16-character limit) before retrying
**And** if all slots pass, the handler writes one YAML file per slot to `/workspace/{runId}/yamls/` on the runner, then POSTs a success callback
**And** the API transitions the Run to `ready` on a success callback
**And** slot names are auto-generated from player display name and a game abbreviation derived from the first letter of each word in the Archipelago game name, truncated to 3 characters (e.g. "Hollow Knight" → "HK", "A Link to the Past" → "ALT"), with collision resolution by appending an incrementing index (e.g. `Alice_HK1`, `Alice_HK2`)
**And** functional tests cover: all-valid slots, one invalid slot blocking validation, slot name collision resolution, and well-formed YAML content structure

### Story 9.5: Multiworld Generation Pipeline

As the system,
I want the runner to execute the Archipelago generator on the prepared YAML files and notify Symfony of the outcome,
So that the multiworld seed file is ready for server launch.

**Acceptance Criteria:**

**Given** a Run is in status `ready` with YAML files present on the runner workspace
**When** an admin confirms generation in the backoffice
**Then** the API transitions the Run to `generating` and dispatches a `GenerateRunJob{phase: generate}` via Messenger
**And** the Messenger handler on the runner executes `docker run --rm -v {workspace}/yamls:/yamls -v {workspace}/output:/output archipelago-generate --player_files_path /yamls --outputpath /output` as a subprocess
**And** before dispatching, Symfony generates a random integer seed and stores it on the Run entity; the handler passes `--seed {seed}` to the docker run command so the generation is reproducible
**And** generation is bounded to a 5-minute timeout; exceeding the timeout sends a `failed` callback with a timeout reason
**And** on success: the `.archipelago` output file exists in `/workspace/{runId}/output/`, and the handler POSTs a success callback with the file path
**And** on failure: the full stderr output (containing all Archipelago-accumulated errors) is captured and included in the error callback
**And** the API transitions the Run to `generated` on success, or to `failed` with stored error details on failure
**And** the admin can view the full error output in the backoffice when generation fails
**And** the `archipelago-generate` Docker image must be pre-built from the official Archipelago repository and tagged as `archipelago-generate` on each runner (documented as a deployment prerequisite)
**And** functional tests cover: success path with correct output file, error capture on generate failure, timeout handling

### Story 9.6: Server Lifecycle - Launch, Health Monitoring and Auto-recovery

As the system,
I want the runner to launch a persistent Archipelago server container, monitor its health, and support restart on crash,
So that a session stays available to players and recovers automatically from transient failures.

**Acceptance Criteria:**

**Given** a Run is in status `generated` with the `.archipelago` file on the runner workspace
**When** an admin triggers server launch in the backoffice
**Then** the API transitions the Run to `launching` and dispatches a `StartRunJob` via Messenger to the runner that owns the session
**And** the handler allocates two ports from the pool: `port` (game server, mapped to container 38281) and `bridge_port` (Bridge.py REST API, mapped to container 5000)
**And** the handler executes `docker run -d --name archipelago-run-{runId} -p {port}:38281 -p {bridge_port}:5000 -v {workspace}/output:/archipelago/output:ro -v {workspace}/saves:/archipelago/saves -e SERVER_PASSWORD={password} -e RUN_ID={runId} -e CENTRAL_API_SECRET={CENTRAL_API_SECRET} -e SYMFONY_INTERNAL_URL={SYMFONY_INTERNAL_URL} -e MERCURE_HUB_URL={MERCURE_HUB_URL} archipelago-server`
**And** the password is auto-generated (32 random alphanumeric characters) and stored encrypted on the Run entity
**And** the handler POSTs a success callback to the central API containing: `runner_id`, `host` (runner hostname/IP), `port`, `bridge_port`, `container_id`, `password`
**And** the API transitions the Run to `running` on successful callback and stores `runner_id`, `host`, `port`, `bridge_port`, `password`
**And** the `archipelago-server` Docker image must be pre-built with MultiServer.py and Bridge.py included, tagged as `archipelago-server` on each runner (documented as a deployment prerequisite)
**And** the handler schedules periodic health checks (every 30 seconds) by re-dispatching a `RunHealthCheckJob` to its own queue with the session ID
**And** the health check handler attempts a TCP connection to `localhost:{port}`; three consecutive failures dispatch a `RunCrashedCallback` to the central API
**And** the API transitions the Run to `crashed` on a crash callback and returns the port to the available pool
**And** `RestartRunJob` stops the container, re-launches it using the same workspace files, and assigns a new port from the pool
**And** `StopRunJob` stops and removes the container (`docker stop` then `docker rm`) and returns the port to the pool
**And** functional tests cover: launch with port selection, health check failure sequence (3 consecutive → crashed), restart, and stop with port pool verification

### Story 9.7: Session Lifecycle API and Realtime Status

As the system,
I want ArchiLAN's Symfony API to own the session state machine and broadcast status changes via Mercure,
So that admins and players receive live updates without polling.

**Acceptance Criteria:**

**Given** a `Sessions` bounded context exists in the Symfony API
**When** a session changes state
**Then** the transition is persisted in a `sessions` table with `id`, `event_id`, `status`, `host`, `port`, `bridge_port`, `password`, `seed`, `created_at`, `started_at`, `stopped_at`, `finished_at`
**And** associated slots are persisted in `session_slots` with `session_id`, `registration_id`, `game_id`, `slot_name`, `slot_order`
**And** the runner notifies the API of status changes via `POST /api/v1/internal/sessions/{id}/runner-callback` authenticated by `CENTRAL_API_SECRET` shared secret header
**And** on each status change the API publishes a Mercure event on topic `/sessions/{id}`
**And** the full status machine is enforced: `draft → validating → ready → generating → generated → launching → running → stopped | failed | crashed | finished`
**And** the `finished` status is reachable only from `running` (via admin force-end or all-GOAL detection) and triggers archival (Story 9.16); `finished_at` is set when the Run transitions to `finished`
**And** session records and slot assignments are preserved in the database after the container is destroyed
**And** functional tests cover the full status machine including `finished`, callback authentication, and Mercure event publication

### Story 9.8: Admin UI - Session Creation, Monitoring and Controls

As an admin,
I want a dedicated session management page per event in the backoffice,
So that I can create, monitor, control, and audit Archipelago sessions without leaving the ERP.

**Acceptance Criteria:**

**Given** an event has confirmed registrations
**When** an admin opens `/admin/evenements/{eventId}/session`
**Then** they can initiate session creation from confirmed registrations
**And** pre-flight validation results are displayed per slot before the admin confirms generation
**And** the slot review table shows player name, game, and slot name - slot names are editable inline within the 16-character limit
**And** generation and launch progress are shown in real time via SSE without page refresh
**And** a running session displays host, port, and password with copy buttons and a "Stopper" control (graceful stop - container preserved, run can be restarted); force-end and commands are in Story 9.15
**And** a crashed session displays the error and a "Redémarrer" control
**And** a ZIP download of generated YAMLs is available after generation for debugging
**And** a session history section lists all past sessions for the event with status, date, and duration
**And** functional tests cover session creation, pre-flight error display, slot name editing, and stop/restart flows

### Story 9.9: Player Notifications - Email and Realtime Dashboard Alert

As a confirmed registrant,
I want to be notified automatically when my Archipelago session goes live,
So that I know exactly when and how to connect without checking the site manually.

**Acceptance Criteria:**

**Given** a session transitions to the `running` state
**When** the status change is processed
**Then** an email is sent to every confirmed registrant containing the event name, their slot name(s), host, port, and password
**And** the email includes a brief guide on how to connect with an Archipelago client
**And** a realtime dashboard notification is published via Mercure on the player's private topic
**And** failed email delivery is retried up to 3 times with exponential backoff before marking delivery as failed
**And** no notification is sent for `stopped`, `failed`, or `crashed` state transitions
**And** the notification is not re-sent if the session is restarted after a crash
**And** functional tests cover email content per slot, retry logic, and the no-duplicate-notification rule

### Story 9.10: Player Connection View

As a confirmed registrant,
I want to see my Archipelago connection details from my account area,
So that I can retrieve my slot name, server address, and password at any time during the event.

**Acceptance Criteria:**

**Given** an authenticated player has a confirmed registration for an event with an active or past session
**When** they view the event page or their account session tab
**Then** they see each of their slots with game name, slot name, host, port, and password
**And** each field has a copy-to-clipboard button
**And** if the session is in `generating` or `launching` state a live status indicator is shown via SSE - no page refresh needed
**And** if no session exists for the event a neutral "Aucune session active" message is shown
**And** if the session is `stopped` or `completed` the connection info remains visible as read-only history
**And** a player with a pending or cancelled registration cannot access session connection details
**And** functional tests cover slot isolation (player sees only their own slots), live state display, and access control

### Story 9.11: Traefik HTTP Provider - Dynamic WS Routing

As the system,
I want Symfony to expose a Traefik-compatible HTTP provider endpoint for Archipelago sessions,
So that WebSocket connections are routed automatically to the correct runner without manual Traefik configuration.

**Acceptance Criteria:**

**Given** Traefik is configured to poll `GET /internal/traefik` on the central Symfony API
**When** Traefik polls the endpoint
**Then** the response is a JSON object in Traefik HTTP provider format containing one router and one service entry per Run in `running` status
**And** each router matches the host rule `Host(\`{runId}.ws.archilan.fr\`)`, uses the `websecure` entrypoint (port 443, TLS), and routes to a backend service pointing to `{runner_host}:{port}`
**And** the endpoint is protected by a shared secret header (`X-Traefik-Token`); requests with an incorrect or missing token return 401
**And** the endpoint returns a valid empty Traefik config (not an error response) when no runs are in `running` status
**And** runs in any status other than `running` are excluded from the generated config
**And** the wildcard TLS certificate for `*.ws.archilan.fr` and Traefik's `websecure` entrypoint are configured in Traefik static config (outside the scope of this story - documented as deployment prerequisites); the recommended polling interval is 5 seconds
**And** Symfony exposes `GET /api/v1/internal/sessions/{runId}/publisher-token` authenticated by `X-Internal-Secret: {CENTRAL_API_SECRET}`; it generates and returns a short-lived Mercure publisher JWT (TTL 1h) signed with `MERCURE_JWT_SECRET`, scoped to publish on topics `runs/{runId}/*` only, as `{"token": "...", "expires_at": "..."}`
**And** functional tests cover: single active run generates correct router/service entry, multiple concurrent runs generate multiple entries, non-running runs are excluded, publisher-token endpoint returns correctly scoped JWT, invalid secret returns 401

### Story 9.12: Bridge.py - Real-Time Observer Service

As the system,
I want a Bridge.py service running inside each Archipelago server container,
So that game events are published to Mercure in real time and admin commands can be forwarded to the server.

**Acceptance Criteria:**

**Given** an Archipelago server container is running and `MERCURE_HUB_URL`, `CENTRAL_API_SECRET`, `SYMFONY_INTERNAL_URL`, and `RUN_ID` are set as container environment variables (injected by the runner at launch - no long-lived Mercure secret in the container)
**When** Bridge.py starts alongside MultiServer.py via the container entrypoint script
**Then** Bridge.py connects to `ws://localhost:38281` as a TextOnly Archipelago client with no game slot
**And** on receiving the initial `RoomInfo` packet, Bridge.py stores the total location count per slot (`RoomInfo.locations` array) so that checks can be displayed as "X / Total"
**And** for each `PrintJSON` packet received, Bridge.py POSTs to the Mercure hub on topic `runs/{runId}/feed` with a JSON payload: `{type, text, color, timestamp}`
**And** for each `StatusUpdate`, `ReceivedItems`, or `LocationChecks` packet, Bridge.py updates its in-memory player state aggregate per slot: `checks_done`, `checks_total`, `items_received`, `client_status`
**And** when a slot's `client_status` transitions to 30 (GOAL), Bridge.py records `goal_reached_at` as the current UTC timestamp on that slot's state
**And** on startup, before connecting to MultiServer.py, Bridge.py reads the `.apsave` file from the shared Docker volume (mounted at `/archipelago/saves/`) using `zlib.decompress` + `pickle.loads`, extracting `location_checks` and `client_game_state` to rebuild in-memory state (`checks_done`, `client_status`, `goal_reached_at` per slot); if the file is absent (first start) or unreadable, state initializes empty
**And** after each state update, Bridge.py POSTs the full aggregate to Mercure topic `runs/{runId}/players`; no persistent write to Symfony DB is needed - initial page load state is served via Bridge.py's own `/state` endpoint
**And** on startup, Bridge.py fetches a short-lived Mercure publisher JWT (TTL 1h) from Symfony by calling `GET /api/v1/internal/sessions/{runId}/publisher-token` (authenticated with `X-Internal-Secret: {CENTRAL_API_SECRET}`); Symfony generates the JWT server-side signed with `MERCURE_JWT_SECRET`, scoped to publish on topics `runs/{runId}/*` only
**And** Bridge.py schedules a token refresh every 50 minutes (before expiry), or immediately on receiving a 401 response from the Mercure hub, by re-calling the same endpoint; all Mercure POST requests include the current token as `Authorization: Bearer {token}`
**And** the `MERCURE_PUBLISHER_JWT` env var is NOT injected into the container - the container only needs `CENTRAL_API_SECRET`, `SYMFONY_INTERNAL_URL`, and `RUN_ID` to bootstrap its token lifecycle
**And** if the WebSocket connection to MultiServer.py drops, Bridge.py retries with exponential backoff (1s → 2s → 4s → 8s → max 30s) until reconnected; on reconnection it re-fetches `RoomInfo` to restore the location totals (in-memory player state is preserved across WS reconnects - `.apsave` is only read at process startup)
**And** Bridge.py exposes an internal REST API bound to `0.0.0.0:5000`: `POST /commands` accepts `{"command": "..."}` and forwards it as a WS `Say` packet to MultiServer.py; `GET /state` returns the current in-memory player aggregate as JSON (Symfony proxies this to frontend callers for initial page load)
**And** `GET /health` on port 5000 returns `{"status": "ok", "ws_connected": true|false}`
**And** all Bridge.py output is structured JSON logs to stdout with fields: `event`, `run_id`, `timestamp`, `severity`
**And** unit tests cover: `.apsave` parsing and in-memory state restoration, RoomInfo parsing for checks_total, PrintJSON → Mercure topic mapping, goal_reached_at timestamp on GOAL transition, player state aggregation, command forwarding via REST API, `/state` endpoint returns correct aggregate, publisher token fetch on startup, token refresh on 401 and on 50-minute schedule

### Story 9.13: Real-Time Event Feed

As a confirmed player or admin,
I want to see a real-time feed of Archipelago game events on the session page,
So that I can follow the session's progress without connecting an Archipelago client.

**Acceptance Criteria:**

**Given** a Run is in `running` status and Bridge.py is publishing events to Mercure topic `runs/{runId}/feed`
**When** an authenticated confirmed registrant or admin opens the session feed page
**Then** the Next.js page calls `GET /api/v1/sessions/{runId}/feed-token`; Symfony verifies that the requester is a confirmed registrant for this event or an admin, then generates and returns a short-lived Mercure subscriber JWT (TTL 1h) scoped to subscribe on topic `runs/{runId}/feed` only
**And** the page subscribes to `runs/{runId}/feed` via EventSource using the obtained JWT
**And** each incoming event is prepended to a scrollable feed list showing: formatted timestamp, message type badge, and text content
**And** message types are styled distinctly: hint → amber, item-received → teal, location-checked → blue, system → muted gray, chat → foreground white
**And** the feed displays the 100 most recent messages; older messages are removed from the DOM as new ones arrive
**And** on first page load, the feed starts empty - Mercure is real-time only (no history replay by default); a static notice "Les messages apparaîtront en direct" is shown until the first event arrives
**And** if the EventSource connection closes, a subtle disconnected indicator appears and automatic reconnection is attempted after 5 seconds
**And** the Mercure subscriber JWT is passed as a query parameter `?authorization=...` in the EventSource URL (EventSource does not support custom headers in browsers)
**And** unauthenticated users or non-registrants receive 403 from the feed-token endpoint and are shown an access denied state
**And** the feed is accessible both from the player view at `/evenements/{slug}/session` and from the admin session management page
**And** functional tests cover: feed-token JWT generation (correct topic scope, TTL, registrant ✅, non-registrant ❌, admin ✅), message delivery end-to-end from Bridge.py POST to EventSource client

### Story 9.14: Player Progress Dashboard

As a confirmed player or admin,
I want a real-time player progress dashboard showing each slot's checks, items, and connection status,
So that I can monitor the overall session at a glance without reading the event feed.

**Acceptance Criteria:**

**Given** a Run is in `running` status and Bridge.py is publishing state to Mercure topic `runs/{runId}/players`
**When** an authenticated confirmed registrant or admin opens the session dashboard
**Then** the page first calls `GET /api/v1/sessions/{runId}/players` (requires auth; Symfony proxies the request to `GET http://{runner_host}:{bridge_port}/state` on Bridge.py and returns the current in-memory player aggregate) to render the initial grid without waiting for a Mercure event
**And** the page calls `GET /api/v1/sessions/{runId}/players-token`; Symfony applies the same authorization as for feed-token (confirmed registrant or admin) and returns a short-lived subscriber JWT (TTL 1h) scoped to topic `runs/{runId}/players` only; the page subscribes via EventSource for live updates
**And** each slot card shows: player display name, game name, slot name, checks completed (X / Total where Total comes from `checks_total` provided by Bridge.py), items received count, and a client status badge
**And** ClientStatus values map to labels: UNKNOWN → "Hors ligne", CONNECTED → "Connecté", READY → "Prêt", PLAYING → "En jeu", GOAL → "Objectif atteint !"
**And** slots with ClientStatus GOAL (30) display a distinct visual indicator (accent color border and checkmark icon)
**And** the grid updates in real time as Bridge.py publishes state changes - no page refresh needed
**And** slots are sorted: GOAL slots first (ordered by `goal_reached_at` ascending), then by checks_done descending
**And** a disconnected indicator is shown if the EventSource closes, with automatic reconnection
**And** functional tests cover: initial state load via Bridge.py `/state` proxy, players-token JWT generation (correct topic scope, TTL, same auth rules as feed-token), real-time update on StatusUpdate, GOAL detection and sorting

### Story 9.15: Admin Server Commands and Log Viewer

As an admin,
I want to send commands to the Archipelago server and view container logs in real time,
So that I can manage the session without SSH access to the runner.

**Acceptance Criteria:**

**Given** a Run is in `running` status
**When** an admin opens the admin session management page
**Then** they see a command input form where they can type Archipelago server commands (e.g. `/hint PlayerName game`, `/release PlayerName`, `/collect PlayerName`, free-text broadcast)
**And** submitting a command calls `POST /api/v1/admin/sessions/{id}/commands` with `{"command": "..."}`, which Symfony validates and forwards to Bridge.py's internal REST API `POST /commands`, which Bridge.py sends as a WS `Say` packet to MultiServer.py
**And** the command endpoint requires admin authentication; non-admin requests return 403
**And** a "Voir les logs" action opens a log viewer panel and immediately dispatches a `FetchLogsJob` via Messenger to the owning runner; the runner executes `docker logs --tail 200 --timestamps archipelago-run-{runId}` and POSTs the output back via callback; the API stores the result and returns it to the admin page
**And** while the log panel is open, the frontend automatically re-polls `GET /api/v1/admin/sessions/{id}/logs` every 10 seconds, triggering a new `FetchLogsJob` dispatch; each refresh replaces the panel content with the latest 200 lines
**And** the log viewer panel displays the 200 most recent log lines in a fixed-height monospace scrollable area; polling stops automatically when the panel is closed
**And** the `admin/sessions/{id}/logs` topic Mercure subscriber JWT is scoped to admins only
**And** a "Forcer la fin de la run" button (distinct from the "Stopper" control in Story 9.8 which only pauses the run) requires confirmation via AlertDialog, then POSTs to `POST /api/v1/admin/sessions/{id}/force-end`, which transitions the Run to `finished`, triggers archival (Story 9.16), and dispatches a `StopRunJob` to the runner
**And** all command and force-end actions are recorded in a run audit log with admin user ID and timestamp
**And** functional tests cover: command routing chain (API → Bridge REST → assertion on WS send), log fetch dispatch and delivery via Mercure, force-end with confirmation and StopRunJob dispatch

### Story 9.16: Run Archival and Statistics

As an admin or player,
I want the session's spoiler log, save file, and statistics archived when the run ends,
So that results are preserved and publicly accessible as history.

**Acceptance Criteria:**

**Given** a Run transitions to status `finished` (via all GOAL or admin force-end)
**When** the Run status is persisted as `finished`
**Then** the API dispatches an `ArchiveRunJob` via Messenger to the runner that owns the run
**And** the runner handler copies the `.apsave` file from the save volume to a permanent archive directory (local path or S3-compatible storage configured via env); the spoiler log (if present in the output volume) is also copied
**And** before stopping the container, the handler calls `GET http://{runner_host}:{bridge_port}/state` to capture the final player aggregate from Bridge.py's in-memory state (this is authoritative - the `.apsave` on disk may be up to 60s behind); if Bridge.py is unreachable, the handler falls back to parsing the `.apsave` file directly
**And** the handler POSTs an archive callback to the central API containing: archive file paths, final per-slot stats, and confirmation of archival success
**And** the API stores on the `sessions` record: `archived_spoiler_path`, `archived_save_path` (the `finished_at` field is already set when the Run transitions to `finished` in Story 9.7); per-slot stats (`checks_done`, `items_received`, `goal_reached_at`) are stored on `session_slots` from the final state snapshot received in the archive callback
**And** a public results page is available at `/evenements/{slug}/session/resultats` showing the most recent `finished` session: run duration (`started_at` → `finished_at`), ranked list of slots (GOAL slots by `goal_reached_at` ascending, non-GOAL slots below), checks and items per slot
**And** if an event has multiple past sessions (test runs, reruns), an admin-visible session history selector allows navigating between them; the public page always shows the most recent `finished` session
**And** the results page is publicly accessible (no authentication required) once the Run is `finished`
**And** admins can download the spoiler log and `.apsave` file from the admin session page via authenticated download endpoints
**And** `GET /api/v1/admin/sessions/{id}/export?format=json` and `?format=csv` return per-slot stats (slot name, player, game, checks_done, items_received, goal_reached_at)
**And** functional tests cover: ArchiveRunJob dispatch on finish, stats persistence per slot, public results page accessibility by unauthenticated user, export JSON/CSV format correctness

## Epic 10: Identité visuelle & médias - pages publiques

Transformer les pages publiques d'un site fonctionnel en une vitrine professionnelle qui reflète l'identité d'ArchiLAN - logo officiel, atmosphère gaming, photos d'événements réels.

**FRs covered:** New scope - visual quality and brand identity enhancement not in original PRD.

### Story 10.1: Logo officiel dans la navigation et le footer

As a visitor,
I want to see the real ArchiLAN logo throughout the site,
So that I immediately recognise the association's visual identity.

**Acceptance Criteria:**

**Given** the public shell exists
**When** a visitor views any public page
**Then** the ArchiLAN illustrated logo (six circular game worlds) appears in the sticky navigation bar
**And** the same logo appears in the footer alongside the copyright text
**And** the old placeholder badge ("A") is fully removed from all public-facing surfaces
**And** the logo is stored in `public/images/logo.webp` and served locally - no external CDN dependency
**And** the logo renders at 36px in the navbar and 24px in the footer with correct aspect ratio

### Story 10.2: Hero immersif avec photo d'événement

As a first-time visitor,
I want a visually impactful homepage hero that shows real event atmosphere,
So that I immediately understand the LAN gaming culture of ArchiLAN.

**Acceptance Criteria:**

**Given** the homepage exists
**When** a visitor opens the landing page
**Then** the hero section spans the full container width with the event photo as background
**And** a dark gradient overlay (left → right and top → bottom) ensures headline text is readable
**And** the ArchiLAN logo is displayed within the hero above the headline
**And** the headline "Un item de ton jeu. Le monde entier." remains the primary message
**And** the two-column fake Archipelago example card is removed
**And** the "C'est quoi Archipelago ?" explainer below is restructured into three clean feature cards
**And** the layout is responsive and readable at 375px

### Story 10.3: Favicon et Open Graph

As a visitor sharing or bookmarking the site,
I want proper visual previews when sharing links and a recognisable favicon,
So that ArchiLAN looks professional when shared on Discord, Twitter, or in browser tabs.

**Acceptance Criteria:**

**Given** any public page is opened or shared
**When** the page metadata is read by a browser or social platform
**Then** the browser tab displays the ArchiLAN logo as favicon
**And** sharing the homepage URL shows the event photo as og:image with correct title and description
**And** `og:locale` is set to `fr_FR` and `og:site_name` to `ArchiLAN`
**And** og:image dimensions are declared (6000×4000)
**And** the description is updated to reflect the ArchiLAN mission accurately

### Story 10.4: Section galerie événements sur la homepage

As a visitor,
I want to see photos from past events on the homepage,
So that I can visualise the atmosphere before deciding to register.

**Acceptance Criteria:**

**Given** the homepage exists
**When** a visitor scrolls past the Archipelago explainer section
**Then** they see a "Nos événements" gallery section with a masonry-style grid
**And** available photos display an event label (e.g. "ARCHILAN 3") and a gradient overlay
**And** unavailable slots show styled placeholder cards with an image icon and "Photos à venir"
**And** a "Voir tous les événements →" link is visible at the top right of the section
**And** the grid is responsive: 1 column mobile, 2 columns tablet, 3 columns desktop with row-spanning large card

### Story 10.5: Cover image par événement

As a visitor,
I want each event to have a representative cover photo,
So that the event listing and detail pages feel visually rich.

**Acceptance Criteria:**

**Given** an admin is editing an event
**When** they set a cover image URL
**Then** the event detail page displays the cover as a hero image
**And** the event listing card displays a cropped version of the cover
**And** events without a cover image display a neutral placeholder
**And** `cover_image_url` (snake_case) is stored in the `events` table via a Doctrine migration
**And** the API serialises the field as `coverImageUrl` (camelCase) in the event payload
**And** the admin event edit form includes a cover image URL field

### Story 10.6: Galerie photos par événement

As a visitor,
I want to see a photo gallery on past event pages,
So that I can relive or discover the atmosphere of a specific event.

**Acceptance Criteria:**

**Given** a completed event with photos configured exists
**When** a visitor opens the event detail page
**Then** they see a responsive photo gallery grid below the main event content
**And** photos are stored as a JSON array of URLs in a `photo_gallery` column on the `events` table
**And** the gallery displays between 2 and 12 photos
**And** events with no photos configured do not show the gallery section
**And** an admin can set and update photo URLs from the event edit form in the backoffice

### Story 10.7: Cover image par article

As a visitor,
I want news and recap articles to have cover images,
So that the content section feels as polished as the events section.

**Acceptance Criteria:**

**Given** a published news post or recap exists
**When** a visitor opens the news listing or article detail page
**Then** articles with a cover image show it in the listing card and as a header on the detail page
**And** articles without a cover image display a neutral placeholder
**And** `cover_image_url` is added to the `posts` table via a Doctrine migration
**And** the API serialises `coverImageUrl` in the post payload
**And** the admin content editor includes a cover image URL field

### Story 10.8: Gaming atmosphere design refresh

As a visitor,
I want the public site to feel visually immersive and gaming-adjacent,
So that the atmosphere matches the cooperative LAN gaming culture of ArchiLAN.

**Acceptance Criteria:**

**Given** any public page is open
**When** a visitor views the page
**Then** a subtle repeating grid pattern is visible on the background, adding depth without cluttering
**And** the navigation bar has a faint teal glow line below it
**And** all interactive cards (feature cards, event cards, community links) emit a soft teal glow on hover
**And** the active navigation link's bottom border has a teal glow
**And** the primary CTA button ("Voir les événements") has a resting teal glow that intensifies on hover

**Given** the homepage hero is displayed
**When** a visitor views the headline
**Then** the h1 text renders as a gradient from white to teal
**And** the overline "Association Archipelago en France" uses the magenta brand colour (`--color-special`) instead of warm orange

**Given** the design tokens exist in globals.css
**When** the gaming design is applied
**Then** no new colours are introduced - only existing tokens (`--color-accent`, `--color-special`) are used
**And** the changes are CSS/Tailwind only with no backend impact

## Epic 13: Secure Token Lifecycle - Refresh Token

The authentication system is upgraded from a single long-lived JWT cookie to a short-lived access token + long-lived refresh token pair, both httpOnly Secure SameSite cookies. The API handles token rotation with reuse detection; the frontend silently refreshes expired sessions without user action.

### Story 13.1: Refresh Token Domain Model and Storage

As a system,
I want to persist refresh tokens server-side with revocation support,
So that tokens can be validated, rotated, and invalidated individually.

**Acceptance Criteria:**

**Given** the database migration runs
**When** the schema is applied
**Then** a `refresh_tokens` table exists with columns: `id`, `user_id` (FK), `token_hash` (SHA-256 of raw token), `expires_at`, `revoked_at` (nullable), `created_at`, `user_agent` (nullable)
**And** an index exists on `(user_id, revoked_at)` for efficient lookups
**And** a `RefreshToken` Doctrine entity and repository are implemented in the `Identity` domain
**And** the repository exposes: `findByTokenHash(string): ?RefreshToken`, `revokeByUser(UserId): void`, `deleteExpiredBefore(DateTimeImmutable): int`
**And** the raw token value (64 random bytes, base64url-encoded) is never stored; only its SHA-256 hash persists

### Story 13.2: Dual-Cookie Token Issuance on Authentication

As a registered user,
I want login to issue both a short-lived access token and a long-lived refresh token,
So that my session stays active without re-entering credentials frequently.

**Acceptance Criteria:**

**Given** valid credentials are submitted to `POST /auth/login`
**When** the response is sent
**Then** two httpOnly Secure SameSite=Lax cookies are set: `access_token` (JWT, TTL 15 minutes) and `refresh_token` (opaque, TTL 30 days)
**And** the raw refresh token is hashed (SHA-256) before being persisted in `refresh_tokens`
**And** the `access_token` cookie has `Path=/` and the `refresh_token` cookie has `Path=/auth/refresh` to minimise exposure
**And** no token value is returned in the response body
**And** existing login behaviour for invalid credentials and CSRF is unchanged

### Story 13.3: Refresh Endpoint with Token Rotation and Reuse Detection

As an authenticated client,
I want a dedicated endpoint to exchange a valid refresh token for a new token pair,
So that my session extends transparently without storing credentials.

**Acceptance Criteria:**

**Given** a valid, non-revoked refresh token cookie is present
**When** `POST /auth/refresh` is called
**Then** the existing refresh token is revoked (sets `revoked_at`)
**And** a new access token and refresh token pair is issued in cookies (same cookie attributes as Story 13.2)
**And** the new refresh token replaces the old one in `refresh_tokens`
**And** the response body is empty with status 204

**Given** an expired or absent refresh token cookie
**When** `POST /auth/refresh` is called
**Then** the response is 401 with a generic `invalid_refresh_token` error code
**And** both cookies are cleared

**Given** a refresh token that has already been revoked (reuse attack scenario)
**When** `POST /auth/refresh` is called
**Then** all refresh tokens for the associated user are immediately revoked
**And** the response is 401 with `token_reuse_detected` error code
**And** the security event is logged with user ID and request metadata

### Story 13.4: Frontend Silent Refresh Interceptor

As a user with an expired access token,
I want the frontend to transparently refresh my session,
So that I am not interrupted mid-action by an unexpected logout.

**Acceptance Criteria:**

**Given** the fetch utility used across the frontend is centralised
**When** any API call returns 401
**Then** the interceptor calls `POST /auth/refresh` once
**And** if refresh succeeds (204), the original request is retried automatically
**And** if refresh fails (401), the authenticated client state is cleared and the user is redirected to `/connexion` with the current path as `?next=` query param
**And** requests to `/auth/refresh` itself are never retried to avoid infinite loops
**And** concurrent 401 responses during a single refresh trigger only one refresh call (queued retry pattern)

### Story 13.5: Logout with Server-Side Token Revocation

As an authenticated user,
I want logout to invalidate my refresh token server-side,
So that stolen cookies cannot be used to obtain new tokens after I sign out.

**Acceptance Criteria:**

**Given** a user is authenticated with a valid refresh token cookie
**When** `POST /auth/logout` is called
**Then** the refresh token identified by the cookie is revoked in the database
**And** both `access_token` and `refresh_token` cookies are cleared (Set-Cookie with `Max-Age=0`)
**And** the response is 204 regardless of whether the token was found (idempotent)
**And** subsequent calls to `/auth/refresh` with the old cookie return 401

### Story 13.6: Expired Token Cleanup Command

As an operator,
I want a Symfony console command to prune stale refresh token records,
So that the `refresh_tokens` table does not grow indefinitely.

**Acceptance Criteria:**

**Given** the command `app:auth:cleanup-refresh-tokens` exists
**When** it is executed
**Then** all rows where `expires_at < now()` OR (`revoked_at IS NOT NULL` AND `revoked_at < now() - 7 days`) are deleted
**And** the number of deleted rows is logged at `info` level via `LoggerInterface`
**And** the command is safe to run in production under concurrent load (DELETE with WHERE, no full-table lock)
**And** a cron entry is documented in `docker-compose.yml` or a Symfony Scheduler message to run daily

---

## Epic 11: Session Management UX/UI Overhaul

Admins experience a streamlined session pipeline - fewer manual steps, animated visual pipeline bar, gaming-aesthetic status cards, and a polished terminal for commands/logs. Players see an informative waiting state, prominent connection info, animated progress cards, and a richly styled event feed. All changes are purely frontend: no API contract changes, no new dependencies, Tailwind CSS 4 animations only.

### Requirements (UX Pass 2026-05-07)

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

### Personal Runs Requirements (2026-05-12)

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

### Session Lifecycle Requirements (2026-05-12)

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

### Story 11.1: Merge Admin Validation + Generation Steps

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

### Story 11.2: Visual Session Pipeline Bar

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

### Story 11.3: Skeleton Loaders for All Loading Zones

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

### Story 11.4: Inline Session Builder Wizard

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

### Story 11.5: Admin Session Detail Visual Overhaul

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

### Story 11.6: Player Session Page Visual Overhaul

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

### Story 11.7: Player Progress Grid Redesign

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

### Story 11.8: Event Feed Redesign

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

## Epic 16: Personal Runs - Private User-Created Archipelago Games

Authenticated users can create private Archipelago runs outside of any ArchiLAN event, configure game worlds, invite friends via an opaque shareable link, and start the server - reusing the runner infrastructure from Epic 9.

**FRs covered:** FR-PR1, FR-PR2, FR-PR3, FR-PR4, FR-PR5, FR-PR6, FR-PR7, NFR-PR1, NFR-PR2, NFR-PR3.

### Story 16.1: Personal Run Domain Model and API

As an authenticated user,
I want to create and manage personal Archipelago runs via the API,
So that I can start private games independently of association events.

**Acceptance Criteria:**

**Given** an authenticated user calls `POST /api/v1/runs` with a valid title
**When** the request is processed
**Then** a new `PersonalRun` record is created with status `draft`, a unique 32-hex-char id, and the caller set as owner
**And** the response is `201 Created` with `{ "data": { "id", "title", "status", "inviteToken", "ownerId", "createdAt", "updatedAt" } }`
**And** the `invite_token` is a 32-byte random hex string (64 chars), unique and non-sequential

**Given** an authenticated user calls `GET /api/v1/runs/mine`
**When** the request is processed
**Then** the response lists all PersonalRuns owned by the caller, ordered by `created_at` descending
**And** runs belonging to other owners are not included

**Given** an authenticated user calls `GET /api/v1/runs/{runId}`
**When** the run exists and the caller is the owner or a participant
**Then** the response includes full run details including game config, status, and participants list
**And** a caller who is neither owner nor participant receives 403

**Given** an authenticated user calls `DELETE /api/v1/runs/{runId}`
**When** the run exists, the caller is the owner, and the run status is `draft` or `idle`
**Then** the run is soft-deleted (status `cancelled`) and the response is 204
**And** attempting to delete a run with status `active` returns 422 with code `run_active` - the run must be stopped first

**And** unauthenticated requests to all `/runs` endpoints return 401
**And** the `PersonalRun` entity lives in a new bounded context `App\PersonalRuns\Domain`
**And** a Doctrine migration creates table `personal_runs` (id, owner_id FK users, title, status, invite_token UNIQUE, game_selection_config JSON, created_at, updated_at)
**And** functional tests cover: create, list mine, get as owner, get as participant (403), delete draft, delete active (422)

### Story 16.2: Invite Link Generation and Join Flow

As a run owner,
I want to share a private invite link,
So that friends can join my personal run without it being publicly discoverable.

**Acceptance Criteria:**

**Given** a run owner calls `POST /api/v1/runs/{runId}/invite/regenerate`
**When** the run exists and the caller is the owner
**Then** a new `invite_token` is generated (old token invalidated) and the response returns the new token and the full invite URL

**Given** an authenticated user follows `GET /api/v1/runs/join/{inviteToken}`
**When** the token matches a non-cancelled run and the caller is not already a participant
**Then** a `PersonalRunParticipant` record is created (`personal_run_id`, `user_id`, `joined_at`)
**And** the response is `200 OK` with the run payload (same shape as Story 16.1 GET)
**And** the user is now visible in the participants list of the run

**Given** an authenticated user follows the same invite link a second time
**When** the caller is already a participant
**Then** the response is `200 OK` (idempotent - no duplicate participant created)

**Given** an unauthenticated visitor follows `GET /api/v1/runs/join/{inviteToken}`
**When** the token is valid
**Then** the response is 401 with code `auth_required` - an account is required to join

**Given** a token does not match any run, or the matched run is `cancelled`
**When** the join endpoint is called
**Then** the response is 404

**And** a Doctrine migration creates table `personal_run_participants` (personal_run_id FK, user_id FK, joined_at; PK: personal_run_id + user_id)
**And** functional tests cover: join as new participant, join idempotent, join unauthenticated (401), invalid token (404), cancelled run (404), regenerate token

### Story 16.3: Game Configuration for Personal Run

As a run owner,
I want to configure which Archipelago games are included in my run,
So that the multiworld generation has the correct game list when I start the server.

**Acceptance Criteria:**

**Given** a run owner calls `PATCH /api/v1/runs/{runId}/games` with `{ "games": [{ "gameId": "..." }, ...] }`
**When** the run is in `draft` or `idle` status and the caller is the owner
**Then** the `game_selection_config` JSON column is updated with the provided game list
**And** each `gameId` must match an existing game in the Archipelago game library (`App\GameSelection` domain)
**And** unknown `gameId` values return 422 with code `unknown_game` listing the invalid IDs

**Given** the owner updates games on an `active` run
**When** the request is processed
**Then** the response is 422 with code `run_active` - configuration changes require stopping the run first

**Given** a non-owner authenticated user calls the game config endpoint
**When** the request is processed
**Then** the response is 403

**And** a minimum of 1 game is required; an empty `games` array returns 422 with code `games_required`
**And** functional tests cover: valid config update (draft/idle), update on active run (422), unknown gameId (422), non-owner (403), empty games (422)

### Story 16.4: Server Launch and Connection Details

As a run owner,
I want to start the Archipelago server for my personal run and see connection details,
So that participants can connect and the game can begin.

**Acceptance Criteria:**

**Given** a run owner calls `POST /api/v1/runs/{runId}/start`
**When** the run is in `draft` status, has at least one game configured, and the owner has a runner available
**Then** the API dispatches a `LaunchPersonalRunJob` via Symfony Messenger (same runner infrastructure as Epic 9)
**And** the run status transitions to `active` once the container is running
**And** connection details (host, port) are stored on the run record once the server reports ready

**Given** the run is active and the caller is the owner or a participant
**When** `GET /api/v1/runs/{runId}` is called
**Then** the response includes `connectionHost`, `connectionPort`, and `connectionPassword` fields (null until active)

**Given** a run owner calls `POST /api/v1/runs/{runId}/start` when the run is already `active`
**When** the request is processed
**Then** the response is 422 with code `run_already_active`

**Given** a run owner calls `POST /api/v1/runs/{runId}/stop`
**When** the run is `active`
**Then** the API dispatches a `StopPersonalRunJob`, the container is stopped gracefully, the run transitions to `idle`, and connection details are cleared
**And** the response is 200 with the updated run payload

**And** a Doctrine migration adds `session_id` (nullable FK to sessions), `connection_host`, `connection_port`, `connection_password` columns to `personal_runs`
**And** functional tests cover: start (draft → active dispatch), start already active (422), stop (active → idle), get connection details when active, get connection details when idle (null)

### Story 16.5: Frontend - Run Creation and Dashboard

As an authenticated user,
I want a dashboard to create and manage my personal runs,
So that I can organize my private games from the site.

**Acceptance Criteria:**

**Given** an authenticated user navigates to `/runs`
**When** the page loads
**Then** they see a list of their personal runs with status badge, title, date created, and action buttons (View, Delete for draft/idle)
**And** a prominent "Créer une partie" button is visible
**And** runs are grouped: active first, then idle, then draft, then cancelled (collapsed by default)
**And** unauthenticated visitors are redirected to `/login?redirect=/runs`

**Given** the user clicks "Créer une partie"
**When** the creation form is shown
**Then** the form asks for a title (required, max 80 chars) and submits to `POST /api/v1/runs`
**And** on success, the user is redirected to `/runs/{runId}`

**Given** the user navigates to `/runs/{runId}` as the owner
**When** the page loads
**Then** they see the run title, status, list of participants, and game configuration section
**And** they see a "Copier le lien d'invitation" button that copies `{siteUrl}/runs/join/{inviteToken}` to clipboard with a toast confirmation
**And** if status is `draft`, a "Configurer les jeux" section and "Démarrer la partie" button are visible
**And** if status is `active`, connection details (host:port, password) are displayed prominently for all participants
**And** if status is `idle`, a "Reprendre" button is visible (links to Epic 17 restart flow)

**And** frontend lives in `src/features/personal-runs/`
**And** routes: `src/app/runs/page.tsx` (list), `src/app/runs/[runId]/page.tsx` (detail)

### Story 16.6: Frontend - Join via Invite Link and Participant View

As an invited user,
I want to join a personal run by following the invite link,
So that I can participate in the game and see connection details.

**Acceptance Criteria:**

**Given** an authenticated user navigates to `/runs/join/{inviteToken}`
**When** the page loads
**Then** the frontend calls `GET /api/v1/runs/join/{inviteToken}` which registers the user as a participant
**And** on success, the user is redirected to `/runs/{runId}` (participant view)
**And** if the token is invalid or the run is cancelled, a clear error message is shown with a link back to the homepage

**Given** an unauthenticated visitor navigates to `/runs/join/{inviteToken}`
**When** the page loads
**Then** they see a "Rejoindre la partie" page with the run title, a brief description, and a "Se connecter / créer un compte" CTA
**And** after authentication they are automatically redirected back to `/runs/join/{inviteToken}` to complete the join

**Given** the user is now a participant and the run is `active`
**When** they view `/runs/{runId}`
**Then** they see connection details (host, port, password) and the participant list (owner highlighted)
**And** they do NOT see configuration controls or the start/stop buttons (owner-only)

**And** `src/app/runs/join/[inviteToken]/page.tsx` handles the join route
**And** the join API call is triggered client-side on page load (not server-side, to avoid join on crawler visits)

---

## Epic 17: Session Lifecycle - Inactivity Timeout & Wake-on-Connect

Sessions (event-based and personal) auto-stop their Archipelago process after 1 hour of inactivity. The container stays alive; the bridge enters a wake-on-connect mode (TCP listener on the AP port). The first player connection attempt automatically restarts the AP process - no admin action required. An explicit "Reprendre" UI trigger is also supported. MinIO backup provides a safety net if the container is ever fully stopped.

**FRs covered:** FR-IT1, FR-IT2, FR-IT3, FR-IT4, FR-IT5, FR-IT6, NFR-IT1, NFR-IT2, NFR-IT3, NFR-IT4.

### Story 17.1: Activity Tracking on Sessions

As a system operator,
I want sessions to track when they last had Archipelago activity,
So that the inactivity watchdog has a reliable signal for when to pause a run.

**Acceptance Criteria:**

**Given** the Bridge.py service receives an Archipelago event (ItemSent, LocationChecked, or any game-state event)
**When** the event is processed
**Then** Bridge.py calls `PATCH /api/v1/sessions/{sessionId}/activity` (internal endpoint, bearer-token protected) with `{ "activityType": "check"|"item"|"hint", "occurredAt": "<ISO8601>" }`
**And** the Symfony API updates `sessions.last_activity_at` to the provided `occurredAt` timestamp (or server time if not provided)

**Given** a session is created (status `running`)
**When** no activity event has been received yet
**Then** `last_activity_at` defaults to the session `started_at` timestamp

**Given** the activity endpoint is called with an invalid or unknown `sessionId`
**When** the request is processed
**Then** the response is 404

**And** a Doctrine migration adds column `last_activity_at datetimetz_immutable DEFAULT NULL` to the `sessions` table
**And** the endpoint requires a machine-to-machine bearer token configured via `BRIDGE_INTERNAL_TOKEN` env var (not a user JWT)
**And** functional tests cover: activity update on existing session, default value equals started_at, unknown session (404), unauthenticated request (401)

### Story 17.2: Inactivity Watchdog - AP Process Stop & Wake-on-Connect Activation

As a system operator,
I want idle sessions to have their Archipelago process stopped automatically after 1 hour without activity,
So that CPU and game-server RAM are freed while the container stays alive for instant wake-on-connect.

**Acceptance Criteria:**

**Given** a Symfony Messenger scheduled message fires every 5 minutes
**When** the `InactivityWatchdogMessage` handler runs
**Then** it queries all sessions with status `running` where `last_activity_at < NOW() - INTERVAL ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS`
**And** for each such session, it dispatches a `PauseRunJob` to the owning runner

**Given** the runner receives a `PauseRunJob`
**When** the job executes
**Then** it calls Bridge.py's internal `POST /pause` endpoint
**And** Bridge.py triggers an Archipelago `/save` command and waits for the `.apsave` file to be written (timeout: 30s)
**And** Bridge.py uploads the `.apsave` file to MinIO at key `sessions/{sessionId}/saves/{timestamp}.apsave` as a safety-net backup
**And** Bridge.py kills the Archipelago process (SIGTERM, then SIGKILL after 5s if still alive)
**And** Bridge.py starts a TCP listener on the AP port (wake-on-connect mode)
**And** Bridge.py calls `POST /api/v1/sessions/{sessionId}/paused` (internal, bearer-token protected) with `{ "lastSaveKey": "<minio-key>", "pausedWithoutSave": false }`
**And** the Symfony API transitions the session status from `running` to `idle` and stores `last_save_key`
**And** the container is NOT stopped - it remains running with only the bridge alive

**Given** `ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS` is not set
**When** the watchdog evaluates sessions
**Then** it defaults to 3600 seconds (1 hour)

**Given** Bridge.py's `/save` call times out (AP process unresponsive)
**When** the PauseRunJob handles the timeout
**Then** Bridge.py kills the AP process anyway, enters wake-on-connect mode, and calls the paused callback with `{ "pausedWithoutSave": true }`
**And** the session is marked `idle` with `paused_without_save = true`
**And** an admin notification is dispatched via Messenger

**And** a Doctrine migration adds columns `last_save_key VARCHAR(500) DEFAULT NULL` and `paused_without_save BOOLEAN NOT NULL DEFAULT FALSE` to `sessions`
**And** the `InactivityWatchdogMessage` is configured as a Symfony Scheduler recurring message (every 5 minutes)
**And** functional tests cover: session below threshold (not paused), session above threshold (PauseRunJob dispatched), save timeout path (paused_without_save=true), default timeout value

### Story 17.3: Explicit Session Restart from UI ("Reprendre")

As a run owner or admin,
I want to explicitly restart a paused session from the UI,
So that I can resume a game without waiting for a player connection.

**Acceptance Criteria:**

**Given** an authenticated user calls `POST /api/v1/sessions/{sessionId}/restart`
**When** the session exists with status `idle`, and the caller is either an admin or the owner of the personal run
**Then** the API calls Bridge.py's internal `POST /resume` endpoint (bearer-token protected) directly on the container
**And** the Symfony API transitions the session status to `restarting`
**And** the response is 202 with `{ "data": { "sessionId", "status": "restarting" } }`

**Given** Bridge.py receives `POST /resume`
**When** the bridge is in wake-on-connect mode (TCP listener active)
**Then** the bridge closes the TCP listener
**And** launches the Archipelago process using the most recent `.apsave` file on disk in the container (falling back to MinIO `last_save_key` if no local file exists)
**And** once the AP server reports ready (health check), Bridge.py calls `POST /api/v1/sessions/{sessionId}/restarted` (internal)
**And** the Symfony API transitions the session status from `restarting` to `running` and resets `last_activity_at` to NOW()

**Given** the session has `paused_without_save = true` and no local `.apsave` file exists
**When** the restart is attempted
**Then** the response is 422 with code `no_save_available`

**Given** a session with status `running` or `completed` is targeted
**When** the restart endpoint is called
**Then** the response is 422 with code `invalid_session_status`

**Given** a non-admin, non-owner authenticated user calls the restart endpoint
**When** the request is processed
**Then** the response is 403

**And** functional tests cover: successful restart dispatch (idle → restarting), bridge callback (restarting → running), no save available (422), already running (422), non-owner non-admin (403)

### Story 17.4: Frontend - Idle Session Status and Restart UI

As a run owner or admin,
I want to see clearly when a session is paused and have the option to restart it from the UI,
So that I can resume a game without waiting for a player connection.

**Acceptance Criteria:**

**Given** a personal run or event session has status `idle`
**When** the owner views `/runs/{runId}` or the admin views the session management page
**Then** the run card displays an "En pause" amber status badge
**And** a subtitle shows the time since last activity (e.g. "Inactif depuis 1h 23min")
**And** an info callout explains: "La partie redémarre automatiquement dès qu'un joueur tente de se connecter. Vous pouvez aussi la relancer manuellement."
**And** a "Reprendre manuellement" secondary button is visible
**And** if `paused_without_save` is true, the button is disabled with tooltip "Reprise impossible : aucune sauvegarde disponible"

**Given** the user clicks "Reprendre manuellement"
**When** the `POST .../restart` request is in flight
**Then** the button shows a loading spinner and is disabled to prevent double-submit
**And** the status badge updates to "Redémarrage en cours..."

**Given** the session transitions to `restarting` (triggered either by UI or by wake-on-connect)
**When** the frontend detects the status change (polling `GET .../` every 5s while restarting)
**Then** the status badge shows "Redémarrage en cours..." with a spinner

**Given** the session transitions back to `running`
**When** the frontend detects the status change
**Then** the "En pause" badge is replaced by the "En cours" active badge
**And** connection details are shown again
**And** a success toast "Partie reprise avec succès" appears

**Given** an admin views the backoffice session list
**When** one or more sessions have status `idle`
**Then** idle sessions appear in a dedicated "Sessions en pause" section above completed sessions
**And** the "Reprendre" action is available inline in the admin list

**And** all status polling uses TanStack Query with 5-second refetch interval while status is `restarting`
**And** polling stops once status returns to `running` or reaches a terminal state

### Story 17.5: Bridge - Wake-on-Connect TCP Listener

As a player,
I want the Archipelago server to restart automatically when I attempt to connect to a paused game,
So that I can resume playing without having to ask anyone to restart it.

**Acceptance Criteria:**

**Given** Bridge.py has killed the AP process due to inactivity (Story 17.2)
**When** the bridge enters idle mode
**Then** it opens a TCP server socket on `config.ap_port` (same port the AP server normally uses)
**And** the socket accepts one connection at a time in a non-blocking loop (does not block the bridge's main heartbeat or REST loops - runs in a dedicated thread or asyncio task)

**Given** the TCP listener is active and a client connects on the AP port
**When** the connection is accepted
**Then** Bridge.py immediately closes the accepted socket (the client receives a connection reset or empty response - this is expected and acceptable UX)
**And** Bridge.py closes the TCP listener socket (no longer listening)
**And** Bridge.py calls `POST /api/v1/sessions/{sessionId}/restarting` (internal, bearer-token protected) to notify Symfony
**And** Symfony transitions the session status from `idle` to `restarting`
**And** Bridge.py launches the AP process using the most recent `.apsave` file on disk (falling back to MinIO download if none found)
**And** once the AP server passes a health check (TCP connect succeeds on port), Bridge.py calls `POST /api/v1/sessions/{sessionId}/restarted`
**And** Symfony transitions the session status from `restarting` to `running` and resets `last_activity_at`

**Given** the AP process fails to start (crash at launch)
**When** the restart attempt fails
**Then** Bridge.py calls `POST /api/v1/sessions/{sessionId}/restart-failed` (internal)
**And** Symfony transitions the session to `idle` again and sets an error flag
**And** an admin notification is dispatched

**Given** Bridge.py itself crashes while in wake-on-connect mode (unexpected)
**When** the container is still alive but the bridge process is dead
**Then** the runner's health check detects a dead bridge (no heartbeat) and marks the session as `idle` with a `bridge_crashed` flag; no automatic recovery attempted - admin action required

**And** unit tests for the TCP listener: listener starts on correct port, connection triggers restart sequence, failed AP launch triggers error callback
**And** the TCP listener port is derived from `config.ap_port` (no new config required)

## Epic 18: Run History, Player Profiles & Community Leaderboards

Players and visitors can explore completed run results, personal history across all runs, and community-wide leaderboards. Slot stats are automatically invalidated when a player forfeits and their slot is released/collected, ensuring leaderboard integrity. The data layer already exists (`checksDone`, `itemsReceived`, `goalReachedAt` on `SessionSlot`, timestamps on `Session`) — this epic exposes it engagingly.

### New Requirements

#### Functional Requirements

FR-HC1: Visitors can view a public run results page (`/runs/{id}/resultats`) showing per-slot stats (checks done, items received, goal reached, playtime), grouped by slot outcome: "Objectif atteint" / "Incomplet" / "Forfait".
FR-HC2: A slot is invalidated when an admin (backoffice) or the personal-run owner triggers the release/collect action AND the slot has no `goal_reached_at`. Slots where `goal_reached_at IS NOT NULL` are immune — a completed player can never be invalidated.
FR-HC3: A `was_released` boolean column is added to `session_slots` and set to `true` atomically within the same transaction as the release/collect action.
FR-HC4: Invalidated slots display a distinct "Forfait" badge on the results page and are excluded from all leaderboard aggregations (goal count, checks count, completion rate). Their run attendance is counted in `runsParticipated` — showing up counts regardless of outcome.
FR-HC5: Any visitor (no login required) can view a public player profile page at `/joueurs/{slug}`. A new unique `slug` column is introduced on `identity_users` as part of this epic and generated from the player's `displayName`.
FR-HC6: The player profile page displays aggregated personal stats: total runs participated in, total checks done, total items received, goal completion rate, and number of goals reached — all metrics excluding invalidated slots from numerators; `runsParticipated` counts all slots.
FR-HC7: A public community leaderboard at `/classements` ranks players on three axes: most goal completions, most checks done (all-time), and fastest single-run completion (goal reached, shortest elapsed time). Each axis is paginated, limit clamped to [1, 100], secondary tie-breaker is `displayName ASC`. Optional filter by event.
FR-HC8: A global community stats widget (embeddable on the landing page) shows: total sessions with status `finished`, total checks done across all non-invalidated slots, and total goals reached.
FR-HC9: Run results and leaderboards are only computed from sessions with status `finished`. Sessions in any other status (generating, running, idle, stopped, failed, crashed) expose no stats publicly.

#### Non-Functional Requirements

NFR-HC1: All API endpoints under this epic (`GET /api/v1/runs/{id}/results`, `GET /api/v1/players/{slug}`, `GET /api/v1/community/stats`, `GET /api/v1/leaderboard`) require no authentication. Frontend routes (`/runs/{id}/resultats`, `/joueurs/{slug}`, `/classements`) are public Next.js pages accessible without login.
NFR-HC2: Leaderboard queries must use indexed columns (`goal_reached_at`, `checks_done`, `session_id`, `was_released`) and paginate; a full table scan on `session_slots` is not acceptable at scale.
NFR-HC3: Setting `was_released = true` must be atomic with the parent release/collect transaction — no separate async job, no eventual consistency gap.
NFR-HC4: Leaderboard and global-stats endpoints must be cacheable; a 60-second server-side cache is acceptable (ETag or `Cache-Control: max-age=60`).
NFR-CQ1: Each test entity factory (`createUser`, `createEvent`, `createGame`, `createRegistration`) is defined exactly once in `FunctionalTestCase`; no functional test file retains its own copy.
NFR-CQ2: Controller auth guard logic is implemented in a single shared location; no controller retains an inline copy of the guard pattern.
NFR-CQ3: Null-check + not-found throw for entity lookups is encapsulated in one place; individual application services do not repeat the pattern inline.
NFR-CQ4: DBAL pagination (`setFirstResult` / `setMaxResults`) is encapsulated in one helper; query services do not inline the calculation.
NFR-CQ5: All four quality gates (PHPStan level max, CS Fixer, `phpunit`, DDD validator) pass green after every story in this epic.

#### FR Coverage Map Additions

FR-HC1: Epic 18 - Public run results page (grouped by outcome).
FR-HC2: Epic 18 - Slot invalidation rule (release/collect + no goal reached).
FR-HC3: Epic 18 - `was_released` column on `session_slots`.
FR-HC4: Epic 18 - Forfait badge + leaderboard exclusion + participation still counted.
FR-HC5: Epic 18 - Public player profile page + `slug` column on `identity_users`.
FR-HC6: Epic 18 - Aggregated personal stats on profile (excluding invalidated from numerators).
FR-HC7: Epic 18 - Community leaderboard page (3 axes, deterministic tie-breaker).
FR-HC8: Epic 18 - Global stats widget (`finished` sessions only).
FR-HC9: Epic 18 - Stats gated on `finished` status only.

---

### Story 18.1: Domain & Migration — `was_released` Flag on SessionSlot

As a developer,
I want a `was_released` flag on `SessionSlot` that is set when an admin releases/collects a forfeiting player's slot,
So that the system can distinguish intentionally invalidated participation from normal activity when computing stats.

**Acceptance Criteria:**

**Given** the existing `session_slots` table
**When** the migration is applied
**Then** a `was_released BOOLEAN NOT NULL DEFAULT FALSE` column is added to `session_slots`
**And** all existing rows default to `false` (no back-fill needed)

**Given** a `SessionSlot` domain entity
**When** `SessionSlot::markAsReleased()` is called
**Then** `wasReleased` is set to `true` only if `goalReachedAt` is `null`; the call is silently ignored for a slot where the goal was already reached (a completed player cannot be forfeited)

**Given** the release/collect action in `SessionOrchestrator` (or the handler that currently processes the slot release command for event sessions, or the personal-run owner triggering the equivalent action for a personal run)
**When** the action targets a slot whose registration is in a cancelled/abandoned state (or the personal-run participant has left)
**Then** `SessionSlot::markAsReleased()` is called inside the same DB transaction that persists the release action
**And** the transaction commits both changes atomically
**And** if the slot has `goalReachedAt` set, `markAsReleased()` is a silent no-op — the transaction still commits, the flag stays `false`

**Given** a slot with `was_released = true`
**When** it is serialized to the API response
**Then** the `wasReleased` boolean is included in the slot DTO (camelCase, as per project conventions)

**And** unit tests cover: `markAsReleased()` sets flag when goal not reached, `markAsReleased()` is a no-op when goal already reached
**And** a Doctrine migration file is generated under `api/migrations/`

---

### Story 18.2: API — Public Run Results Endpoint

As a visitor or player,
I want to fetch the results of a completed run via a public API endpoint,
So that the frontend can display per-slot stats without requiring authentication.

**Acceptance Criteria:**

**Prerequisite:** Story 18.1 (adds `was_released` column and `markAsReleased()`)

**Given** a `GET /api/v1/runs/{id}/results` request for a session with status `finished`
**When** the request is received (no authentication required)
**Then** the response is 200 with:
```json
{
  "data": {
    "sessionId": "uuid",
    "eventName": "ArchiLAN #12",
    "startedAt": "ISO8601",
    "finishedAt": "ISO8601",
    "durationSeconds": 14400,
    "slots": [
      {
        "slotId": "uuid",
        "playerName": "string",
        "game": "Hollow Knight",
        "checksDone": 42,
        "itemsReceived": 35,
        "goalReachedAt": "ISO8601 | null",
        "completionSeconds": 3600,
        "wasReleased": false,
        "isInvalidated": false
      }
    ]
  }
}
```
**And** `isInvalidated` is `true` when `wasReleased = true` and `goalReachedAt IS NULL`
**And** `completionSeconds` is `goalReachedAt - session.startedAt` in seconds, or `null` if goal not reached
**And** slots are ordered: goal-reached first (by `completionSeconds` asc), then incomplete (no goal, not released), then invalidated (was_released = true)

**Given** a `GET /api/v1/runs/{id}/results` request for a session that does not have status `finished` (e.g. status: `generating`, `running`, `idle`, `stopped`, etc.)
**When** the request is received
**Then** the response is 404 with code `run_not_found_or_not_finished`

**Given** a non-existent session ID
**When** the request is received
**Then** the response is 404

**And** a new `RunResultsController` (or equivalent) is created in the `Sessions` bounded context
**And** no authentication guard is applied to this endpoint
**And** functional tests cover: `finished` session (200 + correct payload + correct slot ordering), non-finished session (404 with `run_not_found_or_not_finished`), non-existent session (404), invalidated slot appears with `isInvalidated: true`

---

### Story 18.3: API — Player Profile and History Endpoints

As a visitor,
I want to fetch a player's public profile and run history,
So that the frontend can render their page without requiring authentication.

**Prerequisite:** Story 18.1 (adds `was_released` column)

**Acceptance Criteria:**

**Given** the existing `identity_users` table
**When** the Story 18.3 migration is applied
**Then** a `slug VARCHAR(80) NOT NULL UNIQUE` column is added to `identity_users`
**And** existing rows are back-filled: slug generated by lowercasing `display_name`, stripping accents, replacing spaces and special chars with hyphens, and appending a numeric suffix (`-2`, `-3`, …) to resolve collisions; if `display_name` is null, use the local part of `email_canonical`
**And** new user creation (`User::registerLambda`) sets `slug` at registration time using the same normalization logic (a `SlugGenerator` service handles both the normalization and collision check)

**Given** a `GET /api/v1/players/{slug}` request
**When** the player exists
**Then** the response is 200 with:
```json
{
  "data": {
    "slug": "jean",
    "displayName": "Jean",
    "joinedAt": "ISO8601",
    "stats": {
      "runsParticipated": 8,
      "goalCompletions": 5,
      "goalCompletionRate": 0.625,
      "totalChecksDone": 312,
      "totalItemsReceived": 287
    }
  }
}
```
**And** `runsParticipated` counts distinct `finished` sessions the player has a slot in, regardless of invalidation
**And** all other stats (`goalCompletions`, `goalCompletionRate`, `totalChecksDone`, `totalItemsReceived`) exclude slots where `was_released = true` AND `goal_reached_at IS NULL`

**Given** a `GET /api/v1/players/{slug}/history?page=1&limit=10` request
**When** the player exists
**Then** the response is 200 with a paginated list of runs, each containing:
  - `sessionId`, `eventName`, `finishedAt`, `game`, `checksDone`, `itemsReceived`, `goalReachedAt`, `wasReleased`, `isInvalidated`
**And** only runs from sessions with status `finished` are returned
**And** the list is ordered by `finishedAt` descending (most recent first)

**Given** a slug that matches no user
**When** either endpoint is called
**Then** the response is 404

**And** no authentication guard is applied to either endpoint
**And** functional tests cover: existing player with stats (200 + correct stat computation), player with no finished runs (200 + empty history), invalidated slot excluded from goal rate, non-existent slug (404)
**And** unit tests for `SlugGenerator`: normalization, accent stripping, collision suffix

---

### Story 18.4: API — Community Leaderboard and Global Stats Endpoints

As a visitor,
I want to query community leaderboards and aggregate stats,
So that the frontend can render the `/classements` page and the landing-page stats widget.

**Acceptance Criteria:**

**Prerequisite:** Story 18.1 (adds `was_released` column)

**Given** a `GET /api/v1/community/stats` request
**When** the request is received (no auth)
**Then** the response is 200 with:
```json
{
  "data": {
    "totalFinishedSessions": 42,
    "totalChecksDone": 18432,
    "totalGoalsReached": 156
  }
}
```
**And** `totalFinishedSessions` counts sessions with status `finished`
**And** `totalChecksDone` and `totalGoalsReached` exclude invalidated slots (`was_released = true` AND `goal_reached_at IS NULL`)
**And** the response includes `Cache-Control: public, max-age=60` header

**Given** a `GET /api/v1/leaderboard?axis=goals&page=1&limit=20` request (axis: `goals` | `checks` | `speed`)
**When** the request is received (no auth)
**Then** the response is 200 with a paginated ranking:
```json
{
  "data": [
    { "rank": 1, "slug": "jean", "displayName": "Jean", "value": 12, "unit": "goals" }
  ],
  "meta": { "axis": "goals", "page": 1, "total": 34 }
}
```
**And** for `axis=goals`: `value` = count of `goal_reached_at IS NOT NULL` across non-invalidated slots from `finished` sessions
**And** for `axis=checks`: `value` = sum of `checks_done` across non-invalidated slots from `finished` sessions
**And** for `axis=speed`: `value` = minimum `(goal_reached_at - session.started_at)` in seconds across the player's non-invalidated slots from `finished` sessions; players with no goal completion are excluded entirely
**And** `limit` is clamped server-side to [1, 100]; values outside this range are normalized without error
**And** an empty page (no results for the given page offset) returns `{ "data": [], "meta": { … "total": N } }` with status 200
**And** primary sort is by `value DESC`; secondary tie-breaker is `displayName ASC` (deterministic, case-insensitive)
**And** an optional `eventId` query param filters all three axes to sessions associated with that event
**And** the response includes `Cache-Control: public, max-age=60`

**Given** an invalid `axis` value
**When** the request is received
**Then** the response is 422 with a descriptive error

**And** database indexes are added on `session_slots(was_released, goal_reached_at)` and `session_slots(session_id)` if not already present
**And** functional tests cover: all three axes return correct ranking with correct tie-breaker order, eventId filter narrows results, empty page returns 200 + empty array, limit clamping, invalid axis → 422

---

### Story 18.5: Frontend — Public Run Results Page (`/runs/{id}/resultats`)

As a visitor or player,
I want to view a richly formatted results page for a finished run,
So that I can celebrate achievements, identify who completed what, and understand the run's outcome.

**Prerequisite:** Story 18.2 (run results API endpoint)

**Acceptance Criteria:**

**Given** the user navigates to `/runs/{id}/resultats`
**When** the API returns a session with status `finished`
**Then** the page renders a header with: event name, run date, total duration (formatted as `Xh Ym`)
**And** a results grid displays one card per slot with: player name, game, checks done, items received, goal status badge ("Objectif atteint" or "Incomplet"), and completion time if goal was reached
**And** slots are grouped in three labelled sections: "Objectifs atteints" (sorted by completion time asc), "Incomplets" (no goal, not invalidated), "Forfaits" (invalidated — `isInvalidated: true`)
**And** "Forfait" slot cards display an amber "Forfait" badge, checks and items are shown in muted style, and a tooltip explains "Statistiques exclues des classements (slot relâché)"
**And** the page is publicly accessible with no login requirement
**And** the page has proper `<title>` and `og:title` meta for social sharing (e.g. "Résultats de la run ArchiLAN #12")

**Given** the API returns 404 (session not finished or not found)
**When** the user navigates to `/runs/{id}/resultats`
**Then** a "Résultats non disponibles" placeholder is shown with a back link to the run page

**Given** the results page is loaded on mobile
**When** the viewport is below 768px
**Then** the grid collapses to a single-column list of cards with no horizontal scroll

**And** the page uses Next.js SSR (`getServerSideProps` or equivalent) to render results data on the server for SEO
**And** a "Voir le classement communautaire" link points to `/classements`

---

### Story 18.6: Frontend — Public Player Profile Page (`/joueurs/{slug}`)

As a visitor,
I want to view a player's profile with their run history and personal stats,
So that I can understand their involvement in ArchiLAN runs.

**Prerequisite:** Story 18.3 (player profile API endpoints)

**Acceptance Criteria:**

**Given** the user navigates to `/joueurs/{slug}` for an existing player
**When** the page loads
**Then** a profile header shows: display name, join date ("Membre depuis…"), and aggregated stats row: total runs, total checks done, goal completions, goal completion rate (as a percentage)
**And** below the stats, a "Historique des runs" section lists the player's finished runs in reverse-chronological order, each showing: event name, date, game played, checks done, items received, and a "Objectif atteint" or "Forfait" or "Incomplet" status badge
**And** each run row links to `/runs/{id}/resultats`
**And** invalidated (`isInvalidated: true`) runs display a "Forfait" amber badge and muted stats

**Given** there is no player matching the slug
**When** the page loads
**Then** a 404 page is rendered

**Given** the player has no finished run history
**When** the page loads
**Then** an empty state is shown: "Aucune run terminée pour l'instant"

**And** the page uses Next.js SSR for SEO
**And** `og:title` is set to the player's display name (e.g. "Jean - Profil ArchiLAN")

---

### Story 18.7: Frontend — Community Leaderboard Page (`/classements`) and Stats Widget

As a visitor,
I want to browse community leaderboards and discover top players,
So that I feel part of a competitive and engaged community.

**Prerequisite:** Story 18.4 (leaderboard and community stats API endpoints)

**Acceptance Criteria:**

**Given** the user navigates to `/classements`
**When** the page loads
**Then** three leaderboard tabs are displayed: "Objectifs", "Checks", "Vitesse"
**And** the active tab shows a ranked list of up to 20 players with: rank number, avatar placeholder (initials), display name (linking to their profile), and their value (e.g. "12 objectifs", "3 412 checks", "1h 23min")
**And** an "Événement" dropdown allows filtering all three leaderboards to a specific event (fetched from the events list)
**And** a "Voir plus" pagination link or button loads the next 20 entries within the current tab

**Given** the user lands on the tab for axis `speed`
**When** there are players with no goal completion
**Then** those players do not appear in the speed leaderboard (only players who reached at least one goal appear)

**Given** the community stats widget is embedded on the landing page
**When** the page loads
**Then** the widget shows three counters with animated number transitions on first viewport entry: "X runs terminées", "X checks complétés", "X objectifs atteints"
**And** the counters use data from `GET /api/v1/community/stats` fetched client-side (not blocking SSR)
**And** the widget degrades gracefully if the API call fails (counters show "-")

**Given** the user views the leaderboard on mobile
**When** the viewport is below 768px
**Then** the tabs are horizontally scrollable, the table collapses to a stacked card format, and rank numbers and values remain legible

**And** the leaderboard page uses Next.js SSR for initial data (axis=goals, no event filter) for SEO
**And** tab switching and event filter changes use client-side fetches (TanStack Query) without full page reload

---

## Epic 19: DDD Architecture Enforcement & Code Quality

Enforce DDD layer boundaries machine-checkably and eliminate the structural repetition patterns identified by a systematic audit of the API codebase. Stories 19.1-19.5 (existing, in review) extend the `app:architecture:ddd` validator, refactor CQRS violations out of controllers, and wire the validator into CI. Stories 19.6-19.10 (new) eliminate duplicated boilerplate across tests and application code without introducing new behaviour.

### New Requirements

#### Non-Functional Requirements

NFR-CQ1: Each test entity factory (`createUser`, `createEvent`, `createGame`, `createRegistration`) is defined exactly once in `FunctionalTestCase`; no functional test file retains its own copy.
NFR-CQ2: Controller auth guard logic is implemented in a single shared location; no controller retains an inline copy of the guard pattern.
NFR-CQ3: Null-check + not-found throw for entity lookups is encapsulated in one place; individual application services do not repeat the pattern inline.
NFR-CQ4: DBAL pagination (`setFirstResult` / `setMaxResults`) is encapsulated in one helper; query services do not inline the calculation.
NFR-CQ5: All four quality gates (PHPStan level max, CS Fixer, `phpunit`, DDD validator) pass green after every story in this epic.

#### NFR Coverage Map Additions

NFR-CQ1: Story 19.6 - test entity factories centralised in `FunctionalTestCase`.
NFR-CQ2: Story 19.7 - controller auth guard extracted to shared trait/base class.
NFR-CQ3: Story 19.8 - entity resolution helper in application layer.
NFR-CQ4: Story 19.9 - DBAL pagination helper.
NFR-CQ5: All stories 19.6-19.10 - quality gates enforced at every step.

---

### Story 19.1: Extend `DddArchitectureValidator` - Missing Contexts + CQRS Rules

*(Implementation artifact: `19-1-extend-ddd-validator-missing-contexts-cqrs-rules.md` - status: review)*

As a developer,
I want the `app:architecture:ddd` command to detect all CQRS boundary violations and know about every bounded context,
So that no future controller can bypass the architecture without a CI failure.

---

### Story 19.2: Extract SQL Reads from Epic 18 Controllers

*(Implementation artifact: `19-2-extract-reads-epic18-controllers.md` - status: review)*

As a developer,
I want the 5 Epic 18 public-API controllers to delegate all SQL reads to dedicated Application query classes,
So that the Presentation layer contains zero DB infrastructure and the CQRS boundary is enforced end-to-end.

---

### Story 19.3: Extract SQL Reads from Sessions Presentation Controllers

*(Implementation artifact: `19-3-extract-reads-sessions-controllers.md` - status: review)*

As a developer,
I want the Sessions presentation controllers to delegate all SQL reads to Application query classes,
So that the Presentation layer is free of direct DB access and the DDD validator reports 0 violations.

---

### Story 19.4: Extract Remaining CQRS Violations from 9 Controllers

*(Implementation artifact: `19-4-extract-reads-writes-remaining-controllers.md` - status: review)*

As a developer,
I want the remaining controllers with DB infrastructure violations refactored to use Application services,
So that `app:architecture:ddd` exits 0 on the full codebase.

---

### Story 19.5: CI Integration - `app:architecture:ddd` in Quality Gates

*(Implementation artifact: `19-5-ci-integration-architecture-validator.md` - status: review)*

As a developer,
I want the architecture validator to run in CI and in local quality gates,
So that no future PR can introduce a CQRS or DDD layer violation undetected.

---

### Story 19.6: Centralise Test Entity Factories in FunctionalTestCase

As a developer,
I want `createUser()`, `createEvent()`, `createGame()`, and `createRegistration()` defined once in `FunctionalTestCase`,
So that test files share a single, type-safe implementation and local copies cannot silently diverge.

**Acceptance Criteria:**

**Given** the existing `FunctionalTestCase` base class
**When** this story is complete
**Then** `FunctionalTestCase` exposes at minimum four protected factory methods:
- `createUser(string $email, array $roles = ['ROLE_USER'], ?string $displayName = null, ?string $slug = null): User`
- `createEvent(string $title, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, int $capacity = 50): Event`
- `createGame(string $name, string $slug): ArchipelagoGame`
- `createRegistration(string $eventId, string $userId, string $status = Registration::STATUS_RESERVED): Registration`
**And** each factory persists and flushes its entity via `$this->entityManager`
**And** each factory is typed such that PHPStan level max reports 0 errors on call sites

**Given** a functional test file that previously defined its own local `createUser()` (or `makeUser()`, `buildUser()`, or any equivalent) helper
**When** the migration to `FunctionalTestCase` is applied to that file
**Then** the local helper is removed and calls are replaced with `$this->createUser(...)` using equivalent arguments
**And** the test suite for that file passes without modification to assertions

**Given** a test file that passed extra arguments not covered by the base factory signature
**When** that file is migrated
**Then** the base factory is extended with a default parameter, or the file retains only an override that calls the shared factory internally - no file retains a full duplicate of the shared logic

**And** PHPStan level max, CS Fixer, and `phpunit` all pass after the migration
**And** the DDD validator exits 0 (factories live in `tests/Functional/`, not in `src/`)

---

### Story 19.7: Extract Controller Auth Guard to a Shared Trait

As a developer,
I want the auth guard boilerplate extracted from individual controllers into a single reusable location,
So that the pattern is enforced consistently and each controller action stays focused on its business purpose.

**Acceptance Criteria:**

**Given** the repeated pattern across 13+ controllers:
```php
$user = $this->requireUser($request);
if (!$user instanceof User) {
    return $user;
}
```
**When** this story is complete
**Then** a `RequiresAuthTrait` PHP trait (or an abstract `AuthenticatedController` base class) is created in `src/Shared/Presentation/`
**And** it exposes a single protected method:
```php
/** @return User|JsonResponse */
protected function requireAuthenticatedUser(Request $request): User|JsonResponse;
```
**And** the method returns the authenticated `User` on success and a `JsonResponse` (401 or 403) on failure
**And** all controllers that previously inlined this check use the shared method instead - no controller retains an inline copy

**Given** PHPStan analyses the controllers post-migration
**When** a call site does `$user = $this->requireAuthenticatedUser($request); if ($user instanceof JsonResponse) { return $user; }`
**Then** PHPStan level max reports 0 errors - the return type union is correctly narrowed

**And** CS Fixer reports 0 violations
**And** all existing functional tests for the affected controllers still pass

---

### Story 19.8: Named Entity Resolution Helper in Application Services

As a developer,
I want a shared `findOrFail` helper for entity lookups in application services,
So that the null-check + exception-throw boilerplate is written once and services remain readable.

**Acceptance Criteria:**

**Given** the pattern repeated 30+ times across application services:
```php
$entity = $this->entityManager->find(SomeEntity::class, $id);
if (null === $entity) {
    throw new \RuntimeException("not found: $id");
}
```
**When** this story is complete
**Then** an `EntityFinderTrait` is added to `src/Shared/Application/` exposing:
```php
/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
protected function findOrFail(string $class, string $id): object;
```
**And** the method throws a consistent exception when the entity is not found
**And** application services that previously inlined the pattern are migrated to `findOrFail()`

**Given** PHPStan analyses the migrated services
**When** a service calls `$slot = $this->findOrFail(SessionSlot::class, $id)`
**Then** PHPStan infers `$slot` as `SessionSlot` via the `@template` annotation
**And** PHPStan level max reports 0 errors

**Given** a service that previously threw a context-specific exception
**When** that service is migrated
**Then** it wraps `findOrFail()` with a catch+rethrow - the inline null-check boilerplate is eliminated in all cases

**And** CS Fixer reports 0 violations, `phpunit` passes, DDD validator exits 0

---

### Story 19.9: Encapsulate DBAL Pagination in a Query Helper

As a developer,
I want the `setFirstResult` / `setMaxResults` DBAL pagination calculation in one place,
So that the formula `($page - 1) * $limit` is not copy-pasted across paginated DBAL query services.

**Acceptance Criteria:**

**Given** the `($page - 1) * $limit` pagination formula used in DBAL QueryBuilder query services
**When** this story is complete
**Then** a `PaginationHelper` (final class, no state) is added to `src/Shared/Application/` exposing:
```php
public static function applyTo(QueryBuilder $qb, int $page, int $limit, int $minLimit = 1, int $maxLimit = 100): void;
```
**And** the method clamps `$limit` to `[$minLimit, $maxLimit]` before applying
**And** `PublicGameCatalog` (the only Application service that used `setFirstResult(($page-1)*$limit)->setMaxResults($limit)` via a `QueryBuilder`) is migrated to `PaginationHelper::applyTo()`

> **Scope note (added during implementation):** The original AC estimated "8+ query services". Codebase audit found exactly 1 service with a `Doctrine\DBAL\Query\QueryBuilder` + `($page-1)*$limit` pattern (`PublicGameCatalog`, migrated from ORM QB to DBAL QB as part of this story). `LeaderboardQuery` and `PlayerHistoryQuery` paginate via raw SQL heredocs / PHP `array_slice` — they do not use a `QueryBuilder` and are out of scope without a larger architectural change.

**Given** PHPStan analyses `PaginationHelper`
**When** it is passed a non-DBAL `QueryBuilder`
**Then** PHPStan reports a type error - the parameter is `Doctrine\DBAL\Query\QueryBuilder`, not a generic object

**And** CS Fixer reports 0 violations, `phpunit` passes

---

### Story 19.10: Extract Message Handler Error Logging Pattern

As a developer,
I want the try/catch/log boilerplate in message handlers centralised in a shared trait,
So that error handling is consistent and handlers remain focused on their command logic.

**Acceptance Criteria:**

**Given** the pattern repeated in 5+ message handlers:
```php
try {
    // handler logic
} catch (\Throwable $e) {
    $this->logger->error('handler_name failed: '.$e->getMessage(), ['exception' => $e]);
}
```
**When** this story is complete
**Then** a `LogsHandlerErrors` PHP trait is added to `src/Shared/Application/Handler/` exposing:
```php
protected function executeWithLogging(string $context, \Closure $fn): void;
```
**And** the method wraps `$fn()` in a try/catch, logs any `\Throwable` at `error` level with `['exception' => $e]` context, and re-throws so Messenger can retry or route to the failure transport
**And** all 5+ handlers that previously inlined the pattern use `executeWithLogging()` instead

**Given** a handler that previously swallowed exceptions (no re-throw)
**When** it is migrated
**Then** the behaviour change (swallow -> re-throw) is noted explicitly in the PR description

**Given** PHPStan analyses the trait
**When** a handler using `LogsHandlerErrors` does not declare a `LoggerInterface $logger` property
**Then** PHPStan reports an error - the trait includes a `@property-read LoggerInterface $logger` annotation to make the assumption explicit

**And** CS Fixer reports 0 violations, `phpunit` passes, DDD validator exits 0

---

## Epic 20: Code Quality Enforcement — Frontend & Bridge

Mirror the quality discipline of Epic 19 (API layer) on the two remaining stacks: the Next.js frontend and the Python bridge. Both stacks already have working code and passing basics (typecheck/lint/build for the frontend, a full pytest suite for the bridge), but neither has the same depth of static analysis, architectural constraints, or structural hygiene enforced by automated gates. Stories 20.1–20.4 address the bridge; stories 20.5–20.8 address the frontend.

### New Requirements

#### Non-Functional Requirements

NFR-BRIDGE1: The bridge has `ruff` and `mypy` running as CI quality gates with zero violations.
NFR-BRIDGE2: The bridge has no module-level mutable globals — all runtime state flows through explicit objects injected at construction time.
NFR-BRIDGE3: The bridge uses proper Python package imports — no `sys.path` manipulation at startup (the `sys.path` hack in `bridge.py` is removed; `save_parser.py`'s AP-source injection is a different concern and out of scope).
NFR-BRIDGE4: `rest.py` route handlers are extracted from the single `create_app` closure into named coroutines, each independently testable.
NFR-FE1: No `process.env` access outside `src/lib/env.ts` in the frontend (enforced by ESLint).
NFR-FE2: No `as SomeThing` type assertions at API response boundaries in the frontend (enforced by ESLint + type guard audit).
NFR-FE3: Every `*-api.ts` file has a corresponding Jest unit test file.
NFR-FE4: The frontend `QueryClient` and its `staleTime` / `gcTime` defaults are defined in a single shared location.

#### NFR Coverage Map

NFR-BRIDGE1: Story 20.1 — ruff + mypy as bridge quality gates.
NFR-BRIDGE2: Story 20.2 — eliminate module-level mutable state from rest.py.
NFR-BRIDGE3: Story 20.3 — fix sys.path import hack in bridge.py.
NFR-BRIDGE4: Story 20.4 — extract rest.py route handlers into named coroutines.
NFR-FE1: Story 20.5 — ESLint rule banning process.env outside env.ts.
NFR-FE2: Story 20.6 — type-guard completeness audit + ESLint assertion-style enforcement.
NFR-FE3: Story 20.7 — Jest unit test suite for the API layer.
NFR-FE4: Story 20.8 — centralise QueryClient configuration.

---

### Story 20.1: Ruff + Mypy as Bridge Quality Gates

As a developer,
I want `ruff check` and `mypy` to run as mandatory CI quality gates on the bridge,
So that Python style violations and type errors are caught before merge, mirroring PHPStan + CS Fixer on the API.

**Acceptance Criteria:**

**Given** `ruff` is configured in `pyproject.toml` (rule set already present)
**When** story 20.1 is complete
**Then** `ruff` and `mypy` are added to `bridge/requirements.txt` and both run in CI
**And** `ruff check bridge/` exits 0 — all existing violations (including `PLW0603` global-statement warnings, `PLC0415` import-not-at-top) are resolved or annotated with a `# noqa:` and an explanation comment
**And** `mypy` is configured in `pyproject.toml` with at minimum `disallow_untyped_defs = true` and `ignore_missing_imports = true` (broad stopgap only — suppresses all unresolved import errors including internal ones; must be narrowed to per-module overrides for external packages once Story 20.3 fixes the package structure)
**And** `mypy bridge/` exits 0 — all public function and method signatures carry type annotations
**And** both commands are added to the CI bridge job
**And** the full existing bridge test suite passes unchanged

---

### Story 20.2: Eliminate Module-Level Mutable State from rest.py

As a developer,
I want runtime pause/wake coordination state to live in an explicit object rather than module globals,
So that bridge modules have no hidden shared state and tests can instantiate multiple app instances in isolation.

**Context:**
`rest.py` currently holds two module-level mutables mutated via `global` statements inside `_pause_flow` and `_cancel_wake_task`:
```python
_wake_stop_event: asyncio.Event | None = None
_wake_task: "asyncio.Task[None] | None" = None
```
This is the Python equivalent of the static mutable properties banned in the API CLAUDE.md.

**Acceptance Criteria:**

**Given** the pause/wake coordination state is module-global today
**When** story 20.2 is complete
**Then** a `PauseResumeCoordinator` dataclass is introduced in `core/coordinator.py` holding `wake_stop_event` and `wake_task` as instance attributes
**And** `create_app` receives a `coordinator: PauseResumeCoordinator` parameter (defaulting to a new instance if omitted, for backwards compatibility)
**And** `_pause_flow` and `_cancel_wake_task` receive the coordinator as a parameter — no `global` statements remain in `rest.py`
**And** all callers of `create_app()` continue to work without signature changes; tests that previously accessed `_rest._wake_stop_event` / `_rest._wake_task` directly are updated to read the coordinator from the app
**And** `ruff check`, `mypy`, and `pytest` all pass

---

### Story 20.3: Fix sys.path Import Hack in bridge.py

As a developer,
I want bridge modules to be importable via proper Python package paths,
So that `import bridge.core.config` works correctly and mypy and ruff resolve symbols without path magic.

**Context:**
`bridge.py` currently inserts `core/` into `sys.path` so internal modules can do `from config import Config`. This breaks mypy's module graph, confuses IDEs, and makes internal imports indistinguishable from stdlib imports. The fix is relative imports inside `bridge/core/` and absolute package imports in `bridge/bridge.py`.

**Acceptance Criteria:**

**Given** all core modules use sibling imports (`from config import Config`, `from state import StateManager`)
**When** story 20.3 is complete
**Then** all imports inside `bridge/core/*.py` use relative imports (`from .config import Config`, `from .state import StateManager`)
**And** `bridge/bridge.py` removes the `sys.path.insert` block entirely
**And** private symbols (`_build_feed_event`, `_PRINT_TYPE_MAP`, `_WS_RETRY_DELAYS`, `_compute_reachable`, `_daemon_ready_events`, `_reachable_cache`, `_reachable_daemons`, `_start_daemon`) are removed from both `__all__` **and** the top-level `import` statements in `bridge.py` — removing from `__all__` alone is insufficient because symbols remain importable as long as they exist at module top-level
**And** `python -m bridge.bridge` and `python bridge/bridge.py` both run from the repo root with no `ImportError` or `ModuleNotFoundError` (both verified as explicit CI steps — no `|| true` masking)
**And** `mypy`, `ruff`, and `pytest` all pass

---

### Story 20.4: Extract rest.py Route Handlers into Named Coroutines

As a developer,
I want each REST route handler in `rest.py` to be a named async function rather than a closure nested inside `create_app`,
So that each handler is independently readable, testable in isolation, and fully analysable by mypy.

**Context:**
`create_app` in `rest.py` is ~300 lines with 10 route handlers defined as nested `async def` closures. Closures capture `state`, `ap_client`, `log`, and `reachable_semaphore` by reference, making it impossible to unit-test a single handler without invoking the full factory. The API's controller-per-action pattern is the reference model.

**Acceptance Criteria:**

**Given** all route handlers (`health`, `get_state`, `post_command` on `/commands`, `get_hints` on `/hints/{slot}`, `request_hint` on `/hints/{slot}/request`, `get_reachable` on `/reachable/{slot}`, `get_item_locations` on `/item-locations/{slot}`, `post_save`, `post_pause`, `post_resume`) are closures inside `create_app`
**When** story 20.4 is complete
**Then** each handler is extracted to a module-level `async def` function receiving its dependencies as parameters or reading them from `request.app`
**And** `AppKey` constants are extracted to a new `rest_keys.py` (imported by both `rest.py` and handler modules to avoid a circular import); handlers are always split by domain into `rest_session.py`, `rest_hints.py`, and `rest_reachable.py`; `rest.py` is reduced to `create_app` as the assembly point (route wiring only — no handler logic, no key definitions)
**And** at least 3 handlers gain dedicated unit tests in `tests/test_rest_handlers.py` covering a success path and one error path each, plus a route parity test
**And** `mypy`, `ruff`, and `pytest` (full suite + new tests) all pass

---

### Story 20.5: ESLint Rule — Ban process.env Outside env.ts

As a developer,
I want an ESLint rule that rejects any `process.env` access outside `src/lib/env.ts`,
So that AC-ENV1 is machine-enforced and cannot be violated silently by future code.

**Context:**
`AGENTS.md` AC-ENV1: "Never access `process.env` directly. Always go through `src/lib/env.ts`." This is currently convention-only. A grep audit verifies the current state; then an ESLint rule locks it permanently.

**Acceptance Criteria:**

**Given** `process.env` may exist in files other than `src/lib/env.ts`
**When** the audit is complete
**Then** every `process.env` access outside `src/lib/env.ts` in non-test files is replaced by the appropriate `env.*` accessor (test files `**/*.test.ts` and `**/*.test.tsx` are excluded from the audit — they intentionally use `process.env` for MSW base URL setup)

**When** all violations are resolved
**Then** an ESLint `no-restricted-syntax` rule is added to `eslint.config.*` reporting an error on any `MemberExpression` matching `process.env`, scoped to `src/**/*.{ts,tsx}` and excluding `src/lib/env.ts` and test files via the `ignores` field in the config block
**And** `pnpm lint` exits 0 with 0 warnings
**And** `pnpm typecheck` and `pnpm build` remain clean

---

### Story 20.6: Type-Guard Completeness Audit + ESLint Assertion Enforcement

As a developer,
I want all API response parse sites to use type guard functions rather than `as` casts,
So that AC-TS3 is verified to be fully respected and cannot regress.

**Context:**
`AGENTS.md` AC-TS3: "Never use `as SomeType` at API boundaries. All API responses are `unknown` until validated by a type guard function (`function isX(v: unknown): v is X`)." Each `*-api.ts` file should expose an `is{TypeName}` guard and return from it.

**Acceptance Criteria:**

**Given** all `src/features/**/*-api.ts` files parse API responses
**When** the audit is complete
**Then** every `as SomeType` cast on an API response value is replaced by an `is{TypeName}(payload)` type guard in the same file
**And** the ESLint rule `@typescript-eslint/consistent-type-assertions` is configured with `assertionStyle: "never"` scoped to `src/features/**/*-api.ts`
**And** `pnpm lint` exits 0 with 0 warnings
**And** `pnpm typecheck` and `pnpm build` remain clean

---

### Story 20.7: Jest Unit Test Suite for the API Layer

As a developer,
I want every `*-api.ts` file to have a corresponding Jest unit test file,
So that fetch logic, type guards, and error handling are verified independently of the browser and the running API.

**Context:**
The frontend currently has zero automated tests. The `src/features/**/*-api.ts` files are the highest-value first target: they contain fetch logic, type guards, and null-return error handling — all testable as pure functions with `fetch` mocked via MSW or `jest.fn()`.

**Acceptance Criteria:**

**Given** Jest is not yet installed
**When** story 20.7 begins
**Then** Jest is configured with the Next.js Jest preset (`next/jest`) and added to `package.json` as `pnpm test`
**And** MSW is added for network-level fetch mocking

**When** configuration is complete
**Then** each `src/features/**/*-api.ts` file has a sibling `*-api.test.ts` covering:
- Happy path: mock returns valid JSON → type guard passes → typed value returned
- Network error: fetch rejects → function returns `null`
- Malformed response: JSON parses but type guard fails → function returns `null`

**And** `pnpm test` runs all suites and exits 0
**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` remain clean

---

### Story 20.8: Centralise QueryClient Configuration

As a developer,
I want `QueryClient` instantiation and default `staleTime` / `gcTime` values defined in a single file,
So that caching behaviour is consistent across all features and changing a global default is a one-line edit.

**Context:**
`AGENTS.md` AC-API5 requires `staleTime` to be set explicitly on every `useQuery` call. Without a shared constant file, each call site chooses its own magic number. A `src/lib/query-client.ts` module exports the `QueryClient` factory and named time constants.

**Acceptance Criteria:**

**Given** `QueryClient` may be instantiated in multiple places and `staleTime` is set as a magic number at each `useQuery` call site
**When** story 20.8 begins
**Then** a grep audit identifies all `new QueryClient(...)` call sites and all raw `staleTime` / `gcTime` numeric literals

**When** the audit is complete
**Then** `src/lib/query-client.ts` is created exporting:
```ts
export const DEFAULT_STALE_TIME = 30_000;    // 30 s — standard catalog data
export const REALTIME_STALE_TIME = 5_000;    // 5 s — live session state
export const SESSION_STALE_TIME = 60_000;    // 60 s — session-level polling
export const STATIC_STALE_TIME = Infinity;   // legal pages, configuration
export const DEFAULT_GC_TIME = 300_000;  // 5 min (300 s) — garbage collection window

export function makeQueryClient(): QueryClient { ... }
```
**And** all `new QueryClient(...)` call sites use `makeQueryClient()`
**And** raw `staleTime` and `gcTime` numeric literals in `useQuery` and `useInfiniteQuery` calls are replaced by named constants
**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` remain clean
