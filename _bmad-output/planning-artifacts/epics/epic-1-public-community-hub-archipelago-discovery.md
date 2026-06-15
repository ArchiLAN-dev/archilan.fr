# Epic 1: Public Community Hub & Archipelago Discovery

Visitors can understand ArchiLAN, discover Archipelago, browse public events and content, and access Twitch/Discord/community entry points.

## Story 1.1: Public Shell, Navigation and Design Tokens

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

## Story 1.2: Landing Page with ArchiLAN Identity and Archipelago Explainer

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

## Story 1.3: Public Event Listings and Event Cards

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

## Story 1.4: Event Detail Public Page with SEO Metadata

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

## Story 1.5: Public News and Recap Reading

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

## Story 1.6: Admin Content Publishing for News and Recaps

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

## Story 1.7: Public Twitch and Discord Entry Points

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
