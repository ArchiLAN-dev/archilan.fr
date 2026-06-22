# Story 30.34: Manually Grant (and Revoke) an Achievement to a User

## Story

**As an** admin,
**I want** to manually award an achievement to a specific user (and undo it if I made a mistake),
**So that** I can recognise something the automatic rules can't capture (an off-platform feat, a one-off
event, a community contribution) without writing a rule for it.

## Context

Achievements are granted automatically by `RecomputeAchievements`, which evaluates each definition's rule
tree against a user's `MetricBag` and creates an `AchievementGrant` (story 30.4, 30.16). Grants are
**monotonic**: recompute never revokes. There is currently no way for an admin to award an achievement
that no rule matches. This story adds a manual grant path that sits beside the engine: it creates the same
`AchievementGrant` row, so the recipient sees the achievement unlocked exactly like an earned one, and it
survives future recomputes (recompute only adds, never removes, and skips already-granted keys).

Revoke is included so an admin can correct a mistaken manual award. (Rule-based grants can also be revoked
this way; the next recompute will simply re-grant them if the user still qualifies, which is the desired
behaviour.)

## Status

done

## Acceptance Criteria

**AC1 (grant):** A new admin-gated endpoint `POST /api/v1/admin/community/achievements/{id}/grants` with
`{ slug }` creates an `AchievementGrant` for that user + the definition's key, unlocked now. It is
**idempotent** (the unique `(user_id, achievement_key)` constraint): granting an already-held achievement
is a no-op success, not a 500. Unknown definition id or unknown/non-listable user -> 404.

**AC2 (revoke):** `DELETE /api/v1/admin/community/achievements/{id}/grants/{slug}` removes that user's
grant for the definition's key (a new `AchievementGrantRepositoryInterface::deleteByUserAndKey`). Removing
a non-existent grant is a no-op success (204). A rule-based grant removed this way may be re-granted on the
next recompute - documented, not prevented.

**AC3 (notify):** on a successful new grant (not on an idempotent no-op), the recipient is notified via the
existing `Notifier` (the same channel recompute uses for unlocks), dispatched after commit. No notification
on revoke.

**AC4 (application service):** an `AdminAchievementGrantService` (Community/Application) resolves the
definition by id to its key, checks the target user is a real listable member, and grants/revokes through
`AchievementGrantRepositoryInterface`. No engine change; manual grants are indistinguishable from earned
ones in the read payloads (profile, catalogue, kudos).

**AC5 (admin UI):** on the admin achievements dashboard, each definition gains a "Attribuer a un joueur"
action that opens a small panel: search/pick a user (reuse the existing community/admin user search), grant,
and a confirmation. The admin can also revoke from the same panel (showing who currently holds it, or by
entering the user). Errors (unknown user, network) surface inline.

**AC6:** All quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest). No em-dashes anywhere (root CLAUDE.md typography rule).

## Tasks / Subtasks

- [x] Task 1: API - `AchievementGrantRepositoryInterface::deleteByUserAndKey(userId, key)` + the Doctrine
  implementation (remove the grant row if present).
- [x] Task 2: API - `AdminAchievementGrantService`: `grant(definitionId, slug)` /
  `revoke(definitionId, slug)`. Resolve definition -> key; validate the user is a real listable member
  (reuse the directory/cards or user lookup); idempotent grant; post-commit `Notifier` on a new grant.
- [x] Task 3: API - routes on `AdminAchievementController`: `POST /{id}/grants` and
  `DELETE /{id}/grants/{slug}`, admin-gated, mapping not-found -> 404, success -> 201/204.
- [x] Task 4: API tests - grant creates a grant the profile then shows; granting twice is a no-op;
  revoke removes it; unknown definition/user -> 404; a granted user is notified; a manually-granted
  rule-less achievement survives a recompute.
- [x] Task 5: Frontend - `admin-achievements-api.ts`: `grantAchievement(id, userId)` /
  `revokeAchievement(id, userId)` clients. Dashboard "Attribuer a un joueur" panel with a user picker.
- [x] Task 6: Frontend tests (jest) - the grant/revoke clients return success/failure correctly; the
  panel emits the right call.
- [x] Task 7: Quality gates.

## Dev Notes

### Sits beside the engine, no engine change

Manual grant writes the same `AchievementGrant` the rule engine writes. Recompute is monotonic and skips
already-granted keys, so a manual grant is never duplicated nor revoked by it. This is exactly why a manual
grant is durable for things no rule can express.

### User picker

Reuse an existing member search rather than building a new one: the community directory search
(`/community/directory?search=`) or the admin user directory both return slug + display name. The grant
endpoint takes the resolved `userId`.

### Revoke semantics

Revoke is for correcting a mistaken manual award. For a rule-based achievement, revoke is transient (the
next recompute re-grants it if still earned) - surface this in the UI copy so admins aren't surprised.

### Out of scope

- A per-achievement "manual vs earned" provenance flag / audit log (a later story if needed).
- Bulk grant to many users at once.
- Granting a deactivated definition is allowed (admins may award retired achievements); no extra gate.
