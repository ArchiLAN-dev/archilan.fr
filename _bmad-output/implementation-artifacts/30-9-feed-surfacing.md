# Story 30.9: Feed surfacing (own + friends')

Status: ready-for-review

## Story

As a member,
I want to see my own and my friends' recent activity,
so that the community feels alive between LANs. Deps: 30.7, 30.8.

A profile "Activité récente" section + a friends' feed in `/compte`, audience-filtered server-side and
paginated.

## Acceptance Criteria

1. `CommunityFeedQuery.forActor(actorId, viewerId, limit, before)` returns one actor's activity, gated by
   a single `canSee` check (block override + audience vs the viewer's tier); `feed(viewerId, …)` returns
   the viewer's own + friends' activity.
2. Visibility is resolved **at read** against each actor's current profile audience (never stored on the
   entry); a blocked viewer sees nothing; pagination via `before` (occurredAt cursor) + a clamped limit.
3. `GET /community/profiles/{slug}/activity` (optional viewer) + `GET /community/feed` (auth) return
   rendered items: `run_finished` (game/event/sessionId → link to the run results) and `friendship`
   (resolved counterpart slug/name); feed items carry the actor card.
4. The profile shows an "Activité récente" section (client-fetched, audience-gated); `/compte` gains an
   "Activité" tab with the friends' feed.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ read:** extend `ActivityEntryRepositoryInterface::recentForActors` with a `before` cursor;
      `CommunityFeedQuery` (forActor + feed) resolving visibility per actor (tier + block + audience) and
      presenting items (batch-resolve actor + friendship-counterpart cards).
- [x] **api/ Presentation:** `CommunityFeedController` (`/profiles/{slug}/activity`, `/feed`).
- [x] **api/ tests:** functional `CommunityFeedTest` (friends' feed shows a friend's entry; profile
      activity respects the friends audience; feed needs auth) + repo `before` in the unit double.
- [x] **frontend:** `community-feed-api.ts` (typed items + guards); `community-activity.tsx`
      (`ProfileActivity` + `CommunityFeedPanel` + shared row); profile "Activité récente" section + the
      `/compte` "Activité" tab.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent / the key simplification
- The friends' feed actor set is `self + accepted friends`, so every actor is at `self`/`friend` tier →
  all their entries pass any audience; **no per-entry gating needed there**. The only real gate is the
  **profile activity tab** (one actor → one `canSee` check). This keeps the read cheap and correct.
- `canSee`/`viewerTier` mirror `CommunityProfileView` (block override + live `IS_MEMBER`, friend tier);
  cards (actor + friendship counterpart) come from the 30.7 `DbalCommunityUserDirectoryQuery` in one call.

### Architecture guardrails
- Visibility resolved at read, never stored on the entry (epic §H/review #2): changing a profile to
  `friends` retroactively hides its past activity from non-friends.
- Profile activity is client-fetched (the SSR profile page is rendered without the viewer's cookie), so
  the audience gate runs server-side per request with the real viewer.

### Scope boundaries / deviations
- **Mercure live feed updates deferred** (epic marks them optional) - the feed loads on view; realtime push
  can be layered in later (reuse `RealtimePublisher`).
- Pagination is `before`-cursor + limit; the UI loads the first page (no "load more" button yet).

### Project Structure Notes
- New api: `Community/Application/CommunityFeedQuery`, `Community/Presentation/CommunityFeedController`,
  functional test. Modified: `ActivityEntryRepositoryInterface` + Doctrine impl (+ unit double) for `before`.
- New frontend: `features/community/{community-feed-api.ts,community-activity.tsx}`. Modified:
  `player-profile-page.tsx` (Activité section), `account-tabs.tsx` ("Activité" tab).

### References
- Epic §H + story 30.9 (Track 3). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Feed read gates per actor (block + audience + tier); friends' feed needs no per-entry gating (all
  friend/self). Items rendered with resolved cards + run/friendship specifics. Profile "Activité récente"
  + `/compte` "Activité" tab.
- Deviation: Mercure live updates deferred (optional per the epic); first-page load only.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1161 tests, 0 notices
  (incl. `CommunityFeedTest`).
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Application/CommunityFeedQuery.php`
- `api/src/Community/Presentation/CommunityFeedController.php`
- `api/tests/Functional/CommunityFeedTest.php`

**Modified (api)**
- `api/src/Community/Domain/ActivityEntryRepositoryInterface.php` (`before` cursor)
- `api/src/Community/Infrastructure/DoctrineActivityEntryRepository.php` (`before`)
- `api/tests/Unit/Community/ActivityFeedTest.php` (double signature)

**Added (frontend)**
- `frontend/src/features/community/community-feed-api.ts`
- `frontend/src/features/community/community-activity.tsx`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-page.tsx` ("Activité récente" section)
- `frontend/src/features/auth/account-tabs.tsx` ("Activité" tab)
