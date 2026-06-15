# Requirements Inventory

## Functional Requirements

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

## NonFunctional Requirements

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
NFR-DB1: Discord Bot API calls (role assign/remove) must be non-blocking - dispatched as Messenger async messages, never inline in HTTP request handlers.
NFR-DB2: Sync failures must be logged via `LoggerInterface` (PSR-3) but must never surface as user-facing errors; the originating ArchiLAN action always succeeds independently.
NFR-DB3: `DiscordBotClientInterface` must have a null/stub implementation registered in `when@test` so CI passes without a real bot token.
NFR-DB4: The bot's Discord server role must be positioned above all managed ArchiLAN roles in the guild hierarchy (operational constraint, documented in Dev Notes).
FR-WR1: Members can view the current week's active Archipelago weekly run(s) and their leaderboards without authentication.
FR-WR2: Authenticated members can opt into an active weekly run; the system enforces the template's `maxAttempts` limit per user per week.
FR-WR3: The system automatically generates a deterministic seed and creates the `WeeklyRun` record each Monday at 00:00 UTC; individual player sessions are launched on demand when a member clicks "Lancer ma partie".
FR-WR4: The system automatically stops all weekly run containers at Sunday 23:59 UTC and marks each run as finished.
FR-WR5: Admins can create, edit, and soft-delete weekly run templates specifying game, yaml, name, and attempt limit.
FR-WR6: Admins can activate or deactivate a template to include or exclude it from the next automated weekly generation.
FR-WR7: Admins can view the current week's run status and all member entries from the backoffice.
FR-WR8: Three leaderboards are displayed per weekly run: fastest goal (MIN completion time), fewest checks (MIN checks_total), fewest items (MIN items_total).
FR-WR9: Each leaderboard entry shows the member's displayName, the relevant metric value, and their goal timestamp.
FR-WR10: The member-facing leaderboard updates in real time via Mercure when a player reaches the goal.
NFR-ME1: Membership expiry detection and role demotion must run via Symfony Scheduler (daily recurring task), not an external cron job.
NFR-ME2: HelloAsso payment processing and Dolibarr push are handled by async Messenger messages; a Dolibarr sync failure must not block membership activation.
NFR-ME3: `DolibarrClientInterface` must have a null stub implementation registered under `when@test:` in `services.yaml`.
NFR-ME4: Role change (ROLE_MEMBER promotion or demotion) and membership status update must be committed in the same database transaction.
NFR-WR1: `WeeklyRun` record creation (seed generation + DB persist) must complete within 10 seconds of the Monday 00:00 UTC scheduler tick. Individual player session launch (on-demand via `POST .../launch`) must return `connectionInfo` within 30 seconds.
NFR-WR2: The `--seed` parameter must be passed verbatim to the Archipelago generation CLI so the same seed string always produces the same game world.
NFR-WR3: Goal detection latency from bridge callback to leaderboard data update must be under 5 seconds end-to-end.
NFR-WR4: Weekly run scheduling must use the existing Symfony Scheduler provider (`src/Schedule.php`), not an external cron job.

## Additional Requirements

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

## UX Design Requirements

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

## FR Coverage Map

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

## FR Coverage Map (Discord Bot pass)

FR-DB1: Epic 21 - When a user links their Discord account, the bot assigns the ArchiLAN Discord server role matching the user's current ArchiLAN role (Admin → admin role, Membre → member role, User → user role).
FR-DB2: Epic 21 - When an admin promotes or demotes a user's ArchiLAN role, the bot syncs the corresponding Discord server role asynchronously within seconds.
FR-DB3: Epic 21 - When a user unlinks their Discord account, the bot removes all managed ArchiLAN roles from their Discord server membership.
FR-DB4: Epic 21 - A Symfony console command triggers a full resync of all linked accounts (bulk migration/fix tool).
FR-DB5: Epic 21 - Admins can view a bot status panel showing: bot connection status, Discord guild member count, and the list of managed role IDs.
FR-DB6: Epic 21 - Admins can view a per-user table showing each user's ArchiLAN role, Discord username, and last sync status/timestamp.
FR-DB7: Epic 21 - Admins can trigger a bulk resync of all linked accounts from the admin UI.
FR-DB8: Epic 21 - If the Discord bot API is unreachable, the ArchiLAN action (link/unlink/role change) still succeeds; the sync failure is logged and retried via Messenger.
FR-ME1: Epic 22 - A user can initiate a membership subscription or renewal via an embedded HelloAsso checkout in their account profile.
FR-ME2: Epic 22 - On a confirmed HelloAsso payment for the membership form, the system creates or renews the user's membership for a rolling 12-month period from the payment date; processing the same payment twice produces only one membership record.
FR-ME3: Epic 22 - Membership activation automatically promotes the user to ROLE_MEMBER (unless they are already ROLE_ADMIN).
FR-ME4: Epic 22 - Membership expiry automatically demotes the user from ROLE_MEMBER to ROLE_USER.
FR-ME5: Epic 22 - The system sends a confirmation email on membership activation including the expiry date and a thank-you message.
FR-ME6: Epic 22 - The system sends a renewal reminder email 30 days before expiry with a direct link to the membership checkout.
FR-ME7: Epic 22 - The system sends a second renewal reminder email 7 days before expiry.
FR-ME8: Epic 22 - The system sends an expiry notification email on the day of expiry informing the user their member status has been removed.
FR-ME9: Epic 22 - Every membership activation and expiry triggers a Discord Bot role sync (Epic 21): the member Discord role is assigned on activation and removed on expiry.
FR-ME10: Epic 22 - A user can view their current membership status (active / expired / none) and expiry date from their account profile.
FR-ME11: Epic 22 - Admins can view a paginated list of all memberships (active and expired) with search by email/name and filter by status.
FR-ME12: Epic 22 - Admins can manually create or renew a membership for any user (for offline payments or corrections), with a note field.
FR-ME13: Epic 22 - The system pushes membership data to Dolibarr via its REST API on creation, renewal, and expiry; push failures are logged and retried asynchronously without blocking the membership operation.
