# Story 30.13: Admin moderation panel + privacy page

Status: done (retroactively documented)

## Story

As an admin,
I want a moderation panel to review reported community content and hide/restore profile comments,
so that abuse on profiles can be handled; and as a member, I want a privacy page explaining what community
data is shown and how to control it. Deps: 30.10 (profile comments + content reports).

A `ModerationService` over the existing content-report + profile-comment models, an admin dashboard
(report queue → resolve / hide / restore), and a public `/confidentialite` privacy page.

## Acceptance Criteria

1. `ModerationService` (admin-gated): list the open report queue (reporter, target, reason, created_at),
   resolve a report, hide a profile comment, restore a hidden comment. Hiding is soft (a `hidden_at` /
   moderation flag on `ProfileComment`), not a hard delete - restorable.
2. A hidden comment disappears from the public profile read but remains visible to moderators in the queue.
3. `AdminModerationController` exposes the queue + actions; all actions require `ROLE_ADMIN`.
4. Admin UI: a moderation dashboard reachable from the admin nav; a member-facing `/confidentialite`
   privacy page describing community data + controls.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Application:** `ModerationService` (queue, resolve, hide, restore).
- [x] **api/ Domain:** extend `ProfileComment` (hide/restore state) + `ProfileCommentRepositoryInterface`
      (exclude hidden from public read, fetch for moderation) + `ContentReportRepositoryInterface` (queue,
      resolve).
- [x] **api/ Infrastructure:** `DoctrineProfileCommentRepository` / `DoctrineContentReportRepository`
      updated for the new reads.
- [x] **api/ Presentation:** `AdminModerationController` (queue / resolve / hide / restore).
- [x] **api/ tests:** functional `AdminModerationTest` (queue, resolve, hide hides from public read, restore).
- [x] **frontend:** `admin-moderation-api.ts` + `admin-moderation-dashboard.tsx` + `/admin/moderation` page;
      admin nav entry; public `/confidentialite` page.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Built entirely on the 30.10 content-report + profile-comment models - no new report entity, the panel is a
  read/act layer over what already exists.
- Hiding reuses a soft-delete flag on `ProfileComment` rather than a separate "moderation log" table.

### Architecture guardrails
- Admin-only actions are gated with `ROLE_ADMIN` (display/admin context - allowed; this is not membership
  access, so the `ROLE_MEMBER` ban in CLAUDE.md does not apply here).
- Hiding is soft + restorable so a moderation mistake is reversible and the audit trail (the report) stays.
- The public profile read filters hidden comments at the repository level, not in the controller.

### Scope boundaries / deviations
- Comments only - moderation of runs/achievements/kudos is out of scope.
- No automated moderation / word filters; the privacy page is informational (no new data-export endpoint).

### Project Structure Notes
- New api: `Community/Application/ModerationService`, `Community/Presentation/AdminModerationController`,
  `tests/Functional/AdminModerationTest`.
- Modified api: `Community/Domain/ProfileComment`, `Community/Domain/{ProfileCommentRepositoryInterface,
  ContentReportRepositoryInterface}`, `Community/Infrastructure/{DoctrineProfileCommentRepository,
  DoctrineContentReportRepository}`, `services.yaml`.
- New frontend: `features/admin/{admin-moderation-api.ts,admin-moderation-dashboard.tsx}`,
  `app/(admin)/admin/moderation/page.tsx`, `app/(public)/confidentialite/page.tsx`. Modified:
  `components/admin-shell.tsx` (nav).

### References
- Epic §G/§I + story 30.13 (Track 4). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Admin moderation dashboard over the existing report/comment models (queue → resolve / soft-hide /
  restore), plus a public privacy page.
- Implemented in commit `d871074`, merged via PR #154.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `AdminModerationTest`); typecheck / lint / build / jest clean.

### File List

**Added (api)**
- `api/src/Community/Application/ModerationService.php`
- `api/src/Community/Presentation/AdminModerationController.php`
- `api/tests/Functional/AdminModerationTest.php`

**Modified (api)**
- `api/src/Community/Domain/ProfileComment.php`
- `api/src/Community/Domain/ProfileCommentRepositoryInterface.php`
- `api/src/Community/Domain/ContentReportRepositoryInterface.php`
- `api/src/Community/Infrastructure/DoctrineProfileCommentRepository.php`
- `api/src/Community/Infrastructure/DoctrineContentReportRepository.php`
- `api/config/services.yaml`

**Added (frontend)**
- `frontend/src/features/admin/admin-moderation-api.ts`
- `frontend/src/features/admin/admin-moderation-dashboard.tsx`
- `frontend/src/app/(admin)/admin/moderation/page.tsx`
- `frontend/src/app/(public)/confidentialite/page.tsx`

**Modified (frontend)**
- `frontend/src/components/admin-shell.tsx` (moderation nav entry)
