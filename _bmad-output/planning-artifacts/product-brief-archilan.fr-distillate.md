---
title: "Product Brief Distillate: archilan.fr"
type: llm-distillate
source: "product-brief-archilan.fr.md"
created: "2026-04-24"
purpose: "Token-efficient context for downstream PRD creation"
---

# Product Brief Distillate: archilan.fr

## Association Context

- Name: ArchiLAN - association loi 1901, Clermont-Ferrand, France
- ~10 volunteers total; 4 members in the bureau d'administration (CA)
- Currently only CA members hold paid "membre" status; public paid membership is a future goal
- Recognized informally as the French-language branch of the global Archipelago community
- Twitch Affiliate (first tier / "partenaire niveau 1") - already streaming events
- Three editions of the flagship LAN: 14 → 30 → 50 participants (50 was a venue cap, not a demand cap)
- Twitch: ~10 avg concurrent viewers, peak 20 during flagship LAN

## Technology: Archipelago Multi World Randomizer

- Open-source Python framework that randomizes multiple games simultaneously and links them in a single co-op session
- Items found in one player's world can be required by another player's game - all games communicate over a shared server
- Play modes: synchronous (all players live - LAN format) and asynchronous (players join at own pace - monthly remote sessions)
- Official site: archipelago.gg - v0.6.7 (April 2026), 120+ officially supported games
- Global Discord: 122,000+ members; ~27,000 games generated/week
- Notable supported titles: Zelda: ALttP, Hollow Knight, Stardew Valley, Dark Souls III, Pokémon (multiple gens), Celeste, Factorio, Kingdom Hearts, StarCraft II
- MultiworldGG exists as a community fork with additional games not in main branch
- Technology dates to ~2023; not yet known to the general French gaming public

## Event Types

1. **Annual flagship LAN** - 3-day weekend, end of calendar year, in-person Clermont-Ferrand, synchronous multiworld, capped at 50 by current venue, Twitch streamed, ~10–20 concurrent viewers
2. **Monthly remote sessions** - online async Archipelago sessions, target monthly cadence (aspiration, not always achieved)
3. **Small member gaming sessions** - informal, association members only, various games (not exclusively Archipelago)
4. **Twitch streams** - primarily during flagship LAN, goal is more regular and more professional production

## Revenue Model

- Food and drink sales during in-person events
- Merchandise (via HelloAsso boutique)
- Association membership fees (cotisation) - currently only CA members; future: open to public
- No external sponsorship currently planned
- All payments flow through HelloAsso

## Technical Stack (decided, non-negotiable)

- **Backend:** Symfony LTS (latest LTS version) - Jean is expert-level; strong choice for DDD
- **Architecture:** N-Tier, DDD (Domain-Driven Design)
- **Frontend:** React - Jean is expert-level; Angular was considered and rejected (see rejected ideas)
- **CSS:** Tailwind CSS
- **Quality tools:** PHPStan, PHP CS Fixer (backend); equivalent linting/type-checking for frontend
- **Testing:** Unit tests + functional tests (both backend and frontend)
- **Quality gate:** After each AI-generated batch of modifications, quality commands must run automatically and self-correct before the batch is considered complete - always keep codebase in coherent state
- **AI development:** Claude (primary) + Codex as co-developer; Jean is architect/reviewer
- **Design:** Professional gaming/tech aesthetic

## Rejected Ideas (do not re-propose)

- **Angular for frontend:** Considered because "more enterprise/professional." Rejected - Jean can't review/maintain code he doesn't know; React with good architecture is equally professional; AI will do most dev anyway but Jean needs to be able to intervene. Verdict: React.
- **Open source codebase:** Considered as community engagement angle. Rejected - AI-generated DDD codebase requires architectural rigor that student contributors typically lack; risk of code quality degradation; Jean prefers controlled codebase. Verdict: closed source.

## User Role System (detailed)

- **Lambda user:** Self-registered via public signup form. Default role for any new public registration. NOT an association member. Can register for public events.
- **Membre:** Association member. Promoted by admins only. Can access password-protected events. Future: will have member-specific benefits (TBD).
- **Admin:** Full backoffice access. Manages events, members, roles, publications.
- Public registration → lambda user (never auto-promoted to membre)
- Members are managed exclusively by admins
- Future: public paid membership flow (not in v1 - deferred)

## Event Management Requirements (detailed)

- Events have: title, description, date(s), type (LAN/remote/session), capacity, registration open/close dates, publication date, public/private flag
- Private events: password-protected registration (for member-only events)
- Public events: open registration, no password
- Registration window: configurable open and close dates per event
- Game selection intake: participants must select and configure their Archipelago games before multiworld is generated - this is a key workflow specific to Archipelago events (not generic event ticketing)
- Backoffice: full CRUD for events, view/manage registrations per event, member management

## HelloAsso Integration (detailed)

- HelloAsso: French nonprofit payment platform, free for associations (revenue from voluntary tips at checkout)
- Covers: memberships (adhésions), event ticketing (billetterie), donations, merchandise (boutique)
- API: OAuth2, sandbox available, fully documented at dev.helloasso.com
- Integration approach: embedded HelloAsso Checkout widget (no redirect) + API sync of orders and member data into internal ERP
- Use cases for archilan.fr: event ticket purchases, membership fees, merchandise orders
- Inventory management (food/drinks, merch) is handled physically by volunteers - no in-site inventory system needed

## Twitch Integration Requirements

- Embed live stream directly in the site (not just a link)
- Link to Twitch channel for non-live state
- Both integration types required: embedded player + external link

## Legal Compliance Requirements (France)

- **Mentions légales** (LCEN Art. 6): association name + address, phone/email, directeur de publication, hosting provider identity
- **Politique de confidentialité** (RGPD/CNIL): data controller identity, processing purposes + legal bases, retention periods, user rights (access/rectification/erasure/portability/opposition), CNIL complaint right
- **Cookie consent banner:** required if any non-functional trackers (analytics, embeds) - Twitch embed likely triggers this
- **CGV** (Conditions Générales de Vente): required for event ticketing and merchandise
- **CGU** (Conditions Générales d'Utilisation): recommended for user accounts and member spaces
- RGAA accessibility: NOT legally required for a private loi 1901 association without public service mission - best effort recommended but not mandated
- All legal pages linked from footer on every page

## Competitive Landscape (French LAN associations)

- **Ethlan (ethlan.fr):** Most feature-complete French LAN association site. Has: user accounts, event ticketing with open/close state, game database with community voting, game proposals by members, photo gallery, Discord integration. No Archipelago. Best reference for feature parity.
- **Lyon e-Sport:** Large annual events, no online registration system - event participation via social/contact only.
- **Mosel'LAN Project:** Multiple event formats, online membership adhesion, external merch store, no in-house ticketing.
- **No French competitor for Archipelago-focused LAN events.**
- Common gap across all: no ERP depth, no game selection intake, no Archipelago content.

## Content & SEO Opportunity (not in v1 scope but worth noting for PRD)

- Zero French-language Archipelago educational content exists online
- archilan.fr could become the de facto French Archipelago reference with a blog/guides section
- Low cost, high SEO leverage - this was flagged as an opportunity but deferred; PRD team should decide if a basic blog is v1 or v2

## Long-Term Technical Vision (Year 3, architecture input)

- Automated Archipelago multiworld server deployment via web interface
- Participants configure game selections + randomizer options online
- Platform generates and hosts the Archipelago server run
- Jean (software architect) expects this may require microservices or a separate project - N-Tier architecture chosen from day one to support this evolution without a rewrite
- This is explicitly out of scope for v1 but must not be blocked by v1 architectural decisions

## Open Questions (unresolved, carry into PRD)

- **Member benefits:** What perks/features will paying public members get? (Jean deferred this - "on en parlera")
- **Public paid membership flow:** How will public users pay cotisation and become membres? What's the UX? (Deferred to v2 but needs design thinking early)
- **Game selection intake UX:** How structured is the game selection form for LAN registrations? Does it integrate with Archipelago's YAML config format? Or is it free-form and admins handle the config?
- **Venue expansion:** With LAN capped at 50 by venue, is finding a larger venue a parallel goal, or does the site need waitlist/overflow management?
- **Monthly remote session tooling:** How structured vs. informal? Does each session need a full registration flow or just a simple RSVP?
- **Twitch stream production quality:** "More professional" during events - what does this mean technically? OBS overlays, schedule pages, stream info updated from site?
- **Blog/content section:** In scope v1 or v2? Who writes content - Jean, volunteers, or generated?

## Scope Signals Summary

| Feature | v1 | Future | Notes |
|---|---|---|---|
| Landing page | ✅ | | |
| Content/news section | ✅ | | Event recaps, VODs, announcements |
| Event backoffice (CRUD) | ✅ | | |
| Public/private event toggle | ✅ | | Private = password-protected |
| Registration system | ✅ | | With open/close dates |
| User roles (admin/membre/lambda) | ✅ | | |
| HelloAsso API integration | ✅ | | Embedded checkout + ERP sync |
| Twitch embed + link | ✅ | | Both required |
| Legal compliance pages | ✅ | | Mentions légales, RGPD, CGV, CGU |
| Global Archipelago Discord link | ✅ | | On landing page |
| Game selection intake (Archipelago-specific) | ✅ | | Per-event, per-registration |
| Public paid membership self-service | | v2 | With perks TBD |
| Member benefits/perks | | v2 | Content TBD |
| Blog/guides section | | v2? | TBD in PRD |
| Community game voting | | v2 | à la Ethlan - vote on games for next LAN |
| Archipelago run automation | | v3 | Separate project/microservice |
| Tournament brackets | | ❌ | Explicitly out of scope |
| Native mobile app | | ❌ | Explicitly out of scope |
