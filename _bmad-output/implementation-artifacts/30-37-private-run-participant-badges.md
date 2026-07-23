# Story 30.37: Participant badges on the private-run detail page

**Status:** done
**Epic:** 30 - Community
**Date:** 2026-07-20
**Issue:** #261

## Story

As a private-run owner or participant,
I want each participant on the run detail page to show their status badges (level, Admin, Adhérent, En jeu),
so that the participant list is coherent with the player profile and I can see who is a member / admin / currently playing.

## Context

The private-run detail page lists participants with identity only (avatar, name, joined date, slot count): `PersonalRunDrafts::getParticipants()` sources from `CommunityUserDirectoryQueryInterface::cards()`, which returns no membership/level/presence data, so the frontend has nothing to render (issue #261). Other surfaces show badges - the **player profile** header (`player-profile-page.tsx`) renders `Niv.`, `Admin`, `Adhérent`, and `En jeu` pills. This story brings that same badge row to the participant list.

### Decisions (the issue's "à préciser")

- **Badge set = the player-profile header row**: `Niv. {level}` + **Admin** (ROLE_ADMIN) + **Adhérent** (live membership) + **En jeu** (live presence). Achievements stay a profile-only section (out of scope, dedicated surface there).
- **Live sources, batch-resolved** (no N+1 over the participant list):
  - Adhérent = `ActiveMembershipQueryInterface` (AC-M2 live lookup; **not** the stale `ROLE_MEMBER`) - a new batch method is added.
  - Level = `CommunityLevelQuery::levelForMany` (existing batch).
  - En jeu = `CommunityPresenceQueryInterface::playing` (existing batch).
  - Admin = `User::getRoles()` on the already-loaded user entities.
- **Shared component**: factor the profile's inline pills into a reusable `PlayerBadges` and use it on the participant list. (The profile page keeps its own inline markup for now to bound risk; a follow-up can dedupe it.)

## Acceptance Criteria

1. `ActiveMembershipQueryInterface` gains a batch method `activeMemberIds(list<string> $userIds): list<string>` (the subset with an active, non-expired membership), implemented in `DbalActiveMembershipQuery` with a single `user_id IN (:ids)` query.
2. `PersonalRunDrafts::getParticipants()` returns, per participant, the existing identity fields plus `isMember: bool`, `isAdmin: bool`, `level: int`, `playing: bool`, all batch-resolved.
3. The run payload (`GET /runs/{runId}`, invite join, my-runs) carries the new participant fields (they flow through `payload()` unchanged).
4. Frontend `PersonalRunParticipant` type carries the new fields; the participant list renders a `PlayerBadges` row (level + Admin + Adhérent + En jeu) matching the profile styling. Graceful when a participant has no badges (just the level pill).
5. Gates green both sides.

## Tasks / Subtasks

- [x] **Task 1 - Membership batch query** (AC 1). Added `activeMemberIds` to `ActiveMembershipQueryInterface` + `DbalActiveMembershipQuery` (`user_id IN (:ids)`, `status=active AND expires_at>=now`) and to the `ActiveMembershipQuery` decorator.
- [x] **Task 2 - Enrich participants** (AC 2, 3). Injected `ActiveMembershipQueryInterface`, `CommunityLevelQuery`, `CommunityPresenceQueryInterface` into `PersonalRunDrafts`; `getParticipants()` returns `isMember`/`isAdmin`/`level`/`playing` (batch-resolved); both docblocks updated.
- [x] **Task 3 - Backend tests** (AC 1, 2). `PersonalRunInviteTest::testGetRunPayloadExposesParticipantBadges` (admin -> isAdmin, active membership -> isMember, defaults false / level 0); `PersonalRunDraftsListMineTest` updated for the new constructor.
- [x] **Task 4 - Frontend** (AC 4). New shared `PlayerBadges` component; `PersonalRunParticipant` extended; badges rendered in `ParticipantList`.
- [x] **Task 5 - Gates** (AC 5). `composer gates` (isolated 1583 tests / 10786 assertions) + `pnpm gates` (196 tests, clean build) green.

## Dev Notes

- `PersonalRunDrafts` already injects `UserRepositoryInterface` (for `isAdmin` via `getRoles()`) and `CommunityUserDirectoryQueryInterface`; cross-context Application-query injection is an established pattern here.
- `payload()` passes `'participants' => $participants` straight through, so no change is needed there beyond the docblock.
- Frontend guard `isPersonalRun` is loose (`Array.isArray(participants)`), so the new fields flow through from the API once added to the type - no per-field guard change.
- Member badge MUST use the live `ActiveMembershipQueryInterface`, never `ROLE_MEMBER` (AC-M1: stale-prone).

### Project Structure Notes

- `api/src/Membership/Application/Query/ActiveMembershipQueryInterface.php`
- `api/src/Membership/Infrastructure/Dbal/DbalActiveMembershipQuery.php`
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php`
- `api/tests/Functional/PersonalRunInviteTest.php`
- `frontend/src/features/community/player-badges.tsx` (new)
- `frontend/src/features/personal-runs/types.ts`
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`

### References

- [Source: GitHub issue #261]
- [Source: frontend/src/features/players/player-profile-page.tsx (badge row to mirror)]
- [Source: api/src/Community/Application/Query/CommunityProfileView.php (member/admin/level/presence resolution)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- Root cause was a payload gap, not a rendering bug: participants were sourced from `CommunityUserDirectoryQueryInterface::cards()` (identity only), so the frontend had no badge data to render.
- All four badges are batch-resolved over the participant user ids (one query each), so the list stays O(1) in queries: `activeMemberIds` (new), `levelForMany`, `playing`, plus `isAdmin` read off the already-loaded `User` entities.
- The member badge uses the live `ActiveMembershipQueryInterface` (AC-M2), never the stale `ROLE_MEMBER` (AC-M1).
- `payload()` already passed `participants` through untouched, so only its docblock changed.
- `CommunityLevelQuery` is `final` with no interface; the unit test builds a real one from two stubbed interfaces rather than introducing an interface just for testing.
- Achievements were deliberately left out of the participant row (profile-only surface); the profile header keeps its own inline pills for now - deduping it against `PlayerBadges` is a possible follow-up.

### File List

- `api/src/Membership/Application/Query/ActiveMembershipQueryInterface.php` (`activeMemberIds`)
- `api/src/Membership/Application/Query/ActiveMembershipQuery.php` (decorator passthrough)
- `api/src/Membership/Infrastructure/Dbal/DbalActiveMembershipQuery.php` (batch `IN (:ids)`)
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php` (enriched participants)
- `api/tests/Functional/PersonalRunInviteTest.php` (badge assertions + membership helper)
- `api/tests/Unit/PersonalRuns/PersonalRunDraftsListMineTest.php` (constructor update)
- `frontend/src/features/community/player-badges.tsx` (new shared component)
- `frontend/src/features/personal-runs/types.ts`
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-07-20 | Created + implemented. Participant rows on the private-run detail page now carry Niv./Admin/Adhérent/En jeu badges, batch-resolved from the live membership, level and presence queries. New `activeMemberIds` batch query and shared `PlayerBadges` component. Gates green both sides. Status -> done. |
