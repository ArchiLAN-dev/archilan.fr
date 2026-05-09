---
title: "Product Brief: archilan.fr"
status: "complete"
created: "2026-04-24"
updated: "2026-04-24"
inputs: ["user-interview", "web-research-archipelago", "web-research-helloasso", "web-research-french-lan-associations", "web-research-legal-requirements"]
---

# Product Brief: archilan.fr

## Executive Summary

ArchiLAN is a Clermont-Ferrand-based nonprofit association on a mission to bring the Archipelago Multi World Randomizer ecosystem to French audiences - starting with computer science students and expanding to the general gaming public. Archipelago is a technically remarkable but largely unknown framework that links multiple randomized video games into a single cooperative multiplayer experience: finding an item in one player's world can unlock progress in someone else's entirely different game, creating a shared adventure across entirely separate titles.

archilan.fr is the association's digital home and operational backbone: a public-facing showcase that builds community and drives event registrations, combined with an internal ERP that handles event lifecycle management, membership, and HelloAsso-integrated ticketing and merchandising. The site must work as a discovery surface for the uninitiated - landing on it should make someone want to join a LAN - and as a reliable operational tool for the ten-person volunteer team that runs it.

The timing is right: Archipelago has reached a mature state (v0.6.7, 120+ supported games, 122,000+ Discord members globally), yet organized in-person community play in France is virtually nonexistent. ArchiLAN is the recognized French-language branch of the Archipelago community, and the first and only association building a structured home for it.

## The Problem

Archipelago is extraordinary technology that almost nobody has heard of in France. A French gamer curious about randomizers will find an active English-language Discord and a technical GitHub repository - but no local community, no guided entry point, and no organized in-person events.

For the ArchiLAN volunteer team, the absence of a dedicated platform means every event cycle involves manual coordination: communicating dates across Discord and social media, collecting registrations informally, and managing participant game selections without structured tooling. The flagship annual LAN - where participants must select and configure their games *before* the multiworld is generated - has no structured intake flow.

For the Twitch streaming side of the association, the lack of a professional home base limits discoverability and makes it difficult to convert a viewer into an event participant or paying member.

## The Solution

archilan.fr solves both problems simultaneously: it is the front door to Archipelago community play in France and the operational spine of the ArchiLAN association.

**For the public:** A landing page that explains what Archipelago is, who ArchiLAN is, and what is coming next - past events with recaps, upcoming LANs, and a clear path to register or follow on social media and Twitch. An embedded HelloAsso flow covers memberships, event tickets, and merchandise with no third-party redirect.

**For participants:** A structured registration system for each event - with game selection support, configurable public vs. password-protected access, and date-managed registration windows. Participants receive a clean, guided onboarding experience.

**For the team:** A backoffice to create and manage events, control publication and registration dates, manage members and user roles (admin / member / lambda user), and monitor registrations - replacing ad-hoc coordination with a purpose-built tool integrated with HelloAsso data.

## What Makes This Different

ArchiLAN is the only organized entity in France building a community around Archipelago LAN play - and is recognized as the de facto French-language branch of the global Archipelago community (122,000+ members on the official Discord). There is no direct French competitor. The closest comparable sites (Ethlan, Lyon e-Sport, Mosel'LAN Project) are LAN associations that do not engage with Archipelago at all.

The differentiator is not the niche alone - it is the trajectory. Three editions of the flagship LAN grew from 14 to 30 to 50 participants, with the 50-person cap imposed by venue size, not lack of demand. The association holds Twitch Affiliate status and averages 10 concurrent viewers (peak: 20) with no dedicated production infrastructure. archilan.fr is designed from day one as an N-Tier architecture built for evolution: the long-term vision includes automating the deployment and management of Archipelago multiworld server runs directly from the web interface, transforming the site from an event directory into a full Archipelago platform serving the entire French-speaking community.

## Who This Serves

**Primary - CS student gaming enthusiasts (Clermont-Ferrand):** Technically curious, already comfortable with Discord and Twitch, open to complex game mechanics. They discover ArchiLAN through university networks or the Twitch stream. The "aha moment" is realizing they can play their favorite game - randomized - while their friends play entirely different games, all interconnected in a shared session.

**Secondary - General gaming public:** Non-student gamers who find ArchiLAN through social media or the stream. Less technically fluent; the site must make Archipelago approachable, not intimidating.

**Internal - ArchiLAN volunteers and board (4 admins, ~10 total):** The people who run events. Success for them means a LAN that runs smoothly because participant game selections were collected and validated in advance, without manual coordination overhead.

## Success Criteria

| Signal | Baseline | Target (12 months post-launch) |
|---|---|---|
| Annual LAN registrations | 50 (venue cap) | Waitlist managed; venue expansion or second date explored |
| Twitch concurrent viewers | 10 avg / 20 peak | 30 avg / 50+ peak during flagship LAN |
| Monthly remote session sign-ups | Informal / untracked | Structured via site; growing attendance |
| Site return visits (non-event periods) | None tracked | Monthly active visits from registered users |
| Public memberships (non-CA) | 0 | First paying public members within 12 months |
| Operational efficiency | Manual via Discord/social | Zero manual coordination for any site-managed event |

## Scope

### In Scope - v1

- **Public landing page:** association presentation, Archipelago explanation, past and upcoming events, Twitch embed + live stream link, HelloAsso boutique integration (embedded checkout for memberships, tickets, merchandising), link to the official global Archipelago Discord
- **Content & news section:** event recaps, announcements, stream VOD archives - provides a content heartbeat between events for monthly remote sessions, small member gaming sessions, and association news
- **Event management backoffice:** create, edit, publish events; manage registration windows; public vs. password-protected access per event
- **User registration system:** lambda user self-registration; admin-managed member and role promotion (admin / member / lambda)
- **HelloAsso API integration:** OAuth2-connected sync of orders and member data into the internal ERP
- **Legal compliance:** Mentions légales, politique de confidentialité (RGPD/CNIL), cookie consent banner, CGV for ticketing and merchandise, CGU for member accounts; loi 1901 association obligations
- **Design system:** Professional gaming/tech aesthetic, Tailwind CSS

### Out of Scope - v1

- Archipelago multiworld run automation or server management
- Public self-service paid membership with perks (deferred to v2)
- Tournament brackets or competitive matchmaking features
- Native mobile application
- Multi-city or multi-association expansion

## Roadmap Thinking

If archilan.fr succeeds, it becomes the reference platform for Archipelago community play in France - and a model for the broader European Archipelago scene.

**Year 1 - Foundation:** Establish the community hub and operational foundation. Grow annual LAN registrations. Establish Twitch as a regular watch destination during events. Launch public memberships.

**Year 2 - Community depth:** Open public paid membership with member perks, introduce community features (game voting for event configurations, monthly remote session tooling), grow streaming production quality.

**Year 3 - Platform:** Automated Archipelago multiworld deployment via the web interface - participants select their games online, configure randomizer options, and the platform generates and hosts the run. ArchiLAN becomes not just an association site but an Archipelago hosting platform, potentially serving the broader French Archipelago community beyond Clermont-Ferrand.
