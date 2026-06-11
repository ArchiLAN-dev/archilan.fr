# Story 13.8: Refresh tokens — per-family revocation + reuse grace (stop nuking every session)

**Status:** review
**Epic:** 13 - Auth, refresh tokens & cleanup
**Date:** 2026-06-11

## Story

As a user logged in on one or more devices,
I don't want a single refresh hiccup (a wake-from-sleep race, a lost refresh response) to
**log me out of everything, persistently**,
so that an open passive page (e.g. the run tracker) doesn't end in a dead, un-recoverable
401 state.

## Context

Diagnosis (live): the access token is a 15-min cookie (`__Host-archilan_session`); the
refresh token is a 30-day cookie (`__Secure-archilan_refresh`). `apiFetch` recovers on a 401
via `coordinatedRefresh` → `POST /auth/refresh`, and that path **works** when the refresh
token is valid (verified live: profile 200 → `/auth/refresh` 204 → profile 200).

So a *persistent* logout means the refresh token got **revoked**. The only path that revokes
it is `RotateRefreshToken`: on a re-presented **already-revoked** token it called
`revokeAllForUser` — revoking **every** session the user has, on every device, irreversibly.
A single benign reuse (a passive tab whose `setInterval` refresh was throttled then retried,
or a refresh whose response was lost on wake) therefore logged the user out *everywhere* and
*persistently* (even a reload then fails with `token_reuse_detected`).

Two fixes:

1. **Per-family revocation.** Each login starts a token **family**; rotations stay in the
   family. On a genuine reuse, revoke only **that family** (that login lineage), not the
   user's other devices.
2. **Reuse grace.** A token re-presented within a short window (30 s) of its **own rotation**
   is treated as a benign retry: re-rotate it in the same family instead of tripping reuse
   detection. This is the standard "refresh token rotation with leeway".

(The frontend-side mitigations — refresh on `visibilitychange`/expiry instead of a throttled
`setInterval`, and the SSE re-fetching its subscriber token — are tracked separately; this
story is the server-side blast-radius fix that stops the *persistent* logout.)

## Acceptance Criteria

1. Refresh tokens carry a **family id**: a new login (password or Discord) starts a fresh
   family; a rotation issues the successor in the **same** family.
2. On a genuine reuse (a revoked token with no recent successor re-presented), only the
   **offending family** is revoked (`revokeFamily`), not all the user's tokens. Other
   devices/sessions stay valid.
3. A token re-presented within the **grace window** of its rotation (it has a successor and
   was revoked ≤ 30 s ago) is **re-rotated** (HTTP 204) in the same family, **not** treated
   as reuse (no 401, no revocation).
4. Genuine reuse still returns 401 `token_reuse_detected` and is logged; nothing weakens the
   detection of a real, old stolen-token replay (outside the grace window).
5. Existing rows get a `family_id` (backfilled to their own id) via a reversible migration.
6. Quality gates green - phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`.

## Tasks / Subtasks

- [x] **Task 1 - Domain** (AC: 1,2,3). `RefreshToken`: add `family_id` (set at issue,
  inherited on rotation) + `replaced_by_token_hash`; `issue(..., ?familyId)`;
  `markRotated(successorHash, at)` (revoke + record successor); `wasRotatedWithinGrace(now, secs)`.
- [x] **Task 2 - Factory** (AC: 1). `RefreshTokenFactory::issue` takes an optional `familyId`
  (null → new family).
- [x] **Task 3 - Repository** (AC: 2). `revokeFamily(familyId)` (DBAL, mirrors
  `revokeAllForUser` but scoped to one family).
- [x] **Task 4 - Rotation logic** (AC: 2,3,4). `RotateRefreshToken`: grace branch
  (re-rotate within window), genuine-reuse branch (`revokeFamily`, not `revokeAllForUser`),
  normal rotation marks the parent rotated and issues the child in the same family.
- [x] **Task 5 - Migration** (AC: 5). `family_id` + `replaced_by_token_hash` columns + index;
  backfill `family_id = id`.
- [x] **Task 6 - Tests + gates** (AC: 6). Unit (`RotateRefreshTokenTest`: normal, genuine
  reuse → only-family, grace retry, outside-grace reuse, expired, unknown) + functional
  (`AuthRefreshTest`: reuse revokes only the family / other device survives; grace retry → 204).

## Dev Notes

- `RotateRefreshToken::REUSE_GRACE_SECONDS = 30`.
- Login call sites unchanged (`AuthController`, `DiscordAuthController` call `factory->issue`
  without a family id → new family).
- The grace re-rotation issues a fresh token in the family without revoking the (possibly
  orphaned) original successor — a tiny, bounded fork the cleanup job (story 13.6) reaps.
- Files: `RefreshToken.php`, `RefreshTokenFactory.php`, `RefreshTokenRepositoryInterface.php`,
  `DoctrineRefreshTokenRepository.php`, `RotateRefreshToken.php`, migration
  `Version20260611100003`.

### Non-goals (tracked separately)

- Frontend: refresh on `visibilitychange`/token-expiry instead of the throttled `setInterval`.
- Frontend: the SSE (tracker) re-fetching its Mercure subscriber token on error.

### References

- [Source: api/src/Identity/Application/RotateRefreshToken.php, RefreshTokenFactory.php]
- [Source: api/src/Identity/Domain/RefreshToken.php, RefreshTokenRepositoryInterface.php]
- [Source: api/src/Identity/Infrastructure/DoctrineRefreshTokenRepository.php]
- [Source: api/src/Identity/Presentation/AuthController.php (cookie TTLs: access 900s, refresh 30d)]
- Related: story 13.4 (multi-tab proactive refresh), 13.6 (refresh token cleanup).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `RefreshToken` gains `family_id` + `replaced_by_token_hash`, `markRotated`,
  `wasRotatedWithinGrace`. `RotateRefreshToken` now: grace retry (re-rotate within 30 s of a
  rotation), genuine reuse → `revokeFamily` (not `revokeAllForUser`), normal rotation marks
  the parent rotated and keeps the family.
- Migration backfills `family_id = id` for existing tokens; index on `family_id`.
- Tests: `RotateRefreshTokenTest` (6 unit) + updated/added `AuthRefreshTest` cases. Full
  backend suite green; phpstan / cs-fixer / ddd green.

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-11 | Implemented from a live diagnosis: `revokeAllForUser` on reuse logged the user out of every device, persistently, on a single benign refresh race (passive tab / wake). Switched to per-family revocation + a 30 s reuse grace (re-rotate benign retries). Migration adds `family_id` + `replaced_by_token_hash` (+ backfill). Status → review. |
