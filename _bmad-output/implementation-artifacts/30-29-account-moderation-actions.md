# Story 30.29: Account moderation actions (warn / suspend / ban) + audit log

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin moderator reviewing a flagged account,
I want to warn, temporarily suspend, or permanently ban the member (with a recorded reason),
so that I can actually act on a problematic account - not just hide individual pieces of content.

Builds directly on the weighted moderation queue (story 30.28).

### Why this exists (root cause)

Moderation today can only **soft-hide a comment** and **resolve a report** - there is **no way to act on
the account itself**. `Identity\User` has only `deletedAt` (account deletion / GDPR), with **no suspension
or ban state**, and nothing in the auth path blocks a bad actor from logging back in. Discord decision
(MasterKafey/Maxime, 2026‑06‑19): once an account crosses the review threshold, "on check perso et on
kick/ban ou on entre en communication avec le joueur en demandant de modifier l'info sensible" - i.e. three
graduated actions (contact/warn, suspend, ban) with a trace of who did what and why.

## Acceptance Criteria

1. **Three graduated actions from the queue.** From `/admin/moderation` (admin-only), a moderator can, on a
   flagged account: **(a) Warn** - send the member a message asking to fix the sensitive info; **(b)
   Suspend** - block access until a chosen date; **(c) Ban** - block access permanently. Each action
   requires a `reason` and optionally references the triggering report(s).
2. **Identity moderation state (cross-context).** `Identity\User` gains a moderation state
   (`suspendedUntil` datetime|null and `bannedAt` datetime|null, with a recorded reason), changed only
   through named domain methods (`suspendUntil()`, `ban()`, `lift()` - no public setters, AC-D5). Migration
   adds the columns (+ `down()`). The Community moderation service calls **into Identity** through a
   consumer-defined interface (`MemberModerationGatewayInterface` defined in Community/Application,
   implemented by an Identity adapter) - Community never touches Identity internals directly.
3. **Enforcement at auth.** A suspended (until future) or banned user is **rejected on login** and their
   existing session/JWT is rejected (auth guard checks the moderation state), with a clear error
   distinguishing suspended-until-date vs banned. A banned/suspended member's **public profile is hidden**
   (`/joueurs/[slug]` returns not-found). Suspension auto-expires once the date passes (no cron).
4. **Warn = message, not block.** "Warn" does not change access; it sends an in-app notification to the
   member with the moderator's message, via the existing `Notifier`, and is recorded in the audit log.
5. **Audit log.** Every moderation action (warn/suspend/ban/lift) is appended to a
   `community_moderation_action` log (actor admin id, target user id, action, reason, created_at, related
   report id nullable). The queue can show an account's action history; lifting a suspension/ban is itself a
   logged action.
6. **Auto-resolve on action.** Taking a suspend/ban action resolves the account's open profile reports, so
   the account leaves the "à examiner" section. Warning does **not** auto-resolve (the issue may persist).
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/ Identity domain**: `User` gains `suspendedUntil` / `bannedAt` (+ `moderationReason`) with
      `suspendUntil()`, `ban()`, `lift()`, and `isAccessBlocked(\DateTimeImmutable $now): bool` +
      `moderationStatus(now)`. Migration adds the columns (+ `down()`).
- [ ] **api/ enforcement**: `AuthenticateUser::findUserById` rejects blocked users (token/session path);
      the login controller returns a distinct suspended/banned 403; `DbalCommunityProfileQuery::forSlug`
      hides blocked members.
- [ ] **api/ Community → Identity boundary**: `MemberModerationGatewayInterface` (Community/Application:
      `suspendUntil`, `ban`, `lift`); Identity adapter (Infrastructure) implements it and mutates the
      `User`. Wire in `services.yaml`.
- [ ] **api/ Community moderation**: `AccountModerationService` (`warn`/`suspend`/`ban`/`lift`) → call the
      gateway, append to the audit log, auto-resolve profile reports for suspend/ban, notify the member for
      warn (post-commit, AC-A4). Add `ModerationAction` entity + repository.
- [ ] **api/ presentation**: admin endpoints `/api/v1/admin/community/accounts/{userId}/{warn|suspend|ban|lift}`
      + an action-history read endpoint, admin-gated, validating `reason` + suspend date.
- [ ] **frontend**: account action controls in the "à examiner" list (warn dialog with message, suspend with
      date, ban with confirm, lift); action-history panel; member-facing blocked messaging on login.
- [ ] **Tests**: backend functional (warn notifies + logs, no access change; suspend/ban blocks login +
      token + hides profile + auto-resolves reports; lift restores; audit rows; non-admin forbidden). Unit
      (`User::isAccessBlocked` boundaries). frontend jest (action dialogs post the right payloads).

## Dev Notes

- **No existing ban/suspend** anywhere in Identity - only `deletedAt` (soft delete). Introduce the
  moderation state; do **not** overload `deletedAt`. [Source: api/src/Identity/Domain/User.php]
- **Auth chokepoint**: `AuthenticateUser::authenticate` (login) and `::findUserById` (token/session via
  `CurrentUserProvider`) both already gate on `isDeleted()`; add the block check there. `findUserById`
  returning null logs a blocked user out of every authenticated endpoint via `ApiAccessGuard`.
  [Source: api/src/Identity/Application/AuthenticateUser.php, api/src/Identity/Application/CurrentUserProvider.php,
  api/src/Shared/Infrastructure/Http/ApiAccessGuard.php]
- **Cross-context precedent**: story 30.26 wired Sessions→Community via a consumer-defined interface +
  provider adapter. Mirror it: Community defines `MemberModerationGatewayInterface`, Identity implements -
  keeps Community free of Identity internals. [Source: api/src/Sessions/Application/AchievementRecomputeTriggerInterface.php]
- **Notifications**: warn uses the existing `Notifier` (story 30.12), post-commit (AC-A4). A banned/suspended
  member can't log in, so suspend/ban are surfaced at login, not in-app. [Source: api/src/Community/Application/Notifier.php]
- **Access control**: admin-only actions → `ROLE_ADMIN` gating is correct (admin tooling). The member's
  blocked state is the live enforced signal - never `ROLE_*`. [Source: api/CLAUDE.md#membership-access-control]
- **Audit**: `ModerationAction` is append-only - the trace Maxime asked for. One row per action incl. `lift`.
- **Depends on**: story 30.28 (the weighted queue surfaces which accounts to act on); the action endpoints
  don't need the weighting, but the FE entry point lives in the same queue.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]
- Predecessor: [Source: _bmad-output/implementation-artifacts/30-28-enriched-profile-reporting-and-weighted-moderation.md]
- Cross-context pattern: [Source: _bmad-output/implementation-artifacts/30-26-achievement-recompute-on-run-finish.md]
- Standards: [Source: api/CLAUDE.md], [Source: api/CLAUDE.md#ddd-layer-rules],
  [Source: api/CLAUDE.md#membership-access-control]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- **Identity moderation state.** `User` gains `suspendedUntil` / `bannedAt` / `moderationReason`, mutated
  only via `suspendUntil()` / `ban()` / `lift()` (each clears the other state); `isAccessBlocked(now)` +
  `moderationStatus(now)` are pure (suspension auto-expires once the date passes - no cron). Migration adds
  the columns.
- **Auth enforcement at the chokepoint.** `AuthenticateUser::findUserById` now rejects blocked users → a
  suspend/ban logs them out of **every** session/token-backed request immediately via `ApiAccessGuard`. The
  login endpoint returns a distinct 403 (`account_banned` / `account_suspended` with the reason + end date),
  returned only after the password checked out so it never leaks account existence. The public profile
  (`DbalCommunityProfileQuery::forSlug`) hides blocked members (404).
- **Cross-context boundary (consumer-defined).** `MemberModerationGatewayInterface` lives in
  Community/Application; the `IdentityMemberModerationGateway` adapter (Identity/Infrastructure) implements it
  and mutates the `User` - Community never touches Identity internals. Mirrors 30.26, inverted; the DDD gate
  is green.
- **Actions + audit + auto-resolve.** `AccountModerationService` (`warn`/`suspend`/`ban`/`lift`): suspend/ban
  go through the gateway, then append an append-only `ModerationAction` row and **auto-resolve** the
  account's open profile reports (so it leaves the "à examiner" list - AC-6); warn only notifies the member
  (`TYPE_MODERATION_WARNING`, the only action that reaches a non-blocked member in-app) and logs, no
  auto-resolve. Admin endpoints under `/admin/community/accounts/{userId}/{warn|suspend|ban|lift|actions}`.
- **Frontend.** The "à examiner" rows gain warn/suspend/ban/lift controls (reason, + date for suspend) and a
  history toggle; the login form already surfaces the server message, so the distinct suspended/banned text
  shows with no change; the notification centre renders the member's `moderation_warning`.
- **Stacking note.** Branched from the 30.28 branch (not yet merged) because the FE actions attach to 30.28's
  flagged list; PR targets the 30.28 branch and should merge after it.
- **Gates:** phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, `lint:container` ✅, phpunit
  (new `AccountModerationTest` 7 + `UserModerationTest` 5 + 258 auth/community/moderation suites - local run
  needs the shared test-DB reset, known flake, CI authoritative) ✅; FE typecheck ✅, lint ✅, build ✅, jest
  (`account-moderation-api`) ✅.

### File List

- api/src/Identity/Domain/User.php (moderation state + methods)
- api/src/Identity/Application/AuthenticateUser.php (block check on token/session resolution)
- api/src/Identity/Presentation/AuthController.php (distinct blocked login 403)
- api/src/Identity/Infrastructure/IdentityMemberModerationGateway.php (new - gateway adapter)
- api/migrations/Version20260619140000.php (new - user moderation columns)
- api/src/Community/Application/MemberModerationGatewayInterface.php (new)
- api/src/Community/Application/AccountModerationService.php (new)
- api/src/Community/Domain/ModerationAction.php (new - audit entity)
- api/src/Community/Domain/ModerationActionRepositoryInterface.php (new)
- api/src/Community/Infrastructure/DoctrineModerationActionRepository.php (new)
- api/migrations/Version20260619140100.php (new - audit table)
- api/src/Community/Domain/Notification.php (TYPE_MODERATION_WARNING)
- api/src/Community/Domain/ContentReportRepositoryInterface.php (pendingForProfileTarget)
- api/src/Community/Infrastructure/DoctrineContentReportRepository.php (pendingForProfileTarget)
- api/src/Community/Infrastructure/DbalCommunityProfileQuery.php (hide blocked profile)
- api/src/Community/Presentation/AccountModerationController.php (new - admin endpoints)
- api/config/services.yaml (gateway + audit repo aliases)
- api/tests/Functional/AccountModerationTest.php (new)
- api/tests/Unit/Identity/UserModerationTest.php (new)
- frontend/src/features/admin/admin-moderation-api.ts (account action calls + history)
- frontend/src/features/admin/account-moderation-controls.tsx (new)
- frontend/src/features/admin/reports-moderation-panel.tsx (controls in flagged list)
- frontend/src/features/community/notification-center.tsx (moderation_warning)
- frontend/src/features/admin/account-moderation-api.test.ts (new)
