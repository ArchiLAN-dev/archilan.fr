# Story 30.1: Community context skeleton + enriched profile read model + page shell

Status: ready-for-review

## Story

As a member,
I want a redesigned, Steam-style player profile backed by a new `Community` foundation,
so that the read-only Epic-18 profile becomes the base of a living community hub.

Establishes the `Community` bounded context (4 layers + DDD/services/Doctrine registration), the
`CommunityProfile` aggregate (created lazily on first view, idempotent on the unique `userId`), a
`CommunityProfileQuery` read model composing identity + Epic-18 stats, a `GET` endpoint, and a
redesigned `/joueurs/{slug}` header + stat showcase. Read-mostly; no social writes. Deps: none.

## Acceptance Criteria

1. A new `Community` bounded context exists with the four layers and is registered: added to
   `DddArchitectureValidator::CONTEXTS`, `services.yaml` Domain exclusion, and a Doctrine mapping.
2. `CommunityProfile` aggregate (1-1 with a `User` by `userId`, unique index) + repository; a member's
   row is created **lazily on first profile view**, **idempotently** (race-safe on the unique `userId`).
3. `GET /api/v1/community/profiles/{slug}` returns the enriched read model - identity (`slug`,
   `displayName`, `joinedAt`) + `avatarUrl` (null until 30.2) + aggregate `stats` - reusing Epic-18's
   `PlayerStatsQueryInterface`. 404 for unknown or deleted (`deleted_at`) users.
4. `/joueurs/{slug}` is redesigned (Steam-style header: avatar/initials + banner + identity, stat
   showcase) and reads the new endpoint; run history (Epic 18) is preserved.
5. Gates green: php-cs-fixer, phpstan, phpunit (0 notices), `app:architecture:ddd`; typecheck, lint,
   build, jest.

## Tasks / Subtasks

- [x] **api/ context registration:** add `Community` to `DddArchitectureValidator::CONTEXTS` (+ the unit
      test's fixture context list), `services.yaml` Domain exclusion, `doctrine.yaml` mapping.
- [x] **api/ Domain:** `CommunityProfile` aggregate (`final`, `create()`, getters; ORM entity, unique
      `user_id`) + `CommunityProfileRepositoryInterface` (`findByUserId`, `save`).
- [x] **api/ Migration:** `community_profile` (id, user_id, created_at, updated_at) + unique index on
      `user_id`; reversible.
- [x] **api/ Infrastructure:** `DoctrineCommunityProfileRepository`; `DbalCommunityProfileQuery`
      (reads the quoted `user` table by slug, filters `deleted_at IS NULL`, injects
      `PlayerStatsQueryInterface` for stats).
- [x] **api/ Application:** `CommunityProfileQueryInterface` (read model) + `CommunityProfileView`
      facade (composes the query + idempotent `ensureProfile`, catching `UniqueConstraintViolationException`).
- [x] **api/ Presentation:** `CommunityProfileController` - `GET /api/v1/community/profiles/{slug}`,
      one Application call, 200/404.
- [x] **api/ tests:** `CommunityProfileTest` (identity+stats, unknown 404, deleted 404, lazy+idempotent
      row creation).
- [x] **frontend:** repoint `getPlayerProfile` to the community endpoint, add `avatarUrl` to the type +
      guard; redesign `player-profile-page.tsx` (banner + avatar/initials header + stat showcase);
      update `player-profile-api.test.ts` mocks.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Stats reuse Epic-18 `PlayerStatsQueryInterface` (`DbalPlayerStatsQuery`) - no second stats path.
  [Source: api/src/Identity/Application/PlayerStatsQueryInterface.php]
- The read mirrors `PlayerProfileQuery`'s shape (slug/displayName/joinedAt/stats) so the frontend
  migration is a near drop-in; run history stays on the Epic-18 `/players/{slug}/history` endpoint.
- DBAL-reading the `user` table from `Community/Infrastructure` follows the `DbalPlayerHistoryQuery`
  precedent (read foreign tables when no query service exists). `user` is quoted (reserved word) as in
  `DbalUserDirectoryQuery`.

### Architecture guardrails
- New context obeys the DDD validator: Domain pure (ORM attributes only), Application injects
  interfaces (no `EntityManager`/`Connection`), DBAL lives in Infrastructure, controller does one call.
- Cross-context reuse (`PlayerStatsQueryInterface`) is injected into `Community/Infrastructure`
  (Application→Application interface), keeping `Community` a one-way leaf consumer (epic §B).
- Lazy-create-on-read mirrors the local precedent in `PersonalRunGameSelection::loadParticipant`
  (a read path that ensures a companion row); idempotency via unique index + caught violation (review #9).

### Scope boundaries (deferred to later 30.x)
- `avatarUrl` is always `null` here; **real Discord/Steam resolution + identicon onError = 30.2**. The
  page renders initials now; the `<img>` branch is forward-compat (no client `onError` yet).
- Customization (bio/audience/banner presets), achievements/level, social graph, feed, interactions,
  notifications, directory - all later stories. No `AudiencePolicy` yet (profile is public, Epic-18 parity).

### Project Structure Notes
- New context `api/src/Community/{Domain,Application,Infrastructure,Presentation}`.
- Frontend changes are contained to `features/players` (no new `features/community` yet).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md] (Track 0 / 30.1)
- Epic-18 profile: [Source: api/src/Identity/Application/PlayerProfileQuery.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Implemented on branch `feature/epic-30-story-1-community-profile-foundation` (from `develop`).
- New `Community` context: `CommunityProfile` aggregate + repo, `CommunityProfileQueryInterface` +
  `DbalCommunityProfileQuery` (identity by slug + Epic-18 stats), `CommunityProfileView` facade (lazy
  idempotent profile upsert), `CommunityProfileController` (`GET /api/v1/community/profiles/{slug}`),
  migration `Version20260617210000`. Registered in the DDD validator (+ its fixture test), services.yaml
  (exclusion + 2 bindings) and doctrine.yaml.
- Frontend: `getPlayerProfile` now hits the community endpoint, `PlayerProfile.avatarUrl` added; profile
  page redesigned with a banner + avatar (initials fallback) header and a stat showcase. History kept.
- Deviations: avatar is null/initials only (30.2 brings real resolution + onError fallback); profile is
  public with no `AudiencePolicy` yet (added in 30.3/30.7). Both are intended phase boundaries.

### Validation Results

- `vendor/bin/php-cs-fixer fix src tests --dry-run`: 0 violations.
- `vendor/bin/phpstan analyse src tests`: 0 errors.
- `php bin/console app:architecture:ddd`: exit 0.
- `php bin/phpunit`: 1124 tests, 8087 assertions, OK (0 notices) - incl. `CommunityProfileTest` (4).
- `pnpm typecheck` / `pnpm lint` / `pnpm build` / `pnpm test` (jest 86): all clean.

### File List

**Added (api)**
- `api/src/Community/Domain/CommunityProfile.php`
- `api/src/Community/Domain/CommunityProfileRepositoryInterface.php`
- `api/src/Community/Application/CommunityProfileQueryInterface.php`
- `api/src/Community/Application/CommunityProfileView.php`
- `api/src/Community/Infrastructure/DoctrineCommunityProfileRepository.php`
- `api/src/Community/Infrastructure/DbalCommunityProfileQuery.php`
- `api/src/Community/Presentation/CommunityProfileController.php`
- `api/migrations/Version20260617210000.php`
- `api/tests/Functional/CommunityProfileTest.php`

**Modified (api)**
- `api/src/Shared/Application/DddArchitectureValidator.php` (CONTEXTS += Community)
- `api/tests/Unit/DddArchitectureValidatorTest.php` (fixture contexts += Community)
- `api/config/services.yaml` (Domain exclusion + repo/query bindings)
- `api/config/packages/doctrine.yaml` (Community mapping)

**Modified (frontend)**
- `frontend/src/features/players/player-profile-api.ts` (community endpoint + avatarUrl)
- `frontend/src/features/players/player-profile-api.test.ts` (endpoint mocks + avatarUrl)
- `frontend/src/features/players/player-profile-page.tsx` (Steam-style header + showcase)
