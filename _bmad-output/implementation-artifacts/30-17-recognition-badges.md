# Story 30.17: Member & admin recognition badges on the public profile

Status: done (retroactively documented)

## Story

As a visitor,
I want to see whether a member is an active adhérent and/or an admin on their public profile,
so that status and roles are recognisable at a glance. Deps: 30.1 (public profile read), Payments
(membership lookup).

Two recognition badges on the profile header: **Adhérent** (driven by a live membership lookup, never the
stale `ROLE_MEMBER`) and **Admin** (the `ROLE_ADMIN` role, display-only).

## Acceptance Criteria

1. The profile read exposes a `badges` block `{member: bool, admin: bool}`.
2. `member` is computed from a **live** membership query (`expires_at >= now`), never from `ROLE_MEMBER` on
   the user/JWT (CLAUDE.md AC-M1: `ROLE_MEMBER` is stale-prone and must not gate or signal membership).
3. `admin` reflects `ROLE_ADMIN` — used here only for display, which is the one permitted use of a role
   (AC-M3).
4. The profile header renders an "Adhérent" badge and/or an "Admin" badge when the respective flag is true;
   neither shows otherwise. Badges are readable against the banner.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Application:** `CommunityProfileView` resolves `badges`; `member` via the active-membership
      query, `admin` via the role; `CommunityProfileQueryInterface` exposes the data it needs.
- [x] **api/ Infrastructure:** `DbalCommunityProfileQuery` returns the role/identity columns feeding the
      badges.
- [x] **api/ tests:** `CommunityProfileTest` covers member-on/off (live) + admin-on/off.
- [x] **frontend:** `player-profile-api.ts` parses `badges`; `player-profile-page.tsx` renders the chips.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- Membership status reuses the existing live `ActiveMembership` query (Payments) — no new membership state,
  and no reliance on the persistent `ROLE_MEMBER`.

### Architecture guardrails
- **AC-M1/AC-M3 (CLAUDE.md):** `member` is a live `expires_at >= now` lookup; `ROLE_MEMBER` is never read.
  `ROLE_ADMIN` is read for display only.
- Badge computation stays in the Application read (`CommunityProfileView`); the controller only serialises.

### Scope boundaries / deviations
- Two badges only (adhérent, admin); no per-event/role taxonomy.
- Badges are public — they don't depend on the viewer.

### Project Structure Notes
- Modified api: `Community/Application/{CommunityProfileView,CommunityProfileQueryInterface}`,
  `Community/Infrastructure/DbalCommunityProfileQuery`, `tests/Functional/CommunityProfileTest`.
- Modified frontend: `features/players/{player-profile-api.ts,player-profile-page.tsx}`.

### References
- Epic §A + CLAUDE.md "Membership access control" (AC-M1/M3). [Source: api/CLAUDE.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Member/admin recognition badges on the public profile; `member` from a live membership query (not
  `ROLE_MEMBER`), `admin` display-only.
- Implemented in commit `b8a088a`.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityProfileTest`); typecheck / lint / build / jest clean.

### File List

**Modified (api)**
- `api/src/Community/Application/CommunityProfileView.php`
- `api/src/Community/Application/CommunityProfileQueryInterface.php`
- `api/src/Community/Infrastructure/DbalCommunityProfileQuery.php`
- `api/tests/Functional/CommunityProfileTest.php`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-api.ts`
- `frontend/src/features/players/player-profile-page.tsx`
