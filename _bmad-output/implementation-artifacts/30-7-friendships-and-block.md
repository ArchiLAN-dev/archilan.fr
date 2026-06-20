# Story 30.7: Friendships + block

Status: ready-for-review

## Story

As a member,
I want mutual friendships and the ability to block users,
so that I control who I'm connected to and who can see my friends-only profile. Deps: 30.3.

Opens Phase 2 (social). `Friendship` + `Block` aggregates, request/accept/decline/remove + block/unblock,
a friends list, and the `friend`/block tiers wired into `AudiencePolicy` enforcement everywhere.

## Acceptance Criteria

1. `Friendship` aggregate (request -> accept/decline), one canonical row per pair (unique `pairKey`);
   `accepted` = mutual. `Block` aggregate, unique per `(blocker, blocked)`.
2. Endpoints (auth): send request / accept / decline / remove (unfriend or cancel) / block / unblock /
   relationship / friends list. A pending request the other way round becomes a mutual accept; a declined
   one re-opens on a fresh request.
3. **Block is the strongest action**: it retracts any existing/pending friendship, and a blocked viewer
   (either direction) sees no social surface - it overrides every audience. The core profile stays public.
4. `AudiencePolicy` viewer tier now resolves `friend` (accepted friendship); `friends`-audience
   customization is visible to friends (and self), hidden from members/anonymous; block hides it regardless.
5. Profile shows a relationship action (add / pending / accept-decline / unfriend / unblock); `/compte`
   gains an "Amis" tab (friends, incoming requests with accept/decline, outgoing).
6. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `Friendship` (pairKey, request/accept/decline/reopen, involves/otherParty) +
      `Block`; `FriendshipRepositoryInterface` + `BlockRepositoryInterface`.
- [x] **api/ Migration:** `community_friendship` (unique pair + status indexes) + `community_block`.
- [x] **api/ Infrastructure:** Doctrine repos; `DbalCommunityUserDirectoryQuery` (slug→userId + user cards).
- [x] **api/ Application:** `FriendshipService` (request/accept/decline/remove/block/unblock + relationship
      + friends reads); `CommunityProfileView` resolves the `friend` tier and applies the block override.
- [x] **api/ Presentation:** `CommunityFriendshipController` (8 routes, slug-addressed + friendship-id).
- [x] **api/ tests:** unit `FriendshipTest`; functional `CommunityFriendshipTest` (request/accept →
      friend-audience unlock; block retracts + hides + overrides; self 422; auth 401).
- [x] **frontend:** `community-friends-api.ts`; `ProfileRelationshipActions` (profile header, client);
      `CommunityFriendsPanel` + "Amis" `/compte` tab; `hasNullableStringProp` reused.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- Slug→userId + friend cards (incl. cached avatar) come from one `DbalCommunityUserDirectoryQuery`
  (joins `user` + `community_profile`), keeping endpoints slug-addressed (public) while the domain works
  on user ids. Block override + friend tier slot into the existing `CommunityProfileView` gating.

### Architecture guardrails
- `AudiencePolicy` stays pure (static `canView(tier, audience)`); the tier (incl. `friend`) and the block
  check are resolved in Application and fed in. Enforced server-side on every read.
- `Friendship.pairKey` (sorted pair) + a unique index guarantee a single row per pair regardless of
  direction; the controller is thin (deserialize → resolve slug → one service call → serialize).

### Scope boundaries / deviations
- No notifications when a request is received/accepted yet (in-app center = 30.12); the requester/addressee
  discover state via the profile button / "Amis" tab. The activity feed (30.8/30.9) and friend-gated
  comments (30.10) build on the `friend` tier added here.
- A blocked viewer still sees the public core (identity + stats + achievements + level) per epic parity;
  only the social/customization surface is hidden.

### Project Structure Notes
- New api: `Community/Domain/{Friendship,Block,*RepositoryInterface}`,
  `Community/Application/{FriendshipService,CommunityUserDirectoryQueryInterface}`,
  `Community/Infrastructure/{DoctrineFriendshipRepository,DoctrineBlockRepository,DbalCommunityUserDirectoryQuery}`,
  `Community/Presentation/CommunityFriendshipController`, migration, unit+functional tests.
- Modified: `CommunityProfileView` (friend tier + block override), `services.yaml` (5 bindings).
- New frontend: `features/community/{community-friends-api.ts,profile-relationship-actions.tsx,community-friends-panel.tsx}`.
  Modified: `player-profile-page.tsx` (relationship actions), `account-tabs.tsx` ("Amis" tab).

### References
- Epic §C/§G + story 30.7 (Track 3). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Mutual friendships + block with the block-overrides-everything semantics; `friend` tier wired into the
  audience policy so `friends`-only customization unlocks for accepted friends.
- Profile relationship button (client, self-hides for anonymous/self/blocked) + an "Amis" tab managing
  friends and incoming/outgoing requests.
- Deviations: no notifications yet (30.12); blocked viewers keep the public core.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1155 tests, 0 notices
  (incl. `FriendshipTest` + `CommunityFriendshipTest`).
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Domain/Friendship.php`
- `api/src/Community/Domain/Block.php`
- `api/src/Community/Domain/FriendshipRepositoryInterface.php`
- `api/src/Community/Domain/BlockRepositoryInterface.php`
- `api/src/Community/Application/FriendshipService.php`
- `api/src/Community/Application/CommunityUserDirectoryQueryInterface.php`
- `api/src/Community/Infrastructure/DoctrineFriendshipRepository.php`
- `api/src/Community/Infrastructure/DoctrineBlockRepository.php`
- `api/src/Community/Infrastructure/DbalCommunityUserDirectoryQuery.php`
- `api/src/Community/Presentation/CommunityFriendshipController.php`
- `api/migrations/Version20260618120000.php`
- `api/tests/Unit/Community/FriendshipTest.php`
- `api/tests/Functional/CommunityFriendshipTest.php`

**Modified (api)**
- `api/src/Community/Application/CommunityProfileView.php` (friend tier + block override)
- `api/config/services.yaml` (friendship/block/directory bindings)

**Added (frontend)**
- `frontend/src/features/community/community-friends-api.ts`
- `frontend/src/features/community/profile-relationship-actions.tsx`
- `frontend/src/features/community/community-friends-panel.tsx`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-page.tsx` (relationship actions in header)
- `frontend/src/features/auth/account-tabs.tsx` ("Amis" tab)
