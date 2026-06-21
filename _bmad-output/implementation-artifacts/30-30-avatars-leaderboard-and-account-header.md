# Story 30.30: Avatars on the Leaderboard and the Account Header

## Story

**As a** member browsing the community,
**I want** to see real profile photos on the leaderboard (`/classements`) and on my account header (`/compte`),
**So that** players are recognisable everywhere, consistently with the rest of the community surfaces (directory, friends, feed) instead of a flat initial.

## Context

The avatar resolution + caching capability already exists (story 30.2, `AvatarUrlResolver`) and is
used by the community directory, friends, and feed. Two surfaces were left showing only the first
letter of the pseudo:

- `/classements` — `LeaderboardClient.PlayerAvatar` renders `displayName.charAt(0)` (story 18.7 AC6
  explicitly shipped an "avatar initial"). The `/leaderboard` API does not return any avatar URL.
- `/compte` — `AccountTabs` header renders `getInitials(profile)`. The account uses
  `GET /account/profile` (Identity) which has no avatar, but `GET /community/profile` already returns
  a fully resolved `avatarUrl` for the current user.

Reported as bugs #7 and #8 in `HOTFIX-BACKLOG.md`.

## Status

done

## Acceptance Criteria

**AC1:** `GET /api/v1/leaderboard` returns an `avatarUrl: string|null` for every entry, resolved with
the same precedence as the directory (custom uploaded avatar presigned > cached external URL > null),
across all three axes (goals, checks, speed) and with/without the event filter.

**AC2:** On `/classements`, each leaderboard row shows the player's resolved profile photo. When no
avatar is set (or the image fails to load), it falls back to the deterministic initial currently shown.

**AC3:** On `/compte`, the account header shows the current user's resolved profile photo (from
`GET /community/profile`), falling back to the existing initials when none is set or the image fails.

**AC4:** No new API endpoint is added for AC3 — the account header reuses the existing
`GET /community/profile` payload (`avatarUrl`).

**AC5:** All quality gates pass: `phpstan` (max, 0 errors), `php-cs-fixer` (0 violations),
`phpunit` (green, 0 notices/deprecations/warnings), `app:architecture:ddd` (exit 0), and the
frontend `typecheck` / `lint` / `build`.

## Tasks / Subtasks

### API — leaderboard avatars (AC1)

- [x] Task 1: `LeaderboardQueryInterface` + `LeaderboardQuery` — extend the return docblocks to
  `array{slug, displayName, avatarUrl, value}` (entries now carry `avatarUrl: string|null`).
- [x] Task 2: `DbalLeaderboardQuery` — inject `App\Community\Application\AvatarUrlResolver`; in both
  user-hydration queries (`computeAggregatePage`, `computeSpeedPage`) select `cp.avatar_url` and
  `cp.custom_avatar_key`; resolve `avatarUrl` per user and carry it through sorting/slicing into the
  final entries. (Cross-context Infra→Community/Application coupling — allowed by the DDD validator;
  the query already joins the `community_profile` table directly. See Dev Notes.)
- [x] Task 3: `LeaderboardController` — add `'avatarUrl' => $entry['avatarUrl']` to each serialized row.
- [x] Task 4: Update/extend the leaderboard query unit/functional tests for the new field.

### Frontend — leaderboard (AC2)

- [x] Task 5: `community-api.ts` — add `avatarUrl: string | null` to `LeaderboardEntry` and its type
  guard (`hasNullableStringProp`).
- [x] Task 6: `leaderboard-client.tsx` — `PlayerAvatar` renders the photo with an `onError` fallback
  to the current initial (mirrors `ProfileAvatar`'s cached-URL-404 handling).

### Frontend — account header (AC3/AC4)

- [x] Task 7: `account-tabs.tsx` — fetch `GET /community/profile` (`fetchMyCommunityProfile`) and render
  the resolved `avatarUrl` in the header, falling back to `getInitials`.

### Gates

- [x] Task 8: Run all backend + frontend quality gates (AC5).

## Dev Notes

### Cross-context coupling (Task 2)

`DbalLeaderboardQuery` lives in `Sessions/Infrastructure` and will import
`App\Community\Application\AvatarUrlResolver`. This is acceptable:

- `DddArchitectureValidator` only forbids Doctrine `Connection`/`EntityManager` in Application and
  Presentation, and same-context layer imports inside Domain. It does **not** restrict cross-context
  Application imports from Infrastructure.
- The query already reaches into the Community-owned `community_profile` table via `leftJoin`, so the
  coupling already exists at the data level; reusing the resolver keeps the avatar-precedence logic in
  one place (DRY) rather than duplicating it in `Sessions`.

### Avatar fallback (Tasks 6/7)

Reuse the established failed-state pattern from `ProfileAvatar`: render an `<img>` and, on `onError`
(a snapshotted Discord/Steam URL can later 404), fall back to the deterministic initial — never a
broken image.

## File List

- `api/src/Sessions/Application/LeaderboardQueryInterface.php` — modified
- `api/src/Sessions/Application/LeaderboardQuery.php` — modified
- `api/src/Sessions/Infrastructure/DbalLeaderboardQuery.php` — modified
- `api/src/Sessions/Presentation/LeaderboardController.php` — modified
- `frontend/src/features/community/community-api.ts` — modified
- `frontend/src/features/community/leaderboard-client.tsx` — modified
- `frontend/src/features/auth/account-tabs.tsx` — modified
- tests — modified/added as needed

## Change Log

| Date | Change |
|------|--------|
| 2026-06-21 | Story created (bugs #7/#8 from HOTFIX-BACKLOG) |
