# Story 30.5: Level & XP

Status: ready-for-review

## Story

As a member,
I want a Steam-style level and XP on my profile,
so that my overall activity is captured in one progression number.

Defines the **canonical XP formula** (the single ranking source the 30.15 directory must reuse) and a
level curve, surfaced as a level badge + XP progress bar. Deps: 30.4.

## Acceptance Criteria

1. `CommunityXp::compute(goals, checks, runs, achievementsUnlocked)` is the canonical, deterministic XP
   formula, derived from the **same components as the Epic-18 leaderboard** (goals + checks are its axes)
   plus participation + achievements - no competing score (review #8).
2. `Level::fromXp(xp)` maps XP to a level on a gently accelerating curve (cost L→L+1 = 100·(L+1)),
   exposing `level`, `xpIntoLevel`, `xpForNextLevel`; pure, clamps negatives, capped.
3. The profile read exposes `level` (`level`, `xp`, `xpIntoLevel`, `xpForNextLevel`); public like stats.
4. The profile shows a level badge ("Niv. N") and an XP progress bar.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `CommunityXp` (canonical formula, weights as consts) + `Level` (pure curve VO).
- [x] **api/ read:** `CommunityProfileView` computes XP from the profile's stats + unlocked-achievement
      count and adds `level` to the payload.
- [x] **api/ tests:** `LevelTest` (curve boundaries, negative clamp, XP formula weights).
- [x] **api/ functional:** level/XP assertions in `CommunityAchievementsTest` (2 grants → 200 XP → level 1).
- [x] **frontend:** `ProfileLevel` type + guard; level badge + XP bar (`LevelBar`) in the profile header.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent / single ranking source
- XP weights mirror the leaderboard's primacy of **goals** (500) then **checks** (1 each), + runs (50) +
  achievements (100). This is THE score; 30.15 "top players" ranks on it (or the leaderboard) - one source
  of truth, not two. [Source: api/src/Sessions/Infrastructure/DbalLeaderboardQuery.php (goals/checks axes)]
- No new query/table: XP is computed in the read facade from already-composed stats + achievement grants.

### Architecture guardrails
- `CommunityXp` + `Level` are pure Domain (static/readonly, no deps). The level loop is bounded
  (MAX_LEVEL) so a pathological XP can't spin.

### Scope boundaries
- XP/level are derived on read (cheap, deterministic) - no persistence. If the directory (30.15) needs to
  sort many users by XP efficiently, it should compute the same formula in a dedicated list query rather
  than composing full profiles (review #13) - deferred to 30.15.

### Project Structure Notes
- New api: `Community/Domain/{CommunityXp,Level}`, `tests/Unit/Community/LevelTest`.
- Modified: `CommunityProfileView` (level in payload), `CommunityAchievementsTest` (level assertions).
- Frontend: `player-profile-api.ts` (+ ProfileLevel type/guard), `player-profile-page.tsx` (badge + bar).

### References
- Epic story 30.5 + review #8. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Canonical XP (goals/checks/runs/achievements) + a Steam-style level curve, surfaced as a badge + XP bar.
  Computed on read from existing stats + grants, no persistence.
- Documented as the single ranking source for the 30.15 directory.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1147 tests, 0 notices
  (incl. `LevelTest` + level assertions in `CommunityAchievementsTest`).
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Domain/CommunityXp.php`
- `api/src/Community/Domain/Level.php`
- `api/tests/Unit/Community/LevelTest.php`

**Modified (api)**
- `api/src/Community/Application/CommunityProfileView.php` (level/XP in the read model)
- `api/tests/Functional/CommunityAchievementsTest.php` (level assertions)

**Modified (frontend)**
- `frontend/src/features/players/player-profile-api.ts` (ProfileLevel type + guard + mapping)
- `frontend/src/features/players/player-profile-page.tsx` (level badge + XP bar)
