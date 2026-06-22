# Story 30.11: Kudos (likes) on runs and achievements

Status: done (retroactively documented)

## Story

As a member,
I want to give kudos (a like) on other members' runs and unlocked achievements,
so that the community can encourage and react to each other. Deps: 30.4 (achievements), 30.9 (feed runs).

A single `Kudos` reaction model keyed by `(actor, target_type, target_id)`, an idempotent toggle, a
self-kudos guard, and counts + viewer-state surfaced on the profile achievements and the run feed.

## Acceptance Criteria

1. `Kudos` aggregate: one reaction per `(actor_id, target_type, target_id)` (unique), `target_type ∈
   {run, achievement}` (run = an activity-feed run entry; achievement = an achievement grant). Toggling is
   idempotent - a second toggle removes it.
2. A member **cannot** kudos their own run/achievement (server-enforced); the early-click toggle is guarded
   so a click before the viewer's given-state has loaded can't desync the count (review fix).
3. Endpoints (member-gated): toggle (`{targetType, targetId}` → `{given, count}`), and a batch state query
   (`targets[]` → per-target `{given, count}`) for rendering many buttons at once. 422 on an invalid target
   type; 422 when targeting your own content.
4. The profile achievements grid and the run feed entries each show a kudos button + count, reflecting the
   viewer's given-state; counts come from the read models (no N+1).
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `Kudos` (+ `grant`, target constants) + `KudosRepositoryInterface`
      (`countsFor`, viewer-given lookup, `ownerOf` for the self-kudos guard).
- [x] **api/ Application:** `KudosService` (toggle + state; self-kudos rejection); `CommunityProfileView` /
      `CommunityFeedQuery` expose `grantId`/`kudosCount` per item.
- [x] **api/ Infrastructure:** `DoctrineKudosRepository`.
- [x] **api/ Presentation:** `CommunityKudosController` (toggle + state).
- [x] **api/ Migration:** `community_kudos` (unique `actor_id,target_type,target_id`; index on target).
- [x] **api/ tests:** functional `CommunityKudosTest` (toggle on/off, count, self-kudos 422, state for two
      viewers, invalid target).
- [x] **frontend:** `community-kudos-api.ts` + `kudos-button.tsx`; integrated into `profile-achievements.tsx`
      and the run feed (`community-activity.tsx` / `community-feed-api.ts`); profile + feed wiring.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Kudos targets reuse existing ids: a run = an `ActivityEntry` id (30.8/30.9), an achievement = an
  `AchievementGrant` id (30.4). No new identifier scheme.
- Counts are folded into the existing profile/feed read queries (`countsFor` over a batch of ids) rather
  than a per-item request.

### Architecture guardrails
- Kudos are peer-only: the owner's own view suppresses the kudos target (you can't kudos yourself), enforced
  server-side via `ownerOf`, not just hidden in the UI.
- The toggle button stays disabled until the viewer's given-state is known, so an early click can't toggle
  off a kudos that was already given (review #).

### Scope boundaries / deviations
- No notification on receiving kudos yet - that lands with the notification center (30.12).
- Only two target types (run, achievement); comments/profiles are not kudos-able.

### Project Structure Notes
- New api: `Community/Domain/{Kudos,KudosRepositoryInterface}`, `Community/Application/KudosService`,
  `Community/Infrastructure/DoctrineKudosRepository`, `Community/Presentation/CommunityKudosController`,
  migration `Version20260618150000`, `tests/Functional/CommunityKudosTest`.
- Modified api: `CommunityProfileView`, `CommunityFeedQuery` (kudos count + grant id per item),
  `services.yaml` (repo binding).
- New frontend: `features/community/{community-kudos-api.ts,kudos-button.tsx}`. Modified:
  `profile-achievements.tsx`, `community-activity.tsx`, `community-feed-api.ts`, `player-profile-api.ts`,
  `player-profile-page.tsx`.

### References
- Epic §C/§I + story 30.11 (Track 4). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Idempotent kudos toggle on runs + achievements with a batch state endpoint; self-kudos rejected
  server-side; early-click toggle guarded (review fix `ad00776`).
- Implemented in commit `ef6d82f` (+ review fix `ad00776`), merged via PR #152.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityKudosTest`); typecheck / lint / build / jest clean.

### File List

**Added (api)**
- `api/src/Community/Domain/Kudos.php`
- `api/src/Community/Domain/KudosRepositoryInterface.php`
- `api/src/Community/Application/KudosService.php`
- `api/src/Community/Infrastructure/DoctrineKudosRepository.php`
- `api/src/Community/Presentation/CommunityKudosController.php`
- `api/migrations/Version20260618150000.php`
- `api/tests/Functional/CommunityKudosTest.php`

**Modified (api)**
- `api/src/Community/Application/CommunityProfileView.php`
- `api/src/Community/Application/CommunityFeedQuery.php`
- `api/config/services.yaml` (kudos repository binding)

**Added (frontend)**
- `frontend/src/features/community/community-kudos-api.ts`
- `frontend/src/features/community/kudos-button.tsx`

**Modified (frontend)**
- `frontend/src/features/community/profile-achievements.tsx`
- `frontend/src/features/community/community-activity.tsx`
- `frontend/src/features/community/community-feed-api.ts`
- `frontend/src/features/players/player-profile-api.ts`
- `frontend/src/features/players/player-profile-page.tsx`
