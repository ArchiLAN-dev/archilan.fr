# Story 31.6: Community contributions - public submission

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an authenticated community member,
I want to submit a structured installation tutorial - either a change to an existing game or docs for a
game not yet in the catalog - and see my pending submissions,
so that the community can collectively improve the per-game install guides (admins review and apply them
in 31.7).

Community track of Epic 31. Builds on 31.1's step model (same `{type, title, description, links}`
shape) and reuses the existing `catalog-games` list (not-yet-imported Archipelago games) for the
"not-yet-listed game" target. This story covers **submission + my-contributions** only; admin moderation
and applying contributions to `install_steps` are story 31.7.

## Acceptance Criteria

1. **Model.** A `GameTutorialContribution` aggregate (GameSelection) holds: `id`, `authorId`,
   target = **either** `gameId` (existing game) **or** `proposedGameName` (not-yet-listed, exactly one of
   the two is set), `steps` (ordered `list<array{type,title,description,links:[{label,url}]}>` per 31.1),
   optional `message` (to the moderator), `status` (`pending` initially), `createdAt`. State changes only
   via business methods (no public setters); approve/reject methods exist for 31.7 but are not exercised
   here.
2. **Submit (existing game).** `POST /api/v1/game-contributions` with `{ gameSlug, steps, message? }` by
   an **authenticated user** creates a `pending` contribution targeting that game. Unknown/`unavailable`
   slug → 404. Invalid steps (bad `type`, blank `title`) → 422. Anonymous → 401.
3. **Submit (not-yet-listed game).** Same endpoint with `{ proposedGameName, steps, message? }` creates a
   `pending` contribution with no `gameId`. Blank name, or a name that already maps to an existing game,
   is handled explicitly (422 / or attach to that game - see Dev Notes). Exactly one of `gameSlug` /
   `proposedGameName` must be provided (else 422).
4. **Step validation reuse (incl. safety).** Steps are validated/normalized by the **shared
   `InstallStepsNormalizer` built in 31.1** - no duplicate rules: `InstallStepType` enum, trim, links
   coercion, **`url` restricted to http/https** (reject `javascript:`/other), and `description` kept as
   **plain text** (no HTML - rendered safely in 31.2). A contribution with zero valid steps → 422. This
   is the trust boundary for user-submitted content.
5. **My contributions.** `GET /api/v1/game-contributions/me` (authenticated) returns the caller's
   contributions (target label, status, createdAt, step count), most recent first.
6. **Access & abuse.** Any authenticated user may submit; submissions are never auto-applied (they are
   `pending` until 31.7). Per-user rate/duplicate guarding is light (e.g., cap pending contributions per
   target) - see Dev Notes; `ROLE_MEMBER` is **not** used for gating (AC-M1).
7. **UI - existing game.** `/jeux/[slug]` shows a "Proposer une modification" affordance **only for
   authenticated users**; it opens a structured step editor (reused component) prefilled from the game's
   current steps, plus an optional message, and submits to the endpoint with success/error feedback.
8. **UI - not-yet-listed game.** Near the existing game-request section, a "Proposer une doc (jeu non
   listé)" entry lets an authenticated user pick a name from the `catalog-games` list (or free text) and
   author steps, submitting to the same endpoint.
9. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Domain** (AC: 1)
  - [ ] `GameTutorialContribution` (`final`, GameSelection/Domain): named ctor `submitForGame(...)` /
        `submitForProposedName(...)`, `STATUS_PENDING/APPROVED/REJECTED`, `approve(reviewerId, now)` /
        `reject(reviewerId, reason, now)` (used in 31.7), getters; no public setters. ORM entity.
  - [ ] `GameTutorialContributionRepositoryInterface` (Domain): `save`, `findById`, `findByAuthor`,
        `countPendingForTarget(...)`.
  - [ ] Doctrine mapping (the GameSelection context already has entities). No manual test-schema list -
        `FunctionalTestCase` builds the full schema from all mapped metadata.
- [ ] **Migration** (AC: 1)
  - [ ] `Version20260619######.php` (**timestamp strictly after the 31.1 migration** - both land the same
        day): create `game_tutorial_contribution` (id, author_id, game_id NULL, proposed_game_name NULL,
        steps JSON, message TEXT NULL, status, reviewed_by NULL, reviewed_at NULL, rejection_reason NULL,
        created_at) + reversible `down()`. FK game_id → game ON DELETE SET NULL.
- [ ] **Application** (AC: 2, 3, 4, 5, 6)
  - [ ] **Reuse the shared `InstallStepsNormalizer` (built in 31.1)** for step validation/normalization
        (incl. the http/https url check + plain-text description) - do not re-implement.
  - [ ] `SubmitGameTutorialContribution` command: resolve target (gameSlug → game via repo, or
        proposedGameName), validate exactly-one-target + steps, enforce the light pending cap, persist;
        returns the new id / errors.
  - [ ] `MyGameTutorialContributionsQuery` (interface in Application, DBAL impl in Infrastructure):
        list the caller's contributions as DTOs.
- [ ] **Presentation** (AC: 2, 3, 5)
  - [ ] `GameContributionController`: `POST /api/v1/game-contributions` (auth via
        `ApiAccessGuard::requireUser` - authenticated, **not** member-gated) and
        `GET /api/v1/game-contributions/me`. Deserialize → one Application call → 201/200/401/404/422.
- [ ] **Frontend** (AC: 7, 8)
  - [ ] **Reuse the shared `InstallStepsEditor` (built in 31.1)** in both public submission forms (do not
        re-extract).
  - [ ] `features/games/game-contribution-api.ts` (+ type guards): `submitContribution(...)`,
        `getMyContributions()`.
  - [ ] `/jeux/[slug]`: a client "Proposer une modification" block (auth-gated via `useAuth`) opening the
        editor prefilled from `game` steps + message + submit.
  - [ ] Near `game-request-section.tsx`: "Proposer une doc (jeu non listé)" using the `catalog-games`
        list + the editor.
- [ ] **Tests** (AC: 9)
  - [ ] Backend functional: submit on existing game (201, pending); submit not-yet-listed (201); invalid
        steps → 422; both/neither target → 422; anonymous → 401; `GET .../me` isolation (only own).
  - [ ] Backend unit: `InstallStepsNormalizer` (shared with 31.1), `SubmitGameTutorialContribution`
        target/validation logic with repo + stub.
  - [ ] Frontend jest: contribution API guards; the auth-gated affordance renders only when logged in.

## Dev Notes

### Reuse, don't reinvent
- **Step model + validation**: same `{type,title,description,links}` and `InstallStepType` as 31.1 -
  **reuse** the shared `InstallStepsNormalizer` (built in 31.1; admin save + this submission share one
  source of truth, incl. the http/https url check + plain-text description). [Source: _bmad-output/implementation-artifacts/31-1-install-steps-model-and-admin-authoring.md]
- **Not-yet-listed games**: the `catalog-games` list (Archipelago games not yet imported) already powers
  the game-request combobox - reuse it for the proposedGameName picker. [Source: api/src/CatalogSync/Presentation/PublicCatalogGamesController.php, frontend/src/features/games/game-request-api.ts, game-request-section.tsx]
- **Existing community/request prior art**: `GameRequest` (vote-based requests) shows the
  domain/repo/query/controller layout for a community feature in GameSelection. [Source: api/src/GameSelection/Domain/GameRequest.php, Application/GameRequests.php, Presentation/GameRequestController.php]
- **Auth**: `ApiAccessGuard::requireUser` for "authenticated" (not member-gated). Never gate on
  `ROLE_MEMBER` (AC-M1).
- **Step editor component**: **reuse** the shared `InstallStepsEditor` built in 31.1 (admin + public) -
  do not duplicate the editor.

### Architecture guardrails
- DDD: aggregate is `final`, state via business methods (`approve`/`reject` for 31.7), no public setters
  (AC-D5). Repository interface in Domain; DBAL query interface in Application + DBAL impl in
  Infrastructure (the my-contributions read). No `EntityManager`/`Connection` in Application or
  controllers. Command service returns void/ids, not entities.
- No new context (GameSelection already exists) → no `DddArchitectureValidator` change; just add the
  Doctrine mapping. The functional-test schema is auto-built from all metadata (no manual list).
- PHPStan max: narrow all request JSON; the steps array is normalized through the shared normalizer.
- **Render safety**: `description` is plain text; the public render (31.2) must not use raw HTML. URLs
  are validated to http/https by the normalizer; render links with `rel="noopener noreferrer"`.
- Frontend: no `as` at the boundary (guards), env via `src/lib/env.ts`, auth via `useAuth`, tokens-only.

### Decisions to honour / open points
- **Exactly one target**: enforce `gameSlug XOR proposedGameName`. If `proposedGameName` matches an
  existing game (case-insensitive / archipelago name), prefer attaching to that `gameId` (don't create a
  duplicate proposal) - mirror `CatalogSyncService` matching precedence.
- **Light abuse guard**: cap simultaneous `pending` contributions per (author, target) - e.g. 1 pending
  per existing game per author; keep it simple, no full rate-limiter.
- **No application on submit**: contributions never touch `install_steps` here - that is 31.7
  (moderation). This story ends at `pending`.

### Project Structure Notes
- New (api): `Domain/GameTutorialContribution.php` + `...RepositoryInterface.php`,
  `Application/SubmitGameTutorialContribution.php`,
  `Application/MyGameTutorialContributionsQuery(Interface).php`,
  `Infrastructure/Doctrine...Repository.php` + `Dbal...Query.php`,
  `Presentation/GameContributionController.php`, migration, tests.
- Reused from 31.1: `Application/InstallStepsNormalizer.php`, `features/games/install-steps-editor.tsx`.
- Modified (api): `config/services.yaml`, Doctrine config.
- New/Modified (frontend): shared `InstallStepsEditor`, `features/games/game-contribution-api.ts`,
  `/jeux/[slug]` submission block, game-request area entry.

### Dependencies
- **Depends on 31.1** (step model + `InstallStepType` + normalizer to extract). Pairs with **31.7**
  (moderation applies these contributions).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]
- Membership rule: [Source: api/CLAUDE.md#membership-access-control] (do not gate on ROLE_MEMBER)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.

### File List
