# Story 31.7: Community contributions - admin moderation & apply

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin,
I want a moderation queue for the community tutorial contributions where I can review proposed steps
(against the game's current tutorial), then approve them - applying them to the game - or reject them
with a reason,
so that good community contributions improve the per-game install guides and the author gets feedback.

Completes the Epic 31 community track started in 31.6 (which captures `pending` contributions). This
story adds the **admin moderation queue, the approve/apply and reject flows, and author notification**.

## Acceptance Criteria

1. **Admin list.** `GET /api/v1/admin/game-contributions?status=pending` (admin-gated) returns the
   contributions with: id, author (display name + id), target (existing game name+slug **or**
   proposed name), proposed `steps`, `message`, `createdAt`, and - for an existing-game target - the
   game's **current** `install_steps` so the UI can diff. Defaults to `pending`; supports
   `status=approved|rejected` for history.
2. **Approve (existing game).** `POST /api/v1/admin/game-contributions/{id}/approve` applies the
   contribution's steps to the target game's `install_steps` (normalized via the shared
   `InstallStepsNormalizer`), marks the contribution `approved` (reviewer id + timestamp), in **one unit
   of work**. Optional `{ steps }` body lets the moderator approve an **edited** version (the edited
   steps are applied and stored as approved). 404 unknown contribution; 409/422 if it is not `pending`.
3. **Approve (not-yet-listed game).** Approving a `proposedGameName` contribution marks it `approved`
   **without** writing any game (no auto-create); the content remains available to seed a manual game
   creation later. (Surface a hint in the UI that game creation is manual.)
4. **Reject.** `POST /api/v1/admin/game-contributions/{id}/reject` with `{ reason }` marks the
   contribution `rejected`, stores the reason. Blank reason → 422. Not-`pending` → 409/422.
5. **State on the aggregate.** Transitions use the `approve(reviewerId, now)` / `reject(reviewerId,
   reason, now)` business methods on `GameTutorialContribution` (added in 31.6); no public setters; a
   contribution can be moderated only from `pending`.
6. **Author notification (post-commit).** After the transaction commits, the author is notified
   (approved / rejected + reason) via the project's Notifier pattern, dispatched **asynchronously after
   flush** (never inline before commit, AC-A4). Failure to notify never rolls back the moderation.
7. **Admin UI.** A "Contributions tutoriels" **tab/section** in `/admin/moderation`: lists pending
   contributions with author, target, the proposed steps and (for existing games) a **prominent diff**
   "actuel vs proposé"; **Approve** and **Reject (with reason)** actions; pending count; optimistic
   busy/invalidate like the existing report queue. The Approve control states explicitly that approving
   **replaces the whole tutorial** (`install_steps`) with the contribution - so the moderator reviews the
   diff before applying, and uses `overrideSteps` to merge/trim when the contribution is only a partial
   fix.
8. **Access control.** All endpoints are admin-gated (`requireAuthenticatedAdmin`). `ROLE_MEMBER` is
   never used (AC-M1).
9. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Application** (AC: 1, 2, 3, 4, 5, 6)
  - [ ] `ModerateGameTutorialContribution` (command): `approve(string $id, string $reviewerId, ?array $overrideSteps)` and `reject(string $id, string $reviewerId, string $reason)`. Load contribution via repo; on approve with an existing-game target, load the game (repo), apply normalized steps via `Game::setInstallSteps`, save game + contribution; call the aggregate's `approve()/reject()`. One unit of work; **dispatch the author notification after** persistence (Messenger message / Notifier), not inline.
  - [ ] `AdminGameContributionsQuery` (interface in Application, DBAL impl in Infrastructure): list by status with author display name (join users) + target label; include the target game's current `install_steps` for existing-game rows.
- [ ] **Presentation** (AC: 1, 2, 3, 4, 8)
  - [ ] `AdminGameContributionController`: `GET /api/v1/admin/game-contributions`,
        `POST .../{id}/approve`, `POST .../{id}/reject`. `requireAuthenticatedAdmin` → one Application
        call → 200/404/409|422. Mirror `AdminGameLibraryController` conventions.
- [ ] **Notification** (AC: 6)
  - [ ] `GameTutorialContributionReviewed` message + handler (Application/Message + Application/Handler)
        OR reuse the existing Notifier channel from Epic 30; notify the author of approved/rejected
        (+reason). Dispatched post-commit. Add a `when@test` no-op/collector so tests stay deterministic.
- [ ] **Frontend** (AC: 7)
  - [ ] `features/admin/admin-game-contributions-api.ts` (+ guards): `fetchContributionQueue`,
        `approveContribution(id, steps?)`, `rejectContribution(id, reason)`.
  - [ ] Turn `admin-moderation-dashboard.tsx` into a **tabbed** view (existing "Signalements" +
        new "Contributions tutoriels"), or add a sibling section; reuse the busy/`invalidateQueries`
        pattern and card layout. Each contribution card: author, target, the proposed steps (read-only via
        the shared step renderer/editor), a diff/"actuel vs proposé" for existing games, Approve, and
        Reject-with-reason (prompt/textarea). Pending count in the header.
- [ ] **Tests** (AC: 9)
  - [ ] Backend functional: approve existing-game applies steps to `install_steps` + status approved;
        approve with `overrideSteps` applies the edited version; approve not-yet-listed → approved, no
        game write; reject stores reason; non-admin → 403/401; double-moderation (not pending) → 409/422;
        notification dispatched (assert message on the test transport).
  - [ ] Backend unit: `ModerateGameTutorialContribution` approve/reject paths with repo + stubs; aggregate
        transition guards (only from pending).
  - [ ] Frontend jest: contributions API guards; tab renders the queue; approve/reject call the right
        endpoints and invalidate.

## Dev Notes

### Reuse, don't reinvent
- **Apply path**: reuse the shared `InstallStepsNormalizer` (extracted in 31.6) and `Game::setInstallSteps`
  from 31.1 - approval is just "normalize + set + save". No new step logic. [Source: _bmad-output/implementation-artifacts/31-1-install-steps-model-and-admin-authoring.md, 31-6-community-contributions-public-submission.md]
- **Moderation UI**: extend the existing dashboard rather than build a new page - it already has the
  TanStack query + busy/invalidate + card patterns; add a tab. [Source: frontend/src/features/admin/admin-moderation-dashboard.tsx, admin-moderation-api.ts]
- **Admin controller conventions**: `requireAuthenticatedAdmin` → service → 200/404/422, like
  `AdminGameLibraryController` (incl. the `resync-platforms` POST template). [Source: api/src/GameSelection/Presentation/AdminGameLibraryController.php]
- **Post-commit notification**: follow Epic 30's Notifier-post-commit pattern (the comment-report
  moderation lives in Community/`ModerationService` for prior art on the moderation shape). [Source: api/src/Community/Application/ModerationService.php, api/src/Community/Presentation/AdminModerationController.php]

### Architecture guardrails
- DDD: moderation lives in **GameSelection** (same context as `Game` + the contribution aggregate), so
  approve can load+mutate both via their repository interfaces in **one transaction**. Side effects
  (author notification) dispatched **after** flush, never inline (AC-A4). No `EntityManager`/`Connection`
  in Application/controllers; the read list uses a DBAL query interface + Infrastructure impl. Command
  services return void; aggregate state via `approve()/reject()` business methods only (AC-D5).
- The existing `/admin/moderation` (comment reports) is a **separate** Community concern - do not couple
  the two backends; the frontend dashboard merely aggregates two queues into tabs.
- PHPStan max: narrow request JSON (`reason`, optional `steps`); the steps go through the normalizer.
- Frontend: no `as` at the boundary (guards), tokens-only, admin-gated by the existing admin shell.

### Decisions to honour
- **Approve applies as-is, with optional moderator edit**: the moderator may tweak steps before approving
  (`overrideSteps`); otherwise the submitted steps are applied verbatim. Either way the game's tutorial is
  **replaced** by the approved steps (not merged), keeping the model simple and the 31.1 editor available
  for follow-up tweaks.
- **Not-yet-listed = no auto-create** (epic decision): approving records acceptance; game creation stays
  manual via the admin editor + 31.1 seed.
- **Idempotency / races**: only `pending` contributions are moderatable; a second approve/reject returns
  409/422 rather than re-applying.

### Project Structure Notes
- New (api): `Application/ModerateGameTutorialContribution.php`,
  `Application/AdminGameContributionsQuery(Interface).php`,
  `Infrastructure/DbalAdminGameContributionsQuery.php`,
  `Presentation/AdminGameContributionController.php`,
  `Application/Message/GameTutorialContributionReviewed.php` + `Application/Handler/...Handler.php`, tests.
- Modified (api): the contribution aggregate already exposes `approve()/reject()` (31.6); `services.yaml`.
- New/Modified (frontend): `features/admin/admin-game-contributions-api.ts`, tabbed
  `admin-moderation-dashboard.tsx` (+ shared step renderer reused from 31.6).

### Dependencies
- **Depends on 31.6** (the `GameTutorialContribution` aggregate, repo, and `approve()/reject()` methods)
  and **31.1** (`install_steps` + normalizer). Sequenced after both.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]
- Membership rule: [Source: api/CLAUDE.md#membership-access-control]
- Side-effects rule: [Source: api/CLAUDE.md] (AC-A4 - dispatch after commit)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Implemented on branch `feature/epic-31-story-7-admin-moderation` (from develop).
- `ModerateGameTutorialContribution` approve/reject: approve applies the (optionally moderator-edited) steps to the target game's `install_steps` and marks the contribution approved **in one flush** (game is a managed entity, flushed with the contribution); not-yet-listed → approved without a game write. Reject requires a reason. Only `pending` → 409 otherwise.
- Author notified **post-commit** via the reused `Community\Application\Notifier` (type `tutorial_contribution_reviewed`); wrapped in try/catch so a notify failure never undoes the moderation.
- `AdminGameContributionsQuery` (DBAL): lists by status with author display name (quoted `user` join), target, the proposed steps **and** the game's current steps for the diff; drops unknown-type steps.
- `AdminGameContributionController`: GET list + POST approve/reject (admin-gated; 404/409/422).
- Frontend: tabbed `/admin/moderation` (Signalements + Contributions tutoriels); `ContributionsModerationPanel` (TanStack queue, current-vs-proposed diff via `InstallStepsView`, Approve / Reject-with-reason, explicit "remplace l'intégralité" note). `admin-game-contributions-api.ts` (+ test).
- Gates green: php-cs-fixer 0, phpstan 0 (src+tests), DDD exit 0, `AdminGameContributionModerationTest` 5/5; FE typecheck/lint/build, jest 59.

### File List

**Added (api)**
- `api/src/GameSelection/Application/ModerateGameTutorialContribution.php`
- `api/src/GameSelection/Application/AdminGameContributionsQueryInterface.php`
- `api/src/GameSelection/Infrastructure/DbalAdminGameContributionsQuery.php`
- `api/src/GameSelection/Presentation/AdminGameContributionController.php`
- `api/tests/Functional/AdminGameContributionModerationTest.php`

**Modified (api)**
- `api/config/services.yaml` (query alias)

**Added (frontend)**
- `frontend/src/features/admin/admin-game-contributions-api.ts` (+ test)
- `frontend/src/features/admin/contributions-moderation-panel.tsx`

**Modified (frontend)**
- `frontend/src/features/admin/admin-moderation-dashboard.tsx` (tabbed: reports + contributions)

### File List
