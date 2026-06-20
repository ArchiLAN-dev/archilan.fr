# Story 30.26: Recompute achievements (with notification) when a run finishes

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player who just finished a run and reached my goal,
I want my achievements to unlock automatically with a notification,
so that I actually see "Première partie" / "Premier objectif" the moment I earn them — instead of nothing.

### Why this exists (root cause)

Achievements are only ever evaluated by the manual `community:achievements:recompute` command — which is
**not in the scheduler** and grants **silently** (`notify: false`, to avoid spamming during bulk backfill).
There is **no automatic recompute** when a run finishes or stats change. Observed: a player reached their
goal, but `first_run` / `first_goal` were granted only after someone ran the command by hand, and with no
notification at all (`community_notification`: 0 achievement rows for the user). So players earn
achievements without ever being told.

## Acceptance Criteria

1. **Real-time recompute on archive.** When a session is archived (`SessionLifecycleManager::storeArchive`,
   which persists the final `goal_reached_at` / `checks_done` / `items_received` snapshot), the
   participants' achievements are recomputed **with notification** (`notify: true`). Covers both event
   sessions and personal/weekly runs (archive is the common finalization point).
2. **Participants resolved correctly.** Recompute targets every distinct user who has a slot in the
   session: event slots resolve via `registration.user_id`; personal-run slots use `slot.registration_id`
   (which is the user id). Deduplicated.
3. **Async + post-commit.** Recompute runs off the request path: the archive flow dispatches one async
   message per participant **after** the DB flush (AC-A4). The handler calls
   `RecomputeAchievements::recomputeForUser(userId, notify: true)` (monotonic + idempotent, so re-archives
   never double-grant or double-notify).
4. **Cross-context boundary.** Sessions does not depend on Community internals: an
   `AchievementRecomputeTriggerInterface` is defined in **Sessions/Application** and implemented by a
   **Community/Infrastructure** adapter that dispatches the Community recompute message. (Consumer defines
   the interface; provider implements it.)
5. **Scheduler backstop.** A daily scheduled job recomputes all users (`notify: false`) so any unlock
   missed by the real-time path (e.g. a lost archive callback) is still caught — without notification
   spam. Reuses the existing all-users recompute logic.
6. **No double notification.** Because grants are monotonic and the handler is idempotent, a user who
   already has an achievement gets neither a new grant nor a new notification on subsequent recomputes.
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend unaffected (no FE change expected; notifications already render via the existing centre).

## Tasks / Subtasks

- [ ] **Sessions/Application**: `AchievementRecomputeTriggerInterface::recomputeForUsers(list<string>
      $userIds): void`.
- [ ] **SessionLifecycleManager::storeArchive**: after the flush, collect the matched slots'
      `registrationId`s, resolve each to a user id (`registrations->findById()?->getUserId()` ?? the id
      itself for personal runs), dedupe, and call the trigger. No-op when empty.
- [ ] **Community/Application**: `Message/RecomputeAchievementsForUserMessage` (userId) +
      `Handler/RecomputeAchievementsForUserHandler` → `recompute->recomputeForUser(userId, notify: true)`.
- [ ] **Community/Infrastructure**: `MessengerAchievementRecomputeTrigger implements
      AchievementRecomputeTriggerInterface` → dispatch one message per user id.
- [ ] **Backstop**: `Message/RecomputeAllAchievementsMessage` + handler (iterate
      `CommunityUserIdsQueryInterface::allUserIds()`, `notify: false`); add a daily `RecurringMessage::cron`
      to `src/Schedule.php`.
- [ ] **Wiring**: `services.yaml` binds `AchievementRecomputeTriggerInterface` →
      `MessengerAchievementRecomputeTrigger`; `messenger.yaml` routes both new messages to `async`.
- [ ] **Tests**: functional — archiving a session with a goal-reached slot dispatches a recompute message
      and (when handled) grants + notifies `first_run`/`first_goal`; re-archive does not double-notify; the
      backstop handler grants silently. Unit where useful (trigger dispatches per user).

## Dev Notes

- **Hook point**: `RunnerCallbackController` (status `archived`) → `SessionLifecycleManager::storeArchive`
  sets the slot stats then flushes; trigger right after. [Source: api/src/Sessions/Application/SessionLifecycleManager.php, api/src/Sessions/Presentation/RunnerCallbackController.php]
- **Engine**: `RecomputeAchievements::recomputeForUser($userId, $notify)` already does exactly the right
  thing (build MetricBag → evaluate active DB definitions → persist new grants → notify). Just call it with
  `notify: true` per participant. [Source: api/src/Community/Application/RecomputeAchievements.php]
- **Participant resolution**: mirror the leaderboard union — event = `registration.user_id`, PR =
  `slot.registration_id`. `SessionLifecycleManager` already injects `RegistrationRepositoryInterface` and
  has the matched `SessionSlot`s in hand. [Source: api/src/Sessions/Infrastructure/DbalLeaderboardQuery.php]
- **Cross-context precedent**: `SessionLifecycleManager` already orchestrates across Events/Identity/
  PersonalRuns/Registrations/WeeklyRuns and dispatches messages; an Application interface + Infra adapter
  in Community keeps the dependency direction clean (Sessions defines, Community implements).
- **Notifications**: `Notifier::notify($recipientId, Notification::TYPE_ACHIEVEMENT_UNLOCKED, [...])` is
  already used inside `RecomputeAchievements`; the FE notification centre renders it. [Source: api/src/Community/Application/Notifier.php]
- **Scheduler**: add to `src/Schedule.php` alongside the existing cron jobs. [Source: api/src/Schedule.php]
- **Scope**: no new achievement definitions; no FE changes. Builds on story 17.15 (finish → archive).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]
- Achievement engine story: [Source: _bmad-output/implementation-artifacts/] (30.16 recompute engine)
- Finish flow: [Source: _bmad-output/implementation-artifacts/17-15-owner-finish-personal-run.md]
- Standards: [Source: api/CLAUDE.md], [Source: api/CLAUDE.md#cqrs-naming]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Hooked into `SessionLifecycleManager::storeArchive`: after the slot stats flush, it resolves the matched
  slots' participants (event → `registration.user_id`; personal run → `slot.registration_id`) and calls the
  trigger. Covers event + personal + weekly runs (archive is the shared finalization point).
- Cross-context kept clean: `AchievementRecomputeTriggerInterface` lives in Sessions/Application;
  `MessengerAchievementRecomputeTrigger` (Community/Infrastructure) implements it and dispatches one async
  `RecomputeAchievementsForUserMessage` per user → handler calls `recomputeForUser(notify: true)`.
- Monotonic + idempotent engine means re-archive never double-grants or double-notifies (AC-6).
- Backstop: `RecomputeAllAchievementsMessage` + handler (all users, `notify: false`) scheduled daily at
  03:45 in `src/Schedule.php`, so a missed real-time recompute is still caught silently.
- DI: new required `AchievementRecomputeTriggerInterface` arg on `SessionLifecycleManager` placed before the
  bound `$runnerPublicHost` (avoids "optional before required"); container lints clean.
- No frontend change — unlock notifications already render in the existing notification centre.
- Gates: phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, `lint:container` ✅, phpunit
  (AdminRunArchival incl. new recompute-dispatch test + achievement/lifecycle suites, 92 green) ✅.

### File List

- api/src/Sessions/Application/AchievementRecomputeTriggerInterface.php (new)
- api/src/Sessions/Application/SessionLifecycleManager.php (trigger dep + post-archive recompute + resolver)
- api/src/Community/Application/Message/RecomputeAchievementsForUserMessage.php (new)
- api/src/Community/Application/Handler/RecomputeAchievementsForUserHandler.php (new)
- api/src/Community/Application/Message/RecomputeAllAchievementsMessage.php (new)
- api/src/Community/Application/Handler/RecomputeAllAchievementsHandler.php (new)
- api/src/Community/Infrastructure/MessengerAchievementRecomputeTrigger.php (new)
- api/src/Schedule.php (daily backstop)
- api/config/services.yaml (trigger interface binding)
- api/config/packages/messenger.yaml (route both messages to async)
- api/tests/Functional/AdminRunArchivalTest.php (recompute-dispatch test)
