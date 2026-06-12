# Story 16.10: Joined private runs surfaced in "Mes parties" ("Parties rejointes")

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-06-12

## Story

As a user who has joined another player's private run via an invite link,
I want that run to appear in my "Mes parties" space under a distinct **"Parties rejointes"** sub-category,
so that I can find and open the runs I participate in (not only the ones I own).

## Context

Story 16.2 lets a user join a private run via its invite link, creating a `RunParticipant`
(`personal_run_id` + `user_id`). Story 16.5/16.6 built the "Mes parties" list page
(`GET /api/v1/runs/mine`) and the participant detail view. But the list only ever shows runs the
caller **owns**: `PersonalRunController::listMine` → `PersonalRunDrafts::listForOwner` →
`RunRepository::findByOwnerId($ownerId)`. A user who joins someone else's run has **no entry point**
to it from their own space - they can only re-use the original invite link.

This story adds the joined runs to `/runs/mine` and renders them in a separate **"Parties rejointes"**
section on the list page, visually distinct from the owned runs (which stay grouped by status).

### Decisions (confirmed with Jean)

- **Same endpoint, new shape.** `GET /runs/mine` returns `{ owned: [...], joined: [...] }` instead of a
  flat array. `joined` = runs where the caller is a `RunParticipant` **and not** the owner.
- **Label = "Parties rejointes"**, a separate sub-section under "Mes parties", below the owned runs.
- **No owner-secret leak.** Joined-run payloads must not expose the owner's `inviteToken` (now gated by
  `isOwner`; `adminPassword` was already owner-gated). Connection info (host/port/password) stays - a
  participant needs it to connect. `isOwner` is `false` on joined runs, so the card hides owner-only
  actions (restart/launch/invite) exactly as on the participant detail view.
- **No new migration / no schema change** - `run_participant` already exists.

## Acceptance Criteria

1. `GET /api/v1/runs/mine` returns `{ data: { owned: PersonalRun[], joined: PersonalRun[] } }`.
   `owned` = runs the caller owns (unchanged content/order); `joined` = runs where the caller is a
   participant **but not** the owner (most recent first), de-duplicated.
2. A run the caller owns appears **only** in `owned`, never in `joined` (owner is excluded from the
   joined query even though the owner is also a participant).
3. Joined-run payloads expose `isOwner: false` and **do not** include the owner's `inviteToken`
   (it is `null` for non-owners); `adminPassword` stays `null` for non-owners (unchanged). Connection
   info remains available so a participant can connect.
4. The list page renders a separate **"Parties rejointes"** section (label exactly "Parties rejointes")
   below the owned runs, listing the joined runs as `PersonalRunCard`s linking to `/runs/{id}`. When
   there are no joined runs, the section is not rendered. The empty-state (no owned **and** no joined)
   is preserved.
5. Owner-only affordances (restart, and anything gated on `isOwner` in the card/detail) do not appear
   for joined runs.
6. Quality gates green - API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`;
   frontend: typecheck / lint / build.

## Tasks / Subtasks

- [ ] **Task 1 - Domain finder** (AC: 1,2). Add `RunRepositoryInterface::findJoinedByUserId(string $userId): array`
  (runs where the user is a participant and not the owner).
- [ ] **Task 2 - Infrastructure impl** (AC: 1,2). Implement in `DoctrineRunRepository` using a **DBAL
  QueryBuilder** (inject `Doctrine\DBAL\Connection`): select `run_participant.personal_run_id` joined to
  `run` where `user_id = :id` and `run.owner_id <> :id`, then load the `Run` entities by id via the ORM
  (ordered `createdAt DESC, id DESC`), de-duplicated. Return `list<Run>`.
- [ ] **Task 3 - Application** (AC: 1,2,3). Add `PersonalRunDrafts::listMine(string $userId): array`
  returning `['owned' => ..., 'joined' => ...]` (owned via `findByOwnerId`, joined via
  `findJoinedByUserId`, both mapped through `payload($run, $userId, [])`). Gate `inviteToken` by
  `isOwner` in `payload()` (compute `$isOwner` once; reuse for `adminPassword`).
- [ ] **Task 4 - Presentation** (AC: 1). `PersonalRunController::listMine` returns
  `['data' => $this->drafts->listMine($user->getId())]`. Controller still ≤ one Application call.
- [ ] **Task 5 - Frontend** (AC: 4,5). In `personal-runs-list-page.tsx`: parse the new `{ owned, joined }`
  shape (type guard), group/render `owned` exactly as today, and add a "Parties rejointes" `<section>`
  for `joined` (cards link to `/runs/{id}`, no `onRestart`). Update `types.ts`: `inviteToken: string | null`.
- [ ] **Task 6 - Tests** (AC: 1,2,3). Unit-test `PersonalRunDrafts::listMine` (mock
  `RunRepositoryInterface`): owned + joined split, joined payload has `isOwner === false` and
  `inviteToken === null`. Functional test on `/runs/mine` (owner sees owned-only; a joined participant
  sees the run under `joined`, not `owned`).
- [ ] **Task 7 - Gates** (AC: 6). All gates; verify live a joined run shows under "Parties rejointes".

## Dev Notes

- **DDD:** the participant→run lookup is a DBAL QueryBuilder **in Infrastructure** (the repository),
  not in Application; Application keeps using the Domain repo interface. The repo loads entities by id
  via the ORM after the DBAL id query (the interface returns `Run` aggregates, consistent with
  `findByOwnerId`).
- **Why exclude the owner from the joined query:** the owner is typically *also* a `RunParticipant`
  (they hold slots). `run.owner_id <> :id` keeps owned runs out of `joined` so a run never shows twice.
- **Payload gating:** `adminPassword` is already `isOwner`-gated (only set when `$isActive && owner`).
  This story adds the same gate to `inviteToken` (only the owner manages the invite). The participant
  detail page already renders the invite/admin panels only when `run.isOwner`, so gating the API does
  not change the participant UI - it just stops sending owner secrets to non-owners.
- **Response shape change is breaking** for the single `/runs/mine` consumer
  (`personal-runs-list-page.tsx`), updated in this story. No other caller.

### Project Structure Notes

- `api/src/PersonalRuns/Domain/RunRepositoryInterface.php` (new `findJoinedByUserId`)
- `api/src/PersonalRuns/Infrastructure/DoctrineRunRepository.php` (DBAL `Connection` + impl)
- `api/src/PersonalRuns/Application/PersonalRunDrafts.php` (`listMine` + `inviteToken` gate)
- `api/src/PersonalRuns/Presentation/PersonalRunController.php` (`/runs/mine` → `listMine`)
- `frontend/src/features/personal-runs/personal-runs-list-page.tsx` (parse shape + "Parties rejointes")
- `frontend/src/features/personal-runs/types.ts` (`inviteToken: string | null`)
- Tests under `api/tests/Unit/PersonalRuns/` and `api/tests/Functional/`

### References

- [Source: _bmad-output/implementation-artifacts/16-2-invite-link-and-join-flow.md (RunParticipant created on join)]
- [Source: _bmad-output/implementation-artifacts/16-5-frontend-run-dashboard.md (the list page)]
- [Source: _bmad-output/implementation-artifacts/16-6-frontend-join-and-participant-view.md (participant detail, isOwner gating)]
- [Source: api/src/PersonalRuns/Application/PersonalRunDrafts.php (listForOwner, payload)]
- [Source: api/src/PersonalRuns/Domain/RunParticipant.php (personal_run_id, user_id)]
- [Source: frontend/src/features/personal-runs/personal-runs-list-page.tsx (consumer of /runs/mine)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `RunRepositoryInterface::findJoinedByUserId` + `DoctrineRunRepository` impl (DBAL id query on
  `run_participant` JOIN `run` with `owner_id <> :id`, then ORM `findBy(id IN ...)` ordered).
- `PersonalRunDrafts::listMine` returns `{owned, joined}`; `inviteToken` now `isOwner`-gated in `payload`.
- `PersonalRunController::listMine` returns `{data: {owned, joined}}`.
- Frontend list page parses the new shape and renders a "Parties rejointes" section under the owned runs.
- Tests: unit on `listMine` split + joined-payload gating; functional on `/runs/mine` owned vs joined.

### File List

- `api/src/PersonalRuns/Domain/RunRepositoryInterface.php`
- `api/src/PersonalRuns/Infrastructure/DoctrineRunRepository.php`
- `api/src/PersonalRuns/Application/PersonalRunDrafts.php`
- `api/src/PersonalRuns/Presentation/PersonalRunController.php`
- `frontend/src/features/personal-runs/personal-runs-list-page.tsx`
- `frontend/src/features/personal-runs/types.ts`
- `api/tests/Unit/PersonalRuns/PersonalRunDraftsListMineTest.php` (new)
- `api/tests/Functional/PersonalRunsMineTest.php` (new or extended)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-12 | Story created and implemented. `/runs/mine` now returns `{owned, joined}`; joined = participant-but-not-owner runs (`findJoinedByUserId`, DBAL). Frontend renders a "Parties rejointes" sub-section. `inviteToken` gated by `isOwner`. Status → review. |
