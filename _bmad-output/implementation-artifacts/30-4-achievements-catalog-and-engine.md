# Story 30.4: Achievement catalog + deterministic engine + recompute

Status: ready-for-review

## Story

As a member,
I want achievements that unlock automatically from my play history,
so that my profile celebrates what I've accomplished.

Code-defined `AchievementDefinition` catalog, persisted `AchievementGrant` records, a deterministic
evaluator over the existing Epic-18 read models, a `community:achievements:recompute` command, and
unlocked/locked surfacing on the profile. Deps: 30.1.

## Acceptance Criteria

1. A code-defined `AchievementCatalog` (no DB table) of `AchievementDefinition`s, each a threshold over a
   derived metric (`runs`/`goals`/`checks`/`items`/`distinctGames`); unique keys.
2. `AchievementGrant` records (unique per `(user, key)`) persist unlocks. `RecomputeAchievements` derives a
   user's metrics (reusing `PlayerStatsQueryInterface` + `PlayerHistoryQueryInterface`), grants newly
   unlocked definitions, and is **monotonic** (only adds, never revokes) and **idempotent**.
3. `community:achievements:recompute [userId]` recomputes one user or all (via a user-ids query),
   off the request path.
4. The public profile read surfaces all catalog achievements with `unlocked` + `unlockedAt` (locked ones
   included); achievements are public (like aggregate stats).
5. The profile page renders a Succès section (unlocked highlighted, locked dimmed, unlock date, X/Y count).
6. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `AchievementDefinition` + `AchievementMetrics` (metric selector) + `AchievementCatalog`
      (code-defined) + `AchievementGrant` entity + `AchievementGrantRepositoryInterface`.
- [x] **api/ Migration:** `community_achievement_grant` (unique `(user_id, achievement_key)` + user index).
- [x] **api/ Application:** `RecomputeAchievements` (metrics → catalog → grant new, monotonic/idempotent);
      `CommunityUserIdsQueryInterface` for the all-users pass.
- [x] **api/ Infrastructure:** `DoctrineAchievementGrantRepository`, `DbalCommunityUserIdsQuery`.
- [x] **api/ Presentation:** `community:achievements:recompute` command (optional userId arg).
- [x] **api/ read:** `CommunityProfileView` composes the catalog + the user's grants into `achievements`.
- [x] **api/ tests:** unit (`RecomputeAchievementsTest`: catalog uniqueness, unlock + monotonic/idempotent,
      higher metrics unlock more) + functional (`CommunityAchievementsTest`: profile surfaces unlocked/locked).
- [x] **frontend:** `Achievement` type + guard on the public read; Succès section on the profile page.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Metrics derive entirely from Epic-18 reads (`DbalPlayerStatsQuery` + `DbalPlayerHistoryQuery`); the slot
  row already carries everything. Distinct-games is counted from history rows.
- Achievements are public (composed alongside stats), not audience-gated.

### Architecture guardrails
- Catalog/definitions/metrics are pure Domain (no deps). The evaluator (Application) injects read-model
  interfaces only; grants persist via a Domain repository interface (Doctrine in Infrastructure).
- Monotonic recompute (review #12): a grant once earned is never removed, even if a later stat
  invalidation (forfeit / `was_released`) would lower the underlying count.

### Scope boundaries / deviations
- Recompute is **command/scheduled**, off the request path (epic §E.1) - the profile shows grants from the
  last pass; a brand-new user's achievements appear after the next recompute. No Symfony Scheduler entry
  added here (wire it in ops alongside `community:avatars:refresh`). The write-site freshness dispatch
  (epic §E.2 `RunOutcomeRecorded`) lands with the feed (30.8).
- Level/XP (30.5) and showcases (30.6) build on this catalog.

### Project Structure Notes
- New api: `Community/Domain/{AchievementDefinition,AchievementMetrics,AchievementCatalog,AchievementGrant,AchievementGrantRepositoryInterface}`,
  `Community/Application/{RecomputeAchievements,CommunityUserIdsQueryInterface}`,
  `Community/Infrastructure/{DoctrineAchievementGrantRepository,DbalCommunityUserIdsQuery}`,
  `Community/Presentation/RecomputeAchievementsCommand`, migration, unit+functional tests.
- Modified: `CommunityProfileView` (achievements in payload), `services.yaml` (2 bindings).
- Frontend: `player-profile-api.ts` (+ Achievement type/guard), `player-profile-page.tsx` (Succès section).

### References
- Epic §C/§E.1 + story 30.4. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Deterministic, monotonic, idempotent achievement engine over the Epic-18 read models; 9-achievement
  starter catalog (runs / goals / checks / items / distinct-games thresholds).
- `community:achievements:recompute` recomputes one or all users; grants persist and surface on the
  profile (unlocked/locked, public).
- Deviation: recompute is off-path (command/scheduled), not lazy-on-view; write-site freshness deferred to
  the feed story.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1143 tests, 0 notices
  (incl. `RecomputeAchievementsTest` + `CommunityAchievementsTest`).
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Domain/AchievementDefinition.php`
- `api/src/Community/Domain/AchievementMetrics.php`
- `api/src/Community/Domain/AchievementCatalog.php`
- `api/src/Community/Domain/AchievementGrant.php`
- `api/src/Community/Domain/AchievementGrantRepositoryInterface.php`
- `api/src/Community/Application/RecomputeAchievements.php`
- `api/src/Community/Application/CommunityUserIdsQueryInterface.php`
- `api/src/Community/Infrastructure/DoctrineAchievementGrantRepository.php`
- `api/src/Community/Infrastructure/DbalCommunityUserIdsQuery.php`
- `api/src/Community/Presentation/RecomputeAchievementsCommand.php`
- `api/migrations/Version20260618100000.php`
- `api/tests/Unit/Community/RecomputeAchievementsTest.php`
- `api/tests/Functional/CommunityAchievementsTest.php`

**Modified (api)**
- `api/src/Community/Application/CommunityProfileView.php` (achievements in the read model)
- `api/config/services.yaml` (grant repo + user-ids query bindings)

**Modified (frontend)**
- `frontend/src/features/players/player-profile-api.ts` (Achievement type + guard + mapping)
- `frontend/src/features/players/player-profile-page.tsx` (Succès section)
