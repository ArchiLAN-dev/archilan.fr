# Epic List

## Epic 0: Project Foundation & Quality Gates
Set up the monorepo, Next.js frontend, Symfony API, dependencies, CI, and quality gates so implementation can proceed in a coherent, repeatable state.
**FRs covered:** None directly; supports architecture requirements and quality NFRs.

## Epic 1: Public Community Hub & Archipelago Discovery
Visitors can understand ArchiLAN, discover Archipelago, browse public events and content, and access Twitch/Discord/community entry points.
**FRs covered:** FR1, FR2, FR3, FR5, FR6, FR7, FR8, FR9

## Epic 2: Accounts, Authentication & Role-Based Access
Visitors can create accounts, authenticated users can manage their profiles and data rights, and admins can manage users and roles.
**FRs covered:** FR29, FR30, FR31, FR32, FR33, FR34, FR35, FR36, FR37

## Epic 3: Event Lifecycle Backoffice
Admins can create, configure, publish, protect, manage, and complete events, including game library and game-selection setup.
**FRs covered:** FR10, FR11, FR12, FR13, FR14, FR15, FR19, FR20

## Epic 4: Event Registration & Archipelago Game Selection
Authenticated users can register for events, access private events, select and configure Archipelago games, update/cancel registrations, and receive confirmation.
**FRs covered:** FR21, FR22, FR23, FR24, FR25, FR26, FR27, FR28, FR46

## Epic 5: Admin Registration Operations
Admins can monitor registrations, inspect game selections, export data, handle capacity notifications, modify registrations, and contact participants.
**FRs covered:** FR16, FR17, FR18, FR45, FR47, FR48

## Epic 6: Payments, Ticketing & HelloAsso Sync
Visitors can use embedded HelloAsso checkout for tickets, memberships, and merchandise while admins see synchronized payment/order status in the ERP.
**FRs covered:** FR38, FR39, FR40, FR41, FR42

## Epic 7: Live Experience, Twitch & Realtime Presence
The site displays Twitch live/offline state and realtime activity signals, including live seat counters and resilient fallback behavior.
**FRs covered:** FR4, FR43, FR44

## Epic 8: Legal Compliance, Consent & Trust
The site satisfies required French legal/RGPD/CNIL surfaces, displays legal documents in the right flows, and manages cookie consent lifecycle.
**FRs covered:** FR49, FR50, FR51, FR52, FR53, FR54

## Epic 9: Archipelago Session Management
Admins can generate Archipelago multiworld sessions from confirmed event registrations, launch dedicated server containers automatically via Symfony Messenger workers on runner servers, and players receive real-time event feeds, progress dashboards, and connection details - fully automated, without manual file uploads. Run results are archived with statistics for post-event analysis.
**FRs covered:** New scope - not in original PRD. FR-R1–FR-R23, NFR-R1–NFR-R6 (Archipelago Run Generation architecture, 2026-05-05).

## Epic 11: Session Management UX/UI Overhaul
Admins experience a streamlined session pipeline - fewer manual steps, animated visual pipeline bar, gaming-aesthetic status cards, and a polished terminal for commands/logs. Players see an informative waiting state, prominent connection info, animated progress cards, and a richly styled event feed.
**FRs covered:** FR-UX1–FR-UX17, NFR-UX1–NFR-UX5 (UX sessions pass, 2026-05-07).

## Epic 13: Secure Token Lifecycle - Refresh Token
The authentication system is upgraded from a single long-lived JWT cookie to a short-lived access token + long-lived refresh token pair, both httpOnly Secure SameSite cookies. The API handles token rotation with reuse detection; the frontend silently refreshes expired sessions without user action.
**FRs covered:** NFR9 (strengthened), NFR18 (stateless refresh). New security scope.

## Epic 16: Personal Runs - Private User-Created Archipelago Games
Authenticated users can create private Archipelago runs outside of any ArchiLAN event, configure game worlds, invite friends via an opaque shareable link, and start the server - reusing the runner infrastructure from Epic 9.
**FRs covered:** FR-PR1, FR-PR2, FR-PR3, FR-PR4, FR-PR5, FR-PR6, FR-PR7, NFR-PR1, NFR-PR2, NFR-PR3.

## Epic 17: Session Lifecycle - Inactivity Timeout & Restart
Sessions (event-based and personal) auto-stop after 1 hour of inactivity, with graceful state save to MinIO, a distinct `idle` status, and a one-click restart that resumes from the saved state.
**FRs covered:** FR-IT1, FR-IT2, FR-IT3, FR-IT4, FR-IT5, NFR-IT1, NFR-IT2, NFR-IT3.

## Epic 18: Run History, Player Profiles & Community Leaderboards
Players and visitors can explore completed run results, personal history across all runs, and community-wide leaderboards. Slot stats are automatically invalidated when a player forfeits and their slot is released/collected, ensuring leaderboard integrity.
**FRs covered:** FR-HC1, FR-HC2, FR-HC3, FR-HC4, FR-HC5, FR-HC6, FR-HC7, FR-HC8, FR-HC9, NFR-HC1, NFR-HC2, NFR-HC3, NFR-HC4.

## Epic 21: Discord Bot - Role Synchronisation & Admin Panel
Users with linked Discord accounts automatically receive the Discord server role matching their ArchiLAN role; admins can monitor bot health and trigger resyncs from the backoffice.
**FRs covered:** FR-DB1, FR-DB2, FR-DB3, FR-DB4, FR-DB5, FR-DB6, FR-DB7, FR-DB8, NFR-DB1, NFR-DB2, NFR-DB3, NFR-DB4.

## Epic 22: Membership Management - Adhésions & Sync Dolibarr
Users can subscribe and renew their ArchiLAN membership autonomously via HelloAsso; the system handles role transitions, expiry reminders, Discord sync, and Dolibarr push automatically. Admins retain a full dashboard and manual override.
**FRs covered:** FR-ME1, FR-ME2, FR-ME3, FR-ME4, FR-ME5, FR-ME6, FR-ME7, FR-ME8, FR-ME9, FR-ME10, FR-ME11, FR-ME12, FR-ME13, NFR-ME1, NFR-ME2, NFR-ME3, NFR-ME4.
