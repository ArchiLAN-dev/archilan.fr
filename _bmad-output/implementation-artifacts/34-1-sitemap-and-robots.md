# Story 34.1: Sitemap & Robots (frontend/)

Status: done

## Story

As a search engine crawler (and therefore as a visitor searching Google for ArchiLAN, Archipelago or local LAN events),
I want archilan.fr to expose a complete, accurate `sitemap.xml` and a `robots.txt` with correct crawl directives,
so that every indexable public page is discovered and crawled, and private/admin surfaces are kept out of the index.

## Context

Epic 34 baseline audit (2026-07-08): the site has **no sitemap and no robots file** - discovery relies
entirely on internal links. This is the epic's gap n°1 and the prerequisite for Search Console data (34.7).
Everything else in the SEO stack (per-page metadata, noindex hygiene, server rendering) is already in place.

This is the first story of Epic 34 - see
`_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` for the locked decisions,
notably: **the indexable surface is the public site only**, and **URLs in the sitemap use the route the
crawler actually visits** (see the event id/slug note in Dev Notes).

## Acceptance Criteria

1. **AC1 - Dynamic sitemap.** `frontend/src/app/sitemap.ts` (App Router metadata route, default export
   returning `MetadataRoute.Sitemap`) emits:
   - every static indexable public route: `/`, `/evenements`, `/actualites`, `/jeux`, `/runs-hebdo`,
     `/classements`, `/communaute`, `/boutique`, `/adhesion`, `/aide/archipelago`, `/cgu`, `/cgv`,
     `/mentions-legales`, `/confidentialite`;
   - one entry per published event (`/evenements/{id}`, upcoming **and** past - completed events stay
     published with recaps);
   - one entry per published post (`/actualites/{slug}`) with `lastModified` from `publishedAtIso`;
   - one entry per public game (`/jeux/{slug}`);
   - one entry per current weekly-run game (`/runs-hebdo/jeu/{gameSlug}`), slug derived by the same
     `slugify` used by the page (extracted to a shared module - see Task 3);
   - **no** noindex/private route ever appears (no `/admin`, `/o/`, `/compte`, `/connexion`,
     `/inscription`, `/runs/*`, `/joueurs/*`, `/streams/*`, no event `inscription`/`resultats` sub-routes).
2. **AC2 - Robots.** `frontend/src/app/robots.ts` (default export returning `MetadataRoute.Robots`) allows
   the public site, disallows the crawl-worthless private areas (`/admin/`, `/o/`, `/compte/`, auth flows),
   and references the absolute sitemap URL. Routes governed by meta `robots noindex` that can carry
   external links (e.g. `/joueurs/*`) are NOT disallowed, so crawlers can see the noindex tag (see Dev
   Notes - robots.txt vs meta noindex).
3. **AC3 - Absolute URLs.** All sitemap/robots URLs are absolute, built from `env.appUrl`
   (`new URL(path, env.appUrl).toString()`), matching the existing canonical convention. No
   `process.env` access (AC-ENV1).
4. **AC4 - Graceful degradation.** If any API fetch fails, the sitemap still renders with the static
   routes plus whatever dynamic sections succeeded (the fetchers already return `[]` fallbacks - AC-API2);
   the route never throws a 500.
5. **AC5 - Tests.** Co-located jest tests (MSW, house pattern) assert: composition rules (indexable in,
   noindex out), absolute URL base, `lastModified` presence on posts, degradation on API error, and the
   robots rules (disallow list + sitemap URL).
6. **AC6 - Gates.** `pnpm gates` green (typecheck, lint 0 warnings, jest, build). Manual smoke:
   `/sitemap.xml` and `/robots.txt` render on the dev server.

## Tasks / Subtasks

- [x] Task 1: `src/app/robots.ts` (AC: 2, 3)
  - [x] `MetadataRoute.Robots` with `rules: { userAgent: "*", allow: "/", disallow: [...] }`,
        `sitemap: new URL("/sitemap.xml", env.appUrl).toString()`.
  - [x] Disallow list: `/admin/`, `/o/`, `/compte/`, `/connexion`, `/inscription`,
        `/mot-de-passe-oublie`, `/reinitialisation-mot-de-passe`, `/confirmation-email`, `/runs/`,
        `/evenements/*/inscription` (wildcard - Google supports `*`).
        Do NOT disallow `/joueurs/`, `/streams/`, `/evenements/*/resultats` (meta noindex handles them).
        Note: a bare `Disallow: /inscription` only matches the root-level auth page, not
        `/evenements/*/inscription` (prefix matching from root) - hence the separate wildcard rule.
- [x] Task 2: `src/app/sitemap.ts` (AC: 1, 3, 4)
  - [x] `async` default export returning `MetadataRoute.Sitemap`.
  - [x] Static entries list (the 14 routes of AC1).
  - [x] Events: `getPublicEvents()` from `src/features/events/public-events-api.ts` - concat
        `upcoming` + `past`, URL `/evenements/${event.id}`. Omit `lastModified` (no real update
        timestamp on events - see Dev Notes, "lastModified honesty").
  - [x] Posts: `getPublicPosts()` from `src/features/content/public-posts-api.ts` - URL
        `/actualites/${post.slug}`, `lastModified: post.publishedAtIso`.
  - [x] Games: `getAllPublicGames()` from `src/features/games/public-games-api.ts` (the non-paginated
        catalog call, NOT `getPublicGames`) - URL `/jeux/${game.slug}`, no `lastModified`.
  - [x] Weekly runs: `fetchCurrentWeeklyRuns()` from `src/features/weekly-runs/weekly-runs-api.ts` -
        URL `/runs-hebdo/jeu/${slugify(run.gameName)}` using the shared slugify (Task 3). Dedupe slugs.
  - [x] Fetch the four sources concurrently (`Promise.all`) - they are independent.
- [x] Task 3: extract `slugify` (AC: 1)
  - [x] Move the local `slugify` from `src/features/weekly-runs/weekly-run-game-client.tsx` (NFD
        normalize, lowercase, non-alnum to `-`) into a shared module (e.g.
        `src/features/weekly-runs/slugify.ts` or `src/lib/slugify.ts`), export it, and reuse it in BOTH
        the client page and the sitemap so the sitemap slug can never drift from the page slug.
- [x] Task 4: tests (AC: 5)
  - [x] `src/app/sitemap.test.ts` + `src/app/robots.test.ts`, co-located, MSW handlers on
        `TEST_API_BASE_URL` for `GET /events`, `GET /posts`, `GET /games` (MSW matches on path -
        the `?all=1` query is irrelevant to the handler), `GET /weekly-runs/current`
        (see Dev Notes - MSW pattern).
  - [x] Sitemap: static routes present; event/post/game/weekly entries present with expected URLs;
        `lastModified` on posts; no excluded prefix appears in any URL (assert against the AC1
        exclusion list); API failure (`HttpResponse.error()`) still yields the static entries.
  - [x] Robots: disallow list exact, `/joueurs/` absent from disallow, sitemap URL absolute.
- [x] Task 5: verify + ship (AC: 6)
  - [x] `pnpm gates` green; dev-server smoke of `/sitemap.xml` and `/robots.txt` (valid XML, expected
        directives), result noted in Dev Agent Record.
  - [x] Branch `feature/epic-34-story-1-sitemap-robots` from `develop`, PR to `develop` (Gitflow).

## Dev Notes

### Framework reality check (do this first)

- **Next.js is 16.2.10, not 15** (`frontend/package.json`; confirmed in `node_modules/next/package.json`).
  `frontend/AGENTS.md` warns: read the local docs before coding. The exact references for this story:
  - `node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/01-metadata/sitemap.md`
  - `node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/01-metadata/robots.md`
- Shapes (Next 16.2.10): `MetadataRoute.Sitemap = Array<{ url; lastModified?; changeFrequency?;
  priority?; ... }>`, `MetadataRoute.Robots = { rules; sitemap?; host? }`. Import
  `type { MetadataRoute } from "next"`. Default exports (correct for app route files - AC-CO3).
- No `MetadataRoute` usage exists anywhere in `src` yet - these are the first metadata routes.
- The data fetchers use `cache: "no-store"`, so the sitemap route is request-time dynamic. That matches
  the site's current `force-dynamic` model; do NOT add caching here - story 34.4 owns the
  ISR/revalidate strategy epic-wide.

### Fetcher contracts (exact, verified 2026-07-08)

| Source | Function | File | URL field | Date field |
|--------|----------|------|-----------|------------|
| Events | `getPublicEvents(): Promise<{upcoming, past}>` | `src/features/events/public-events-api.ts` | `event.id` | none usable |
| Posts | `getPublicPosts(): Promise<PublicPost[]>` | `src/features/content/public-posts-api.ts` | `post.slug` | `publishedAtIso` (`YYYY-MM-DD`) |
| Games | `getAllPublicGames(): Promise<PublicGame[]>` | `src/features/games/public-games-api.ts` | `game.slug` | none |
| Weekly | `fetchCurrentWeeklyRuns(): Promise<CurrentWeeklyRun[]>` | `src/features/weekly-runs/weekly-runs-api.ts` | `slugify(run.gameName)` | none |

- All four return safe fallbacks (`[]` / `{upcoming: [], past: []}`) on any failure and never throw
  (AC-API2) - AC4 degradation comes for free, just don't wrap them in code that can itself throw.
- None of them paginate for these calls; `getAllPublicGames` hits `GET /games?all=1`.
- They are public endpoints; unauthenticated server-side calls work (`apiFetch`'s refresh interceptor is
  a browser no-op).

### Event id vs slug (known tension, do not "fix" it here)

The route is `(public)/evenements/[eventSlug]/page.tsx` but the value used everywhere is the event
**`id`** (`PublicEvent` has no slug field; the detail page canonical is `/evenements/${event.id}`).
Epic 34's locked decision says sitemap URLs use **the route the crawler actually visits** - today that
is `/evenements/{id}`. Emit `event.id`. Story **34.2** owns the canonical-identity question; if a real
slug appears later, the sitemap follows in that story. Do not invent a slug here.

### lastModified honesty (conscious deviation from epic AC1 wording)

Epic 34.1 AC1 says "each with `lastModified` from real data". Events and games expose **no** update
timestamp; a fabricated `lastModified` (e.g. an event's start date) is worse for crawl scheduling than
none. So: `lastModified` on posts only (real `publishedAtIso`), omitted elsewhere. This deviation is
deliberate and recorded here; extending the API payloads with real `updatedAt` fields is out of scope.

### robots.txt vs meta noindex (why AC2 does not disallow everything)

A robots.txt `Disallow` blocks **crawling**, which prevents Google from ever seeing a page's
`<meta name="robots" content="noindex">`. A disallowed-but-externally-linked URL can still get indexed
(URL-only). Therefore: disallow only the zones where crawling is pure waste and no external links are
expected (`/admin/`, `/o/`, `/compte/`, auth flows, registration funnels); leave `/joueurs/*`,
`/streams/*`, `/evenements/*/resultats` crawlable so their existing meta noindex (verified in the epic
baseline audit) is honored. Route-group URL prefixes: `(admin)` = `/admin/...`, `(overlay)` = `/o/...`.

### Weekly-run slugs

`/runs-hebdo/jeu/[gameSlug]` has no server-side slug source: the client derives slugs via a local
`slugify(gameName)` in `src/features/weekly-runs/weekly-run-game-client.tsx` (not exported). Task 3
extracts it so sitemap and page share one implementation. Weekly slugs rotate with the program; the
sitemap reflects the **current** runs only, which is correct (the route renders an empty state for
stale slugs).

### House rules that apply (frontend/AGENTS.md)

- AC-ENV1: `env.appUrl` from `src/lib/env.ts` (trailing slash already stripped, validated http/https).
  Never `process.env`. ESLint enforces this repo-wide (`no-restricted-syntax`), tests exempted.
- AC-TS2/TS3: no `any`, no `as` casts; the fetchers already validate with type guards, the sitemap only
  consumes their typed results - no new boundary parsing should be needed.
- Lint is 0-warnings; `pnpm test`/`pnpm build` need `NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN` (jest.setup and
  `.env.example` provide it).

### Testing pattern (house standard - MSW, not jest.mock)

- Tests are **co-located** `*.test.ts`; the codebase has zero `jest.mock` - HTTP is faked with **MSW**:

  ```ts
  import { http, HttpResponse } from "msw";
  import { server } from "@/tests/setup";        // existing tests use relative ../../tests/setup
  import { TEST_API_BASE_URL } from "@/tests/constants";
  server.use(http.get(`${TEST_API_BASE_URL}/events`, () => HttpResponse.json({ data: [payload] })));
  ```

- `src/tests/constants.ts` must keep zero imports (ESLint-enforced). MSW server runs with
  `onUnhandledRequest: "error"` - the tests MUST register handlers for **all four** endpoints the
  sitemap calls, or the run fails on the unhandled request.
- `jest.setup.ts` sets `NEXT_PUBLIC_API_BASE_URL` to `TEST_API_BASE_URL` (`http://localhost:8080`) and
  the env module reads it - so fetchers hit the MSW handlers naturally. `env.appUrl` in tests is the
  default `http://localhost:3000`; assert sitemap URLs against that base.
- Model the payload shapes on the existing fetcher tests (e.g.
  `src/features/events/public-events-api.test.ts`) - they contain valid raw API payloads to copy.
- Run just these tests: `pnpm test sitemap` / `pnpm test robots`.

### Project Structure Notes

- New files: `src/app/sitemap.ts`, `src/app/robots.ts` (+ their co-located tests), one shared `slugify`
  module (Task 3). One edit: `weekly-run-game-client.tsx` imports the extracted `slugify`.
- No API (Symfony) change, no new env var, no config change. `pnpm gates` is the only gate suite.
- BMAD/Gitflow: `feature/epic-34-story-1-sitemap-robots` from `develop`, PR to `develop`.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` (story 34.1, locked decisions, baseline audit)
- Next 16 local docs: `node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/01-metadata/{sitemap,robots}.md`
- Fetchers: `frontend/src/features/{events/public-events-api,content/public-posts-api,games/public-games-api,weekly-runs/weekly-runs-api}.ts`
- Env: `frontend/src/lib/env.ts`; canonical convention example: `frontend/src/app/(public)/actualites/[postSlug]/page.tsx`
- Standards: `frontend/AGENTS.md` (AC-ENV1, AC-TS2/3, AC-API2/3); root `CLAUDE.md` (gates, Gitflow, no em-dashes)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- Dev-server smoke (API not running, so dynamic sections degraded to empty - proves AC4 live):
  - `GET /robots.txt` -> valid text: `User-Agent: *`, `Allow: /`, the 10 `Disallow` rules, and
    `Sitemap: http://localhost:3000/sitemap.xml`.
  - `GET /sitemap.xml` -> valid XML `<urlset>`, 14 `<url>` entries (all static routes), **0** forbidden
    URLs (`grep -cE '/admin|/compte|/connexion|/inscription'` = 0), 1 `Sitemap` line.

### Completion Notes List

- Implemented per spec, no deviations from the story's own Dev Notes decisions:
  - `lastModified` set on posts only (`publishedAtIso`), omitted on events/games (no honest timestamp).
  - Events keyed by `id` (`/evenements/{id}`); canonical slug question deferred to 34.2.
  - robots.txt disallows only crawl-worthless zones; `/joueurs`, `/streams`, `/evenements/*/resultats`
    left crawlable so their meta noindex is honored.
- Task 3: extracted `slugify` to `src/features/weekly-runs/slugify.ts`, reused in both
  `weekly-run-game-client.tsx` and `sitemap.ts` so the sitemap slug can never drift from the page slug.
- `pnpm gates` green (typecheck, lint, jest, build). 9 new tests (sitemap 6, robots 3). The pre-existing
  `react-hooks/exhaustive-deps` warning in `admin-content-dashboard.tsx` is untouched by this story
  (not one of the changed files) and `pnpm lint` exits 0.

### File List

- `frontend/src/app/sitemap.ts` (new)
- `frontend/src/app/robots.ts` (new)
- `frontend/src/app/sitemap.test.ts` (new)
- `frontend/src/app/robots.test.ts` (new)
- `frontend/src/features/weekly-runs/slugify.ts` (new)
- `frontend/src/features/weekly-runs/weekly-run-game-client.tsx` (edit: use extracted slugify)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-08 | Story created from epic 34 (first story). Context grounded in a code-level exploration: exact fetcher contracts, Next 16.2.10 metadata-route shapes, MSW test pattern, robots-vs-noindex subtlety, event id/slug tension deferred to 34.2, lastModified restricted to real dates. Status: ready-for-dev. |
| 2026-07-15 | Implemented: `sitemap.ts`, `robots.ts`, extracted shared `slugify`, co-located MSW tests. `pnpm gates` green + dev-server smoke. Status: done. |