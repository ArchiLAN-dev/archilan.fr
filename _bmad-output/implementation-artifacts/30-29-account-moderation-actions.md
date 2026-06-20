# Story 30.29: Account moderation actions (warn / suspend / ban) + audit log

Status: drafted

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin moderator reviewing a flagged account,
I want to warn, temporarily suspend, or permanently ban the member (with a recorded reason),
so that I can actually act on a problematic account — not just hide individual pieces of content.

Builds directly on the weighted moderation queue (story 30.28).

### Why this exists (root cause)

Moderation today can only **soft-hide a comment** and **resolve a report** — there is **no way to act on
the account itself**. `Identity\User` has only `deletedAt` (account deletion / GDPR), with **no suspension
or ban state**, and nothing in the auth path blocks a bad actor from logging back in. Discord decision
(MasterKafey/Maxime, 2026‑06‑19): once an account crosses the review threshold, "on check perso et on
kick/ban ou on entre en communication avec le joueur en demandant de modifier l'info sensible" — i.e. three
graduated actions (contact/warn, suspend, ban) with a trace of who did what and why.

## Acceptance Criteria

1. **Three graduated actions from the queue.** From `/admin/moderation` (admin-only), a moderator can, on a
   flagged account: **(a) Warn** — send the member a message asking to fix the sensitive info; **(b)
   Suspend** — block access until a chosen date; **(c) Ban** — block access permanently. Each action
   requires a `reason` and optionally references the triggering report(s).
2. **Identity moderation state (cross-context).** `Identity\User` gains a moderation state
   (`suspendedUntil` datetime|null and/or `bannedAt` datetime|null, with a recorded reason), changed only
   through named domain methods (`suspendUntil()`, `ban()`, `lift()` — no public setters, AC-D5). Migration
   adds the columns (+ `down()`). The Community moderation service calls **into Identity** through a
   consumer-defined interface (`MemberModerationGatewayInterface` defined in Community/Application,
   implemented by an Identity adapter) — Community never touches Identity internals directly.
3. **Enforcement at auth.** A suspended (until future) or banned user is **rejected on login** and their
   existing session/JWT is rejected (auth guard checks the moderation state), with a clear error
   distinguishing suspended-until-date vs banned. A banned/suspended member's **public profile is hidden**
   (directory + `/joueurs/[slug]` return not-found / gated), reusing the existing visibility gating.
4. **Warn = message, not block.** "Warn" does not change access; it sends an in-app notification (and/or
   email) to the member with the moderator's message, via the existing `Notifier` / mail path, and is
   recorded in the audit log.
5. **Audit log.** Every moderation action (warn/suspend/ban/lift) is appended to a
   `community_moderation_action` log (actor admin id, target user id, action, reason, created_at, related
   report id nullable). The queue shows an account's action history; lifting a suspension/ban is itself a
   logged action.
6. **Auto-resolve on action.** Taking a suspend/ban action resolves the account's open reports (or marks
   them actioned), so the account leaves the "à examiner" section. Warning does **not** auto-resolve (the
   issue may persist).
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/ Identity domain**: `User` gains `suspendedUntil` / `bannedAt` (+ `moderationReason`) with
      `suspendUntil()`, `ban()`, `lift()`, and an `isAccessBlocked(\DateTimeImmutable $now): bool` query.
      Migration adds the columns (+ `down()`).
- [ ] **api/ enforcement**: reject blocked users in the auth path (login + token guard) with distinct
      suspended/banned messaging; ensure `ApiAccessGuard` / the security layer consults the state. Hide
      blocked members from the directory + public profile (extend the existing visibility/gating).
- [ ] **api/ Community → Identity boundary**: `MemberModerationGatewayInterface` (Community/Application:
      `warn`, `suspendUntil`, `ban`, `lift`); Identity adapter (Infrastructure) implements it and mutates
      the `User`. Wire in `services.yaml`.
- [ ] **api/ Community moderation**: extend `ModerationService` (or a new `AccountModerationService`) with
      `warn()`, `suspend()`, `ban()`, `lift()` → call the gateway, append to the audit log, auto-resolve
      reports for suspend/ban, notify the member for warn (post-commit, AC-A4). Add
      `ModerationAction` entity + repository (`community_moderation_action`).
- [ ] **api/ presentation**: admin endpoints under `/api/v1/admin/community/accounts/{userId}/...`
      (`warn` / `suspend` / `ban` / `lift`), admin-gated, validating `reason` + suspend date.
- [ ] **frontend**: account action controls in `/admin/moderation` (warn dialog with message, suspend with
      date picker, ban with confirm, lift); account action-history panel; surfaced blocked state.
- [ ] **Tests**: backend functional (warn notifies + logs, no access change; suspend/ban blocks login +
      token + hides profile + auto-resolves reports; lift restores access; audit rows written; non-admin
      forbidden). Unit (`User::isAccessBlocked` boundaries around `suspendedUntil`). frontend jest (action
      dialogs post the right payloads).

## Dev Notes

- **No existing ban/suspend** anywhere in Identity — only `deletedAt` (soft delete). This story introduces
  the moderation state; do **not** overload `deletedAt` (deletion ≠ ban). [Source: api/src/Identity/Domain/User.php]
- **Cross-context precedent**: story 30.26 wired Sessions→Community via a consumer-defined interface +
  provider adapter (Sessions defines, Community implements). Mirror it the other way: Community defines
  `MemberModerationGatewayInterface`, Identity implements — keeps the dependency direction clean and Community
  free of Identity internals. [Source: api/src/Sessions/Application/AchievementRecomputeTriggerInterface.php,
  api/src/Community/Infrastructure/MessengerAchievementRecomputeTrigger.php]
- **Auth enforcement**: find where login + token validation resolve the `User` and add an
  `isAccessBlocked()` gate; reuse `ApiAccessGuard` so every endpoint inherits it. Distinguish suspended
  (temporary, show until-date) from banned (permanent). [Source: api/src/Identity/]
- **Notifications**: warn uses the existing `Notifier` (story 30.12) and/or the mail path; emitted
  post-commit (AC-A4). [Source: api/src/Community/Application/Notifier.php]
- **Access control**: these are admin-only actions — `ROLE_ADMIN` gating is correct here (admin tooling,
  not member-feature gating, so AC-M doesn't apply). The *member's* blocked state is the live, enforced
  signal — never `ROLE_*` for that. [Source: api/CLAUDE.md#membership-access-control]
- **Audit**: `ModerationAction` is append-only (no edit/delete) — it's the moderation trace Maxime asked
  for. One row per action including `lift`.
- **Depends on**: story 30.28 (the weighted queue surfaces *which* accounts to act on). Can be built after
  it lands; the action endpoints don't need the weighting, but the FE entry point lives in the same queue.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]
- Predecessor: [Source: _bmad-output/implementation-artifacts/30-28-enriched-profile-reporting-and-weighted-moderation.md]
- Cross-context pattern: [Source: _bmad-output/implementation-artifacts/30-26-achievement-recompute-on-run-finish.md]
- Standards: [Source: api/CLAUDE.md], [Source: api/CLAUDE.md#ddd-layer-rules],
  [Source: api/CLAUDE.md#membership-access-control]
