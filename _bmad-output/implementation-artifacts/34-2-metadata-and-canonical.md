# Story 34.2: Metadata completion & canonical hygiene (frontend/)

Status: done

## Story

As a search engine (and a person sharing an ArchiLAN link in a chat or on social),
I want every indexable public page to expose a correct canonical URL, a complete Open Graph / Twitter card,
and a keyword-honest title & description, with no page self-canonicalising to the wrong URL,
so that Google indexes the right URL for each page and shared links render a proper preview everywhere.

## Context

Epic 34 baseline audit (2026-07-08) found (gaps this story closes):

- **Gap 2** - the **home page has no `metadata` export**: it inherits the root layout default
  (`title: "ArchiLAN"`, generic description, no canonical). The single most-linked page has no targeted
  title/description and no self-canonical.
- **Gap 4** - listing/marketing pages (`evenements`, `actualites`, `jeux`, `runs-hebdo` (+ `jeu`),
  `classements`, `communaute`, `boutique`, `adhesion`, `aide/archipelago`, legal pages) have
  **title + description only** - no `alternates.canonical`, inconsistent/absent `openGraph`, no `twitter`.
- **Gap 5** - **canonical bug**: `evenements/[eventSlug]/page.tsx` builds the canonical, the OG `url` and the
  JSON-LD `url` from `event.id` while the route param is `eventSlug`. If the visited slug ever differs from
  the DB id, the page self-canonicalises to a *different* URL than the one crawled - a canonical that points
  away from itself. 34.1 deliberately deferred this identity question to this story.

Locked epic decision that governs the fix: **"Slug is the canonical identity of a public resource -
canonicals, sitemap entries, OG urls and JSON-LD urls all use the slug-based route the crawler actually
visits, never a DB id."** For events there is no separate slug field yet (`PublicEvent` has none), so "the
route the crawler visits" is the `[eventSlug]` param value; the fix is to build the event's canonical from
that param, not from `event.id`. Today the two are equal (the 34.1 sitemap emits `/evenements/{id}`), so
this is a latent-bug fix with no observable URL change, but it removes the divergence risk and satisfies
the rule.

What already exists and must not regress (baseline audit): root layout has `metadataBase`, the
`%s | ArchiLAN` title template, default description, OG defaults and favicon; detail pages
(`actualites/[postSlug]`, `jeux/[slug]`) already follow the correct slug-param canonical pattern -
`actualites/[postSlug]` is the reference implementation to copy.

## Acceptance Criteria

1. **AC1 - Home metadata.** `src/app/(public)/page.tsx` exports `metadata` with a keyword-targeted title,
   a targeted description, and `alternates.canonical: "/"`, plus `openGraph` + `twitter`. The home title is
   absolute (not run through the `%s | ArchiLAN` template - it would double the brand).
2. **AC2 - Listing/marketing coverage.** Every indexable listing/marketing page - `evenements`,
   `actualites`, `jeux`, `runs-hebdo`, `runs-hebdo/jeu/[gameSlug]`, `classements`, `communaute`, `boutique`,
   `adhesion`, `aide/archipelago`, and the legal pages (`cgu`, `cgv`, `mentions-legales`, `confidentialite`) -
   gains `alternates.canonical` (its own path) and a complete `openGraph` (title, description, url, siteName,
   type, locale, image). Pages that don't set their own `twitter` inherit sensible `twitter` defaults added
   to the **root layout** (AC-shared). `runs-hebdo/jeu/[gameSlug]` becomes a `generateMetadata` so its
   canonical carries the visited `gameSlug` param.
3. **AC3 - Event canonical fix (gap 5).** `evenements/[eventSlug]/page.tsx` builds the canonical, the OG
   `url` and the JSON-LD `url` from the **`eventSlug` route param**, not `event.id`. A regression test proves
   that when the fetched event's `id` differs from the visited slug, the canonical still equals
   `/evenements/{visitedSlug}`.
4. **AC4 - Keyword skeleton & title hygiene.** Titles/descriptions follow the keyword-map skeleton (final
   copy may be refined in 34.6); no rendered title exceeds ~60 chars and no two covered pages share the same
   title. Descriptions are meaningful French, one per page.
5. **AC5 - No process.env / house rules.** All URLs resolve from `env.appUrl` via `metadataBase` +
   relative paths (AC-ENV1, no `process.env`). No `as` casts, no `any`. A shared metadata helper builds the
   canonical/OG/twitter block so the 14+ pages cannot drift apart.
6. **AC6 - Gates.** `pnpm gates` green (typecheck, lint 0 warnings, jest, build). Dev-server smoke: view
   source on `/`, one listing page and one event detail page shows the expected `<link rel="canonical">`,
   `og:*` and `twitter:*` tags; result noted in the Dev Agent Record.

## Tasks / Subtasks

- [x] Task 1: shared metadata helper (AC: 2, 4, 5)
  - [x] `src/lib/seo.ts` exporting `buildPageMetadata({ title, description, path, ogType?, images?,
        absoluteTitle? }): Metadata`. It sets `title` (absolute when `absoluteTitle`), `description`,
        `alternates.canonical: path`, a full `openGraph` (title `"${title} | ArchiLAN"` - or the absolute
        title for the home page, `description`, `url: path`, `siteName`, `type` default `"website"`,
        `locale: "fr_FR"`, `images` defaulting to the site OG image), and a `twitter` summary card.
  - [x] Export `SITE_NAME`; keep the default OG image path in one place. Relative `path`/`url` resolve
        against the root `metadataBase` - do NOT re-declare `metadataBase` per page.
  - [x] Unit test `src/lib/seo.test.ts`: canonical == path; OG url == path; OG title branded; home
        `absoluteTitle` yields `title.absolute` and an unbranded OG title; passing `images` overrides the
        default; twitter title/description present.
- [x] Task 2: root layout twitter defaults (AC: 2)
  - [x] Add a `twitter` block to `src/app/layout.tsx` metadata (`card: "summary_large_image"`, default
        title/description matching the OG defaults) so pages without their own `twitter` inherit it.
- [x] Task 3: home metadata (AC: 1)
  - [x] `src/app/(public)/page.tsx`: `export const metadata = buildPageMetadata({ absoluteTitle: true,
        title: <keyword home title>, description: <keyword home description>, path: "/" })`.
- [x] Task 4: listing/marketing pages (AC: 2, 4)
  - [x] Route each static-metadata page through `buildPageMetadata({ title, description, path })`:
        `evenements`, `actualites`, `jeux`, `runs-hebdo`, `classements`, `communaute`, `boutique`,
        `adhesion`, `aide/archipelago`, `cgu`, `cgv`, `mentions-legales`, `confidentialite`. Keep the
        existing (already-unique) titles/descriptions unless a length/uniqueness fix is needed; drop the now
        redundant `robots: { index: true }` on legal pages (indexable is the default).
  - [x] `runs-hebdo/jeu/[gameSlug]/page.tsx`: convert `export const metadata` to
        `export async function generateMetadata({ params })`, `await params`, and build the canonical/OG from
        `/runs-hebdo/jeu/${gameSlug}` via the helper. Title stays generic (per-game copy is 34.6's scope).
- [x] Task 5: event canonical fix (AC: 3)
  - [x] `evenements/[eventSlug]/page.tsx`: in `generateMetadata`, build `canonicalPath` and the OG `url`
        from `eventSlug` (the awaited param), not `event.id`. In the page body, build `canonicalUrl`
        (JSON-LD `url`) from `eventSlug` too. The `getEventStructuredData` `url` argument follows.
  - [x] Regression test `src/app/(public)/evenements/[eventSlug]/metadata.test.ts` (MSW): stub
        `GET /events/:slug` returning an event whose `id` differs from the requested slug; assert
        `generateMetadata` returns `alternates.canonical === "/evenements/{requestedSlug}"` and
        `openGraph.url` likewise.
- [x] Task 6: verify + ship (AC: 6)
  - [x] `pnpm gates` green; dev-server view-source smoke of `/`, one listing page and one event detail,
        result noted in the Dev Agent Record.
  - [x] Branch `feature/epic-34-story-2-metadata-canonical` from `develop`, PR to `develop` (Gitflow).

## Dev Notes

### Framework reality check

- Next.js is **16.2.10** (`frontend/package.json`). Metadata docs:
  `node_modules/next/dist/docs/01-app/03-api-reference/04-functions/generate-metadata.md`.
- **Metadata merging is shallow, per top-level field.** A route that defines `openGraph` (or `twitter`)
  **replaces** the parent's object for that field - Next does not merge `openGraph` field-by-field across
  segments. Consequence: a page that sets `openGraph` without an `images` entry loses the root default
  image. That is why the helper always emits an `images` array (default = the site OG image) rather than
  relying on inheritance, and why the `twitter` defaults go on the **root layout** (pages that never set
  their own `twitter` then inherit it wholesale).
- `alternates.canonical` and `openGraph.url` may be **relative** strings; Next resolves them against the
  root `metadataBase` (already `new URL(env.appUrl)` in `src/app/layout.tsx`). Do not re-declare
  `metadataBase` per page (the existing detail pages that do are harmless but redundant).
- The `%s | ArchiLAN` title **template** applies to string titles of descendant segments; it does NOT apply
  to `openGraph.title` (set that explicitly) and it is bypassed by `title: { absolute: "..." }` (used for
  the home page to avoid `ArchiLAN ... | ArchiLAN`).

### The canonical bug, precisely (gap 5)

`evenements/[eventSlug]/page.tsx` today:

- `generateMetadata`: `const canonicalPath = \`/evenements/${event.id}\`` (also OG `url`).
- page body: `const canonicalUrl = new URL(\`/evenements/${event.id}\`, env.appUrl).toString()` -> JSON-LD.

`getPublicEvent(eventSlug)` hits `GET /events/{eventSlug}` and returns a `PublicEvent` whose `.id` is the
DB id. Route param is `eventSlug`. The fix: use `eventSlug` (the value the crawler visited) in all three
spots. `PublicEvent` has no slug field, so `eventSlug` IS the canonical identity here (locked decision).
The reference implementation that already does this correctly is `actualites/[postSlug]/page.tsx`
(uses `post.slug`, which equals its route param) and `jeux/[slug]/page.tsx` (uses `game.slug`).

> Do not invent an event slug or change the sitemap (34.1 emits `/evenements/{id}`, which equals the param
> today). If a real event slug is ever added, both this canonical and the sitemap follow in that later work.

### Helper shape (house rules: AC-ENV1, AC-TS2/3, AC-CO3)

```ts
// src/lib/seo.ts
import type { Metadata } from "next";

export const SITE_NAME = "ArchiLAN";
const DEFAULT_OG_IMAGE = { url: "/images/events/lan-photo-1.webp", width: 6000, height: 4000,
  alt: "Participants jouant lors d'un événement ArchiLAN" };

type PageMetaInput = {
  title: string; description: string; path: string;
  ogType?: "website" | "article";
  images?: NonNullable<NonNullable<Metadata["openGraph"]>["images"]>;
  absoluteTitle?: boolean;
};

export function buildPageMetadata({ title, description, path, ogType = "website", images,
  absoluteTitle = false }: PageMetaInput): Metadata {
  const ogTitle = absoluteTitle ? title : `${title} | ${SITE_NAME}`;
  return {
    title: absoluteTitle ? { absolute: title } : title,
    description,
    alternates: { canonical: path },
    openGraph: { title: ogTitle, description, url: path, siteName: SITE_NAME, type: ogType,
      locale: "fr_FR", images: images ?? [DEFAULT_OG_IMAGE] },
    twitter: { card: "summary_large_image", title: ogTitle, description },
  };
}
```

- No `metadataBase` here (root owns it); relative `path` resolves absolute at render.
- `src/lib/env.ts` is not imported by the helper - it emits relative paths and lets `metadataBase` resolve
  them, keeping the helper a pure function of its input (easy to unit test, no env needed).

### Titles/descriptions (AC4 - skeleton, not final copy)

- Keep the existing, already-unique listing titles/descriptions; route them through the helper for the
  canonical/OG/twitter gain. 34.6 owns the keyword-map-validated final copy - do not over-rewrite here.
- Home is the only page needing brand-new copy (it has none today). Use a keyword-targeted skeleton, e.g.
  title `"ArchiLAN - Événements Archipelago multiworld en France"` (<= 60 chars), description built from the
  Archipelago-in-France cluster. Mark it as skeleton in the Dev Agent Record so 34.6 revisits it.
- Rendered title = raw title + `" | ArchiLAN"` (11 chars) for template pages; keep raw titles short enough
  that the rendered form stays <= ~60. Legal titles like "Conditions Générales d'Utilisation" render to ~46.

### Testing pattern (house standard - MSW, co-located, no jest.mock)

- Helper test is a pure unit test (no MSW needed). The event regression test uses MSW exactly like
  `src/app/sitemap.test.ts`: `server` from `../../../../tests/setup` (count the `../` from
  `src/app/(public)/evenements/[eventSlug]/`), `TEST_API_BASE_URL` from the constants module, and a
  handler on `` `${BASE}/events/:slug` `` (or the exact requested path) returning a valid
  `PublicEventPayload` whose `id` != the requested slug. Copy a valid event payload shape from
  `src/features/events/public-events-api.test.ts` or the `validEvent` in `src/app/sitemap.test.ts`.
- `jest.setup.ts` points `NEXT_PUBLIC_API_BASE_URL` at `TEST_API_BASE_URL`; `env.appUrl` defaults to
  `http://localhost:3000`. `alternates.canonical` is a **relative** string (`/evenements/...`) in the
  returned `Metadata` - assert the relative value (Next resolves it against `metadataBase` only at render).
- MSW runs with `onUnhandledRequest: "error"`; register the one handler the test needs.

### Project Structure Notes

- New: `src/lib/seo.ts` (+ `src/lib/seo.test.ts`), event regression test.
- Edited: `src/app/layout.tsx` (twitter defaults), `src/app/(public)/page.tsx` (home metadata),
  13 listing/marketing pages (route through helper), `runs-hebdo/jeu/[gameSlug]/page.tsx`
  (static -> `generateMetadata`), `evenements/[eventSlug]/page.tsx` (canonical fix, 3 spots).
- No API change, no new env var, no config change. `pnpm gates` is the only gate suite.
- Detail pages `actualites/[postSlug]` and `jeux/[slug]` already use the correct slug-param canonical and
  their own OG; they are NOT rewritten here (they'll inherit the new root `twitter` defaults). Player and
  stream detail pages stay `robots noindex` - out of scope.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` (gaps 2/4/5, locked
  "slug is canonical identity" decision, story 34.2 ACs).
- Reference canonical pattern: `frontend/src/app/(public)/actualites/[postSlug]/page.tsx`.
- Env: `frontend/src/lib/env.ts` (`appUrl`); root metadata: `frontend/src/app/layout.tsx`.
- Standards: `frontend/AGENTS.md` (AC-ENV1, AC-TS2/3, AC-CO3, AC-NX6); root `CLAUDE.md` (gates, Gitflow,
  no em-dashes).
- Predecessor: story 34.1 (sitemap/robots) - deferred the event id/slug canonical question to here.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- Dev-server view-source smoke (`pnpm dev`, API down - so the event detail 404s, hence smoked home +
  two listings; the event canonical is covered by the regression test):
  - `/` -> `<title>ArchiLAN - Événements Archipelago multiworld en France</title>` (absolute, no doubled
    brand), `<link rel="canonical" href="http://localhost:3000">`, full `og:*` (title/description/url/
    site_name/locale/image/type=website) and `twitter:card=summary_large_image` + branded title.
  - `/evenements` -> `<title>Événements | ArchiLAN</title>` (template applied), canonical + og:url
    `.../evenements`, og:image default, twitter card present.
  - `/jeux` -> same shape, canonical `.../jeux`.
- Typecheck initially failed only in `seo.test.ts` (`OpenGraph`/`Twitter` are unions; `.type`/`.card`
  need `in`-narrowing). Fixed the assertions; no production code affected.

### Completion Notes List

- Introduced `src/lib/seo.ts::buildPageMetadata` as the single source of the canonical + OG + twitter
  block; 14 pages route through it so they cannot drift. Helper stays a pure function (no env import) -
  it emits relative paths that Next resolves against the root `metadataBase`.
- Root layout gained `twitter` defaults; `jeux/[slug]` and other pages that don't set their own twitter
  now inherit a proper card (metadata merges shallowly per field).
- Gap-5 fix: event detail canonical / OG url / JSON-LD url now use the `eventSlug` route param, not
  `event.id`. Regression test proves the canonical tracks the visited slug even when `event.id` differs.
- `runs-hebdo/jeu/[gameSlug]` converted from a static `metadata` const to `generateMetadata` so its
  canonical carries the visited slug.
- AC4 (title hygiene): kept the existing, already-unique listing titles; only the home page got new
  keyword-skeleton copy (marked skeleton - 34.6 refines). Longest rendered title
  "Conditions Générales d'Utilisation | ArchiLAN" = 45 chars (< 60). No two covered pages share a title.
- `pnpm gates` green: typecheck 0, lint 0 errors (1 pre-existing warning in the untouched
  `admin-content-dashboard.tsx`), jest 189/189 (+8 new), build clean.
- Detail pages `actualites/[postSlug]` and `jeux/[slug]` already used the correct slug-param canonical
  and were left as-is (they inherit the new root twitter defaults). Not rewritten - out of this story's
  minimal-diff intent.

### File List

- `frontend/src/lib/seo.ts` (new)
- `frontend/src/lib/seo.test.ts` (new)
- `frontend/src/app/(public)/evenements/[eventSlug]/metadata.test.ts` (new)
- `frontend/src/app/layout.tsx` (edit: twitter defaults)
- `frontend/src/app/(public)/page.tsx` (edit: home metadata)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (edit: canonical from eventSlug, 3 spots)
- `frontend/src/app/(public)/runs-hebdo/jeu/[gameSlug]/page.tsx` (edit: static -> generateMetadata)
- `frontend/src/app/(public)/{evenements,actualites,jeux,runs-hebdo,classements,communaute,boutique,adhesion,aide/archipelago,cgu,cgv,mentions-legales,confidentialite}/page.tsx` (edit: route through buildPageMetadata)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-15 | Story created from epic 34 (gaps 2/4/5). Grounded in a code-level read of the root layout, home page, all listing/marketing metadata blocks, the event/post/game detail `generateMetadata`, the `getPublicEvent` fetcher contract and the metadata-merging semantics of Next 16.2.10. Status: ready-for-dev. |
| 2026-07-15 | Implemented: `buildPageMetadata` helper, root twitter defaults, home metadata, 14 pages routed through the helper, event canonical fix (gap 5) + regression test, `runs-hebdo/jeu` -> generateMetadata. `pnpm gates` green + view-source smoke. Status: done. |