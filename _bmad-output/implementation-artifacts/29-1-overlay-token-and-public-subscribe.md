# Story 29.1: revocable overlay token + public subscribe endpoint

Status: review

## Story

As an administrator (or private-run owner) of ArchiLAN,
I want to issue a **revocable, long-lived overlay token** for a session and a **public endpoint** that
exchanges it for a short-lived Mercure subscriber JWT,
so that an OBS browser source can subscribe to the session's read-only realtime feeds without logging
in, and I can kill the access at any time by revoking the token.

## Context

Every session type (private game, event, weekly-run entry) resolves to a `sessionId`. The existing
realtime token controllers (`FeedTokenController`, `PlayersPushController`'s token path, etc.) mint a
**short-TTL** Mercure JWT but require an **authenticated** request and per-type authorization - unusable
from OBS, which cannot log in and is configured once for hours.

Mercure subscriber JWTs are **stateless**, hence non-revocable on their own. The locked design is
**opaque → exchange**: persist an opaque token per session (revocable), and expose a **public** endpoint
that validates the opaque token and returns a freshly minted short-TTL Mercure JWT. Revoking the opaque
row makes every overlay for that session lose access on its next reconnect.

This story is the backend enabler only - no UI. It lives in the **`Streaming`** bounded context (which
already exists for Twitch status) and reuses `Sessions\Application\SessionQuery` for authorization and
`Symfony\Component\Mercure\HubInterface` for JWT minting, exactly like `FeedTokenController`
(`$factory->create(subscribe: [...], additionalClaims: ['exp' => ...])`).

Topics granted to the overlay JWT: `runs/{sessionId}/feed` and `runs/{sessionId}/players` (the two the
widgets need - items/log come from `feed`, goal detection from `players`). No `reachable` topic (it is
per-slot and spoiler-heavy; not needed for the read-only overlays).

## Acceptance Criteria

1. **Domain.** `App\Streaming\Domain\SessionOverlayToken` is a `final` aggregate with a `final readonly`
   value for the opaque secret. Fields: `id`, `sessionId`, `token` (opaque, high-entropy), `createdAt`,
   `revokedAt` (nullable). State change only via named methods (`revoke(\DateTimeImmutable $now)`); no
   public setters. `isActive(\DateTimeImmutable $now)` returns false once revoked.
2. **Migration.** New `session_overlay_token` table (`id`, `session_id`, `token` unique, `created_at`,
   `revoked_at` nullable), reversible `up()`/`down()`, timestamped one second after the last migration.
3. **Issue / rotate.** `IssueOverlayToken` (Application, returns `void` or the new opaque string per
   AC-A3 - return the opaque string as a typed DTO, not an entity) creates a token for a session;
   issuing again **rotates** (revokes the previous active token, creates a new one) so a session has at
   most one active overlay token. Opaque secret generated via an injected randomness source (no `rand()`
   in Application - inject an interface or pass the value in).
4. **Revoke.** `RevokeOverlayToken` (Application, returns `void`) marks the active token revoked. Idempotent
   (revoking when none active is a no-op, no error).
5. **Subscribe query.** `OverlaySubscribeQuery` (Application read, no transaction) takes `(sessionId,
   opaque)`; returns `null` when the session is unknown or the opaque token is missing/revoked/not
   matching the session; otherwise returns a DTO `{ token: <Mercure JWT>, hubUrl, topics: [feed, players] }`
   with a short TTL (e.g. 3600s) minted via `HubInterface::getFactory()->create(subscribe: [...])`.
6. **Issue/revoke endpoint.** `OverlayTokenController`:
   `POST /api/v1/sessions/{id}/overlay-token` → issue/rotate, returns the opaque token;
   `DELETE /api/v1/sessions/{id}/overlay-token` → revoke. Both are **authorization-gated using the exact
   same per-type rule already applied by `FeedTokenController`** (admin always; otherwise the
   session-type-appropriate check - event registration / weekly member / private-run owner). Unknown
   session → 404; unauthorized → 403.
7. **Public subscribe endpoint.** `PublicOverlaySubscribeController`
   `GET /api/v1/public/overlay/{id}/subscribe?t={opaque}` requires **no authentication**; it delegates to
   `OverlaySubscribeQuery`. Returns `404`/`401` (choose one, documented) with a generic message on
   invalid/revoked token - never leaks whether the session exists vs the token is wrong. On success
   returns `{ data: { token, hubUrl, topic | topics } }` shaped so the frontend EventSource flow can
   consume it.
8. **No spoilers / no writes.** The minted JWT carries **subscribe-only** claims for `feed` + `players`
   only - never publish claims, never `reachable`/hints topics.
9. **DDD + gates green.** No `EntityManagerInterface`/`Connection` in Application or Presentation (AC-A2,
   AC-P1/2); repository interface in `Streaming\Domain`, DBAL implementation in `Streaming\Infrastructure`;
   `Streaming` added wherever new contexts are validated if needed. API gates all green: `phpstan`,
   `php-cs-fixer`, `phpunit`, `app:architecture:ddd`.

## Tasks / Subtasks

- [x] Task 1 - Domain + persistence (AC 1, 2, 9).
  - [x] `SessionOverlayToken` aggregate + `SessionOverlayTokenRepositoryInterface` in `Streaming\Domain`.
  - [x] Doctrine mapping + migration `session_overlay_token`.
  - [x] DBAL/ORM repository in `Streaming\Infrastructure`.
- [x] Task 2 - Application services (AC 3, 4, 5).
  - [x] `IssueOverlayToken` (rotate-on-reissue), `RevokeOverlayToken`, `OverlaySubscribeQuery`.
  - [x] No `rand()`/`time()` in Application: clock passed in as a parameter (`$now`), opaque secret
        generated via `random_bytes` in the issuing service (mirrors `RefreshTokenFactory`).
- [x] Task 3 - Controllers (AC 6, 7, 8).
  - [x] `OverlayTokenController` (POST/DELETE) reusing the `FeedTokenController` authz rule.
  - [x] `PublicOverlaySubscribeController` (GET, unauthenticated).
- [x] Task 4 - Tests (AC 1-9).
  - [x] Unit: domain revoke/isActive; revoke-twice keeps first timestamp; hash-not-raw.
  - [x] Functional: POST issues (admin 200, unauthorized 403, unauth 401, unknown session 404),
        DELETE revokes (subscribe then 404), reissue rotates previous (old token 404), public
        subscribe returns a JWT for an active token and 404 for missing/unknown/revoked.

## Dev Notes

### Project Structure Notes

- New context surface under `api/src/Streaming/{Domain,Application,Infrastructure,Presentation}/`.
- Mirror `api/src/Sessions/Presentation/FeedTokenController.php` for the Mercure factory usage and the
  per-type authorization branch (admin vs `hasActiveEventRegistration`, etc.). **Confirm the exact authz
  per session type at implementation time** - reuse the existing helper rather than inventing a rule.
- Keep the opaque token generation out of Domain (Domain stays pure, AC-D3): generate in Application via
  an injected `interface`, pass the value into the aggregate.

### References

- `api/src/Sessions/Presentation/FeedTokenController.php` - token minting + authz pattern to mirror.
- `api/src/Realtime/Application/RealtimePublisher.php`, `Realtime/Infrastructure/*Hub.php` - Mercure hub
  wiring already in the project.
- `api/src/Streaming/**` - existing context (Twitch) to extend.
- Epic 29 planning file - locked decisions (opaque→exchange, feed+players topics, read-only).
- Topics consumed downstream: `runs/{id}/feed` (`FeedTokenController`), `runs/{id}/players`
  (`PlayersPushController`).

## Dev Agent Record

- **Token = opaque, DB stores SHA-256 hash** (not the raw token), mirroring `RefreshToken`: the raw
  opaque value lives only in the overlay URL; a DB leak yields no usable tokens. Lookup is by hash.
- **Rotation/revocation via the aggregate's `revoke()` method** (AC-D5), not a DBAL bulk `UPDATE`: there
  is at most one active token per session, so `revokeAllForSession` loads the active token(s), mutates
  through `revoke($now)`, and flushes. This keeps the ORM identity map consistent (a later
  `findByTokenHash` observes the revocation in-process) and avoids a heavy `EntityManager::clear()`.
- **JWT minted in Presentation, not Application** - `OverlaySubscribeQuery` only validates and returns
  the subscribe-only topics (`runs/{id}/feed`, `runs/{id}/players`); `PublicOverlaySubscribeController`
  mints via `HubInterface::getFactory()->create(subscribe: …, additionalClaims: ['exp' => …])`, exactly
  like `FeedTokenController`. The query depends on `Sessions\Application\SessionQuery` (cross-context
  Application→Application; allowed by `DddArchitectureValidator`).
- **Authz reuses `SessionQuery::isUserAuthorizedForSession`** (admin bypass + event-registrant / run
  owner / participant) - generic across the three session types, no per-type branching.
- **Public route needs no security.yaml change**: the `main` firewall is `lazy` with no `access_control`,
  so anonymous requests reach controllers; the public endpoint simply does not call
  `requireAuthenticatedUser`. Invalid/revoked/missing token → single generic 404 (no existence leak).
- New Doctrine mapping `Streaming` added (the context had no entity before) + repository binding in
  `services.yaml`. Migration `Version20260616120000`.

### Quality gates (all green)

- `vendor/bin/phpstan analyse src tests` → No errors (750 files).
- `vendor/bin/php-cs-fixer check src` / `check tests` → 0 violations.
- `php bin/phpunit` → 1079 tests OK (incl. 9 functional + 4 unit added here).
- `php bin/console app:architecture:ddd` → boundaries respected.

### Files

- `api/src/Streaming/Domain/SessionOverlayToken.php`, `…/SessionOverlayTokenRepositoryInterface.php`
- `api/src/Streaming/Infrastructure/DoctrineSessionOverlayTokenRepository.php`
- `api/src/Streaming/Application/IssueOverlayToken.php`, `RevokeOverlayToken.php`, `OverlaySubscribeQuery.php`
- `api/src/Streaming/Presentation/OverlayTokenController.php`, `PublicOverlaySubscribeController.php`
- `api/migrations/Version20260616120000.php`
- `api/config/packages/doctrine.yaml` (Streaming mapping), `api/config/services.yaml` (repo binding)
- `api/tests/Unit/Streaming/SessionOverlayTokenTest.php`, `api/tests/Functional/OverlayTokenTest.php`

## Change Log

- 2026-06-16 - Story created (status: planned).
- 2026-06-16 - Implemented: opaque revocable overlay token (Streaming context) + public subscribe
  endpoint exchanging it for a short-TTL Mercure JWT (feed + players). All gates green (status: review).
- 2026-06-16 - Refinement: added an **overlay-only test channel** `runs/{id}/overlay-test` to the JWT
  subscribe claims + a new `OverlayTestController` (`POST /sessions/{id}/overlay-test`, same authz)
  that publishes a sample feed/players event there. Player progression pages don't subscribe to it, so
  operator "Test" events never reach them. +2 functional tests; all gates green.
- 2026-06-16 - Model change (user feedback): the token is now **retrievable** so the owner sees the same
  overlay URL across browsers/devices (previously the raw token lived only in one browser's localStorage,
  and issuing elsewhere rotated it). Added a nullable `token` column (raw) alongside `token_hash`
  (migration `Version20260616120001`); the public subscribe still looks up by hash (unchanged). New
  `ActiveOverlayTokenQuery` + `GET /sessions/{id}/overlay-token` (admin/owner) return the active raw
  token. Trade-off accepted: a read-only, revocable subscribe token is now stored in cleartext. Gates
  green (cs-fixer/phpstan/ddd, overlay tests 15/15).
- 2026-06-16 - **Dropped the token entirely → permanent tokenless overlay URLs (user feedback).** The
  per-URL token meant the streamer had to re-paste OBS sources on every revoke/rotation, and tokens
  diverged per browser. Since overlays are read-only and meant to be shown on stream, the public
  subscribe is now **tokenless**: `OverlaySubscribeQuery.resolveTopics($sessionId)` grants the
  read-only topics for any known session; the URL is `/o/{session}/{widget}?slot=…` and never changes.
  **Removed** `SessionOverlayToken` (domain/repo/interface/Doctrine), `IssueOverlayToken`,
  `RevokeOverlayToken`, `ActiveOverlayTokenQuery`, `OverlayTokenController`, both migrations
  (table no longer needed), the services.yaml binding, and the token tests. Added
  `PublicOverlaySubscribeTest` (404 unknown / 200 tokenless + topics). `OverlayTestController` stays
  (operator-authenticated). Trade-off accepted: anyone who knows a session id can view its read-only
  overlay. Gates green.
