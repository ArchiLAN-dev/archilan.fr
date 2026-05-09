# Story 11.1: IGDB Proxy Endpoint (PHP)

Status: done

## Story

As an admin,
I want the PHP API to expose a secure search endpoint backed by the IGDB game database,
so that the frontend can query game metadata (name, description, cover, slug) without exposing Twitch credentials to the browser.

## Acceptance Criteria

1. `GET /api/v1/admin/igdb/search?q={query}` returns up to 10 game results from IGDB, each with `{ igdbId, name, slug, summary, coverUrl }`. Requires `requireAdmin()`.
2. The endpoint returns HTTP 422 with `{ error: { code: "igdb_query_required" } }` if `q` is missing or blank.
3. Authentication with IGDB uses the Twitch OAuth2 `client_credentials` flow (`POST https://id.twitch.tv/oauth2/token`). The access token is cached using Symfony Cache with a TTL of 90% of `expires_in`.
4. Credentials `IGDB_CLIENT_ID` and `IGDB_CLIENT_SECRET` are injected via `#[Autowire('%env(IGDB_CLIENT_ID)%')]` and must be documented in `.env.example`.
5. If IGDB authentication fails (non-2xx token response), the endpoint returns HTTP 502 with `{ error: { code: "igdb_auth_failed" } }`.
6. If the IGDB search call fails (non-2xx or network error), the endpoint returns HTTP 502 with `{ error: { code: "igdb_search_failed" } }`.
7. `coverUrl` is a valid absolute HTTPS URL using IGDB's `t_cover_big` image size (e.g. `https://images.igdb.com/igdb/image/upload/t_cover_big/{image_id}.jpg`). If a game has no cover, `coverUrl` is `null`.
8. All new code is covered by unit tests (mocked HttpClient); no existing tests regress.

## Tasks / Subtasks

- [x] Add `IGDB_CLIENT_ID` and `IGDB_CLIENT_SECRET` to `.env.example` (AC: 4)

- [x] Create `api/src/GameSelection/Infrastructure/IgdbHttpClient.php` (AC: 3, 4, 5, 6, 7)
  - [x] Constructor: inject `HttpClientInterface`, `CacheInterface`, `string $clientId`, `string $clientSecret`
  - [x] `getAccessToken(): string` - fetches from cache or calls `POST https://id.twitch.tv/oauth2/token`, caches with TTL = `expires_in * 0.9`
  - [x] `searchGames(string $query, int $limit = 10): array` - calls `POST https://api.igdb.com/v4/games` with IGDB query DSL body: `fields id,name,slug,summary,cover.image_id; search "{query}"; limit {limit};`, returns raw array
  - [x] Cover URL helper: `coverUrl(?array $cover): ?string` - builds `https://images.igdb.com/igdb/image/upload/t_cover_big/{image_id}.jpg` or null

- [x] Create `api/src/GameSelection/Presentation/AdminIgdbController.php` (AC: 1, 2, 5, 6)
  - [x] Route: `#[Route('/api/v1/admin/igdb/search', methods: ['GET'])]`
  - [x] Guard: `requireAdmin($request)`
  - [x] Validate `q` param - 422 if blank
  - [x] Call `IgdbHttpClient::searchGames($q)`, catch exceptions → 502
  - [x] Map results to `{ igdbId, name, slug, summary, coverUrl }` array
  - [x] Return `JsonResponse(['data' => $results, 'meta' => []])`

- [x] Register `IgdbHttpClient` in `config/services.yaml` with env var bindings (AC: 4)

- [x] Add `api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php` (AC: 8)
  - [x] Token fetch + caching (mock HTTP, assert cache hit on second call)
  - [x] `searchGames` maps response + builds correct cover URL
  - [x] `searchGames` on non-2xx throws / returns expected error

- [x] Add `api/tests/Functional/AdminIgdbSearchTest.php` (AC: 1, 2, 5, 6, 8)
  - [x] Happy path: valid query → 200, correct structure
  - [x] Blank query → 422
  - [x] Unauthenticated → 401
  - [x] Non-admin → 403
  - [x] IGDB returns error → 502

## Dev Notes

### Project Structure
- New file: `api/src/GameSelection/Infrastructure/IgdbHttpClient.php`
- New file: `api/src/GameSelection/Presentation/AdminIgdbController.php`
- Modified: `api/config/services.yaml` (service bindings)
- Modified: `.env.example` (new vars)
- New test: `api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php`
- New test: `api/tests/Functional/AdminIgdbSearchTest.php`

### Auth pattern
Every admin endpoint guards with `ApiAccessGuard::requireAdmin($request)` - see existing `AdminGameController.php`, `AdminPostController.php`.

### HttpClient pattern
Follow `TwitchApiClient` (`Streaming\Infrastructure`) exactly:
- Inject `HttpClientInterface $httpClient` and `CacheInterface $cache`
- Cache key: `'igdb.access_token'`
- Token TTL: `(int)($data['expires_in'] * 0.9)` seconds

### IGDB query DSL
IGDB uses a proprietary query language sent as the POST body (plain text, not JSON):
```
fields id,name,slug,summary,cover.image_id; search "Hollow Knight"; limit 10;
```
Content-Type must be `text/plain` (not `application/json`). The Symfony HttpClient `body` option handles this.

### IGDB image URL format
```
https://images.igdb.com/igdb/image/upload/t_cover_big/{image_id}.jpg
```
`image_id` comes from the nested `cover.image_id` field. Use `t_cover_big` (264×374 px) for display, `t_thumb` (90×90) would be too small for the results panel.

### Required IGDB request headers
```
Client-ID: {IGDB_CLIENT_ID}
Authorization: Bearer {access_token}
```

### Env vars
```
IGDB_CLIENT_ID=your_twitch_client_id
IGDB_CLIENT_SECRET=your_twitch_client_secret
```
Obtain at https://dev.twitch.tv/console - create an application, set category to "Website Integration".

### Response shape (success)
```json
{
  "data": [
    {
      "igdbId": 1234,
      "name": "Hollow Knight",
      "slug": "hollow-knight",
      "summary": "A challenging 2D action adventure...",
      "coverUrl": "https://images.igdb.com/igdb/image/upload/t_cover_big/co1rgi.jpg"
    }
  ],
  "meta": []
}
```

## Dev Agent Record

### Agent Model Used
claude-sonnet-4-6

### Debug Log References
- Fixed User constructor call in functional test (only 9 args required - $deletedAt and $cguAcceptedVersion have defaults)
- Used IgdbHttpClientInterface + StubIgdbHttpClient (static state pattern like SpyHub) for test isolation

### Completion Notes List
- Created IgdbHttpClientInterface to allow test substitution via services.yaml when@test
- IgdbAuthException vs IgdbSearchException allows the controller to return the correct 502 error code
- 395 tests passing, 0 regressions

### File List
- api/src/GameSelection/Infrastructure/IgdbHttpClientInterface.php
- api/src/GameSelection/Infrastructure/IgdbAuthException.php
- api/src/GameSelection/Infrastructure/IgdbSearchException.php
- api/src/GameSelection/Infrastructure/IgdbHttpClient.php
- api/src/GameSelection/Infrastructure/StubIgdbHttpClient.php
- api/src/GameSelection/Presentation/AdminIgdbController.php
- api/config/services.yaml (modified)
- api/.env (modified)
- api/.env.example (created)
- api/tests/Unit/GameSelection/Infrastructure/IgdbHttpClientTest.php
- api/tests/Functional/AdminIgdbSearchTest.php
