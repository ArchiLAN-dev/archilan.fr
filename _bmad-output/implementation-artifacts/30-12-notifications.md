# Story 30.12: In-app notification center

Status: done (retroactively documented)

## Story

As a member,
I want an in-app notification center,
so that I'm told when someone gives me kudos, sends me a friend request, comments on my profile, or when I
unlock an achievement. Deps: 30.7 (friendships), 30.10 (comments), 30.11 (kudos), 30.4 (achievements).

A `Notification` model + a `Notifier` port emitted from the existing write paths (kudos, friend request,
comment, achievement unlock) **after** the DB commit, a bell with an unread count, and a near-real-time push.

## Acceptance Criteria

1. `Notification` (recipient, `type`, JSON `payload`, `created_at`, nullable `read_at`); a `Notifier`
   interface in Application, implemented by `NotificationService`. Producers depend on the `Notifier` port,
   never on the concrete service.
2. Notifications are emitted as a **side effect after** the originating unit of work commits (never inline
   before flush): kudos received, friend request received, profile comment posted, achievement unlocked.
   The recompute backfill must **not** spam historical unlocks (`notify: false`).
3. Endpoints (auth): list recipient notifications (recent + unread count), mark read / mark all read.
4. The public shell shows a bell with the live unread count and a dropdown center; updates push in near
   real time over the Realtime (Mercure/SSE) channel — without triggering a reconnect storm (review fix:
   isolate emission + stabilise the SSE subscription).
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `Notification` (+ type constants, `markRead`) + `NotificationRepositoryInterface`.
- [x] **api/ Application:** `Notifier` (port) + `NotificationService` (create + list + mark-read); wire the
      emitters in `KudosService`, `FriendshipService`, `ProfileCommentService`, `RecomputeAchievements`.
- [x] **api/ Infrastructure:** `DoctrineNotificationRepository`.
- [x] **api/ Presentation:** `CommunityNotificationController` (list / mark-read).
- [x] **api/ Realtime:** publish a notification event via `RealtimePublisher` / `RealtimeController`
      (per-recipient topic).
- [x] **api/ Migration:** `community_notification` (index on `recipient_id, read_at`).
- [x] **api/ tests:** functional `CommunityNotificationTest`; `RecomputeAchievementsTest` updated for the
      `notify` flag.
- [x] **frontend:** `notifications-api.ts` + `notification-center.tsx` (bell + dropdown) in `public-shell`.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- A single `Notifier` port is injected into every existing write service rather than each service knowing
  about notifications — adding a new notification type = one more `notify(...)` call, no new plumbing.
- Reuses the existing Realtime (Mercure) infrastructure for the push instead of adding a websocket stack.

### Architecture guardrails
- Emission is a post-commit side effect (AC-A4): the write commits first, the notification is created
  after, so a notification failure can't roll back the action and the read stays consistent.
- The backfill recompute passes `notify: false` so re-running it never floods members with historical
  achievement unlocks.
- Review fix (`c6e5358`): isolate the emission and stabilise the SSE subscription so the client stops
  reconnecting in a storm.

### Scope boundaries / deviations
- In-app only — no email/push digest (out of scope).
- Per-type preferences/muting deferred.

### Project Structure Notes
- New api: `Community/Domain/Notification`, `Community/Application/{Notifier,NotificationService}`,
  `Community/Domain/NotificationRepositoryInterface`, `Community/Infrastructure/DoctrineNotificationRepository`,
  `Community/Presentation/CommunityNotificationController`, migration `Version20260618160000`,
  `tests/Functional/CommunityNotificationTest`.
- Modified api: `KudosService`, `FriendshipService`, `ProfileCommentService`, `RecomputeAchievements` (+
  command + test), `Realtime/{Application/RealtimePublisher,Presentation/RealtimeController}`, `services.yaml`.
- New frontend: `features/community/{notifications-api.ts,notification-center.tsx}`. Modified:
  `components/public-shell.tsx`.

### References
- Epic §C/§I + story 30.12 (Track 4). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Notification center driven by a `Notifier` port emitted post-commit from kudos/friendship/comment/
  achievement paths, with a live unread bell and Mercure push.
- Implemented in commit `4035725` (+ review fix `c6e5358`: isolate emission, stop SSE reconnect storm),
  merged via PR #153.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityNotificationTest`); typecheck / lint / build / jest clean.

### File List

**Added (api)**
- `api/src/Community/Domain/Notification.php`
- `api/src/Community/Domain/NotificationRepositoryInterface.php`
- `api/src/Community/Application/Notifier.php`
- `api/src/Community/Application/NotificationService.php`
- `api/src/Community/Infrastructure/DoctrineNotificationRepository.php`
- `api/src/Community/Presentation/CommunityNotificationController.php`
- `api/migrations/Version20260618160000.php`
- `api/tests/Functional/CommunityNotificationTest.php`

**Modified (api)**
- `api/src/Community/Application/KudosService.php`
- `api/src/Community/Application/FriendshipService.php`
- `api/src/Community/Application/ProfileCommentService.php`
- `api/src/Community/Application/RecomputeAchievements.php`
- `api/src/Community/Presentation/RecomputeAchievementsCommand.php`
- `api/src/Realtime/Application/RealtimePublisher.php`
- `api/src/Realtime/Presentation/RealtimeController.php`
- `api/tests/Unit/Community/RecomputeAchievementsTest.php`
- `api/config/services.yaml` (Notifier binding + repository)

**Added (frontend)**
- `frontend/src/features/community/notifications-api.ts`
- `frontend/src/features/community/notification-center.tsx`

**Modified (frontend)**
- `frontend/src/components/public-shell.tsx` (notification bell)
