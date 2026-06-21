# Story 17.18: Run Navigation — Participant Profiles, Richer Participant Cards, Game Pages

## Story

**As a** player viewing a run,
**I want** to jump to a participant's player profile and to a game's page from the run screens, and to
see participants with their community pseudo + profile photo,
**So that** I can navigate the community fluidly instead of hitting dead ends.

## Context

Feedback after the v0.5.1 deploy: no way to reach a player's profile from a run's participants, nor a
game's page from the run game-selection; and participants should show the community pseudo + avatar.

## Status

done

## Acceptance Criteria

**AC1:** On the personal-run detail page, each participant links to their public profile
(`/joueurs/{slug}`) when a slug is available; otherwise the name is plain text (no broken link).

**AC2:** Each participant shows their **community pseudo** (display-name override) and **profile photo**
(resolved avatar), falling back to the account name / initials when none.

**AC3:** On the run game-selection page, each game's name links to its public game page
(`/jeux/{slug}`), opening in a new tab so the selection flow isn't lost. Selecting a game (the "Ajouter"
button) is unaffected.

**AC4:** The `GET /runs/{id}` participant payload exposes `slug`, `displayName` (community override) and
`avatarUrl`, resolved via the existing community card query (`CommunityUserDirectoryQueryInterface`),
reusing the avatar precedence + slug from the rest of the community surfaces.

**AC5:** All quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest).

## Tasks / Subtasks

- [x] Task 1: API — `PersonalRunDrafts::getParticipants` injects `CommunityUserDirectoryQueryInterface`
  and merges each participant's `slug` / community `displayName` / `avatarUrl` from `cards()`, falling
  back to the account name/email when no visible card.
- [x] Task 2: API tests — `PersonalRunInviteTest` asserts the participant payload exposes `slug` +
  `avatarUrl`; `PersonalRunDraftsListMineTest` updated for the new constructor dependency.
- [x] Task 3: Frontend — `PersonalRunParticipant` gains `slug` + `avatarUrl`; the participant list
  renders a photo (initials fallback) and links the name to `/joueurs/{slug}`.
- [x] Task 4: Frontend — run game-selection list links each game name to `/jeux/{slug}` (new tab,
  external-link icon), without interfering with the add-game button.
- [x] Task 5: Quality gates.

## Dev Notes

### Reusing community cards

`CommunityUserDirectoryQueryInterface::cards(userIds)` already returns `{userId, slug, displayName,
avatarUrl}` with the community display-name override and resolved avatar. Reusing it keeps participant
identity consistent with the directory/leaderboard and avoids a new query. A user with no visible card
(none / banned / suspended / deleted) is filtered out of `cards()`; we fall back to the account name
with no avatar and no profile link, which is the desired behaviour.

### Game-selection link opens a new tab

The game-selection screen is an active flow (building the run's game set). Linking the game name in the
same tab would lose the search/scroll state, so the game page opens in a new tab with an external-link
affordance. Added games are persisted server-side regardless, so navigating away is non-destructive.

## File List

- `api/src/PersonalRuns/Application/PersonalRunDrafts.php` — modified
- `api/tests/Functional/PersonalRunInviteTest.php` — modified
- `api/tests/Unit/PersonalRuns/PersonalRunDraftsListMineTest.php` — modified
- `frontend/src/features/personal-runs/types.ts` — modified
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` — modified
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx` — modified

## Change Log

| Date | Change |
|------|--------|
| 2026-06-21 | Story created and implemented (post-v0.5.1 navigation feedback) |
