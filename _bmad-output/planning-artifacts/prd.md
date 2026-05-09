---
stepsCompleted: ["step-01-init", "step-02-discovery", "step-02b-vision", "step-02c-executive-summary", "step-03-success", "step-04-journeys", "step-05-domain", "step-06-innovation", "step-07-project-type", "step-08-scoping", "step-09-functional", "step-10-nonfunctional", "step-11-polish", "step-12-complete"]
workflowStatus: complete
completedAt: "2026-04-24"
inputDocuments:
  - "_bmad-output/planning-artifacts/product-brief-archilan.fr.md"
  - "_bmad-output/planning-artifacts/product-brief-archilan.fr-distillate.md"
briefCount: 2
researchCount: 0
brainstormingCount: 0
projectDocsCount: 0
workflowType: 'prd'
classification:
  projectType: web_app
  domain: general
  complexity: medium
  projectContext: greenfield
---

# Product Requirements Document - archilan.fr

**Author:** Jean
**Date:** 2026-04-24

---

## Executive Summary

archilan.fr is the digital home and operational ERP of ArchiLAN, a Clermont-Ferrand nonprofit (loi 1901) and the recognized French-language branch of the global Archipelago Multi World Randomizer community. The product serves two simultaneous goals: (1) a public-facing community hub that makes Archipelago discoverable and approachable to French audiences, and (2) an internal ERP replacing manual event coordination with structured tooling for the ten-person volunteer team.

**Target users:**
- CS students in Clermont-Ferrand (primary): technically curious, Discord/Twitch-native, open to complex game mechanics
- General gaming public (secondary): less technical, needs guided onboarding to Archipelago concepts
- ArchiLAN volunteers and board - 4 admins, ~10 total (internal): need reliable operational tools to run events without manual overhead

**Problem:** Archipelago (v0.6.7, 120+ supported games, 122,000+ global Discord members) is a mature technology with zero French-language organized community or discovery surface. The volunteer team manages all event coordination - registrations, game selections, communication - manually across Discord and social media, with no structured intake for the game selection workflow every Archipelago event requires.

### What Makes This Special

ArchiLAN is the only organized Archipelago LAN association in France, informally recognized as the francophone branch of the global Archipelago community. No French competitor exists. Three consecutive flagship LANs grew 14 → 30 → 50 participants, with the 50-person cap enforced by venue capacity, not demand. Twitch Affiliate status established with 10 avg / 20 peak concurrent viewers and no dedicated production infrastructure.

The critical differentiator is the **Archipelago-specific game selection intake workflow**: before any multiworld can be generated, all participants must select and configure their games. This is not generic event registration - it is a domain-specific intake flow with no existing tooling. archilan.fr solves this while serving as the French entry point to a 122,000-member global community.

Architecture is designed from day one for evolution toward automated multiworld server deployment (Year 3 vision), ensuring v1 decisions do not block the long-term platform trajectory.

---

## Project Classification

- **Project Type:** Web application - Next.js (SSR/CSR frontend) + Symfony LTS REST API (backend)
- **Domain:** General - gaming community management + event ERP
- **Complexity:** Medium - RGPD/LCEN compliance, HelloAsso OAuth2 API integration, Archipelago-specific workflows, RBAC (3 roles), DDD + N-Tier architecture
- **Project Context:** Greenfield

---

## Success Criteria

### User Success

- A first-time visitor understands ArchiLAN's gaming/tech identity before reading a word - design delivers the "aha" on page load
- Every user journey (discovery, registration, game selection, account creation) completes without friction or need for external support
- A visitor with no prior Archipelago knowledge leaves understanding the concept and wanting to participate
- Landing page visitors convert to event registrants, social followers, or Twitch subscribers through clear, low-friction CTAs
- Registered users return to the site outside of event periods to check upcoming sessions and read recaps

### Business Success

| Metric | Baseline | 12-month target |
|---|---|---|
| Annual LAN demand | 50 (venue cap) | Waitlist active - demand exceeds venue capacity |
| Twitch concurrent viewers | 10 avg / 20 peak | 30 avg / 50+ peak during flagship LAN |
| Monthly remote session attendance | Informal / untracked | Structured sign-up; growing tracked attendance |
| Non-CA public paid memberships | 0 | First paying public members |
| Events managed via backoffice | 0% (all manual) | 100% - zero manual coordination |

### Technical Success

- Codebase permanently coherent: PHPStan max level, PHP CS Fixer clean, test suite (unit + functional) green after every AI batch
- HelloAsso API sync reliable - orders and member data reflected in ERP without manual intervention
- RGPD-compliant from day one - no post-launch legal remediation
- Zero data loss on registrations or payments

### Measurable Outcomes

- 100% of ArchiLAN events managed through backoffice - first event managed through the site is the launch milestone
- Flagship LAN demand exceeds venue cap within one edition post-launch
- Game selection intake used for 100% of Archipelago event participants - zero manual collection by volunteers

---

## Product Scope

### MVP - Minimum Viable Product

- Public landing page: association presentation, Archipelago explainer, past/upcoming events, Twitch embed + link, HelloAsso boutique embedded checkout, global Archipelago Discord link
- Content & news section: event recaps, announcements, VOD archive
- Event backoffice: CRUD, draft/publish lifecycle, configurable registration windows, public/private toggle
- Archipelago-specific game selection intake: per-event, per-registration
- User accounts + RBAC: admin / membre / lambda; public signup → lambda only; membre promotion by admin only
- HelloAsso API integration: OAuth2, embedded checkout, ERP sync of orders and member data
- Real-time seat counter and backoffice registration feed (SSE)
- Legal compliance: mentions légales, politique de confidentialité (RGPD/CNIL), cookie consent, CGV, CGU

### Growth Features (Post-MVP)

- Public paid membership self-service (cotisation flow, lambda → membre)
- Member benefits and perks system
- Community game voting for event configurations
- French-language Archipelago guides and blog
- Enhanced monthly remote session tooling

### Vision (Future)

- Automated Archipelago multiworld server deployment via web interface
- Participants configure game options online; platform generates and hosts the run
- Separate microservice / project - N-Tier isolation in v1 ensures no rewrite required
- Platform expands to serve the broader French-speaking Archipelago community

---

## User Journeys

### Journey 1: Thomas - CS Student, First LAN (Primary Happy Path)

**Persona:** Thomas, 2nd-year CS student at ISIMA Clermont-Ferrand. Comfortable with Discord and Twitch, plays roguelikes and metroidvanias, vaguely aware of randomizers but not Archipelago.

**Opening:** A classmate drops a link in a Discord server. Thomas clicks. The site loads - dark, sharp, gaming aesthetic. He reads it as legitimate immediately. He scrolls.

**Rising action:** The Archipelago explainer lands. He watches a 2-minute VOD from the last LAN - someone finds a Hollow Knight item while playing Stardew Valley. He understands the concept. The upcoming flagship LAN shows "Registration open - 23 spots remaining." He creates an account: email, password, done. No friction, no email verification gate before browsing.

**Climax:** He opens the LAN registration form. Game selection intake: he picks Hollow Knight and Stardew Valley. The form surfaces key randomizer options with plain-language descriptions - no Archipelago wiki required. He submits. Confirmation screen, confirmation email.

**Resolution:** Thomas attends the LAN. His world is one of 50 interconnected. He finds a Stardew item in Hollow Knight within the first hour. Post-event he checks the recap and looks at the next monthly remote session.

**Capabilities revealed:** Archipelago explainer, VOD archive, event listing with capacity indicator, frictionless account creation, game selection intake with guided options, confirmation flow, content section.

---

### Journey 2: Marie - General Public, Archipelago Discovery (Newcomer Path)

**Persona:** Marie, 28, game enthusiast, not a CS student. Sees a retweeted Twitch clip: someone playing Celeste finds a Zelda item. Caption: "ArchiLAN annual LAN." She has no idea what she watched.

**Opening:** She searches "ArchiLAN" and lands on archilan.fr. The design signals legitimacy. She reads the Archipelago explainer top to bottom. The concept resolves. She's intrigued.

**Rising action:** She checks past events - photos, VODs, participant counts growing edition over edition. A monthly remote session is listed two weeks out. Asynchronous, joinable from home. Accessible for a first-timer.

**Climax:** She clicks "Register." Creates an account in under two minutes. The event is public. She gets a confirmation with a "what to expect" summary and a link to the Archipelago getting-started guide.

**Resolution:** She participates remotely, playing Hollow Knight in a 12-player world. She follows ArchiLAN on Twitch. Three months later she registers for the flagship LAN.

**Capabilities revealed:** Archipelago explainer (newcomer-optimized), past events with recaps, remote session registration, Archipelago Discord link, onboarding confirmation with next steps.

---

### Journey 3: Antoine - Volunteer Admin, Event Lifecycle (Internal Operations)

**Persona:** Antoine, CA member, one of four admins. Currently coordinates all events via Discord messages and a shared spreadsheet.

**Opening:** Antoine logs into the backoffice. He creates the annual flagship LAN: title, description, type=LAN, dates, venue, capacity=50, registration window, public, game selection enabled. Saves as draft.

**Rising action:** Two days before the registration window opens, he publishes. The event appears on the landing page. 20 registrations arrive in the first 48 hours. He monitors the dashboard, seeing each participant's game selections live.

**Climax:** At 47 registrations, he notices unusual game options on one participant's selection. He contacts them via the platform. Separately, he promotes a community regular from lambda to membre: user profile → role → membre → save. The 50th registration arrives. Capacity reached. Waitlist state activates automatically.

**Resolution:** Registration closes. Antoine exports participant + game selection data for multiworld generation. Zero Discord DMs for registration management. Post-event he updates the event status to "completed" and attaches the recap.

**Capabilities revealed:** Event CRUD, draft/publish lifecycle, registration dashboard with game selections, admin messaging, role promotion, capacity enforcement + waitlist, event status lifecycle, recap attachment.

---

### Journey 4: Camille - Lambda User, Private Event Access (Edge Case)

**Persona:** Camille, existing lambda user, active in the community Discord. She sees a post about a members-only gaming session this weekend.

**Opening:** She visits archilan.fr and sees the event with a lock icon: "Members only." She's lambda, not membre.

**Rising action:** She contacts Antoine on Discord. He gives her the session password as a one-time exception. She returns to the site, enters the password on registration.

**Climax:** Access granted. Registration completes. The system records her as a lambda user with password override - Antoine can see this in the backoffice.

**Resolution:** Camille attends. The experience plants the expectation that a formal membership path exists - which arrives in v2.

**Capabilities revealed:** Password-protected event registration, lock state display, password input on registration, admin visibility of password-access participants.

---

### Journey Requirements Summary

| Capability Area | Revealed By |
|---|---|
| Archipelago explainer + onboarding content | Journeys 1, 2 |
| VOD archive + past event recaps | Journeys 1, 2 |
| Event listing with capacity indicator | Journeys 1, 2 |
| Frictionless public account creation | Journeys 1, 2 |
| Game selection intake with guided options | Journey 1 |
| Registration confirmation + next-steps email | Journeys 1, 2 |
| Event CRUD + draft/publish lifecycle | Journey 3 |
| Backoffice registration dashboard with game selections | Journey 3 |
| Role promotion (lambda → membre) | Journey 3 |
| Capacity enforcement + waitlist state | Journey 3 |
| Event status lifecycle | Journey 3 |
| Password-protected event access | Journey 4 |
| Admin visibility of password-access participants | Journey 4 |

---

## Domain-Specific Requirements

### Compliance & Regulatory

**French Legal - LCEN Art. 6 (Mentions légales)**
- Association name, registered address, phone/email contact, name of *directeur de la publication*, full hosting provider identity
- Dedicated page linked from footer on every page

**RGPD / CNIL**
- Privacy policy required: data controller identity, processing purposes + legal bases, retention periods, full user rights (access / rectification / erasure / portability / opposition), CNIL complaint right
- Legal basis for registration data (email, username) must be explicit (*intérêt légitime* or *consentement*)
- Data retention periods defined and enforced per data type
- Account deletion removes all associated personal data

**Cookie / Tracker Consent**
- Twitch embed and any analytics require prior consent (CNIL guidance)
- Cookie banner on first visit for non-functional trackers; session cookies exempt
- Consent must be freely given, specific, informed, and withdrawable at any time

**Commercial / Transactional**
- CGV required for event ticketing and merchandise; CGU required for user accounts and member spaces
- Both linked from footer and presented at relevant transaction and account creation points

**Loi 1901**
- Site must not misrepresent the association's nonprofit status
- Membership processes must align with the association's statutes

### Integration Requirements

| System | Integration Type | Data Flow |
|---|---|---|
| HelloAsso | OAuth2 REST API + embedded checkout widget | Checkout → payment confirmed → ERP sync (orders, member data) |
| Twitch | Embed iframe + channel link | Live state detection → conditional embed display |
| Archipelago Discord | External link only | Outbound link on landing page |
| Email (transactional) | SMTP / transactional provider | Registration confirmations, admin notifications |

### Domain Risk Mitigations

| Risk | Mitigation |
|---|---|
| HelloAsso API downtime during event registration | Graceful degradation UI; retryable; no data loss |
| RGPD non-compliance at launch | Legal pages drafted before launch; cookie consent active from day one |
| Capacity race condition | Server-side atomic capacity check - lock acquired before registration confirmed |
| Admin promotes wrong user | Role change requires explicit confirmation step; action logged |
| Game selection data lost pre-multiworld | Selections stored persistently; exportable by admin at any time |

---

## Innovation & Novel Patterns

### Detected Innovation Areas

**1. Archipelago-native event registration workflow**

No existing event platform - LAN-specific (BYCEPS, LanHUB, pretix) or general (Eventbrite, HelloAsso standalone) - incorporates Archipelago game selection as part of registration. The standard approach: register online → collect game choices manually via Discord → assemble multiworld config manually. archilan.fr collapses this into a single structured intake: participant selects games, configures options, submits - data immediately available to admins in exportable form. No direct precedent exists.

**2. First structured French-language Archipelago community platform**

The global Archipelago community (122,000+ Discord members, ~27,000 active games/week) has no French-language organized web presence. archilan.fr introduces a content and community category that does not currently exist in France. The Archipelago explainer, VOD archive, and guided game selection fill a zero-competition gap.

**3. Architecture primed for multiworld server automation (v3)**

Current Archipelago hosting requires local Python setup, manual YAML configuration, and manual server launch. A web interface abstracting this has no public equivalent. The N-Tier architecture is designed from v1 to enable this without a rewrite.

### Competitive Landscape

- **LAN platforms (BYCEPS, LanHUB, pretix):** No Archipelago game selection. BYCEPS seat reservation + game voting is the closest adjacent feature - entirely different workflow.
- **archipelago.gg:** Hosted multiworld service, no event organization, no French content, no LAN management.
- **French LAN associations (Ethlan, Lyon e-Sport, Mosel'LAN):** No Archipelago content. Ethlan's community game voting is the closest adjacent feature.
- **Gap:** Zero overlap between "Archipelago platform" and "LAN event management" in France. archilan.fr occupies that intersection alone.

### Innovation Validation

| Innovation | Validation Signal |
|---|---|
| Game selection intake replaces manual collection | First event where 100% of selections collected via site, zero via Discord |
| Archipelago explainer converts newcomers | Marie-type users register within 30 days of first visit |
| Platform as French community reference | Inbound links from Archipelago Discord / archipelago.gg; French-language search traffic |

---

## Web Application Specific Requirements

### Architecture Overview

archilan.fr is a hybrid web application: **Next.js** (frontend, SSR/CSR) calling a **Symfony LTS REST API** (backend). This preserves clean N-Tier separation while enabling SEO on public-facing pages.

**Rendering strategy:**

| Page / Feature | Rendering | Rationale |
|---|---|---|
| Landing page | SSR | SEO - indexed by Google, social sharing previews |
| Event listing + detail pages | SSR or ISR | SEO - event pages discoverable via search |
| Content/news section | SSR or ISR | SEO - French Archipelago reference content |
| Registration flow | CSR | Dynamic, authenticated, no SEO value |
| Backoffice | CSR | Authenticated only |
| Legal pages | SSG | Static content, no server cost |

**API:** Symfony REST API, versioned (`/api/v1/`), stateless JWT auth stored in httpOnly cookie. Next.js BFF pattern used only to proxy requests where CORS applies (e.g., HelloAsso); all business logic stays in Symfony.

### Browser Matrix

| Browser | Support |
|---|---|
| Chrome / Edge (last 2 versions) | Full |
| Firefox (last 2 versions) | Full |
| Safari / Safari iOS (last 2 versions) | Full |
| Chrome Android (last 2 versions) | Full |
| Internet Explorer | Not supported |

### Responsive Design

- Mobile-first - landing and event pages fully functional on smartphone
- Breakpoints: mobile (< 768px), tablet (768–1024px), desktop (> 1024px)
- Backoffice: tablet minimum (admins use desktop or tablet)
- Twitch embed: responsive sizing, degrades to link on very small viewports

### Real-Time

- **Seat counter:** remaining capacity updated live via SSE during open registration periods
- **Twitch status:** live/offline auto-detected, embed/link toggled without user action
- **Backoffice feed:** new registrations appear in admin dashboard without refresh
- **SSE fallback:** automatic 30-second polling if SSE connection drops

### SEO

- `schema.org/Event` structured data on all event pages
- Open Graph + Twitter Card meta tags on all public pages
- Auto-generated sitemap; canonical URLs; `lang="fr"` on all pages
- Page titles and meta descriptions admin-editable per event and news post

### Accessibility

- WCAG 2.1 AA across all public-facing pages
- Dark gaming design: AA contrast ratios enforced (4.5:1 normal text, 3:1 large text)
- Keyboard navigation, visible focus indicators, programmatically linked form labels and error messages
- Twitch embed: fallback text/link for users unable to interact with iframe

---

## Project Scoping & Risk

### MVP Philosophy

**Experience MVP** - v1 is intentionally complete, not minimal. The product must simultaneously replace a broken manual workflow AND serve as a credible community hub. Partial launch (e.g., landing page only) fails both goals. All v1 capabilities are interdependent.

**Release strategy:** Single full release. Resource model: Jean (architect/reviewer) + AI development (Claude + Codex). DDD + N-Tier adds upfront complexity but eliminates future rewrite risk.

### Risk Register

**Technical Risks:**

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| HelloAsso API unexpected behavior in production | Medium | High | Sandbox throughout dev; graceful degradation from day one |
| Game selection UX friction for non-technical users | Medium | High | Progressive disclosure; Jean reviews all UX for zero-tension compliance |
| SSE deployment complexity | Low | Medium | Symfony Mercure or native SSE; polling fallback built in |
| DDD complexity slows initial velocity | Medium | Medium | Jean finalizes domain model upfront; AI compensates velocity |

**Market Risks:**

| Risk | Mitigation |
|---|---|
| Venue cap limits growth signal | Waitlist captures demand data even at capacity |
| Low traffic between events | Content section provides return-visit reason; tracked via success metrics |

**Resource Risks:**

| Risk | Mitigation |
|---|---|
| AI code quality drift | PHPStan, CS Fixer, tests run after every batch - always coherent |
| Jean unavailable for review | Architecture documented; AI continues within PRD guardrails |

---

## Functional Requirements

### Community Hub & Content

- **FR1:** Visitors can view the association's identity, mission, and an explanation of Archipelago on the landing page
- **FR2:** Visitors can browse past event listings with recaps and key statistics
- **FR3:** Visitors can browse upcoming event listings with dates, type, and availability status
- **FR4:** Visitors can watch an embedded live Twitch stream when a broadcast is active
- **FR5:** Visitors can access a link to the ArchiLAN Twitch channel when no stream is active
- **FR6:** Visitors can access the official global Archipelago Discord from the landing page
- **FR7:** Visitors can read news posts, event recaps, and association announcements in the content section
- **FR8:** Admins can create, edit, publish, and unpublish news posts and event recaps
- **FR9:** Visitors can share event and news pages with correct title, description, and image previews on social media and Discord

### Event Management

- **FR10:** Admins can create events with title, description, type (LAN / remote / member session), dates, venue, capacity, registration window dates, and public/private access flag
- **FR11:** Admins can save events as drafts before publishing
- **FR12:** Admins can publish events, making them immediately visible on the public listing
- **FR13:** Admins can configure game selection intake per event (enable/disable, available games, visible options)
- **FR14:** Admins can set and update a password for private events
- **FR15:** Admins can transition event status through its lifecycle (draft → published → in-progress → completed)
- **FR16:** Admins can view all registrations for an event, including each participant's game selections
- **FR17:** Admins can export participant and game selection data for a given event
- **FR18:** Admins can cancel or modify individual participant registrations
- **FR19:** Admins can manage the association's Archipelago game library (add, edit, remove games and configurable randomizer options)
- **FR20:** Admins can attach a recap article or VOD link to a completed event

### Event Participation

- **FR21:** Authenticated users can register for public events during the open registration window
- **FR22:** Authenticated users can register for private events by providing the correct event password
- **FR23:** Registrants can select one or more games from the event's Archipelago game library during registration
- **FR24:** Registrants can configure key randomizer options for each selected game using plain-language descriptions
- **FR25:** Registrants can view and update their game selections before the registration window closes
- **FR26:** Registrants can cancel their own event registration
- **FR27:** The system prevents registration when an event has reached its declared capacity
- **FR28:** The event registration page displays remaining seat count updated in real time

### User & Access Management

- **FR29:** Visitors can create a lambda user account with email and password
- **FR30:** Authenticated users can view and edit their profile information
- **FR31:** Authenticated users can delete their account and all associated personal data
- **FR32:** Admins can view, search, and filter all user accounts
- **FR33:** Admins can promote a lambda user to membre
- **FR34:** Admins can demote a membre to lambda user
- **FR35:** Admins can create other admin accounts
- **FR36:** The system enforces role-based access: lambda users restricted from backoffice; membres can access member-only events; admins have full backoffice access
- **FR37:** Authenticated users can exercise RGPD rights (access, rectification, erasure, portability) through their account or a dedicated contact process

### Payments & Commerce

- **FR38:** Visitors can purchase event tickets via embedded HelloAsso checkout without leaving the site
- **FR39:** Visitors can pay association membership fees via embedded HelloAsso checkout
- **FR40:** Visitors can browse and purchase merchandise via embedded HelloAsso boutique checkout
- **FR41:** The system automatically syncs HelloAsso order and member data into the internal ERP
- **FR42:** Admins can view HelloAsso payment status associated with event registrations

### Real-Time Updates

- **FR43:** Event pages display remaining seat count updated in real time without page reload
- **FR44:** The site automatically shows the Twitch embed when a stream is live and a channel link when it is not, without user action
- **FR45:** The admin backoffice registration dashboard updates in real time as new registrations arrive

### Communications

- **FR46:** Registrants receive a confirmation email upon successful registration, including event details and next steps
- **FR47:** Admins receive a notification when an event reaches capacity
- **FR48:** Admins can send a message to individual registrants from the backoffice

### Legal & Compliance

- **FR49:** The site displays a Mentions Légales page linked from the footer on every page
- **FR50:** The site displays a Politique de Confidentialité page linked from the footer on every page
- **FR51:** The site presents CGV before any transactional action
- **FR52:** The site presents CGU during account creation
- **FR53:** The site displays a cookie consent banner on first visit and respects the user's consent choices
- **FR54:** Users can withdraw or update cookie consent at any time from a persistent footer control

---

## Non-Functional Requirements

### Performance

- Public pages (landing, events, content): LCP < 2.5s, CLS < 0.1, INP < 200ms (desktop and mobile)
- Symfony API - public endpoints: p95 < 200ms; authenticated endpoints: p95 < 500ms
- SSE seat counter propagation: remaining capacity reflected on client within 1 second of a new registration
- Twitch embed: lazy-loaded - zero impact on initial page render
- Registration form submission: confirmation or error delivered within 3 seconds

### Security

- HTTPS enforced site-wide; HTTP redirects to HTTPS; HSTS header set
- Passwords hashed with Argon2id - never stored or logged in plain text
- Authentication tokens in httpOnly, Secure, SameSite cookies - not in localStorage or JS-accessible memory
- RBAC enforced at Symfony API layer on every endpoint - not delegated to frontend
- Registration capacity check atomic server-side - two concurrent requests cannot both claim the last spot
- CSRF protection on all state-changing forms and API mutations
- Symfony API CORS restricted to Next.js origin only
- No credentials or API keys in frontend bundle or client-accessible env vars
- All user input validated and sanitized server-side before persistence

### Scalability

- 50+ simultaneous registration attempts handled without data corruption or degradation
- SSE connections scale proportionally to active users without resource exhaustion
- Stateless Symfony API - horizontal scaling requires no session affinity
- Database schema supports growth to thousands of users and hundreds of events without structural rework
- Domain services isolated from delivery layer - v3 multiworld service added without touching v1 logic

### Accessibility

- WCAG 2.1 AA across all public-facing pages
- All text/background combinations meet AA contrast ratios (4.5:1 normal text, 3:1 large text)
- All interactive elements keyboard-navigable with visible focus indicators
- All form fields have associated labels; error messages programmatically linked
- Twitch embed provides fallback text/link for users unable to interact with iframe

### Integration Reliability

- HelloAsso unavailable: graceful degradation message - no broken page, no silent failure
- HelloAsso sync failure: auto-retry; admin notified of persistent failures; no registration blocked by sync delay
- Email delivery failure: logged and flagged to admin - registration record not rolled back
- SSE drop: client auto-falls back to 30-second polling
- Twitch API unavailable: embed falls back to channel link

### Reliability

- Availability: 99.5% uptime (excluding scheduled maintenance)
- Registration atomicity: completes fully or rolls back entirely - no partial states
- Zero registration or payment data lost due to server errors
- Scheduled maintenance performed outside active registration windows
