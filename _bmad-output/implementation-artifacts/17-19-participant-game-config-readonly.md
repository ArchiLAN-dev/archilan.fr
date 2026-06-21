# Story 17.19: Participant Game & YAML Config — Read-Only Inspection

## Story

**As a** player viewing a run,
**I want** to click a participant and see, read-only, the games they selected and the YAML configuration
applied to each slot,
**So that** I can understand what the others are playing and how their worlds are configured without
having to ask them.

## Context

Follow-up to story 17.18 (richer participant cards + profile/game links). The run detail page
(`/runs/{runId}?tab=participants`) lists participants but offers no way to inspect what each one is
actually playing. Personal runs are collaborative: members want to see each other's game selection and
applied YAML. Today only `GET /runs/{runId}/participants/me/game-selection` exists — it returns the
**caller's own** slots and is read-write. There is no way to view **another** participant's slots.

## Status

done

## Acceptance Criteria

**AC1:** A **dedicated page** `/runs/{runId}/participants/{participantId}` shows the participant's identity
(community pseudo + profile photo, reusing the community card, linking to `/joueurs/{slug}`) and their
selected games. The run detail "participants" tab links each participant there. Nice, responsive layout.

**AC2:** Each game shows its name (linking to `/jeux/{slug}` in a new tab when the game has a public page)
and a **read-only** YAML viewer opened in a modal with **two tabs**: a **visual** view (the shared
`YamlOptionsView` also used by the weekly-run game page — labelled options, weighted-dict distribution
bars) and a **textual** view (raw YAML). A slot with no YAML is clearly labelled.

**AC3:** A new endpoint `GET /runs/{runId}/participants/{participantId}/game-selection` returns the target
participant's identity (`userId`, `slug`, `displayName`, `avatarUrl`) and slots (`slotId`, `gameId`,
`gameName`, `gameSlug`, `coverImageUrl`, `availability`, `playerYaml`). It does **not** return the
editable `availableGames`/`recentlyPlayedGames` catalogue — it is a read-only projection.

**AC4 (access control):** The endpoint is authorized for the run **owner** and **any participant** of the
run (collaborative visibility). A user who is neither owner nor participant gets `403`. An unknown run or
a `participantId` that is not a participant of the run gets `404`. Unauthenticated → `401`.

**AC5:** All quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest).

## Tasks / Subtasks

- [x] Task 1: API — `PersonalRunGameSelection::getParticipantSlots(runId, viewerId, participantId)`
  returns the target participant's read-only slots. Viewer authorized iff owner or participant; target
  must be a participant of the run. Enriches each slot with game name/slug/cover/availability via
  `GameRepositoryInterface::findByIds`.
- [x] Task 2: API — new route `GET /api/v1/runs/{runId}/participants/{participantId}/game-selection` in
  `PersonalRunController`, mapping found→404 / authorized→403, returning `{ data: { slots } }`.
- [x] Task 3: API tests — `PersonalRunParticipantGameSelectionTest` asserts: owner can read a
  participant's slots+YAML; a co-participant can; a stranger gets 403; unknown participant gets 404;
  unauthenticated gets 401.
- [x] Task 4: Frontend — dedicated page `PersonalRunParticipantDetailPage` + route
  `/runs/[runId]/participants/[participantId]`; participants tab links each participant there. Player
  header (avatar + pseudo + profile link) and a responsive games grid.
- [x] Task 5: Frontend — per-game read-only YAML modal `PersonalRunYamlViewerDialog` with Visual / Texte
  tabs. Visual reuses the shared `@/components/yaml/yaml-options-view` (extracted from the weekly-run
  game page, which now consumes it too). Handles loading / 401 / 403 / 404 / error states; game name
  links to `/jeux/{slug}` (new tab) when the game has a public page.
- [x] Task 6: Quality gates — phpstan, php-cs-fixer, phpunit (PersonalRun suite 122 green), DDD validator;
  frontend typecheck/lint/build/jest (137 green).

## Dev Notes

### Access model

Decision (owner): **all participants** of a run may inspect each other's selection + YAML — consistent
with the collaborative nature of personal runs. The owner is always authorized (even before they have a
`RunParticipant` row). Mirror the owner-or-participant check already used by `PersonalRunDrafts::get`.

### Reuse

- Backend slot enrichment mirrors `getMySlots` (game name/slug/cover/availability) but drops the
  read-write catalogue payload.
- Frontend: the participants tab and its `ParticipantList` live in
  `frontend/src/features/personal-runs/personal-run-detail-page.tsx`; reuse the existing in-file modal
  pattern (`StopDialog`/`FinishDialog`) and the YAML read-only rendering idiom from the slot pages.

### Out of scope

- No editing of another participant's YAML (read-only only).
- No new realtime/notifications.