# Story 28.6: Platform categories on the Jeux catalog (curated families from IGDB + Steam facet)

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor on `/jeux`,
I want to filter the catalog by platform category (Super Nintendo, GameCube, N64, PC, Switch, PlayStation, Xbox…) plus a Steam facet,
so that I can quickly find the games for the systems I care about.

Builds on Epic 28 (28.1 IGDB backfill pattern, 28.5 client-driven catalog). Adds platform data from IGDB, mapped to a **curated, readable set of families** (IGDB lists ~150 platforms incl. re-release variants — too noisy raw), exposed on the catalog API and surfaced as multi-select category chips on `/jeux`. "Steam" is a separate **store** facet derived from the existing `steamAppId`, shown alongside platform categories.

## Acceptance Criteria

1. `GameCatalogSync` persists the raw IGDB platforms of a game (their IGDB platform **ids** + names, JSON, nullable). A reversible migration adds the column.
2. `IgdbHttpClientInterface` gains `fetchPlatforms(int $igdbId): list<array{id: int, name: string}>` (via the `games` endpoint, `fields platforms.name;`). Implemented in `IgdbHttpClient` (reuses the cached token, throws `IgdbSearchException` on HTTP error) and in `StubIgdbHttpClient`.
3. A pure domain mapper turns raw IGDB platforms into a **curated family list** (deduped): variants collapse (e.g. `SNES`/`SFAM` → "Super Nintendo", `New 3DS`/`3DS` → "Nintendo 3DS", `PS4`/`PS5`/`Vita` → "PlayStation", `Win`/`Mac`/`Linux` → "PC", `iOS`/`Android` → "Mobile"). Unmapped IGDB platforms fall back to their IGDB name so nothing is silently dropped.
4. The public `GET /api/v1/games` (both paginated and `?all=1`) exposes `platforms: string[]` per game = the curated families (sorted, deduped). Games without resolved platforms return `[]`.
5. A console command `app:games:backfill-platforms` resolves and stores platforms for every game with an `igdbId` but no stored platforms yet (per-game failures logged & skipped), mirroring `app:games:backfill-steam-app-ids`.
6. On `/jeux`, multi-select **category chips** are built from the union of all games' families **plus a "Steam" chip** (games with `steamAppId !== null`). Selecting categories filters the grid (a game matches if its category set intersects the selection — OR within the facet). Works together with search, availability filter, owned toggle, and sort.
7. Gates green: backend (php-cs-fixer, phpstan, phpunit, app:architecture:ddd) and frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Domain: platforms on `GameCatalogSync` + family mapper** (AC: 1, 3)
  - [ ] Add `#[ORM\Column(name: 'platforms', type: 'json', nullable: true)] private ?array $platforms = null` to `GameCatalogSync` (raw IGDB `[{id,name}]`), with `recordPlatforms(?array): void` and `getPlatforms(): ?array`. Delegating `Game::getPlatforms()` / `Game::recordPlatforms()` (mirror `steamAppId`).
  - [ ] `api/src/GameSelection/Domain/PlatformCategory.php` (`final`, pure): `public static function families(array $igdbPlatforms): list<string>` — maps each `{id,name}` to a curated family via an internal `id => family` table; dedupe + sort; fall back to the IGDB `name` for unmapped ids. Unit-tested with the noisy real cases (Super Metroid → ["Super Nintendo"]; Stardew → PC/PlayStation/Xbox/Switch/Mobile…).
- [ ] **Migration** (AC: 1)
  - [ ] `Version20260615######.php`: `ALTER TABLE game_catalog_sync ADD COLUMN platforms JSON DEFAULT NULL` / drop.
- [ ] **IGDB client: fetchPlatforms** (AC: 2)
  - [ ] Interface method + `IgdbHttpClient` impl: `POST .../v4/games`, body `fields platforms.name; where id = <igdbId>; limit 1;`, parse `platforms` to `list<{id,name}>` (narrow all `mixed`). `StubIgdbHttpClient`: static `$platforms` map keyed by igdbId + `fetchPlatforms` + `reset()`.
- [ ] **Expose families on the catalog** (AC: 4)
  - [ ] In `DbalGameCatalogQuery::mapRow`, read `sync.platforms` (LEFT JOIN already present), decode JSON, run `PlatformCategory::families(...)`, add `'platforms' => [...]` to each item. Update the item shape docblock. (DBAL can't call domain — decode in PHP within `mapRow`, then call the pure mapper.)
- [ ] **Backfill** (AC: 5)
  - [ ] `Application/BackfillGamePlatforms.php` + `Presentation/BackfillGamePlatformsCommand.php` (`app:games:backfill-platforms`), mirror `BackfillSteamAppIds`: iterate `findAllSortedByName()`, skip games with no `igdbId` or already-set platforms, `fetchPlatforms`, `recordPlatforms`, save; per-game try/catch + warning log.
- [ ] **Frontend** (AC: 6)
  - [ ] `public-games-api.ts`: add `platforms: string[]` to `PublicGame` + guard (`every` string); include in `getPublicGames` / `getAllPublicGames` mapping (already spreads `...g`).
  - [ ] `games-filter.ts`: extend `CatalogFilters` with `categories: string[]` (selected). A game's category set = `game.platforms` ∪ (`steamAppId !== null` ? ["Steam"] : []). Match = selection empty OR intersection non-empty. Add a helper `allCategories(games): string[]` (union, "Steam" appended if any owned-able game) for the chip list. Extend the unit test.
  - [ ] `games-catalog.tsx`: render category chips (multi-select, toggle) from `allCategories(initialGames)`; wire into the `filterAndSortGames` criteria; keep search/availability/owned/sort. Mobile-first wrap; chips use design tokens.
- [ ] **Tests** (AC: 7)
  - [ ] Unit: `PlatformCategoryTest` (mapping/dedupe/fallback), `BackfillGamePlatformsTest`, IGDB `fetchPlatforms` cases. Functional: catalog payload includes `platforms`. Jest: `games-filter` category matching + `allCategories`; `getAll/getPublicGames` guard still passes with the new field (update fixtures).

## Dev Notes

### Findings that shape this story (verified live against IGDB)
- IGDB `platforms` lists **every** platform incl. re-releases → noisy: `Super Metroid` → SNES, Wii, WiiU, New 3DS, SFAM; `Ocarina` → Wii, N64, 64DD, WiiU; `Stardew` → 11 platforms. Hence **curated families**, not raw. Map by IGDB platform **id** (stable) — names are for readability/fallback. [Probed during story grooming.]
- **Steam is not an IGDB platform** — it's a store (`external_game_source`, already captured as `steamAppId` in 28.1). Keep it a separate derived facet; do not put it in the IGDB platforms data. The frontend merges it into the chip list per AC6.
- IGDB does **not** know which platform the Archipelago apworld targets — families reflect "released on", so a re-released game legitimately carries several categories (accepted: model = curated families, not single primary).

### Reuse, don't reinvent
- Backfill trio: copy `BackfillSteamAppIds` + its command. [Source: api/src/GameSelection/Application/BackfillSteamAppIds.php, api/src/GameSelection/Presentation/BackfillSteamAppIdsCommand.php]
- IGDB client/stub pattern + token reuse. [Source: api/src/GameSelection/Infrastructure/IgdbHttpClient.php (fetchSteamAppId), StubIgdbHttpClient]
- Catalog query already LEFT JOINs `game_catalog_sync` and has a shared `mapRow` (28.5) — add `platforms` there. [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php]
- Frontend catalog already client-driven with a pure `filterAndSortGames` + `GamesCatalog` controls (28.5) — extend, don't rebuild. [Source: frontend/src/features/games/games-filter.ts, games-catalog.tsx]

### Architecture guardrails
- `PlatformCategory` is pure Domain (no I/O) — the family table is a `const` map. DBAL stays DBAL: decode JSON + call the pure mapper inside `mapRow` (Infrastructure may use Domain). Application backfill may inject `IgdbHttpClientInterface` (precedent). PHPStan max: narrow all decoded JSON (`is_array`, `is_int`, `is_string`). Yoda, `final`, `declare(strict_types=1)`.
- Frontend: no `as` at boundary (guard the `platforms` array), tokens-only chips, stable keys, no impure render.

### Curated family mapping (starting set — editable)
Nintendo: NES, Super Nintendo (SNES/SFAM), Nintendo 64 (N64/64DD), GameCube, Wii, Wii U, Switch (Switch/Switch 2), Game Boy (GB/GBC), Game Boy Advance, Nintendo DS, Nintendo 3DS (3DS/New 3DS). Other: PC (Win/Mac/Linux), PlayStation (PS1–PS5/PSP/Vita), Xbox (Xbox/360/One/Series), Sega (Genesis/Saturn/Dreamcast/etc.), Mobile (iOS/Android), Arcade. Unmapped IGDB id → use its IGDB name verbatim (so new platforms still appear). Keep the table in `PlatformCategory` for easy edits.

### Scope boundaries
- One backfill pass per metadata kind (platforms separate from steam appid — different IGDB endpoints).
- No admin UI to edit platforms/categories (backfill-driven, like steamAppId). No server-side category filtering (client-side, consistent with 28.5).

### Project Structure Notes
- New (api): `Domain/PlatformCategory.php`, `Application/BackfillGamePlatforms.php`, `Presentation/BackfillGamePlatformsCommand.php`, migration, unit + functional tests.
- Modified (api): `Domain/GameCatalogSync.php`, `Domain/Game.php`, `Infrastructure/IgdbHttpClient(Interface).php`, `StubIgdbHttpClient.php`, `Infrastructure/DbalGameCatalogQuery.php`.
- New/Modified (frontend): `games-filter.ts` (+ test), `games-catalog.tsx`, `public-games-api.ts` (+ test fixtures).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md]
- Prior stories: [Source: _bmad-output/implementation-artifacts/28-1-steam-appid-igdb-sync.md], [Source: _bmad-output/implementation-artifacts/28-5-jeux-page-redesign.md]
- IGDB platforms: `POST https://api.igdb.com/v4/games` `fields platforms.name; where id = <igdbId>;`
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed — comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-6-platform-categories` (stacked on 28.5).
- `PlatformCategory` maps by **name keyword rules** (ordered, first-match) rather than IGDB platform ids — robust to id uncertainty and collapses variants (Super Famicom→Super Nintendo, 64DD→Nintendo 64, New 3DS→Nintendo 3DS); unmapped names fall back verbatim.
- DBAL `mapRow` decodes the `platforms` JSON and calls the pure mapper to expose curated `platforms: string[]`. "Steam" is a frontend-derived facet (from `steamAppId`), not stored in the platforms data.
- Frontend: `categoriesOf`/`allCategories` helpers + multi-select chips (OR within the facet), wired into the existing client catalog (28.5).
- Gates green: php-cs-fixer 0, phpstan 0, ddd exit 0, phpunit 1064; FE typecheck/lint/build, jest 85.

### File List

**Added (api)**
- `api/src/GameSelection/Domain/PlatformCategory.php`
- `api/src/GameSelection/Application/BackfillGamePlatforms.php`
- `api/src/GameSelection/Presentation/BackfillGamePlatformsCommand.php`
- `api/migrations/Version20260615120002.php`
- `api/tests/Unit/GameSelection/PlatformCategoryTest.php`
- `api/tests/Unit/GameSelection/BackfillGamePlatformsTest.php`
- `api/tests/Functional/PublicGameCatalogPlatformsTest.php`

**Modified (api)**
- `api/src/GameSelection/Domain/GameCatalogSync.php` (platforms column + get/record)
- `api/src/GameSelection/Domain/Game.php` (platforms delegation)
- `api/src/GameSelection/Infrastructure/IgdbHttpClientInterface.php` + `IgdbHttpClient.php` (fetchPlatforms)
- `api/src/GameSelection/Infrastructure/StubIgdbHttpClient.php` (platforms stub)
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php` (expose curated platforms)
- `api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php`

**Added (frontend)**
- (helpers/tests within existing files)

**Modified (frontend)**
- `frontend/src/features/games/public-games-api.ts` (+ test) — PublicGame.platforms + guard
- `frontend/src/features/games/games-filter.ts` (+ test) — categories, categoriesOf, allCategories
- `frontend/src/features/games/games-catalog.tsx` — category chips
