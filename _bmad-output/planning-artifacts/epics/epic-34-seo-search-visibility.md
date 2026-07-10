# Epic 34 - SEO & Search Visibility (referencement Google)

Status: planned (not started)
Date: 2026-07-08

## Goal

Maximise archilan.fr's organic visibility on Google across two complementary targets: the **francophone
Archipelago/randomizer niche** (high-intent, low-competition queries: "Archipelago randomizer", "multiworld",
"Archipelago tutoriel fr", game-specific randomizer searches) and **local LAN-event discovery** (people
searching for LAN parties / gaming events in the association's region). The epic covers four tracks agreed
with the product owner: technical SEO, performance / Core Web Vitals, editorial content, and measurement.

The site's SEO foundation is already sound - every public page is a fully server-rendered Server Component,
per-page metadata is near-universal, `Event`/`NewsArticle` JSON-LD exists on detail pages, and private/admin
surfaces are cleanly noindexed. This epic closes the identified gaps rather than rebuilding anything.

## Baseline audit (2026-07-08, grounding for the stories)

What exists (keep, do not regress):
- All `(public)` pages are async Server Components; primary content fetched server-side (crawlable HTML).
- Root layout: `metadataBase`, title template `%s | ArchiLAN`, default description, OG defaults, favicon set
  (story 10.3). `<html lang="fr">`.
- `generateMetadata` with canonical + OG + twitter on detail pages (event, post, game, player, streams).
- JSON-LD: `Event` on `evenements/[eventSlug]`, `NewsArticle` on `actualites/[postSlug]` (XSS-escaped).
- Disciplined `robots index:false` on `(admin)`, `(overlay)`, `compte/*`, registration flows, thin pages.
- `next/image` everywhere in `(public)`, `priority`/`sizes` on hero images.

Gaps found (the epic's scope):
1. **No `sitemap.ts`, no `robots.ts`/`robots.txt`** - discovery relies on internal links only.
2. **Home page has no `metadata` export** - generic "ArchiLAN" title, no canonical, no targeted description.
3. No site-wide `Organization`/`WebSite` JSON-LD; no `BreadcrumbList` anywhere.
4. Listing/marketing pages (`evenements`, `actualites`, `jeux`, `runs-hebdo`, `adhesion`, `boutique`,
   `communaute`, `classements`, `aide/archipelago`) have title+description only - no OG/twitter/canonical.
5. **Canonical bug**: event detail canonical + OG url built from `event.id` while the route is
   `[eventSlug]` - if id differs from slug the canonical points to a different URL than the crawled one.
6. `export const dynamic = "force-dynamic"` on every public page, `revalidate` nowhere - zero ISR/CDN
   caching, higher TTFB. Root cause: MinIO presigned image URLs expire in ~1h, which killed the original
   SSG approach on event detail (story 1.4).
7. No root `not-found.tsx` (scoped ones exist for event/post/admin only).
8. Brand fonts (Space Grotesk, Inter) are declared in `globals.css` but never loaded - no `next/font`, no
   `@font-face`; they silently fall back to system fonts.
9. `images.remotePatterns` allows any host on http **and** https; no AVIF; remote MinIO images bypass
   optimization via `unoptimized`.
10. No `headers()`/`redirects()` in `next.config.ts` (no cache/security headers, no host canonicalization).
11. No Search Console integration, no position tracking, no Lighthouse budget in CI.

## Decisions (locked)

- **Indexable surface is the public site only.** Nothing in `(admin)`, `(overlay)`, `compte/*`, auth or
  registration flows ever becomes indexable; the sitemap enumerates only pages that are `index:true`.
- **Slug is the canonical identity of a public resource.** Canonicals, sitemap entries, OG urls and JSON-LD
  urls all use the slug-based route the crawler actually visits, never a DB id (fixes gap 5 by rule).
- **Performance work must not sacrifice realtime correctness.** Seat counters, Twitch live badges and other
  realtime signals stay client-side; ISR applies to the static shell + primary content only. A cached page
  must never show a stale seat count as authoritative server content.
- **The MinIO presigned-URL blocker is solved once, as infrastructure, not per-page.** Public images get
  stable URLs (dedicated public bucket, proxy route, or equivalent) so that every public page can adopt
  `revalidate`. Story 34.4 owns this decision and documents the trade-off chosen.
- **Content changes are keyword-informed, not keyword-stuffed.** Story 34.6 starts from an explicit keyword
  map validated by the product owner; copy stays natural French addressed to humans first.
- **Measurement before and after.** Search Console + a Lighthouse baseline are set up early (34.7 runs right
  after 34.1/34.2) so the epic's impact is observable instead of assumed.
- **Off-site local SEO (Google Business Profile, backlinks, directories) is out of repo scope** - noted as
  a human/association action list in 34.7, not implemented as code.

## Scope

### In scope
- `sitemap.ts` + `robots.ts`, canonical hygiene, full metadata coverage of indexable pages.
- JSON-LD completion: `Organization`, `WebSite`, `BreadcrumbList`; audit of existing `Event`/`NewsArticle`.
- ISR/revalidate strategy for public pages, including the MinIO public-image infrastructure it requires.
- Web performance pass: `next/font`, image config, cache/security headers, root `not-found.tsx`.
- Editorial pass on indexable pages: keyword map, titles/descriptions/H1s, internal linking, alt texts.
- Measurement: Google Search Console, sitemap submission, Lighthouse CI (advisory), baseline record.

### Out of scope (open doors, not built here)
- Multilingual/hreflang (site is deliberately French-only).
- Paid acquisition, social automation, newsletter.
- Off-site SEO execution (Google Business Profile, link building) - listed as human actions only.
- A blog/CMS overhaul; `actualites` stays the editorial surface as-is.
- API-side changes beyond what stable public image URLs require.

## Proposed stories

> Sizes: S < ~0.5d, M ~1-2d, L > 2d. MoSCoW per story. Frontend stories are gated by `pnpm gates`; any
> api/ touch (34.4 image infrastructure) also by `composer gates`.

- **34.1 - Sitemap & robots (frontend/). [S-M, Must]**
  - AC1: `src/app/sitemap.ts` emits every indexable route: static public pages plus dynamic entries for
    published events, published posts, public game pages and runs-hebdo game pages, each with
    `lastModified` from real data; noindex routes are absent. Built on the existing server-side fetchers.
  - AC2: `src/app/robots.ts` allows the public site, disallows `/admin`, `/compte`, registration/auth
    flows and overlay routes, and references the sitemap URL.
  - AC3: sitemap URLs are absolute via `env.appUrl`/`metadataBase` and use slug-based routes only.
  - AC4: `pnpm gates` green; a jest test asserts sitemap composition rules (indexable in, noindex out).

- **34.2 - Metadata completion & canonical hygiene (frontend/). [S-M, Must]**
  - AC1: home page exports keyword-targeted `metadata` (title, description, canonical `/`).
  - AC2: every indexable listing/marketing page (`evenements`, `actualites`, `jeux`, `runs-hebdo`(+ jeu),
    `classements`, `communaute`, `boutique`, `adhesion`, `aide/archipelago`, legal pages) gains
    `alternates.canonical` + `openGraph`; root layout gains `twitter` defaults so cards resolve everywhere.
  - AC3: event detail canonical/OG url uses the slug route param, not `event.id` (bug fix, gap 5); a
    regression test covers it.
  - AC4: titles/descriptions on these pages follow the keyword map skeleton (finalised copy may be
    refined again in 34.6); no page exceeds sane title length (~60 chars) or duplicates another's title.
  - AC5: `pnpm gates` green.

- **34.3 - Structured data completion (frontend/). [M, Must]**
  - AC1: site-wide `Organization` JSON-LD in the root layout (name, url, logo, `sameAs` Discord/Twitch,
    postal address for the local signal - address value supplied by the product owner) and `WebSite`
    JSON-LD (name, url).
  - AC2: `BreadcrumbList` JSON-LD on event, post and game detail pages matching the visible navigation path.
  - AC3: existing `Event` JSON-LD audited: `Place` populated from real venue data (name + address when
    available), `offers` added when ticketing applies; `NewsArticle` audited for `image`/`dateModified`.
  - AC4: all emitted JSON-LD passes Google's Rich Results Test / schema.org validator (documented check);
    shared escaped-serialization helper reused (no new hand-rolled script tags).
  - AC5: `pnpm gates` green.

- **34.4 - ISR strategy & stable public image URLs (frontend/ + api/). [L, Should]**
  - AC1: a decision record for stable public image delivery (public MinIO bucket vs API proxy route vs
    long-lived URLs), with the chosen approach implemented for public event/post/game images; presigned
    URLs remain for private surfaces.
  - AC2: public pages move from `force-dynamic` to `revalidate` (target: home, listings, detail pages;
    per-page interval documented; realtime widgets stay client-side per the locked decision).
  - AC3: measured before/after TTFB (or lab LCP) on home + one event page, recorded in the story file.
  - AC4: image-heavy pages no longer need `unoptimized` for public images (Next optimization applies).
  - AC5: `pnpm gates` + `composer gates` green; no behaviour change on private/admin surfaces.

- **34.5 - Web performance & crawl hygiene pass (frontend/). [M, Should]**
  - AC1: Space Grotesk + Inter actually loaded via `next/font` (self-hosted, `display: swap`), matching
    the CSS variables already declared; visual check that headings/body render the intended families.
  - AC2: `images.remotePatterns` tightened to the actual hosts (no wildcard http), AVIF enabled.
  - AC3: root `not-found.tsx` added (branded, `robots index:false`, useful links home/events).
  - AC4: `headers()` in `next.config.ts`: sensible `Cache-Control` for static assets and baseline security
    headers that do not break Twitch/HelloAsso embeds (verified against consent-gated embed pages).
  - AC5: Lighthouse (mobile) run on home + event detail before/after, scores recorded; no CWV regression.
  - AC6: `pnpm gates` green.

- **34.6 - Editorial & keyword content pass (frontend/ content). [M, Should] [human: keyword map validation]**
  - AC1: a keyword map committed as the story's worklist: francophone Archipelago/randomizer cluster
    (head + long-tail, per public game page) and local LAN cluster (event queries + region), each mapped
    to one target page; validated by the product owner before copy changes.
  - AC2: home, listing pages and `aide/archipelago` H1s/intro copy rewritten against the map; one clear
    H1 per page; heading hierarchy validated.
  - AC3: internal linking pass: tutorial hub links to game pages and events; event/post pages link back
    to the relevant hubs; footer links audited.
  - AC4: alt-text pass on indexable pages - meaningful images get descriptive French alt text (decorative
    images stay `alt=""`).
  - AC5: no keyword stuffing (copy reads naturally, per the locked decision); `pnpm gates` green.

- **34.7 - Measurement & tooling (frontend/ CI + external). [S-M, Should] [human: GSC ownership]**
  - AC1: Google Search Console property verified for archilan.fr (DNS or meta tag committed) and the
    sitemap submitted [human: account access]; verification artefact committed if tag-based.
  - AC2: Lighthouse CI (or equivalent) added to frontend CI in advisory mode with a recorded baseline for
    home + one event page; thresholds documented, not yet blocking.
  - AC3: a short measurement doc: baseline date, target queries from the 34.6 keyword map to watch in GSC,
    and the off-site human action list (Google Business Profile, association directories, backlinks from
    partner communities) explicitly handed to the association.
  - AC4: gates green (CI change must not destabilise existing workflows).

## Sequencing

1. **34.1 + 34.2** first - sitemap/robots and metadata are the highest-impact, lowest-risk wins, and both
   are prerequisites for meaningful Search Console data.
2. **34.7 (GSC part) immediately after** - verify + submit the sitemap early so impression data starts
   accumulating while the rest of the epic proceeds; the Lighthouse CI part can land any time.
3. **34.3** - structured data, independent of the above, any time after 34.2 (shares the canonical rules).
4. **34.4 then 34.5** - 34.4 changes the rendering/caching model, 34.5 measures and tunes on top of it;
   doing 34.5's Lighthouse pass before 34.4 would measure a model about to change.
5. **34.6** - editorial pass last (or in parallel with 34.4/34.5): it depends on the keyword map and human
   validation, and its copy lands on pages whose metadata skeleton 34.2 already fixed.

## Risks / notes

- **34.4 is the only structurally risky story.** Changing image delivery + caching touches api/ and the
  rendering model. Mitigation: decision record first, private surfaces untouched, realtime widgets stay
  client-side, measured before/after, both gate suites green.
- **Niche vs volume.** Archipelago francophone queries are low-volume; the payoff is qualified traffic and
  quasi-monopoly positioning, not raw numbers. Local queries bring the volume. Expectations recorded in
  34.7's measurement doc.
- **Local SEO is mostly off-site.** On-site we can only ship `Organization` address + `Event` `Place` +
  local copy; Google Business Profile and citations are association actions (34.7 AC3 hands them off).
- **Content requires human input.** 34.6 blocks on keyword-map validation and the association's address
  (34.3) - flagged [human] so the epic does not stall silently.
- **Security headers can break embeds.** 34.5 AC4 explicitly verifies Twitch/HelloAsso embed pages before
  merge.
- **SEO effects are lagged.** Weeks to months for ranking movement; the epic's DoD is the on-site work +
  measurement being in place, not a ranking target.

## Discoverability

Standalone epic file, following the epic 27-33 convention. Not yet in `epic-list.md` / `index.md` (the
index is generated; regenerate via `bmad-index-docs` when convenient - same note as epic 33).

## Change Log

| Date       | Change |
|------------|--------|
| 2026-07-08 | Epic planned from a code-level SEO audit of the frontend (findings recorded in "Baseline audit"). Scope agreed with the product owner: technical SEO + performance/CWV + editorial content + measurement, targeting both the francophone Archipelago niche and local LAN discovery. Stories 34.1-34.7 proposed; sitemap/robots + metadata sequenced first, GSC verification early, ISR/image-infrastructure (34.4) isolated as the only structurally risky story. |