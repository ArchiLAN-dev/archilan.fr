# Story 34.3: Structured data completion (frontend/)

Status: done

## Story

As a search engine building a rich understanding of ArchiLAN,
I want site-wide `Organization` + `WebSite` structured data, a `BreadcrumbList` on every detail page, and the
existing `Event` / `NewsArticle` JSON-LD tightened to real data,
so that Google can attribute the site to the association (knowledge panel, local signal), show breadcrumb
and event rich results, and never choke on malformed or thin structured data.

## Context

Epic 34 baseline audit (2026-07-08), gaps this story closes:

- **Gap 3** - no site-wide `Organization`/`WebSite` JSON-LD; no `BreadcrumbList` anywhere. Only two detail
  pages emit structured data today: `Event` on `evenements/[eventSlug]` and `NewsArticle`/`Article` on
  `actualites/[postSlug]`, each via a **hand-rolled** `<script type="application/ld+json">` with an inline
  XSS-escaping `.replace(...)` chain duplicated in both files.

The existing `Event`/`NewsArticle` payloads are also thin: `Event` has a `Place` with only `name`, no
`offers` even when ticketing (HelloAsso checkout) applies; `NewsArticle` has no `dateModified` and no
`publisher`. The locked epic decision requires: **JSON-LD urls use the slug-based route the crawler
visits** (already fixed for events in 34.2) and **a shared escaped-serialization helper is reused - no new
hand-rolled script tags**.

The association's real postal identity is already public on `/mentions-legales` (no new human input needed):
ArchiLAN, association loi 1901, **siège 26 rue de la Gantière, 63000 Clermont-Ferrand**, RNA W632015225,
contact@archilan.fr. Social profiles for `sameAs` come from `src/lib/external-links.ts` (Twitch, ArchiLAN
Discord).

## Acceptance Criteria

1. **AC1 - Organization + WebSite (site-wide).** The root layout emits an `Organization` JSON-LD (`name`,
   `url`, absolute `logo`, `sameAs` = Twitch + ArchiLAN Discord, `address` as a `PostalAddress` from the real
   Clermont-Ferrand siège, `email`) and a `WebSite` JSON-LD (`name`, `url`), both through the shared
   serialization helper.
2. **AC2 - BreadcrumbList on detail pages.** Event, post and game detail pages each emit a `BreadcrumbList`
   matching the visible nav path (Accueil -> section -> current), with **absolute** item URLs and the current
   page's URL built from its slug route (consistent with the 34.2 canonical rule).
3. **AC3 - Event / NewsArticle audit.** `Event`: `Place` keeps `name` from real venue data (`event.location`);
   an `offers` (`Offer`) is added when ticketing applies (`checkoutEmbedUrl` present), with `url` +
   `availability` derived from capacity (no fabricated price - the public payload has none). `NewsArticle`:
   `image` kept when present, `dateModified` added (from `publishedAtIso` - the public payload exposes no
   separate modified timestamp), and a `publisher` (Organization + logo) added so Article rich results
   validate.
4. **AC4 - Validity + shared helper.** A single `JsonLd` component (shared, escaped serialization) replaces
   both hand-rolled `<script>` blocks and is used by every new emitter - no new hand-rolled script tags. All
   emitted JSON-LD is well-formed and schema.org-correct; validity is asserted by unit tests (round-trips as
   JSON, correct `@type`/required fields) and a documented view-source check (Rich Results Test is the human
   follow-up).
5. **AC5 - Gates.** `pnpm gates` green (typecheck, lint 0 warnings, jest, build). No `process.env`, no `as`
   casts, no `any` (AC-ENV1, AC-TS2/3).

## Tasks / Subtasks

- [x] Task 1: shared serialization + component (AC: 4)
  - [x] `src/lib/structured-data.ts` exports `serializeJsonLd(data): string` - the XSS-escaping
        (`<`, `>`, `&` -> `\uXXXX`) currently inlined in the event/post pages, extracted once.
  - [x] `src/components/json-ld.tsx` exports a `JsonLd({ data })` server component rendering
        `<script type="application/ld+json" dangerouslySetInnerHTML={{ __html: serializeJsonLd(data) }} />`.
  - [x] Replace the hand-rolled `<script>` blocks in `evenements/[eventSlug]/page.tsx` and
        `actualites/[postSlug]/page.tsx` with `<JsonLd data={...} />`.
- [x] Task 2: Organization + WebSite builders (AC: 1)
  - [x] In `structured-data.ts`: `organizationJsonLd()` and `websiteJsonLd()` returning the schema.org
        objects. Absolute urls (`logo`, and `Organization.url`/`WebSite.url`) from `env.appUrl`; `sameAs`
        from `externalLinks` (Twitch, ArchiLAN Discord); `address` = the Clermont-Ferrand `PostalAddress`.
  - [x] `src/app/layout.tsx`: render `<JsonLd data={organizationJsonLd()} />` and
        `<JsonLd data={websiteJsonLd()} />` in the body (site-wide).
- [x] Task 3: BreadcrumbList builder + wiring (AC: 2)
  - [x] In `structured-data.ts`: `breadcrumbJsonLd(items: { name: string; path: string }[])` -> a
        `BreadcrumbList` with 1-indexed `ListItem`s and absolute `item` urls.
  - [x] Event page: Accueil (`/`) -> Événements (`/evenements`) -> `event.title` (`/evenements/${eventSlug}`).
  - [x] Post page: Accueil -> Actualités (`/actualites`) -> `post.title` (`/actualites/${post.slug}`).
  - [x] Game page (`jeux/[slug]`, no JSON-LD today): Accueil -> Jeux (`/jeux`) -> `game.name`
        (`/jeux/${game.slug}`), wrapped alongside `<GameDetail />`.
- [x] Task 4: Event / NewsArticle audit (AC: 3)
  - [x] `getEventStructuredData`: add `offers` when `event.checkoutEmbedUrl` is set (`Offer` with `url` =
        the event canonical and `availability` from `event.capacity`/status); keep `Place` name-only
        (no structured address field exists - documented).
  - [x] `getPostStructuredData`: add `dateModified: post.publishedAtIso` and a `publisher`
        (Organization with logo); keep the conditional `image`.
- [x] Task 5: tests (AC: 4, 5)
  - [x] `src/lib/structured-data.test.ts`: `serializeJsonLd` escapes `<`/`>`/`&` (no raw `</script>` can
        break out) and still round-trips via `JSON.parse`; `organizationJsonLd`/`websiteJsonLd` have the
        right `@type` + required fields + absolute urls + the real `addressLocality`; `breadcrumbJsonLd`
        yields ordered `ListItem`s with absolute urls and correct `position`s.
- [x] Task 6: verify + ship (AC: 5)
  - [x] `pnpm gates` green; dev-server view-source of `/` (Organization + WebSite) and one post/game page
        (Breadcrumb), each parsed as JSON to prove validity; result noted in the Dev Agent Record.
  - [x] Branch `feature/epic-34-story-3-structured-data` from `develop`, PR to `develop` (Gitflow).

## Dev Notes

### What exists today (do not regress)

- `evenements/[eventSlug]/page.tsx`: `getEventStructuredData(event, canonicalUrl)` -> `Event` with
  `eventStatus`, `eventAttendanceMode`, `location` (`Place`, name-only), `organizer`. Emitted via an inline
  `<script>` with a `.replace(/</g,"\\u003c").replace(/>/g,"\\u003e").replace(/&/g,"\\u0026")` chain.
  `canonicalUrl` is already `/evenements/${eventSlug}` (34.2 fix).
- `actualites/[postSlug]/page.tsx`: `getPostStructuredData(post, canonicalUrl)` -> `NewsArticle`/`Article`
  with `headline`, `description`, `url`, `datePublished`, `author` (Organization), conditional `image`.
  Same inline escaping. `schemaTypeByPostType`: news/announcement -> `NewsArticle`, recap -> `Article`.
- Both escaping chains are identical - that duplication is exactly what the shared `JsonLd` removes.

### Data reality (fields that exist, so we don't invent any)

- `PublicEvent` (`src/features/events/event-types.ts`): `location: string` (venue **name** only - there is no
  structured street/city field, so `Place` stays name-only; address "when available" is not available and is
  documented, not faked). `capacity?: { total; remaining }`, `checkoutEmbedUrl?`, `status`, `dateIso`,
  `endDateIso`, `coverImageUrl?`. Ticketing "applies" iff `checkoutEmbedUrl` is set. There is **no price** in
  the public payload -> the `Offer` carries `url` + `availability` only (no fabricated `price`).
- `PublicPost` (`src/features/content/content-types.ts`): `publishedAtIso`, `title`, `excerpt`, `slug`,
  `coverImageUrl?`. There is **no public `updatedAt`** (only `AdminContentPost` has `updatedAtIso`), so
  `dateModified` truthfully defaults to `publishedAtIso` (an article unmodified since publication). Extending
  the public API payload with a real `updatedAt` is out of scope (same honesty rule as 34.1's `lastModified`).
- `PublicGame` (`src/features/games/*`): `name`, `slug`, `coverImageUrl?`. Game detail gets a
  `BreadcrumbList` only (a full `VideoGame`/`Game` schema is not in this story's ACs).

### Organization values (already public on /mentions-legales - no new human input)

```
name: ArchiLAN ; loi 1901 ; RNA W632015225
address: 26 rue de la Gantière, 63000 Clermont-Ferrand, FR
email: contact@archilan.fr
sameAs: externalLinks.twitch, externalLinks.archilanDiscord   (src/lib/external-links.ts)
logo: absolute /images/logo.webp
```

### Helper shape (house rules: AC-ENV1, AC-TS2/3, AC-CO3)

- `structured-data.ts` may import `env` (it legitimately needs the absolute site origin for `url`/`logo`/
  breadcrumb items) - unlike `seo.ts`, these values are absolute by nature. Build absolutes with
  `new URL(path, env.appUrl).toString()`. Never touch `process.env`.
- `JsonLd` is a **named** export in `src/components/` (AC-CO3 forbids default component exports only inside
  `features/`; `components/` uses named exports too by convention). It is a server component (no `"use
  client"`), pure render.
- Type the builder returns as `Record<string, unknown>` (schema.org objects are heterogeneous); no `as`
  casts. `serializeJsonLd(data: Record<string, unknown>)`.

### Placement of Organization/WebSite

Epic says **site-wide, in the root layout** (`src/app/layout.tsx`). Emitting `Organization`/`WebSite` on
every route (including the noindex `(admin)`/`(overlay)` shells) is harmless - crawlers ignore structured
data on noindex pages - and matches the "site-wide identity" intent. Do not scatter it per public page.

### Testing pattern

- Pure unit tests (no MSW, no rendering) - the builders are pure functions of `env` (jest provides the
  default env: `env.appUrl` = `http://localhost:3000`). Assert `@type`, required fields, absolute urls,
  and that `serializeJsonLd` output has no raw `<`/`>`/`&` yet `JSON.parse`s back to the input.
- The `JsonLd` component itself is thin (delegates to `serializeJsonLd`); testing `serializeJsonLd`
  directly covers the security-relevant logic without needing a DOM renderer.
- View-source smoke (Task 6) parses the emitted `<script type="application/ld+json">` bodies as JSON to
  prove real-page validity; a Google Rich Results Test run is the documented human follow-up (can't run in
  CI). Reference: the 34.1/34.2 view-source smokes.

### Project Structure Notes

- New: `src/lib/structured-data.ts` (+ `src/lib/structured-data.test.ts`), `src/components/json-ld.tsx`.
- Edited: `src/app/layout.tsx` (Org + WebSite), `evenements/[eventSlug]/page.tsx` (JsonLd + offers +
  breadcrumb), `actualites/[postSlug]/page.tsx` (JsonLd + dateModified/publisher + breadcrumb),
  `jeux/[slug]/page.tsx` (breadcrumb).
- No API change, no new env var, no config change. `pnpm gates` only.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` (gap 3, story 34.3 ACs,
  "shared escaped-serialization helper" + "slug is canonical identity" locked decisions).
- Existing emitters to refactor: `evenements/[eventSlug]/page.tsx`, `actualites/[postSlug]/page.tsx`.
- Org data source: `frontend/src/app/(public)/mentions-legales/page.tsx`; socials: `src/lib/external-links.ts`.
- Predecessors: 34.1 (sitemap/robots), 34.2 (canonical hygiene - event JSON-LD url already uses the slug).
- Standards: `frontend/AGENTS.md` (AC-ENV1, AC-TS2/3, AC-CO3); root `CLAUDE.md` (gates, Gitflow, no em-dashes).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- Dev-server view-source of `/`, JSON-LD blocks parsed with `json.loads` (proves real-page validity):
  - 2 `application/ld+json` blocks found, both parse.
  - `Organization`: keys `@context, @type, address, email, logo, name, sameAs, url`;
    `address.addressLocality = Clermont-Ferrand`; `sameAs = [twitch/test-channel, discord.gg/...]`;
    `logo = http://localhost:3000/images/logo.webp`.
  - `WebSite`: `@type WebSite`, name, absolute url.
  - Detail-page breadcrumbs could not be live-smoked (local API down -> event/post/game detail 404); they
    use the same shared `JsonLd` component proven on `/`, and `breadcrumbJsonLd` is unit-tested. Google
    Rich Results Test on a live detail page is the documented human follow-up.

### Completion Notes List

- Single shared path for all structured data: `serializeJsonLd` (the escaped serializer, extracted from the
  two duplicated inline `.replace` chains) + `src/components/json-ld.tsx` `<JsonLd data={...} />`. Both the
  event and post pages now use it; no hand-rolled `<script>` blocks remain (AC4).
- `Organization` + `WebSite` emitted site-wide from the root layout body. Address sourced from the
  already-public mentions légales (Clermont-Ferrand siège) - no new human input. `sameAs` = Twitch +
  ArchiLAN Discord from `external-links.ts`.
- `BreadcrumbList` on event / post / game detail (game had no JSON-LD before), absolute item urls, current
  page keyed by its slug route (consistent with the 34.2 canonical rule).
- Event audit: `offers` added only when `checkoutEmbedUrl` is set (ticketing applies), `url` = event
  canonical, `availability` from capacity (SoldOut when `remaining <= 0`, else InStock); no fabricated
  price (the public payload has none). `Place` kept name-only (no structured address field exists).
- NewsArticle audit: `dateModified` = `publishedAtIso` (no public `updatedAt`; truthful for an unmodified
  article), `publisher` (Organization + ImageObject logo) added so Article rich results validate; `image`
  kept conditional.
- `pnpm gates` green: typecheck 0, lint 0 errors (1 pre-existing warning in the untouched
  `admin-content-dashboard.tsx`), jest 194/194 (+5 new), build clean.

### File List

- `frontend/src/lib/structured-data.ts` (new)
- `frontend/src/lib/structured-data.test.ts` (new)
- `frontend/src/components/json-ld.tsx` (new)
- `frontend/src/app/layout.tsx` (edit: Organization + WebSite site-wide)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (edit: JsonLd + breadcrumb + offers)
- `frontend/src/app/(public)/actualites/[postSlug]/page.tsx` (edit: JsonLd + breadcrumb + dateModified/publisher)
- `frontend/src/app/(public)/jeux/[slug]/page.tsx` (edit: breadcrumb)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-15 | Story created from epic 34 (gap 3). Grounded in a read of both existing JSON-LD emitters, the `PublicEvent`/`PublicPost`/`PublicGame` field reality, the association's public legal identity, and `external-links.ts`. Address sourced from the published mentions légales (no new human input). Status: ready-for-dev. |
| 2026-07-15 | Implemented: shared `JsonLd` + `serializeJsonLd`, site-wide Organization/WebSite, BreadcrumbList on event/post/game, Event `offers` + NewsArticle `dateModified`/`publisher`. `pnpm gates` green + view-source validity check. Status: done. |
