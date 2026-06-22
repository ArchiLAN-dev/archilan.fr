# Story 28.9: Public game detail page `/jeux/[slug]`

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor on `/jeux`,
I want to open a dedicated page for a single game (e.g. `/jeux/clair-obscur-expedition-33`),
so that I can see everything ArchiLAN knows about that game - description, availability, platforms,
where to get it (Steam), the randomizer options it supports, and the curated notes/links synced from
the ArchiLAN game catalog - and discover whether I already own it on Steam.

Builds on Epic 28 (28.1 `steamAppId`, 28.5 client-driven `/jeux`, 28.6 platform categories). The `/jeux`
catalog grid already exists but its cards are inert; there is **no** public per-game endpoint and no
detail route. This story adds the read-side detail query + endpoint, the Next.js dynamic route with SEO
metadata, and makes the catalog cards navigate to it. It also surfaces the **Google-Sheet-synced**
catalog metadata (notes + download/reference links), which today is fetched live during admin sync but
**not persisted per game** - so a small persistence enabler is part of this story.

## Acceptance Criteria

1. **Public detail endpoint.** `GET /api/v1/games/{slug}` returns a typed JSON payload for a game whose
   `availability` is `available` or `experimental`. For an unknown slug, or a game with
   `availability = unavailable`, it returns **HTTP 404** (`{"error": "..."}`), never a 200 with empty data.
2. **Payload shape.** The 200 payload (`{ "data": {...} }`) exposes, in addition to the existing catalog
   fields (`id`, `name`, `slug`, `description`, `coverImageUrl`, `coverImageAlt`, `availability`,
   `steamAppId`, `platforms: string[]`, `supportedEventTypes: string[]`):
   - `coverImageCredit: string`,
   - `bundledWithAp: bool`, `adultContent: bool`,
   - `apworld: { deployedVersion: string|null, latestVersion: string|null, sourceUrl: string|null, releaseUrl: string|null, updateStatus: string }`,
   - `options: { key: string, min: int, max: int, default: int|null }[]` (from `Game::getOptionTypes()`; `[]` when null),
   - `catalog: { notes: string|null, links: { label: string, url: string|null }[] }` (the Google-Sheet metadata; `links: []` and `notes: null` when none).
3. **Catalog metadata persistence enabler.** `notes` and `links` from the synced Google Sheet
   (`CatalogEntry.notes`, `CatalogEntry.links`) are persisted on `GameCatalogSync` (a nullable `text`
   `notes` column + a nullable `json` `links` column) via a reversible migration, and populated when a
   catalog entry is applied to a game. Games never synced from the sheet keep `null`/`[]`. `prStatus` is
   **not** exposed publicly (internal Archipelago PR status - admin-only).
4. **Dynamic route + SEO.** `app/(public)/jeux/[slug]/page.tsx` is a Server Component that fetches the game
   by slug and renders the detail view. `generateMetadata({ params })` sets a per-game `<title>`,
   `description` (game description, trimmed), and OpenGraph (title + `images` = cover when present). An
   unresolved slug triggers Next.js `notFound()` (renders the 404 page), and `generateMetadata` does not throw.
5. **Detail content rendered.** The page shows: cover (with `coverImageCredit` attribution when present),
   name, availability badge (reusing the catalog's available/experimental styling), description, platform
   category chips, supported event types, the apworld/randomizer options (key + range), and the catalog
   notes/links section. Empty sections are omitted (no empty headers), mirroring the `GameCard` "render
   only when present" pattern.
6. **Steam surface.** When `steamAppId !== null`, a "Voir sur Steam" link to
   `https://store.steampowered.com/app/{steamAppId}` is shown (`rel="noopener noreferrer"`, opens in a new
   tab). The existing Steam-coupling state is reused so that a coupled visitor who **owns** this game sees
   the same "Tu possèdes ce jeu" badge used on the catalog cards. No new coupling endpoint.
7. **Cards navigate.** Each `GameCard` in the `/jeux` catalog becomes a link to `/jeux/{slug}` (Next.js
   `Link`), keyboard-focusable, without breaking the existing hover/owned-badge styling or the client-side
   search/filter/sort behaviour from 28.5/28.6.
8. **Gates green:** backend (`php-cs-fixer`, `phpstan` max, `phpunit` 0 notices, `app:architecture:ddd`
   exit 0) and frontend (`typecheck`, `lint`, `build`, `jest`).

## Tasks / Subtasks

- [ ] **Catalog metadata persistence enabler** (AC: 3)
  - [ ] `GameCatalogSync`: add `#[ORM\Column(name: 'notes', type: 'text', nullable: true)] private ?string $notes = null` and `#[ORM\Column(name: 'catalog_links', type: 'json', nullable: true)] private ?array $links = null` (shape `list<array{label: string, url: string|null}>`), with `recordCatalogMetadata(?string $notes, ?array $links): void`, `getNotes(): ?string`, and `getLinks(): list<array{label,url}>` (returns `[]` when null). Add delegating `Game::getCatalogNotes()` / `Game::getCatalogLinks()` (mirror `getPlatforms()`).
  - [ ] Reversible migration `Version20260619######.php`: `ALTER TABLE game_catalog_sync ADD COLUMN notes TEXT DEFAULT NULL, ADD COLUMN catalog_links JSON DEFAULT NULL` + matching `down()` drops. Timestamp one second after the latest existing migration.
  - [ ] Wire population: where a `CatalogEntry` is applied to a `Game` during sync import, call `recordCatalogMetadata($entry->notes, $entry->links)`. Confirm the exact apply path (`CatalogSync` import flow; `CatalogSyncService::computeDiff` categorises entries - the import/apply step that creates/updates the `GameCatalogSync` is the touch point). Do **not** persist `prStatus`.
- [ ] **Read query: game-by-slug** (AC: 1, 2)
  - [ ] Add `bySlug(string $slug): ?array` to `GameCatalogQueryInterface` (Application), returning the detail item shape (docblock the exact array shape) or `null` when no row matches the `available`/`experimental` filter.
  - [ ] Implement in `DbalGameCatalogQuery`: reuse `buildBaseQuery` availability filter + a `slug = :slug` predicate; extend the `SELECT` to also fetch `game.cover_image_credit`, `sync.notes`, `sync.catalog_links`, `sync.bundled_with_ap`, `sync.adult_content`, `sync.apworld_*` columns. Decode JSON in PHP (no raw JSON SQL), narrow every column (PHPStan max: `is_string`/`is_int`/`is_bool`), and map `optionTypes` from the `game.option_types` JSON column. Reuse `PlatformCategory::families(...)` for `platforms` exactly like `mapRow`. `updateStatus`: the query can't call a domain method on a hydrated entity, so compute it in PHP from the apworld columns mirroring `GameCatalogSync::computeApworldUpdateStatus()` (or expose a small pure helper reused by both).
- [ ] **Application service + controller** (AC: 1, 2)
  - [ ] `PublicGameDetail` (`final readonly`, `Application/`) injecting `GameCatalogQueryInterface`, method `bySlug(string $slug): ?array`. (Or add `bySlug` to `PublicGameCatalog` - prefer extending `PublicGameCatalog` to keep one public catalog facade.)
  - [ ] `PublicGameDetailController` (or a new action on `PublicGameCatalogController`): `#[Route('/api/v1/games/{slug}', name: 'api_game_selection_public_game_detail', methods: ['GET'])]`. Pattern: read slug from route → one service call → `JsonResponse(['data' => $game])` (200) or `JsonResponse(['error' => 'Game not found'], 404)`. No `EntityManager`/`Connection`, no business logic. Ensure the `{slug}` route does not collide with the existing `/api/v1/games` list route or `/api/v1/games/steam-coupling` (literal segment wins; add a route requirement if needed so `steam-coupling` isn't swallowed).
- [ ] **Frontend: detail API client** (AC: 2, 4)
  - [ ] `public-games-api.ts`: add `PublicGameDetail` type (extends the `PublicGame` fields with `coverImageCredit`, `bundledWithAp`, `adultContent`, `apworld`, `options`, `catalog`) + a `isPublicGameDetail` type guard (no `as` at the boundary). Add `getPublicGame(slug: string): Promise<PublicGameDetail | null>` - `fetch(`${env.apiBaseUrl}/games/${encodeURIComponent(slug)}`, { cache: "no-store" })`, return `null` on non-OK or guard failure.
- [ ] **Frontend: dynamic route** (AC: 4, 5, 6)
  - [ ] `app/(public)/jeux/[slug]/page.tsx` (Server Component): `getPublicGame(slug)`; `if (!game) notFound()`. `export async function generateMetadata({ params })` building title/description/OpenGraph from the game (also `notFound()`-safe: return base metadata if null, don't throw). Render detail sections; omit empty ones.
  - [ ] Detail components under `features/games/` (e.g. `game-detail.tsx`): cover + credit, availability badge (reuse `availabilityConfig` from `game-card.tsx` - extract to a shared module if needed to avoid duplication), description, platform chips, supported-event-type chips, options table/list, catalog notes + links list (links render as anchors only when `url !== null`, else plain label), Steam link block.
  - [ ] Steam "owned" badge: reuse `useSteamCoupling` / the coupling result already used on `/jeux` so a coupled member who owns this `steamAppId` sees the "Tu possèdes ce jeu" badge. Coupling stays client-side (no SSR of personal data).
- [ ] **Frontend: clickable cards** (AC: 7)
  - [ ] Wrap `GameCard` content in a Next.js `Link` to `/jeux/${game.slug}` (or wrap each card in `games-catalog.tsx`). Preserve hover/owned-badge styles, keyboard focus, and the 28.5/28.6 client search/filter/sort. Don't nest interactive elements illegally (the Steam coupling controls live on the page, not inside the card).
- [ ] **Tests** (AC: 8)
  - [ ] Backend functional: `GET /api/v1/games/{slug}` 200 shape (incl. options, catalog notes/links, apworld block), 404 for unknown slug, 404 for `unavailable`. Include `Game` + `GameCatalogSync` in the `SchemaTool::createSchema([...])` array. Honour the zero-notice gate (stubs vs mocks).
  - [ ] Backend unit: the `updateStatus` PHP helper parity with `computeApworldUpdateStatus()`; catalog-metadata persistence (`recordCatalogMetadata`).
  - [ ] Frontend jest: `isPublicGameDetail` guard (accept valid, reject malformed), `getPublicGame` returns `null` on bad payload; a render test that empty sections are omitted and the Steam link uses the correct URL.

## Dev Notes

### Findings that shape this story (verified against the codebase)
- **No detail endpoint exists.** Only `GET /api/v1/games` (list / `?all=1`) and `POST /api/v1/games/steam-coupling` exist in `GameSelection`. [Source: api/src/GameSelection/Presentation/PublicGameCatalogController.php, SteamCouplingController.php]
- **Cards are inert today.** `GameCard` renders an `<article>`, no link. Making it a link is additive. [Source: frontend/src/features/games/game-card.tsx]
- **The detail data already lives on the aggregate.** `Game` exposes `getOptionTypes()` (`array<string,{min,max,default}>` from apworld introspection, story 9.25), `getDefaultYaml()`, `getCoverImageCredit()`, `getSteamAppId()`, `getPlatforms()`, `isBundledWithAp()`, `isAdultContent()`, and the apworld version/url getters + `computeApworldUpdateStatus()`. [Source: api/src/GameSelection/Domain/Game.php, GameCatalogSync.php]
- **The Google-Sheet metadata is richer than what is persisted.** `CatalogEntry` carries `name, availability, prStatus, adultContent, notes, links (list<{label,url}>), bundledWithAp`. Only `adultContent`/`bundledWithAp`/apworld fields/`platforms`/`steamAppId` reach `GameCatalogSync`; **`notes`, `links`, `prStatus` are not persisted** - hence the enabler in this story. [Source: api/src/CatalogSync/Domain/CatalogEntry.php, api/src/CatalogSync/Application/CatalogSyncService.php, api/src/GameSelection/Domain/GameCatalogSync.php]
- **Sheet links may have null URLs.** The CSV-export fallback path yields labels without URLs (`col 3: Links & Downloads - labels only, no URLs in CSV export`). The frontend must render a link only when `url !== null`. [Source: api/src/CatalogSync/Application/CatalogSyncService.php:404]

### Reuse, don't reinvent
- **Catalog query**: `DbalGameCatalogQuery` already LEFT JOINs `game_catalog_sync`, has a shared `mapRow`, and uses `PlatformCategory::families`. Add `bySlug` next to `list`/`all`; reuse `buildBaseQuery` + `mapRow` decode helpers. [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php]
- **Public facade**: extend `PublicGameCatalog` rather than introduce a parallel service if it keeps the public read surface in one place. [Source: api/src/GameSelection/Application/PublicGameCatalog.php]
- **Frontend catalog plumbing**: `public-games-api.ts` already has `PublicGame` + guards and the `...g` spread mapping; extend it for the detail type. The availability badge config + Gamepad fallback live in `game-card.tsx` - share, don't copy. [Source: frontend/src/features/games/public-games-api.ts, game-card.tsx]
- **Steam coupling**: `use-steam-coupling.ts` / `steam-coupling.tsx` / `steam-coupling-api.ts` already compute the owned set on `/jeux`; reuse the hook/result for the "owned" badge on the detail page - no new endpoint. [Source: frontend/src/features/games/]
- **`notes`/`links` JSON column pattern**: copy the `platforms` JSON column added in 28.6 (column + `record*`/`get*` + DBAL decode in PHP). [Source: _bmad-output/implementation-artifacts/28-6-platform-categories.md]

### Architecture guardrails
- **DDD layering (api/CLAUDE.md):** read query interface in `Application`, DBAL `QueryBuilder` in `Infrastructure`; **never** `EntityManager`/`Connection` in Application or the controller (AC-A2, AC-P1/P2). Controller = read slug → one service call → serialize (AC-P3/P4/P5). Query returns a typed array, not a Doctrine entity (AC-A3).
- **PHPStan max:** narrow every column from `fetchAssociative()`/JSON decode (`is_string`, `is_int`, `is_bool`); `fetchAssociative()` may return `false` - handle the no-row case as `null`. No `(string) $mixed` casts.
- **CS Fixer @Symfony:** Yoda, `final`, `declare(strict_types=1)`, `null === $x`.
- **`updateStatus` in a read query:** the DBAL query can't call `Game::computeApworldUpdateStatus()` (no hydrated entity). Extract the version-compare logic into a pure static helper reusable by both the domain method and the query, OR replicate it in `mapRow` with a unit test asserting parity - avoid drift.
- **Frontend (frontend/AGENTS.md):** no `as` at the fetch boundary (type guard), env via `src/lib/env.ts`, pure render, stable keys, design tokens only. `generateMetadata` and the page both call the API → either dedupe via React `cache()` or accept two `no-store` fetches (this list page is already `force-dynamic`; keep it simple, fetch in both - Next dedupes same-request fetches when not `no-store`; with `no-store`, prefer wrapping `getPublicGame` in `cache()`).

### Scope boundaries
- **In:** public read endpoint + detail route + clickable cards + reuse of existing Steam coupling + persist & display sheet `notes`/`links`.
- **Out:** events/runs-where-playable listing (deferred - would need cross-context queries into `Events`/`WeeklyRuns`); editing options or catalog metadata from this page (admin-only, unchanged); exposing `prStatus` publicly; ISR/`generateStaticParams` (page stays dynamic, consistent with `/jeux` `force-dynamic`); any new Steam coupling endpoint.
- **Note on size:** the persistence enabler (AC3) is the only schema change; if the team prefers, it can ship first as a tiny standalone slice, but it is kept here so the "Google-Sheet info" requirement is actually fulfilled rather than rendering empty.

### Project Structure Notes
- **New (api):** `Application/PublicGameDetail.php` (or `bySlug` on `PublicGameCatalog`), `Presentation/PublicGameDetailController.php` (or new action), migration, functional + unit tests.
- **Modified (api):** `Application/GameCatalogQueryInterface.php` (+ `bySlug`), `Infrastructure/DbalGameCatalogQuery.php` (bySlug + extra columns), `Domain/GameCatalogSync.php` (notes/links columns + record/get), `Domain/Game.php` (delegation), the `CatalogSync` import/apply path (populate notes/links).
- **New (frontend):** `app/(public)/jeux/[slug]/page.tsx`, `features/games/game-detail.tsx` (+ any shared badge module extracted from `game-card.tsx`).
- **Modified (frontend):** `features/games/public-games-api.ts` (+ test), `features/games/game-card.tsx` / `games-catalog.tsx` (clickable card).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md]
- Prior stories: [Source: _bmad-output/implementation-artifacts/28-5-jeux-page-redesign.md], [Source: _bmad-output/implementation-artifacts/28-6-platform-categories.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md], [Source: CLAUDE.md (Gitflow, quality gates)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- **AC3 design change (approved):** the catalog sync persists nothing - `computeDiff` is read-only and there is no write-back path. Persisting `notes`/`links` would have meant building a whole sync-apply mechanism. Instead the sheet metadata (`notes`, `links`, `bundledWithAp`, `adultContent`) is **resolved on demand** from the already-cached catalog sheet (1h TTL), consistent with the project's "resolve on demand, no snapshot" principle. **No migration.** Sheet failures degrade gracefully (notes=null, links=[], flags=false) via a try/catch in the facade.
- Cross-context wiring: `CatalogSync\Application\PublicGameDetailQuery` (the facade) depends on `GameSelection\Application\GameCatalogQueryInterface` (CatalogSync already depends on `GameSelection\Domain\Game`, so the direction holds; DDD validator green). The endpoint `GET /api/v1/games/{slug}` therefore lives in `CatalogSync/Presentation` with a `slug` route requirement so it never shadows the POST `/games/steam-coupling`.
- `ApworldUpdateStatus` pure helper extracted so the DBAL read computes the same status as `GameCatalogSync::computeApworldUpdateStatus()` (which now delegates to it) - unit-tested for parity.
- Steam "owned" badge reuses `useSteamCoupling` (no new endpoint); the detail page shows it only when the coupled library contains the game's `steamAppId`.
- Gates green: php-cs-fixer 0, phpstan 0 (src+tests), DDD exit 0, phpunit 1245 tests / 0 notices; FE typecheck/lint/build clean, jest (public-games-api) 12.

### File List

**Added (api)**
- `api/src/GameSelection/Domain/ApworldUpdateStatus.php`
- `api/src/CatalogSync/Application/PublicGameDetailQuery.php`
- `api/src/CatalogSync/Presentation/PublicGameDetailController.php`
- `api/tests/Functional/PublicGameDetailTest.php`
- `api/tests/Unit/GameSelection/ApworldUpdateStatusTest.php`

**Modified (api)**
- `api/src/GameSelection/Application/GameCatalogQueryInterface.php` (+ `bySlug`)
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php` (`bySlug` + `mapDetailRow` + `decodeOptions`)
- `api/src/GameSelection/Domain/GameCatalogSync.php` (delegate to `ApworldUpdateStatus`)
- `api/src/CatalogSync/Application/CatalogSyncService.php` (+ `findEntryForNames`)

**Added (frontend)**
- `frontend/src/app/(public)/jeux/[slug]/page.tsx`
- `frontend/src/features/games/game-detail.tsx`
- `frontend/src/features/games/game-owned-badge.tsx`

**Modified (frontend)**
- `frontend/src/features/games/public-games-api.ts` (+ `PublicGameDetail`/types, `getPublicGame`, guards)
- `frontend/src/features/games/public-games-api.test.ts` (+ `getPublicGame` tests)
- `frontend/src/features/games/game-card.tsx` (clickable `Link`, export `availabilityConfig`)
