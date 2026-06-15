# Story 28.1: Enabler — steamAppId on Game via IGDB external_games sync

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the ArchiLAN platform,
I want each catalog game enriched with its **Steam appid** (resolved from IGDB `external_games`) and exposed on the public games API,
so that a later story can match a user's Steam library against the ArchiLAN catalog by **exact appid** instead of fragile name matching.

This is the **data enabler** of Epic 28 (Steam Library Coupling). No coupling logic, no Steam Web API, no UI in this story — only the appid column, its IGDB resolution, the backfill command, and exposure on `GET /api/v1/games`.

## Acceptance Criteria

1. `GameCatalogSync` persists a **nullable `steam_app_id` (integer)**. `Game::getSteamAppId(): ?int` returns it (delegating to the catalog sync), or `null` when there is no catalog sync or no resolved appid. A reversible migration adds the column to the `game_catalog_sync` table.
2. `IgdbHttpClientInterface` gains `fetchSteamAppId(int $igdbId): ?int`. The `IgdbHttpClient` implementation calls IGDB `external_games` for that game id, filtered to the **Steam category (category = 1)**, returns the `uid` as an `int`, or `null` when IGDB returns no Steam entry. It reuses the existing cached access token and throws `IgdbSearchException` on an HTTP error status (≥ 400), consistent with `searchGames`.
3. The public `GET /api/v1/games` payload includes a `steamAppId` field (`int | null`) on every game item, sourced from `game_catalog_sync.steam_app_id` via a LEFT JOIN. Games without a catalog sync row return `steamAppId: null`.
4. A console command `app:games:backfill-steam-app-ids` resolves and stores the Steam appid for every game that **has an `igdbId` but no `steamAppId` yet**. Per-game IGDB failures are logged and skipped (the command continues); the command reports processed/updated counts and exits `0`.
5. No frontend/UI changes. All four backend quality gates are green: `phpstan` (0), `php-cs-fixer` (0), `phpunit` (all green), `app:architecture:ddd` (exit 0).

## Tasks / Subtasks

- [ ] **Domain: add `steamAppId` to `GameCatalogSync` + delegating getter on `Game`** (AC: 1)
  - [ ] In `api/src/GameSelection/Domain/GameCatalogSync.php`: add constructor property `#[ORM\Column(name: 'steam_app_id', type: 'integer', nullable: true)] private ?int $steamAppId = null` — **append at the end** of the constructor param list so existing positional callers (`new GameCatalogSync($game)`) keep working.
  - [ ] Add `public function recordSteamAppId(?int $steamAppId): void` (sets the field) and `public function getSteamAppId(): ?int`.
  - [ ] Do **NOT** add `steamAppId` to `GameCatalogSync::update()` — appid is resolved out-of-band by the backfill, not part of the admin form payload. Leave `update()` untouched.
  - [ ] In `api/src/GameSelection/Domain/Game.php`: add `public function getSteamAppId(): ?int { return $this->catalogSync?->getSteamAppId(); }` (mirror the existing `getIgdbId()` delegation) and `public function recordSteamAppId(?int $steamAppId): void { $this->catalogSync?->recordSteamAppId($steamAppId); }`.
- [ ] **Migration** (AC: 1)
  - [ ] New file `api/migrations/Version20260615######.php` (timestamp strictly after `Version20260611100004`). `up()`: `ALTER TABLE game_catalog_sync ADD COLUMN steam_app_id INT DEFAULT NULL`. `down()`: `ALTER TABLE game_catalog_sync DROP COLUMN steam_app_id`.
- [ ] **IGDB client: `fetchSteamAppId`** (AC: 2)
  - [ ] Add `public function fetchSteamAppId(int $igdbId): ?int;` to `api/src/GameSelection/Infrastructure/IgdbHttpClientInterface.php`.
  - [ ] Implement in `IgdbHttpClient`: `POST https://api.igdb.com/v4/external_games` with headers `Client-ID` + `Authorization: Bearer <token>` (reuse `getAccessToken()`), body `fields uid,category; where game = <igdbId> & category = 1; limit 1;`. On status ≥ 400 throw `IgdbSearchException`. Parse the response: take the first row's `uid` (a numeric **string**), validate it is digits, return `(int)`; return `null` if no row / non-numeric uid. Follow the existing `mixed`-narrowing style (no `(int)$mixed` casts without `is_*` checks — PHPStan level max).
  - [ ] Implement in `StubIgdbHttpClient`: add `public static array $steamAppIds = [1234 => 367520];` (keyed by igdbId; 1234 = the stub's Hollow Knight, 367520 = its real Steam appid) and `public function fetchSteamAppId(int $igdbId): ?int { if (self::$searchFails) { throw new IgdbSearchException('Stubbed search failure'); } return self::$steamAppIds[$igdbId] ?? null; }`. Add `self::$steamAppIds = [1234 => 367520];` to `reset()`.
- [ ] **Expose `steamAppId` on the public catalog** (AC: 3)
  - [ ] In `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php`: in the **items** query (not `buildBaseQuery`, to leave the COUNT path untouched), `LEFT JOIN 'game_catalog_sync' 'sync' ON sync.game_id = game.id`, add `sync.steam_app_id AS steam_app_id` to the select, and map it in each item as `'steamAppId' => is_numeric($row['steam_app_id'] ?? null) ? (int) $row['steam_app_id'] : null`.
- [ ] **Backfill service + command** (AC: 4)
  - [ ] `api/src/GameSelection/Application/BackfillSteamAppIds.php` (`final readonly`), constructor injects `GameRepositoryInterface $games`, `IgdbHttpClientInterface $igdb`, `LoggerInterface $logger`. `run(): array{processed: int, updated: int}` iterates `$games->findAllSortedByName()`; skip games where `getIgdbId() === null` or `getSteamAppId() !== null`; for each candidate `++$processed`, wrap `$this->igdb->fetchSteamAppId($igdbId)` in try/catch (`\Throwable` → `logger->warning('game.steam_app_id_backfill_failed', [...])`, continue); when a non-null appid is returned, `$game->recordSteamAppId($appId); $this->games->save($game); ++$updated;`. Mirror `BackfillGameOptionTypes` exactly for shape and logging.
  - [ ] `api/src/GameSelection/Presentation/BackfillSteamAppIdsCommand.php` `#[AsCommand(name: 'app:games:backfill-steam-app-ids', description: '...')]`, mirror `BackfillGameOptionTypesCommand` (inject the service, print counts, return `Command::SUCCESS`).
- [ ] **Admin detail payload (minor, helps later stories)** (AC: 3 spirit)
  - [ ] In `api/src/GameSelection/Application/AdminGameLibrary.php` `detailPayload()`, add `'steamAppId' => $sync?->getSteamAppId(),` next to the existing `'igdbId'` line.
- [ ] **Tests** (AC: 1–4)
  - [ ] Unit `tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php`: add `fetchSteamAppId` cases using `MockHttpClient` (token response + `external_games` response `[['uid' => '367520', 'category' => 1]]` → `367520`; empty `[]` → `null`; 500 → `IgdbSearchException`). Reuse the existing token-then-call ordering.
  - [ ] Unit `tests/Unit/GameSelection/BackfillSteamAppIdsTest.php`: mock `GameRepositoryInterface` + `IgdbHttpClientInterface`; assert only games with `igdbId` and no `steamAppId` are resolved+saved, that a per-game `IgdbSearchException` is swallowed and the loop continues, and the returned counts are correct.
  - [ ] Functional `tests/Functional/PublicGameCatalogSteamAppIdTest.php` (extends `FunctionalTestCase`): create a game via `createGame(...)`, attach a `GameCatalogSync` with a `steamAppId` (`$sync = new GameCatalogSync($game); $sync->recordSteamAppId(367520); $game->setCatalogSync($sync); persist+flush`), `GET /api/v1/games`, assert 200 then assert the item exposes `steamAppId === 367520`; create a second game with no sync and assert its `steamAppId` is `null`.

## Dev Notes

### Where things live (verified)
- `igdbId` is **not** on `Game` directly — it lives on `GameCatalogSync` (`igdb_id` column, table `game_catalog_sync`) and is surfaced via `Game::getIgdbId()` delegation. `steamAppId` MUST follow the exact same shape. [Source: api/src/GameSelection/Domain/GameCatalogSync.php:29, api/src/GameSelection/Domain/Game.php:408-411]
- `igdbId` is set **manually by an admin** through the game create/update form (`AdminGameLibrary::parseCatalogSync` reads `igdb_id`), populated from the IGDB search picker in the admin UI. There is **no** automatic IGDB sync job today. The "sync" referenced by this story is the new **backfill command**. [Source: api/src/GameSelection/Application/AdminGameLibrary.php:479-502, api/src/GameSelection/Presentation/AdminIgdbController.php]
- IGDB auth uses the Twitch OAuth token flow; `IgdbHttpClient` is wired with `TWITCH_CLIENT_ID` / `TWITCH_CLIENT_SECRET` and an injected `CacheInterface` that caches the bearer token. **No new env var in this story** (`STEAM_WEB_API_KEY` belongs to story 28.2). [Source: api/config/services.yaml:338-341, api/src/GameSelection/Infrastructure/IgdbHttpClient.php:72-97]

### IGDB external_games contract
- Endpoint: `POST https://api.igdb.com/v4/external_games`, Apicalypse body. Steam is `external_game_source = 1`; the Steam appid is the `uid` field (returned as a numeric string). Body to use: `fields uid; where game = <igdbId> & external_game_source = 1; limit 1;`. Same headers/auth as `searchGames`. **Note:** the older `category` field is deprecated (now returns `null`) — filtering on `category = 1` returns no rows. Confirmed against live IGDB during local testing (Stardew Valley igdb 17000 → Steam uid 413150). [Source: api/src/GameSelection/Infrastructure/IgdbHttpClient.php:21-45 for the request pattern to mirror]

### Reuse, don't reinvent
- **Backfill pattern** already exists — copy `BackfillGameOptionTypes` + `BackfillGameOptionTypesCommand` verbatim in structure (iterate `findAllSortedByName`, count processed/updated, `#[AsCommand]`). [Source: api/src/GameSelection/Application/BackfillGameOptionTypes.php, api/src/GameSelection/Presentation/BackfillGameOptionTypesCommand.php]
- **Stub trio pattern** already exists for IGDB — extend `StubIgdbHttpClient` (it's registered in `when@test` and made `public`), do not create a new test double. [Source: api/config/services.yaml:369-373, api/src/GameSelection/Infrastructure/StubIgdbHttpClient.php]
- The public catalog query is **DBAL only** — keep using `$this->connection->createQueryBuilder()`. The frontend `PublicGame` type already declares optional extra fields; adding `steamAppId` to the payload is additive and safe. [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php, frontend/src/features/games/public-games-api.ts]

### Architecture guardrails (api/CLAUDE.md)
- **Application → Infrastructure interface injection is allowed** here: precedent includes `TwitchStatusChecker` injecting `TwitchApiClientInterface`, `HandleDiscordAuthCallback` injecting `DiscordOAuthClientInterface`. So `BackfillSteamAppIds` injecting `IgdbHttpClientInterface` passes `app:architecture:ddd`. [Source: api/src/Streaming/Application/TwitchStatusChecker.php:8]
- Domain methods stay **pure** — `GameCatalogSync::recordSteamAppId` only mutates state; the IGDB HTTP call lives in Infrastructure and is orchestrated by the Application service. No clock/HTTP/log in domain. [AC-D3]
- PHPStan level max: never `(int) $mixed` — narrow with `is_numeric()` / `is_string()` first (the public-query mapping and the `uid` parse both need this). Yoda comparisons (`null === $x`), `declare(strict_types=1)`, `final readonly` for the Application service. [Source: api/CLAUDE.md PHPStan & CS sections]

### Scope boundaries / decisions
- **Resolution happens via the backfill command only**, not inline on admin save. Rationale: `AdminGameLibrary::create/update` are command services that must perform exactly one unit of work and must not block on an external HTTP call (AC-A4). Auto-resolution on save (likely an async Messenger job) is intentionally deferred — note it but do not build it.
- Re-resolution policy: backfill only fills games **missing** a `steamAppId` (skip ones already set). A forced re-resolve flag is out of scope.
- Games whose IGDB entry has no Steam `external_games` row (console/Nintendo titles) legitimately resolve to `null` — that is expected, not an error.

### Testing standards
- Unit tests extend `PHPUnit\Framework\TestCase`, mock interfaces (`$this->createMock(IgdbHttpClientInterface::class)`), construct domain objects directly. Test method names `test{Scenario}_{Outcome}`. [AC-T1-T5]
- Functional tests extend `FunctionalTestCase`, which builds the **full** schema from all metadata each test (no manual entity list needed) on Postgres (`archilan_test`). Assert HTTP status before body. Use the existing `createGame()` factory; it does NOT attach a `GameCatalogSync`, so create and attach the sync manually when a `steamAppId` is needed. [Source: api/tests/Functional/FunctionalTestCase.php:24-52, 148-168]
- Don't test ORM mapping or routing per project policy.

### Project Structure Notes
- New files: `Application/BackfillSteamAppIds.php`, `Presentation/BackfillSteamAppIdsCommand.php`, `migrations/Version20260615######.php`, `tests/Unit/GameSelection/BackfillSteamAppIdsTest.php`, `tests/Functional/PublicGameCatalogSteamAppIdTest.php`.
- Modified files: `Domain/GameCatalogSync.php`, `Domain/Game.php`, `Infrastructure/IgdbHttpClientInterface.php`, `Infrastructure/IgdbHttpClient.php`, `Infrastructure/StubIgdbHttpClient.php`, `Infrastructure/DbalGameCatalogQuery.php`, `Application/AdminGameLibrary.php`.
- `services.yaml`: no change required — `IgdbHttpClientInterface` is already bound, and the new Application service + console command are autowired/autoconfigured under `src/`.

### References
- Epic spec: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md] (story 28.1, "Proposed stories")
- Backend standards: [Source: api/CLAUDE.md] (DDD layers, CQRS naming, migration standards, testing standards, PHPStan/CS rules)
- Domain: [Source: api/src/GameSelection/Domain/GameCatalogSync.php], [Source: api/src/GameSelection/Domain/Game.php#getIgdbId]
- IGDB client: [Source: api/src/GameSelection/Infrastructure/IgdbHttpClient.php], [Source: api/src/GameSelection/Infrastructure/IgdbHttpClientInterface.php], [Source: api/src/GameSelection/Infrastructure/StubIgdbHttpClient.php]
- Public catalog: [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php], [Source: api/src/GameSelection/Presentation/PublicGameCatalogController.php]
- Backfill template: [Source: api/src/GameSelection/Application/BackfillGameOptionTypes.php], [Source: api/src/GameSelection/Presentation/BackfillGameOptionTypesCommand.php]
- Test patterns: [Source: api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php], [Source: api/tests/Functional/FunctionalTestCase.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed — comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-1-steam-appid-igdb-sync`.
- `steamAppId` lives on `GameCatalogSync` (delegated by `Game`), mirroring `igdbId`. Resolution is via the new `app:games:backfill-steam-app-ids` command only (not inline on admin save), per the AC-A4 scope decision.
- All four quality gates green: php-cs-fixer (0), phpstan src+tests (0), app:architecture:ddd (exit 0), phpunit (1022/1022).

### File List

**Added**
- `api/migrations/Version20260615120000.php`
- `api/src/GameSelection/Application/BackfillSteamAppIds.php`
- `api/src/GameSelection/Presentation/BackfillSteamAppIdsCommand.php`
- `api/tests/Unit/GameSelection/BackfillSteamAppIdsTest.php`
- `api/tests/Functional/PublicGameCatalogSteamAppIdTest.php`

**Modified**
- `api/src/GameSelection/Domain/GameCatalogSync.php` (steam_app_id column + recordSteamAppId/getSteamAppId)
- `api/src/GameSelection/Domain/Game.php` (getSteamAppId/recordSteamAppId delegation)
- `api/src/GameSelection/Infrastructure/IgdbHttpClientInterface.php` (fetchSteamAppId)
- `api/src/GameSelection/Infrastructure/IgdbHttpClient.php` (external_games implementation)
- `api/src/GameSelection/Infrastructure/StubIgdbHttpClient.php` (steamAppIds map + fetchSteamAppId + reset)
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php` (LEFT JOIN + steamAppId in public payload)
- `api/src/GameSelection/Application/AdminGameLibrary.php` (steamAppId in admin detail payload)
- `api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php` (fetchSteamAppId cases)
