# Story 30.3: Profile customization (owner edit) + AudiencePolicy

Status: ready-for-review

## Story

As a member,
I want to customize my community profile (bio, tagline, pronouns, banner, social links, favorite games)
and choose who can see it,
so that my profile reflects me while I control its privacy.

Adds owner-editable customization + a `profile audience` enforced server-side via a Domain `AudiencePolicy`
wired into the profile read. Deps: 30.1.

## Acceptance Criteria

1. `CommunityProfile` stores customization: `bio`, `tagline`, `pronouns`, `bannerPreset`, `socialLinks`,
   `favoriteGameIds`, `audience` (`public|members|friends`, default `members`).
2. `GET/PUT /api/v1/community/profile` (owner, authenticated) read/update the editable profile; PUT
   validates (lengths, banner preset ∈ set, audience ∈ set, ≤5 social links with http(s) URLs, ≤6 favorite
   games that must exist) → 422 on invalid.
3. `AudiencePolicy.canView(viewerTier, audience)` (pure Domain) gates the customization surface on every
   read; identity + aggregate stats stay public. `members` = **live** membership (`ActiveMembershipQuery`,
   never stale `ROLE_MEMBER`); viewer ladder anonymous→authenticated→member→(friend)→self (friend lands in 30.7).
4. The public read (`GET /community/profiles/{slug}`) returns `customization` only when the viewer may see
   it (else `null`), and resolves favorite games to id/name/slug/cover.
5. Owner edit UI in `/compte` (new "Profil" tab): bio/tagline/pronouns, banner preset, audience, social
   links (add/remove), favorite games (pick/remove from the catalog). Public profile renders the
   customization (banner preset, tagline/pronouns, bio, favorites grid, links).
6. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `Audience` + `BannerPreset` (valid-value VOs), `AudiencePolicy` (pure, static
      `canView` + viewer-tier consts); `CommunityProfile` += customization fields + `customize()` + getters.
- [x] **api/ Migration:** add bio/tagline/pronouns/banner_preset/social_links/favorite_game_ids/audience.
- [x] **api/ Application:** `UpdateCommunityProfile` (validate + upsert + customize); extend
      `CommunityProfileView` (viewer tier via `ActiveMembershipQueryInterface`, audience gating, favorite-game
      resolution via `GameRepositoryInterface`, owner `editableForUser`).
- [x] **api/ Presentation:** `GET/PUT /api/v1/community/profile` (owner); public GET now passes the
      optional viewer.
- [x] **api/ tests:** unit `AudiencePolicyTest`; functional `CommunityProfileCustomizationTest` (owner edit
      + read-back, public vs members audience gating, invalid audience/favorite/social-link 422, auth required).
- [x] **frontend:** `features/community/community-profile-api.ts` (owner GET/PUT) +
      `community-profile-customization-form.tsx` (client) wired as a new `/compte` "Profil" tab; public
      profile page renders customization (banner preset, tagline/pronouns, bio, favorites, links);
      `hasNullableStringProp` added to shared type-guards.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Live membership via the existing `ActiveMembershipQueryInterface` (`IS_MEMBER`), never `ROLE_MEMBER`
  (CLAUDE.md AC-M1/M2). Favorite-game covers via `GameRepositoryInterface::findByIds`. Owner-edit favorites
  picker reuses `getAllPublicGames`.
- Validation reuses `Identity\Application\ValidationErrors`.

### Architecture guardrails
- `AudiencePolicy`/`Audience`/`BannerPreset` are pure Domain (no deps); the live-membership check is
  resolved in Application and passed as a tier, keeping Domain pure (epic §G). Enforced server-side on
  every read path, never UI-only.
- `CommunityProfileView` composes cross-context **interfaces** only (Application/Domain), no DB infra.

### Scope boundaries / deviations
- `friend` tier exists in the policy but no viewer is ever resolved to it until the social graph (30.7);
  `friends` audience therefore = self-only for now (documented, intentional).
- Showcase ordering/widgets = 30.6; this story ships favorites as a flat ordered list.
- Favorites editor uses a simple catalog `<select>` picker (max 6) - not a rich search; acceptable MVP.

### Project Structure Notes
- New api: `Community/Domain/{Audience,BannerPreset,AudiencePolicy}`, `Community/Application/UpdateCommunityProfile`,
  migration, unit+functional tests. Modified: `CommunityProfile`, `CommunityProfileView`, controller, services? (no new bindings - all autowired).
- New frontend: `features/community/{community-profile-api.ts,community-profile-customization-form.tsx}`.
  Modified: `player-profile-api.ts`/page (display), `account-tabs.tsx` (tab), `lib/type-guards.ts`.

### References
- Epic §C/§G + story 30.3. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Customization + audience layered onto the 30.1/30.2 foundation. Audience gating is server-side in
  `CommunityProfileView` (tier resolved from live membership + self check); identity/stats stay public.
- Owner edit lives in a new `/compte` "Profil" tab; the public profile page renders bio/tagline/pronouns,
  a banner-preset gradient, a favorites cover grid, and social links.
- Deviations: `friend` tier inert until 30.7 (friends audience = self-only for now); favorites picker is a
  simple catalog select; showcase arrangement deferred to 30.6.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1139 tests, 0 notices (incl.
  `AudiencePolicyTest` + `CommunityProfileCustomizationTest`).
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Domain/Audience.php`
- `api/src/Community/Domain/BannerPreset.php`
- `api/src/Community/Domain/AudiencePolicy.php`
- `api/src/Community/Application/UpdateCommunityProfile.php`
- `api/migrations/Version20260617230000.php`
- `api/tests/Unit/Community/AudiencePolicyTest.php`
- `api/tests/Functional/CommunityProfileCustomizationTest.php`

**Modified (api)**
- `api/src/Community/Domain/CommunityProfile.php` (customization fields + `customize()`)
- `api/src/Community/Application/CommunityProfileView.php` (audience gating, favorites, editable read)
- `api/src/Community/Presentation/CommunityProfileController.php` (owner GET/PUT + viewer on public GET)

**Added (frontend)**
- `frontend/src/features/community/community-profile-api.ts`
- `frontend/src/features/community/community-profile-customization-form.tsx`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-api.ts` (customization + audience in the read)
- `frontend/src/features/players/player-profile-page.tsx` (render customization + banner presets)
- `frontend/src/features/auth/account-tabs.tsx` ("Profil" tab)
- `frontend/src/lib/type-guards.ts` (`hasNullableStringProp`)
