# Story 32.5: Recap and exchange graph for personal runs

**Status:** in progress
**Epic:** 32 - Recaps
**Date:** 2026-07-24

## Story

As the owner (or a participant) of a personal run,
I want the same recap and exchange graph an event session gets,
so that we can relive our private game - and I can choose to share it publicly.

## Context

The recap build pipeline is **already generic**: `BuildSessionRecapJob` is keyed on `sessionId`,
gated only on `Session::STATUS_FINISHED`, and dispatched from `SessionLifecycleManager::storeArchive`
for **every** archiving session. A personal run creates a real `Session` + `SessionSlot`s + a spoiler
exactly like an event session (its `Session.eventId` is overloaded to hold the run id). So a
`SessionRecap` row **is already being built** for every finished personal run - it is simply never
served, because the sole read facade `SessionRecapQuery` rejects any session whose event is not a
public `Event` (`!$event->isPublic()`).

Weekly runs are **out of scope**: an entry is single-player (no inter-player item exchanges, so the
exchange graph is empty by nature), creates no `SessionSlot`s, and never reaches `FINISHED`. A weekly
recap would be a different projection (leaderboard/goal-time based) - deferred.

### Decisions

- **Private by default, owner can publish.** A personal-run recap is visible to the run **owner and
  participants** as soon as the run is finished. The owner can flip a **publish** toggle to make it
  publicly shareable, exactly like an event recap. (Author's choice over owner-only or always-public.)
- **One URL, two access levels.** The recap is served at the existing public route
  `GET /api/v1/parties/{sessionId}/recap`, made viewer-aware via `ApiAccessGuard::optionalUser`:
  - event, public event -> anyone (unchanged);
  - personal run, published -> anyone;
  - personal run, not published -> only the owner or a participant;
  - otherwise 404.
  The shareable link is the same before and after publishing; only who can load it changes.
- **The publish flag lives on the `Run`**, not on the recap projection - the recap is rebuilt
  idempotently by the job and must not carry a user decision that a rebuild could reset.
- **The private preview reuses the public page.** The recap page (`/parties/[sessionId]`) already
  renders everything from `sessionId`/`slotId`; it just needs to forward the viewer's cookies in SSR
  so an owner can load an unpublished recap. No second recap view.
- **The toggle lives on the run detail page** (owner view), next to the finished run - a link to the
  recap plus a "rendre public / rendre privé" control and the shareable URL.

### Security

- The recap exposes the **spoiler-derived** exchange graph - i.e. what item went where. For a private
  run this must never leak: the access branch is the load-bearing part and gets a dedicated functional
  test per case (owner sees, participant sees, anonymous 404 while private, anyone sees once published).
- `generateMetadata` also forwards cookies so a private recap yields a noindex/"indisponible" head
  rather than leaking a title; a published recap keeps its rich metadata.

## Acceptance Criteria

1. A finished personal run's recap (graph + podium + superlatives) is served at
   `/parties/{sessionId}/recap` to its **owner and participants**, and 404s for anyone else while the
   run is not published.
2. The owner can toggle publish; once published the same recap is served to **anyone** (anonymous
   included), like an event recap. Only the owner can toggle it.
3. Event recaps are unchanged (still public, viewer ignored).
4. Weekly runs are untouched.
5. The run detail page (owner/participant) links to the recap when the run is finished; the owner also
   sees the publish toggle + shareable link.
6. The exchange-graph page and OG image work for a published personal-run recap with no frontend view
   changes beyond cookie forwarding.
7. Gates green both sides, with functional tests covering each access case.

## Tasks / Subtasks

- [ ] **Task 1 - Publish flag** (AC 2). `Run.recapPublic` (bool, default false) + `publishRecap()` /
      `unpublishRecap()` / `isRecapPublic()`; migration (reversible).
- [ ] **Task 2 - Toggle command + endpoint** (AC 2). Owner-gated command + `PUT
      /api/v1/runs/{runId}/recap-visibility` on `PersonalRunController`.
- [ ] **Task 3 - Viewer-aware read** (AC 1, 2, 3). `SessionRecapQuery::execute($sessionId, ?$viewerId)`
      branches event vs personal run; `SessionRecapController` passes `optionalUser`.
- [ ] **Task 4 - Frontend** (AC 5, 6). SSR cookie forwarding in the recap fetch (page +
      generateMetadata); recap link + publish toggle on the run detail page.
- [ ] **Task 5 - Tests + gates** (AC 7). Functional tests for the four access cases; both suites green.

## Dev Notes

- `SessionRecapQuery` may inject the PersonalRuns `RunRepositoryInterface` +
  `RunParticipantRepositoryInterface` - `RunResultsQuery` already depends on the run repository, so the
  cross-context read is established.
- For a personal run, `eventName` = `Run::getTitle()`, `vodUrl` = null.
- `RunParticipantRepositoryInterface::findByRunAndUser($runId, $userId)` gives the participant check;
  `Run::isOwnedBy` the owner check.

### Project Structure Notes

- `api/src/PersonalRuns/Domain/Entity/Run.php`, a migration, a new command + `PersonalRunController`
- `api/src/Sessions/Application/Query/SessionRecapQuery.php`, `SessionRecapController.php`
- `frontend/src/features/recap/recap-api.ts`, `app/(public)/parties/[sessionId]/*`, the run detail page

### References

- [Source: _bmad-output/implementation-artifacts/32-1-public-session-recap.md]
- [Source: api/src/Sessions/Application/Handler/BuildSessionRecapJobHandler.php] - already generic

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

### File List