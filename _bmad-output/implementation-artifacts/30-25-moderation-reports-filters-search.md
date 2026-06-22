# Story 30.25: Moderation reports - filters, categories & search

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin moderating the community,
I want to filter the reports queue by status, comment state and target type, sort it, and search it,
so that I can triage signalements efficiently instead of scrolling one flat list.

Reworks the "Signalements" tab of `/admin/moderation` (Community context). The contributions tab
(31.7) already has its own panel; this story brings the reports panel up to par.

## Acceptance Criteria

1. **Status category.** The reports list can be filtered by report status: **En attente** (`resolvedAt`
   null, default), **Résolus** (`resolvedAt` set), **Tous**. Today `ModerationService::queue` only
   returns pending; extend it (or a new query) to serve each status.
2. **Comment-state filter.** Filter by the reported comment's state: **masqué** / **visible**
   (`comment.hidden`). Applies to comment-target reports; profile-target reports are unaffected.
3. **Target-type filter.** Filter by `targetType` (commentaire / profil).
4. **Sort.** Sort by `createdAt` - plus récent (default) / plus ancien.
5. **Search.** A text query matches (case-insensitive) the **comment body**, the report **reason**, and
   the **comment author's display name**. Empty query = no text filter.
6. **Combinable + counts.** Filters/search/sort combine (AND). The tab badge shows the **pending** count
   (unchanged default); the list reflects the active filters. Empty result shows a friendly placeholder.
7. **Backend.** `GET /api/v1/admin/community/reports` accepts query params (`status`, `commentState`,
   `targetType`, `sort`, `q`, `limit`/pagination); admin-gated; `ROLE_MEMBER` never used. The list query
   uses a DBAL QueryBuilder in Infrastructure (no raw SQL/DQL), narrowing every column.
8. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [x] **api/ Community**: `AdminReportsQueryInterface` (Application) + `DbalAdminReportsQuery`
      (Infrastructure) join `community_content_report` → `community_profile_comment` → `"user"` (author),
      supporting `status` (pending/resolved/all via `resolved_at`), `commentState` (hidden/visible, narrows
      to comment targets), `targetType`, `sort` (created_at asc/desc), `q` (ILIKE on comment body / reason /
      author display name). Returns ordered report IDs; `ModerationService::list(ReportQueryFilters)` loads
      + reorders them and reuses the existing DTO assembly (`assemble()`). `ReportQueryFilters` normalizes
      raw input. `AdminModerationController::reports` reads the params; badge `meta.count` stays the pending
      count. Repo gained `findByIds`.
- [x] **frontend**: `ReportsModerationPanel` extracted from `admin-moderation-dashboard.tsx` (parallel to
      `ContributionsModerationPanel`) - status chips + comment-state/target-type/sort selects + debounced
      search, all in the TanStack queryKey. Dashboard keeps a small default-filter query for the badge
      count. `admin-moderation-api.ts`: `buildReportsQuery` + `fetchModerationQueue(filters)`.
- [x] **Tests**: backend functional (status/commentState/targetType filters, search on body + author
      display name, sort, badge count); frontend jest (`buildReportsQuery` + `fetchModerationQueue` query
      string).

## Dev Notes

- **Existing model**: `ContentReport` has `resolvedAt`/`resolvedBy` (status) + `targetType` + `reason`;
  the reported `comment` has `hidden`. [Source: api/src/Community/Domain/ContentReport.php, api/src/Community/Application/ModerationService.php]
- **Endpoint**: `GET /api/v1/admin/community/reports` (admin-gated) currently `queue(limit)` only.
  [Source: api/src/Community/Presentation/AdminModerationController.php]
- **Frontend**: the reports rendering lives inline in the `tab === "reports"` branch of
  `admin-moderation-dashboard.tsx` (31.7 added the tabs); extract it into its own panel for symmetry with
  `ContributionsModerationPanel`. Reuse the existing busy/`invalidateQueries` + `ReportCard` patterns.
  [Source: frontend/src/features/admin/admin-moderation-dashboard.tsx, admin-moderation-api.ts]
- **DDD/standards**: DBAL QueryBuilder in Infrastructure behind an Application query interface; admin gate
  via `ApiAccessGuard`; never `ROLE_MEMBER` (AC-M1); narrow all columns (PHPStan max). Search = ILIKE on
  Postgres; quote the `user` table (`quoteSingleIdentifier`) for the author/reporter join.
- **Scope**: this is the reports queue only (the contributions tab is 31.7). No change to the
  resolve/hide/restore actions themselves.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md], [Source: api/CLAUDE.md#membership-access-control]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Filtering/search/sort done at the DB level in DBAL (returns ordered report IDs); the proven DTO
  assembly (comment + parties via the user directory) is reused unchanged via `ModerationService::list`.
- `commentState` (hidden/visible) implicitly narrows to comment-target reports, since profile reports have
  no comment to be in a hidden/visible state.
- The badge count stays the pending count regardless of the active filter (dashboard owns a dedicated
  default-filter query); the panel runs its own filtered query.
- Gates: phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, AdminModerationTest 4 tests/118
  assertions ✅; typecheck ✅, lint ✅, jest 18 suites/112 tests ✅, build ✅.

### File List

- api/src/Community/Application/ReportQueryFilters.php (new)
- api/src/Community/Application/AdminReportsQueryInterface.php (new)
- api/src/Community/Infrastructure/DbalAdminReportsQuery.php (new)
- api/src/Community/Application/ModerationService.php (queue→list + assemble/orderByIds)
- api/src/Community/Domain/ContentReportRepositoryInterface.php (findByIds)
- api/src/Community/Infrastructure/DoctrineContentReportRepository.php (findByIds)
- api/src/Community/Presentation/AdminModerationController.php (filter params)
- api/config/services.yaml (AdminReportsQueryInterface alias)
- api/tests/Functional/AdminModerationTest.php (filters/search/sort test)
- frontend/src/features/admin/admin-moderation-api.ts (ReportFilters + buildReportsQuery)
- frontend/src/features/admin/reports-moderation-panel.tsx (new)
- frontend/src/features/admin/admin-moderation-dashboard.tsx (uses panel + badge query)
- frontend/src/features/admin/admin-moderation-api.test.ts (new)
