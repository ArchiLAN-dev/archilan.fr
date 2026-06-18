# Story 30.2: Avatar resolution + caching

Status: ready-for-review

## Story

As a member,
I want my profile to show my real avatar (Discord/Steam) with a graceful fallback,
so that the community feels personal without ever blocking a page on an external call.

`AvatarResolverInterface` (Discord adapter + test stub), a cached `avatarUrl`/`avatarResolvedAt` on
`CommunityProfile`, an off-request-path refresh (command + reusable service), and an identicon/initials
fallback that also covers load errors. Deps: 30.1.

## Acceptance Criteria

1. `CommunityProfile` caches a resolved `avatarUrl` + `avatarResolvedAt`; `isAvatarStale(now, ttl)` and
   `cacheAvatar(url|null, now)` drive refresh (caching a null result throttles retries).
2. `AvatarResolverInterface` (Application port) with a real Discord adapter (REST → CDN URL via the bot
   token, best-effort: never throws/blocks, null on any failure) + a test stub.
3. Refresh runs **off the request path**: `RefreshCommunityAvatars` (stale/missing, TTL 7 days) + the
   `community:avatars:refresh` command; the profile read never resolves inline (returns the cached snapshot).
4. The enriched profile payload exposes the cached `avatarUrl` (null until first resolved).
5. The profile avatar renders the cached image, falling back to a deterministic **initials** placeholder
   both when the URL is null **and on image load error** (never a broken image).
6. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `CommunityProfile` += `avatarUrl` / `avatarResolvedAt` (trailing-optional) +
      `cacheAvatar()` / `isAvatarStale()` / getters.
- [x] **api/ Migration:** add `avatar_url`, `avatar_resolved_at` to `community_profile`.
- [x] **api/ Application:** `AvatarResolverInterface` (port), `CommunityUserContactsQueryInterface`
      (discordId/steamProfile for a user), `RefreshCommunityAvatars` (`refreshStale`, `refreshForUser`).
- [x] **api/ Infrastructure:** `DiscordAvatarResolver` (real, HTTP + bot token, best-effort),
      `StubAvatarResolver` (when@test), `DbalCommunityUserContactsQuery`; repo gains
      `findNeedingAvatarRefresh` + `flush`.
- [x] **api/ Presentation:** `community:avatars:refresh` console command.
- [x] **api/ wiring:** bind the port → Discord adapter (token via env, empty-default) + contacts query;
      `when@test` overrides the port → stub.
- [x] **api/ read:** `CommunityProfileView` returns the cached `avatarUrl` from the ensured profile entity.
- [x] **api/ tests:** unit (`isAvatarStale`/`cacheAvatar`/null-result), functional (refresh via stub →
      view returns cached URL).
- [x] **frontend:** extract a **client** `ProfileAvatar` with `onError` → initials fallback; page uses it.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- Identity already stores `discord_id` and `steam_profile` on the `user` row → `Community` reads them via
  a small DBAL contacts query (same precedent as `DbalCommunityProfileQuery`), keeping `Community` a leaf
  (no Identity/GameSelection interface changes). [Source: api/src/Identity/Domain/User.php:50,58]
- The avatar is a **snapshot on `CommunityProfile`** refreshed off-path; the page never calls Discord
  (epic §F: "not a live call per page view").

### Architecture guardrails
- Resolver is an Infrastructure adapter behind an Application port; failures resolve to null (never throw,
  never block). Real adapter is exercised only in prod; tests use the stub (mirrors IGDB/Steam clients).
- Repo uses ORM QueryBuilder for the stale-set fetch (entities to mutate); Application stays free of DB infra.

### Scope boundaries / deviations (documented)
- **Discord-only real resolution** ships now; **Steam avatar** (`GetPlayerSummaries` + vanity resolve)
  is deferred behind the same port (compose later). The epic's Discord↔Steam precedence collapses to
  Discord for the MVP.
- **No owner `avatarSource` choice yet** (that's profile customization, story 30.3) → auto precedence only.
- **Refresh is command/scheduled + on-edit-reusable**, not dispatched on view; a brand-new member's avatar
  appears after the next `community:avatars:refresh` pass (accepted MVP trade-off; an on-view async warm
  can be added later). No Symfony Scheduler entry added here — wire the command into the scheduler when ops
  is ready.

### Project Structure Notes
- New: `Community/Application/{AvatarResolverInterface,CommunityUserContactsQueryInterface,RefreshCommunityAvatars}`,
  `Community/Infrastructure/{DiscordAvatarResolver,StubAvatarResolver,DbalCommunityUserContactsQuery}`,
  `Community/Presentation/RefreshCommunityAvatarsCommand`, migration, unit+functional tests,
  `frontend/.../players/profile-avatar.tsx`.

### References
- Epic §F (avatar resolution), story 30.2. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Built on top of 30.1 (same `Community` context). `avatarUrl` now flows from the cached profile row
  through `CommunityProfileView` into the existing `/api/v1/community/profiles/{slug}` payload.
- Discord adapter resolves `discord_id` → `GET /users/{id}` (bot token) → CDN URL; any failure (no token,
  no link, HTTP error, no avatar) → null. `StubAvatarResolver` returns a deterministic URL in tests.
- Frontend avatar became a client component so `onError` can fall back to initials (review #4).
- Deviations (see Scope): Discord-only real resolver (Steam deferred), refresh off-path via command (no
  on-view dispatch), no `avatarSource` owner choice yet.

### Validation Results

- `php-cs-fixer` 0 ; `phpstan` 0 ; `app:architecture:ddd` exit 0 ; `phpunit` 1128 tests, 0 notices
  (incl. `CommunityProfileTest` functional + `Unit\Community\CommunityProfileTest`).
- `pnpm typecheck` / `lint` / `build` / `test` (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Application/AvatarResolverInterface.php`
- `api/src/Community/Application/CommunityUserContactsQueryInterface.php`
- `api/src/Community/Application/RefreshCommunityAvatars.php`
- `api/src/Community/Infrastructure/DiscordAvatarResolver.php`
- `api/src/Community/Infrastructure/StubAvatarResolver.php`
- `api/src/Community/Infrastructure/DbalCommunityUserContactsQuery.php`
- `api/src/Community/Presentation/RefreshCommunityAvatarsCommand.php`
- `api/migrations/Version20260617220000.php`
- `api/tests/Unit/Community/CommunityProfileTest.php`

**Modified (api)**
- `api/src/Community/Domain/CommunityProfile.php` (avatar cache fields + methods)
- `api/src/Community/Domain/CommunityProfileRepositoryInterface.php` (+ findNeedingAvatarRefresh, flush)
- `api/src/Community/Infrastructure/DoctrineCommunityProfileRepository.php`
- `api/src/Community/Application/CommunityProfileView.php` (returns cached avatarUrl)
- `api/config/services.yaml` (resolver/contacts bindings + when@test stub)
- `api/tests/Functional/CommunityProfileTest.php` (avatar caching test)

**Added (frontend)**
- `frontend/src/features/players/profile-avatar.tsx`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-page.tsx` (use client ProfileAvatar)
