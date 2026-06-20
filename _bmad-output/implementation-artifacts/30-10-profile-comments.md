# Story 30.10: Profile comments (guestbook)

Status: ready-for-review

## Story

As a member,
I want to leave comments on profiles (and report/remove abusive ones),
so that the community can interact on each other's pages. Deps: 30.7.

`ProfileComment` + `ContentReport`, audience-aware viewing, member-gated writing, soft-hide, report, and a
simple per-author rate limit.

## Acceptance Criteria

1. `ProfileComment` (soft-hidden, not hard-deleted, on owner removal; authors hard-delete their own) +
   `ContentReport` (one per reporter+target, feeds the 30.13 queue).
2. **Two audiences (review #6):** *viewing* follows the profile audience (`ProfileVisibility`); *writing*
   requires a live member (or the owner) who isn't blocked.
3. Endpoints: list (gated, 403 if not visible), post (422/403/429), delete (author hard-delete / owner
   hide), report (idempotent). Per-author rate limit (5 / 60s).
4. Profile shows a comments section: list + post form (logged-in) + delete (author/owner) + report.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Application:** extracted `ProfileVisibility` (shared block/audience/tier gate);
      `ProfileCommentService` (list/post/delete/report + rate limit).
- [x] **api/ Domain:** `ProfileComment` (+ hide/isAuthor/isOnProfileOf) + repo; `ContentReport` (+ resolve)
      + repo.
- [x] **api/ Migration:** `community_profile_comment` + `community_content_report` (unique reporter+target).
- [x] **api/ Infrastructure:** Doctrine repos (visibleForProfile, countByAuthorSince, report exists).
- [x] **api/ Presentation:** `CommunityCommentController` (list/post/delete/report).
- [x] **api/ tests:** unit `ProfileCommentTest`; functional `CommunityCommentTest` (owner post/list/delete,
      non-member 403, friends-audience list 403, report idempotent, rate limit 429).
- [x] **frontend:** `community-comments-api.ts` + `ProfileComments` (client) on the profile.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- `ProfileVisibility` (new) centralises the block + audience + tier gate (live `IS_MEMBER`, friend tier);
  the comments read reuses it. (The profile read + feed keep their own copies for now - candidate to
  converge onto `ProfileVisibility` in a later cleanup.)
- Author/owner cards come from the 30.7 `DbalCommunityUserDirectoryQuery`.

### Architecture guardrails
- Soft-hide preserves a trace for moderation (30.13). Rate limit is a simple DB count
  (`countByAuthorSince`) - no new dependency (avoids a `symfony/rate-limiter` composer change).
- Writing requires a **live** membership (`ActiveMembershipQueryInterface`), never `ROLE_MEMBER`.

### Scope boundaries / deviations
- Owner "tighten comments to friends-only" toggle deferred (epic mentions it) - writing is members-or-owner
  for now; documented follow-up.
- Admin moderation panel (resolve reports, restore) = 30.13; 30.10 only *creates* reports + owner hide.
- No comment notification yet (30.12).

### Project Structure Notes
- New api: `Community/Application/{ProfileVisibility,ProfileCommentService}`,
  `Community/Domain/{ProfileComment,ProfileCommentRepositoryInterface,ContentReport,ContentReportRepositoryInterface}`,
  `Community/Infrastructure/{DoctrineProfileCommentRepository,DoctrineContentReportRepository}`,
  `Community/Presentation/CommunityCommentController`, migration, unit+functional tests.
- Modified: `services.yaml` (2 bindings).
- New frontend: `features/community/{community-comments-api.ts,profile-comments.tsx}`. Modified:
  `player-profile-page.tsx` (comments section).

### References
- Epic §C/§I + story 30.10 (Track 4). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Guestbook with two audiences (view = profile audience, write = member/owner, not blocked), soft-hide,
  idempotent report (feeds 30.13), and a DB-count rate limit. Profile comments section with post/delete/report.
- Deviations: owner friends-only comment toggle deferred; admin moderation + comment notifications later.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1168 tests, 0 notices
  (incl. `ProfileCommentTest` + `CommunityCommentTest`).
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Application/ProfileVisibility.php`
- `api/src/Community/Application/ProfileCommentService.php`
- `api/src/Community/Domain/ProfileComment.php`
- `api/src/Community/Domain/ProfileCommentRepositoryInterface.php`
- `api/src/Community/Domain/ContentReport.php`
- `api/src/Community/Domain/ContentReportRepositoryInterface.php`
- `api/src/Community/Infrastructure/DoctrineProfileCommentRepository.php`
- `api/src/Community/Infrastructure/DoctrineContentReportRepository.php`
- `api/src/Community/Presentation/CommunityCommentController.php`
- `api/migrations/Version20260618140000.php`
- `api/tests/Unit/Community/ProfileCommentTest.php`
- `api/tests/Functional/CommunityCommentTest.php`

**Modified (api)**
- `api/config/services.yaml` (comment + report repository bindings)

**Added (frontend)**
- `frontend/src/features/community/community-comments-api.ts`
- `frontend/src/features/community/profile-comments.tsx`

**Modified (frontend)**
- `frontend/src/features/players/player-profile-page.tsx` (comments section)
