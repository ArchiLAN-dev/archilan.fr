# Story 16.2: Invite Link Generation and Join Flow

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-05-12

## Story

As a run owner,
I want to share a private invite link,
So that friends can join my personal run without it being publicly discoverable.

## Acceptance Criteria

1. `POST /api/v1/runs/{runId}/invite/regenerate` (owner only) generates a new `invite_token` (old token invalidated, existing participants unaffected) and returns `{ inviteToken, inviteUrl }` where `inviteUrl = {SITE_URL}/runs/join/{newToken}`.
2. `GET /api/v1/runs/join/{inviteToken}`: authenticated user → creates `PersonalRunParticipant` record, returns 200 with run payload. Idempotent: already a participant → 200 (no duplicate). Unauthenticated → 401 `auth_required`. Invalid or cancelled token → 404.
3. If the **owner** calls `GET /api/v1/runs/join/{inviteToken}` (i.e., follows their own link), the response is 200 with the run payload; no participant record is created (owner is implicitly always a member).
4. `GET /api/v1/runs/{runId}` response payload includes `isOwner: boolean` (true when caller is owner) and `participants: [{ userId, joinedAt }]`.
5. A Doctrine migration creates `personal_run_participants` (personal_run_id FK, user_id FK, joined_at; composite PK).

## Tasks / Subtasks

- [x] Task 1: Domain (AC: 2–5)
  - [x] Create `PersonalRunParticipant` entity: personal_run_id, user_id, joined_at
  - [x] Doctrine migration for `personal_run_participants` table
  - [x] Add `getParticipants(): array` via application layer query (not Doctrine relation on entity to keep domain clean)

- [x] Task 2: API endpoints (AC: 1–4)
  - [x] `POST /api/v1/runs/{runId}/invite/regenerate` → regenerate token (owner only)
  - [x] `GET /api/v1/runs/join/{inviteToken}` → join endpoint (authenticated, idempotent, owner-aware)
  - [x] `GET /api/v1/runs/invite/{inviteToken}/preview` → public (no auth), returns `{ title, ownerName, participantCount, status }`, 404 if not found or cancelled
  - [x] Update `PersonalRunDrafts::payload()` to include `isOwner: bool` and `participants: [{ userId, joinedAt }]`

- [x] Task 3: Tests
  - [x] Functional: join as new participant (200), join idempotent (200), owner follows own link (200, no participant record created), unauthenticated (401), invalid token (404), cancelled run (404), regenerate token as owner (200), regenerate token as non-owner (403), get payload includes isOwner=true for owner and isOwner=false for participant, preview endpoint returns 200 without auth, preview returns 404 for cancelled run

## Dev Notes

- `SITE_URL` is the canonical env var for constructing absolute URLs (e.g. `https://archilan.fr`). Frontend uses the same env var via `src/lib/env.ts`.
- Regenerating token invalidates the old token but must NOT remove participant records - participants joined by user ID, not token.
- Owner detection in the join endpoint: compare `$request->userId` to `$run->getOwnerId()`; if equal, return 200 with payload, skip participant creation.
- `participants` in payload: only includes users who explicitly joined via link, not the owner.

### References

- Story 16.1: `PersonalRun` entity, `PersonalRunDrafts` service

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `PersonalRunParticipant` uses composite PK via two `#[ORM\Id]` attributes (Doctrine standard). No FK annotation on entity - FK is in the migration only (tests use SchemaTool which skips migration FKs).
- `get()` returns `{found, authorized, payload}` to let the controller distinguish 404 from 403 without two separate queries.
- `joinByToken()` uses `getOneOrNullResult()` on `inviteToken` field. Idempotency handled via `EntityManager::find()` composite PK lookup before persist.
- `previewByToken()` queries participant count with a COUNT scalar query to avoid loading all participant objects.
- `$siteUrl` injected via global `bind` in services.yaml - defaults to empty string when `SITE_URL` env var not set (tests get relative URL `/runs/join/{token}`).
- Codex 16.1 findings applied in same pass: FK in migration, cancel restricted to draft|idle, stable listForOwner sort, PHPStan test helpers, CS-Fixer docblock.
- `PersonalRuns/Domain` added to services.yaml exclude list (domain entities must not be registered as services).

### Debug Log

PHPStan: `assertIsArray($data)` on already-typed `array<string, mixed>` from `responseData()` - removed redundant assertion.

### File List

- `api/src/PersonalRuns/Domain/PersonalRun.php` (modified - added `regenerateInviteToken()`)
- `api/src/PersonalRuns/Domain/PersonalRunParticipant.php` (new)
- `api/src/PersonalRuns/Application/PersonalRunDrafts.php` (modified - new methods, updated payload/get/cancel)
- `api/src/PersonalRuns/Presentation/PersonalRunController.php` (modified - 3 new endpoints, updated get/cancel)
- `api/migrations/Version20260513130000.php` (new - personal_run_participants)
- `api/migrations/Version20260513120000.php` (modified - FK owner_id → identity_users)
- `api/config/services.yaml` (modified - $siteUrl binding, PersonalRuns/Domain exclude)
- `api/tests/Functional/PersonalRunInviteTest.php` (new - 16 tests)
- `api/tests/Functional/PersonalRunTest.php` (modified - PHPStan helpers, completed/cancelled delete test)

### Change Log

- 2026-05-12: Story 16.2 implemented - invite link, join flow, participant tracking, preview endpoint, 29 functional tests (all passing, PHPStan level 8 clean, CS-Fixer clean). Includes Codex 16.1 corrections.
