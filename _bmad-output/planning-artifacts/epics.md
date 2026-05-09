---
stepsCompleted: ["step-01-validate-prerequisites", "step-02-design-epics", "step-03-create-stories", "step-04-final-validation", "step-01-validate-prerequisites-archipelago-run", "step-02-design-epics-archipelago-run", "step-03-create-stories-archipelago-run", "step-04-final-validation-archipelago-run", "step-01-validate-prerequisites-ux-sessions", "step-02-design-epics-ux-sessions", "step-03-create-stories-ux-sessions", "step-04-final-validation-ux-sessions"]
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
