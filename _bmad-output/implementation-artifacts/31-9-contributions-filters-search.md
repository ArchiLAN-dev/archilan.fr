# Story 31.9: Tutorial contributions - filters, categories & search

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin moderating tutorial contributions,
I want to filter the contributions queue by status and target type, sort it, and search it,
so that I can triage them efficiently — the mirror of story 30.25 for the reports queue.

Reworks the "Contributions tutoriels" tab of `/admin/moderation` (GameSelection context, story 31.7).

## Acceptance Criteria

1. **Status category.** Filter by contribution status: **En attente** (`pending`, default), **Approuvées**
   (`approved`), **Rejetées** (`rejected`), **Toutes**. Today the endpoint accepts a single `status`
   (default pending) and the frontend never sends it; add an `all` bucket and surface the others.
2. **Target-type filter.** Filter by target: **jeu listé** (`game_id` set) / **jeu non listé** (proposed
   name, `game_id` null).
3. **Sort.** Sort by `created_at` — plus récent (default) / plus ancien.
4. **Search.** A text query matches (case-insensitive) the **game name**, the **proposed game name**, the
   **contributor's display name**, and the contribution **message**. Empty query = no text filter.
5. **Badge + counts.** The "Contributions tutoriels" tab gains a **pending** count badge (symmetry with
   the Signalements tab). The list reflects the active filters; the badge stays the pending count. Empty
   result shows a friendly placeholder (celebratory when default & empty, generic otherwise).
6. **Backend.** `GET /api/v1/admin/game-contributions` accepts query params (`status`, `target`, `sort`,
   `q`); admin-gated; `ROLE_MEMBER` never used. One DBAL QueryBuilder in Infrastructure (no raw SQL/DQL),
   narrowing every column; ILIKE search on Postgres. Response: `{ data: [...], meta: { count } }`.
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [x] **api/ GameSelection**: `ContributionQueryFilters` (Application VO, `fromRaw`, safe defaults).
      `AdminGameContributionsQueryInterface::list(string $status)` → `list(ContributionQueryFilters)` +
      `pendingCount(): int`. `DbalAdminGameContributionsQuery`: status (`all` = no filter), target
      (game_id IS [NOT] NULL), sort (created_at asc/desc), `q` (ILIKE on g.name / c.proposed_game_name /
      u.display_name / c.message). DTO assembly stays in this query.
      `AdminGameContributionController::list` reads params, returns `{ data, meta: { count: pendingCount } }`.
- [x] **frontend**: `contributions-moderation-panel.tsx` reworked — status chips + target/sort selects +
      debounced search in the TanStack queryKey (mirror `reports-moderation-panel.tsx`).
      `admin-game-contributions-api.ts`: `ContributionFilters` + `buildContributionsQuery` +
      `fetchContributionQueue(filters)` returning `{ items, count }` (now try/catch-guarded).
      `admin-moderation-dashboard.tsx`: pending count badge on the contributions tab via a default-filter
      query.
- [x] **Tests**: backend functional (status incl. `all`, target filter, search on name/proposed/author/
      message, sort, badge count); frontend jest (`buildContributionsQuery` + `fetchContributionQueue`).

## Dev Notes

- **Existing model**: `GameTutorialContribution` has `STATUS_PENDING|APPROVED|REJECTED`, a target that is
  either `game_id` (listed) or `proposed_game_name` (unlisted), `message`, `created_at`, `author_id`,
  `steps`. [Source: api/src/GameSelection/Domain/GameTutorialContribution.php]
- **Query**: `DbalAdminGameContributionsQuery::list(string $status)` joins `game_tutorial_contribution` →
  `game` → `"user"` and already assembles the full DTO (target, gameSlug, proposed/current steps). Just add
  the filters + a pending count. [Source: api/src/GameSelection/Infrastructure/DbalAdminGameContributionsQuery.php]
- **Endpoint**: `GET /api/v1/admin/game-contributions` (admin-gated) already validates a `status` param
  (default pending). [Source: api/src/GameSelection/Presentation/AdminGameContributionController.php]
- **Frontend**: the panel currently calls `fetchContributionQueue()` with no params and only shows pending.
  Mirror the reports panel UX (chips + selects + debounced search). [Source:
  frontend/src/features/admin/contributions-moderation-panel.tsx, admin-game-contributions-api.ts,
  reports-moderation-panel.tsx]
- **DDD/standards**: DBAL QueryBuilder in Infrastructure behind the Application query interface; admin gate
  via `ApiAccessGuard`; never `ROLE_MEMBER` (AC-M1); narrow all columns; quote the `user` table.
- **Scope**: contributions queue only. No change to the approve/reject actions or the steps diff view.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Sibling story (same pattern, reports queue): [Source: _bmad-output/implementation-artifacts/30-25-moderation-reports-filters-search.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md], [Source: api/CLAUDE.md#membership-access-control]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Simpler than 30.25: the existing DBAL query already assembles the full DTO, so filtering/search/sort were
  added in place (one query) — no IDs-then-reload round trip.
- Search ILIKE spans game name, proposed game name, author display name and the contribution message.
- The contributions tab now mirrors the reports tab: a pending count badge (dedicated default-filter
  query) + a filtered list query; actions invalidate the `["admin-game-contributions"]` prefix.
- Gates: phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, AdminGameContributionModerationTest
  7 tests/123 assertions ✅; typecheck ✅, lint ✅, jest 18 suites/116 tests ✅, build ✅.

### File List

- api/src/GameSelection/Application/ContributionQueryFilters.php (new)
- api/src/GameSelection/Application/AdminGameContributionsQueryInterface.php (list signature + pendingCount)
- api/src/GameSelection/Infrastructure/DbalAdminGameContributionsQuery.php (filters/search/sort + pendingCount)
- api/src/GameSelection/Presentation/AdminGameContributionController.php (filter params + meta.count)
- api/tests/Functional/AdminGameContributionModerationTest.php (filters/search/sort test)
- frontend/src/features/admin/admin-game-contributions-api.ts (ContributionFilters + buildContributionsQuery)
- frontend/src/features/admin/contributions-moderation-panel.tsx (filters + search UI)
- frontend/src/features/admin/admin-moderation-dashboard.tsx (contributions pending-count badge)
- frontend/src/features/admin/admin-game-contributions-api.test.ts (query string + parsing)
