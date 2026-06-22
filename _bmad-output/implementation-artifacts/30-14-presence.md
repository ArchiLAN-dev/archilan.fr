# Story 30.14: "Currently playing" presence on profile + feed

Status: done (retroactively documented)

## Story

As a visitor,
I want to see when a member is currently playing (and what),
so that the profile and the community feed feel live. Deps: 30.1 (profile read), Sessions/Realtime (live
session state).

A read-only presence query over live session state, surfaced as an "En jeu · <game>" indicator on the
profile header and on feed entries, with a batch lookup so the feed stays a single query.

## Acceptance Criteria

1. `CommunityPresenceQueryInterface` (Application) → `{playing, sessionId, game}` for a user, derived from
   currently-running sessions; a batch variant resolves presence for many users at once (feed).
2. The profile read (`CommunityProfileView`) includes a `presence` block; offline = `{playing:false,
   sessionId:null, game:null}`.
3. The community feed (`CommunityFeedQuery`) annotates entries with the author's presence without an N+1
   (one batched presence query per page).
4. Frontend: profile header shows a live "playing" indicator (optionally linking to the session); feed
   entries show the same; offline members show nothing.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Application:** `CommunityPresenceQueryInterface` (single + batch).
- [x] **api/ Infrastructure:** `DbalCommunityPresenceQuery` (DBAL QueryBuilder over the session tables;
      maps running sessions → user presence).
- [x] **api/ Application wiring:** `CommunityProfileView` + `CommunityFeedQuery` consume the presence query.
- [x] **api/ tests:** functional `CommunityPresenceTest` (playing vs offline; batch resolves a mix).
- [x] **frontend:** presence indicator on the profile header (`player-profile-page.tsx` /
      `player-profile-api.ts`) and feed (`community-activity.tsx` / `community-feed-api.ts`).
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Presence is **derived** from existing session state via a read query - no new "presence" table, no
  heartbeat writes. A running session for a user = playing.
- The feed reuses one batched presence lookup keyed by the page's author ids, mirroring how kudos counts are
  folded into the feed query (30.11).

### Architecture guardrails
- Read-only: `CommunityPresenceQueryInterface` is a query interface in Application, implemented with DBAL
  QueryBuilder in Infrastructure (AC-A2) - no raw SQL, no entity returned.
- No clock/randomness in Application; "currently running" is determined by the session read query itself.

### Scope boundaries / deviations
- Presence reflects live sessions only (not a generic "online/last seen"); no websocket presence channel -
  it's resolved on each profile/feed read.
- Respects profile audience: presence is shown wherever the profile/feed is already visible to the viewer.

### Project Structure Notes
- New api: `Community/Application/CommunityPresenceQueryInterface`,
  `Community/Infrastructure/DbalCommunityPresenceQuery`, `tests/Functional/CommunityPresenceTest`.
- Modified api: `Community/Application/{CommunityProfileView,CommunityFeedQuery}`, `services.yaml`.
- Modified frontend: `features/players/{player-profile-page.tsx,player-profile-api.ts}`,
  `features/community/{community-activity.tsx,community-feed-api.ts}`.

### References
- Epic §F/§I + story 30.14 (Track 4). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- "Currently playing" presence derived from live sessions, surfaced on profile + feed with a batched lookup
  to avoid N+1.
- Implemented in commit `f4ca8be`, merged via PR #155.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityPresenceTest`); typecheck / lint / build / jest clean.

### File List

**Added (api)**
- `api/src/Community/Application/CommunityPresenceQueryInterface.php`
- `api/src/Community/Infrastructure/DbalCommunityPresenceQuery.php`
- `api/tests/Functional/CommunityPresenceTest.php`

**Modified (api)**
- `api/src/Community/Application/CommunityProfileView.php`
- `api/src/Community/Application/CommunityFeedQuery.php`
- `api/config/services.yaml`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-page.tsx`
- `frontend/src/features/players/player-profile-api.ts`
- `frontend/src/features/community/community-activity.tsx`
- `frontend/src/features/community/community-feed-api.ts`
