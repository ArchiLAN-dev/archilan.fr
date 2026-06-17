# Story 28.2: Steam Web API integration + coupling endpoint

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor,
I want to submit my Steam account and get back the list of games I own that are playable at ArchiLAN events,
so that I can see at a glance what I can bring to a LAN.

This is the **coupling engine** of Epic 28. It depends on story **28.1** (`steamAppId` on the catalog). It adds the Steam Web API client, the input parsing, the read-side coupling query (intersection), and the public endpoint. No persistence (28.3) and no UI (28.4) here.

## Acceptance Criteria

1. A raw Steam input is parsed (pure, no I/O) into either a **SteamID64** (17-digit, starts with `7656`) or a **vanity name**, accepting: a bare SteamID64, a bare vanity name, `https://steamcommunity.com/profiles/<id64>`, and `https://steamcommunity.com/id/<vanity>` (with or without trailing slash). Unparseable input is reported as invalid.
2. `SteamWebApiClientInterface` (Infrastructure) exposes `resolveVanityUrl(string $vanity): ?string` (→ SteamID64 or null) and `fetchOwnedAppIds(string $steamId64): array{visibility: 'public'|'private', appIds: list<int>}`. The concrete client calls Steam Web API `ISteamUser/ResolveVanityURL` and `IPlayerService/GetOwnedGames`, authenticated with `STEAM_WEB_API_KEY`. A private/empty profile response maps to `visibility: 'private'` with `appIds: []`. Network/HTTP errors are caught and surfaced as a distinct error outcome (never thrown to the caller).
3. `SteamLibraryCouplingQuery` (Application, read-side, returns a typed array, no DB transaction) resolves the input to a SteamID64, fetches owned appids, intersects them with the catalog games that have a `steamAppId` (available/experimental only), and returns: `outcome` (`ok` | `invalid_input` | `private_profile` | `steam_error`), the matched games (`id, name, slug, coverImageUrl, availability, steamAppId`), `ownedCount`, and `matchedCount`.
4. Public endpoint `POST /api/v1/games/steam-coupling` (no auth) accepts `{ "steamProfile": "<raw>" }`, returns `200` with `{ data: { matchedGames, ownedCount, matchedCount } }` on `ok`; `200` with `{ data: { matchedGames: [], ownedCount: 0, matchedCount: 0 }, meta: { outcome: "private_profile" } }` for a private profile; `422` (`steam_invalid_input`) for unparseable input; `502` (`steam_unavailable`) for a Steam API error. Uses `ApiAccessGuard::errorResponse` for error shapes.
5. `STEAM_WEB_API_KEY` is added to `.env` and `.env.example` and bound to the Steam client in `services.yaml`. A `StubSteamWebApiClient` is registered in `when@test` (public), with configurable owned appids and vanity mapping.
6. All four backend quality gates green. No frontend changes.

## Tasks / Subtasks

- [ ] **Domain: pure input parser** (AC: 1)
  - [ ] `api/src/GameSelection/Domain/SteamProfileReference.php` - a `final readonly` value object or static parser `SteamProfileReference::parse(string $raw): ?self` returning `{ kind: 'steamid64'|'vanity', value: string }`. Pure (no HTTP). Validate SteamID64 as `^7656\d{13}$`; extract vanity/id64 from the two URL forms; trim and lowercase the host comparison. Return `null` for empty/invalid.
  - [ ] Unit-test every input form (AC-T1).
- [ ] **Infrastructure: Steam Web API client** (AC: 2, 5)
  - [ ] `api/src/GameSelection/Infrastructure/SteamWebApiClientInterface.php` with the two methods from AC2 (interface in Infrastructure, mirroring `IgdbHttpClientInterface` / `TwitchApiClientInterface`).
  - [ ] `api/src/GameSelection/Infrastructure/SteamWebApiClient.php` (`final`), constructor `HttpClientInterface $httpClient`, `string $apiKey`. Mirror `TwitchApiClient`'s graceful style: if `'' === $apiKey` return null / empty; wrap calls in try/catch.
    - `resolveVanityUrl`: `GET https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/` query `key`, `vanityurl`. Response `response.success === 1` → `response.steamid` (string); else null.
    - `fetchOwnedAppIds`: `GET https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/` query `key`, `steamid`, `include_appinfo=0`, `format=json`. If `response.games` present → map each `appid` to int → `visibility: 'public'`. If `response` is empty / no `games` key → `visibility: 'private', appIds: []`. Narrow all `mixed` (PHPStan max): no `(int) $mixed` without `is_numeric`.
  - [ ] `api/src/GameSelection/Infrastructure/StubSteamWebApiClient.php` - static `$vanityMap` (vanity → id64), `$ownedAppIds` (list<int>), `$visibility`, `$fails` toggles, `reset()`. Mirror `StubIgdbHttpClient` shape.
  - [ ] `services.yaml`: bind `SteamWebApiClientInterface: '@...SteamWebApiClient'`; configure `SteamWebApiClient` arg `$apiKey: '%env(STEAM_WEB_API_KEY)%'`; in `when@test` map the interface to `StubSteamWebApiClient` and make the stub `public: true` (mirror the IGDB block at services.yaml:338-373). Add `STEAM_WEB_API_KEY=` to `.env` and `.env.example` next to the `TWITCH_*` keys.
- [ ] **Application: catalog query for coupling** (AC: 3)
  - [ ] `api/src/GameSelection/Application/SteamCatalogQueryInterface.php` → `allWithSteamAppId(): list<array{id,name,slug,coverImageUrl,availability,steamAppId:int}>` (only available/experimental games that have a non-null `steam_app_id`).
  - [ ] `api/src/GameSelection/Infrastructure/DbalSteamCatalogQuery.php` (DBAL, LEFT/INNER JOIN `game_catalog_sync` on `game_id`, `WHERE steam_app_id IS NOT NULL AND availability IN (...)`). Bind in `services.yaml`. Mirror `DbalGameCatalogQuery`.
- [ ] **Application: coupling query** (AC: 3)
  - [ ] `api/src/GameSelection/Application/SteamLibraryCouplingQuery.php` (`final readonly`), inject `SteamWebApiClientInterface`, `SteamCatalogQueryInterface`, `LoggerInterface`. `couple(string $rawInput): array{outcome, matchedGames, ownedCount, matchedCount}`:
    1. `SteamProfileReference::parse` → null ⇒ `invalid_input`.
    2. If vanity ⇒ `resolveVanityUrl` ⇒ null ⇒ `invalid_input` (vanity not found).
    3. `fetchOwnedAppIds`; on caught error ⇒ `steam_error`; `private` ⇒ `private_profile` (empty match).
    4. Build a `appId => game` map from `allWithSteamAppId()`, intersect with owned appIds, return matched games sorted by name, `ownedCount = count(appIds)`, `matchedCount = count(matched)`.
- [ ] **Presentation: public endpoint** (AC: 4)
  - [ ] `api/src/GameSelection/Presentation/SteamCouplingController.php` (`final readonly`), inject `ApiAccessGuard`, `SteamLibraryCouplingQuery`. `#[Route('/api/v1/games/steam-coupling', methods: ['POST'])]`. Read `steamProfile` from the JSON body (string, trimmed); empty ⇒ `422 steam_invalid_input`. Call the query; map `outcome` → the responses in AC4. No business logic in the controller (AC-P3); one Application call (AC-P4).
- [ ] **Tests** (AC: 1–4)
  - [ ] Unit `SteamProfileReferenceTest` (all input forms + invalid).
  - [ ] Unit `SteamWebApiClientTest` (MockHttpClient: vanity resolve success/fail; owned games public maps appids; private/empty ⇒ `private`; HTTP 500 ⇒ caught).
  - [ ] Unit `SteamLibraryCouplingQueryTest` (mock client + catalog query; assert intersection, counts, and each outcome branch).
  - [ ] Functional `SteamCouplingEndpointTest` (extends `FunctionalTestCase`): seed catalog games with `steamAppId` (attach `GameCatalogSync` + `recordSteamAppId`), configure `StubSteamWebApiClient` via the public test service, POST and assert 200 body for `ok`, 200+`private_profile`, 422 for empty input. Assert status before body (AC-T10).

## Dev Notes

### Dependencies
- **Requires story 28.1** merged: `Game::getSteamAppId()` / `GameCatalogSync.steam_app_id` and the `StubIgdbHttpClient` patterns. Do not start until 28.1's column + getter exist.

### Steam Web API contract (stable public endpoints)
- Vanity: `GET https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/?key=<KEY>&vanityurl=<name>` → `{ response: { steamid, success } }`, `success === 1` means resolved.
- Owned games: `GET https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?key=<KEY>&steamid=<id64>&include_appinfo=0&format=json` → `{ response: { game_count, games: [{ appid }, ...] } }`. **A private "Game details" profile returns `{ response: {} }`** (no `games`) - there is no API path around this (the key is ours, not OAuth scoped). Treat missing `games` as `private`.
- `include_appinfo=0` keeps the payload small (we only need appids for matching).

### Architecture guardrails (api/CLAUDE.md)
- External client interface lives in **Infrastructure** (precedent: `IgdbHttpClientInterface`, `TwitchApiClientInterface`, `DiscordOAuthClientInterface`). The epic text said "Application" - follow the **codebase convention (Infrastructure)** instead.
- `SteamLibraryCouplingQuery` is a **read** (CQRS Query): returns a typed array, no transaction, no `flush`. Application may inject the Infrastructure client interface (precedent: `TwitchStatusChecker`). [api/CLAUDE.md CQRS + AC-A2/A5]
- Controller: deserialize → validate → one Application call → `JsonResponse`; no SQL, no `EntityManager`/`Connection`. Use `ApiAccessGuard::errorResponse(code, message, status)` (see `AdminIgdbController:37,46,48`). [AC-P1..P5]
- PHPStan level max: narrow all `mixed` from `$response->toArray()` (`is_array`, `is_numeric`, `is_string`) before use - see how `IgdbHttpClient` does it. Yoda comparisons, `declare(strict_types=1)`, `final`/`final readonly`.

### Reuse, don't reinvent
- HTTP client shape + token/empty-key graceful degradation: copy `TwitchApiClient` (no token needed for Steam - it uses a static key, simpler). [api/src/Streaming/Infrastructure/TwitchApiClient.php]
- Stub trio + `when@test` registration: copy the IGDB block. [api/config/services.yaml:338-373, api/src/GameSelection/Infrastructure/StubIgdbHttpClient.php]
- DBAL query shape: copy `DbalGameCatalogQuery` (same table join to `game_catalog_sync`). [api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php]
- In `when@test`, `HttpClientInterface` is already mapped to `MockHttpClient` (services.yaml:353-356) - unit tests should construct the client with their own `MockHttpClient` directly (like `IgdbHttpClientTest`), not rely on the container.

### Scope boundaries
- No caching of the owned list in this story (note it as a 28.x follow-up for Steam rate limits). No persistence of the SteamID (that's 28.3). No "games you don't own" suggestions (deferred per epic).
- Endpoint is **public** - do not gate it. The save-for-members part is story 28.3.

### Testing standards
- Unit: `TestCase`, `MockHttpClient`/`MockResponse`, mock interfaces. Functional: `FunctionalTestCase` (full schema, Postgres `archilan_test`), `createGame()` + manual `GameCatalogSync`. [api/tests/Functional/FunctionalTestCase.php:24-52,148-168; api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php]

### Project Structure Notes
- New: `Domain/SteamProfileReference.php`, `Infrastructure/SteamWebApiClient(Interface).php`, `Infrastructure/StubSteamWebApiClient.php`, `Application/SteamCatalogQueryInterface.php`, `Infrastructure/DbalSteamCatalogQuery.php`, `Application/SteamLibraryCouplingQuery.php`, `Presentation/SteamCouplingController.php`, + 4 test files.
- Modified: `config/services.yaml`, `.env`, `.env.example`.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md] (story 28.2)
- Prior story: [Source: _bmad-output/implementation-artifacts/28-1-steam-appid-igdb-sync.md]
- Client pattern: [Source: api/src/Streaming/Infrastructure/TwitchApiClient.php], [Source: api/src/GameSelection/Infrastructure/IgdbHttpClient.php]
- Controller/error pattern: [Source: api/src/GameSelection/Presentation/AdminIgdbController.php], [Source: api/src/GameSelection/Presentation/PublicGameCatalogController.php]
- DBAL query: [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php]
- DI/test wiring: [Source: api/config/services.yaml:338-379]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-2-steam-coupling-endpoint` (stacked on the 28.1 branch).
- Steam client interface placed in Infrastructure (codebase convention), not Application. Errors surface as a typed `SteamApiException`, caught by the coupling query → `steam_error` outcome (endpoint returns 502, never 500).
- Private "Game details" profiles map to `private_profile` (Steam returns no `games` list - no API workaround). `STEAM_WEB_API_KEY` added to `.env`; `.env.example` not present in repo, so only `.env` updated.
- All four quality gates green: php-cs-fixer (0), phpstan src+tests (0), app:architecture:ddd (exit 0), phpunit (1044/1044).

### File List

**Added**
- `api/src/GameSelection/Domain/SteamProfileReference.php`
- `api/src/GameSelection/Infrastructure/SteamApiException.php`
- `api/src/GameSelection/Infrastructure/SteamWebApiClientInterface.php`
- `api/src/GameSelection/Infrastructure/SteamWebApiClient.php`
- `api/src/GameSelection/Infrastructure/StubSteamWebApiClient.php`
- `api/src/GameSelection/Application/SteamCatalogQueryInterface.php`
- `api/src/GameSelection/Infrastructure/DbalSteamCatalogQuery.php`
- `api/src/GameSelection/Application/SteamLibraryCouplingQuery.php`
- `api/src/GameSelection/Presentation/SteamCouplingController.php`
- `api/tests/Unit/GameSelection/SteamProfileReferenceTest.php`
- `api/tests/Unit/GameSelection/Infrastructure/SteamWebApiClientTest.php`
- `api/tests/Unit/GameSelection/SteamLibraryCouplingQueryTest.php`
- `api/tests/Functional/SteamCouplingEndpointTest.php`

**Modified**
- `api/config/services.yaml` (bindings + apiKey arg + when@test stub)
- `api/.env` (STEAM_WEB_API_KEY)
