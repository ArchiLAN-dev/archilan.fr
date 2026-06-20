# Story 30.15: /communaute directory — browse, rank, recent, friends

Status: done (retroactively documented)

## Story

As a visitor,
I want a `/communaute` directory to browse members,
so that I can discover people by rank, find recently active members, and see my friends in one place. Deps:
30.5 (level/rank), 30.7 (friendships), 30.1 (public profile read).

A `CommunityDirectory` read model + query (browse with sort modes: rank / recent / friends), a
`/communaute` page, and a nav entry.

## Acceptance Criteria

1. `CommunityDirectoryQueryInterface` (Application) → paginated directory entries (slug, display name,
   avatar, level/rank, last-active), with sort modes: `rank` (by level/xp desc), `recent` (recently active),
   `friends` (the viewer's accepted friends).
2. The `friends` mode requires an authenticated viewer and returns only accepted friendships; `rank`/
   `recent` are public.
3. Only members with a publicly visible profile (per audience) appear in the public modes.
4. `CommunityDirectoryController` exposes the directory; the `/communaute` page renders the list with mode
   switching; a nav entry points to it.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain/read model:** `CommunityDirectory` (directory entry shape).
- [x] **api/ Application:** `CommunityDirectoryQueryInterface` (browse + sort modes + viewer-scoped friends).
- [x] **api/ Infrastructure:** `DbalCommunityDirectoryQuery` (DBAL QueryBuilder; joins level/rank +
      friendship + last-active; audience filter).
- [x] **api/ Presentation:** `CommunityDirectoryController`.
- [x] **api/ tests:** functional `CommunityDirectoryTest` (rank order, recent order, friends mode requires
      auth + returns only accepted, audience filtering).
- [x] **frontend:** `community-directory-api.ts` + `community-directory.tsx` + `/communaute` page; nav entry.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- Ranking reuses the level/xp model from 30.5 and friendships from 30.7 — the directory is a read/join over
  existing data, no new ranking computation stored.
- Audience visibility reuses the same profile-audience rule already applied by the profile read (30.1).

### Architecture guardrails
- Query-only: `CommunityDirectoryQueryInterface` in Application, `DbalCommunityDirectoryQuery` in
  Infrastructure using DBAL QueryBuilder (AC-A2) — no raw SQL, returns DTO/array, never an entity.
- The `friends` mode is viewer-scoped server-side (uses the authenticated user id), not a client filter.
- Controller is deserialize → call one query → serialize (AC-P3/P4): a single directory query per request.

### Scope boundaries / deviations
- No free-text search/filtering yet (browse + 3 sort modes only).
- "recent" activity is derived from existing activity/last-active data; no new tracking column.

### Project Structure Notes
- New api: `Community/Domain/CommunityDirectory` (read model),
  `Community/Application/CommunityDirectoryQueryInterface`,
  `Community/Infrastructure/DbalCommunityDirectoryQuery`,
  `Community/Presentation/CommunityDirectoryController`, `tests/Functional/CommunityDirectoryTest`.
- Modified api: `services.yaml` (query binding).
- New frontend: `features/community/{community-directory-api.ts,community-directory.tsx}`,
  `app/(public)/communaute/page.tsx`. Modified: `components/public-shell.tsx` (nav entry).

### References
- Epic §F/§I + story 30.15 (Track 4). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- `/communaute` directory with rank / recent / friends modes over the existing level + friendship data,
  audience-filtered, friends mode viewer-scoped.
- Implemented in commit `febbb8f`, merged via PR #156.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityDirectoryTest`); typecheck / lint / build / jest clean.

### File List

**Added (api)**
- `api/src/Community/Domain/CommunityDirectory.php`
- `api/src/Community/Application/CommunityDirectoryQueryInterface.php`
- `api/src/Community/Infrastructure/DbalCommunityDirectoryQuery.php`
- `api/src/Community/Presentation/CommunityDirectoryController.php`
- `api/tests/Functional/CommunityDirectoryTest.php`

**Modified (api)**
- `api/config/services.yaml`

**Added (frontend)**
- `frontend/src/features/community/community-directory-api.ts`
- `frontend/src/features/community/community-directory.tsx`
- `frontend/src/app/(public)/communaute/page.tsx`

**Modified (frontend)**
- `frontend/src/components/public-shell.tsx` (communauté nav entry)
