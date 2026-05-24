# Story 22.3: Symfony Scheduler - Daily Expiry & Reminder Dispatch

## Story

**As a** system,
**I want** a daily scheduled task that detects expired memberships and dispatches expiry and reminder messages for each,
**So that** role demotion and email notifications happen automatically without any manual intervention.

## Status

done

## Acceptance Criteria

**AC1:** `src/Schedule.php` includes `RecurringMessage::cron('5 0 * * *', new CheckMembershipExpiryMessage())` on the default schedule.

**AC2:** `CheckMembershipExpiryMessageHandler` dispatches one `ExpireMembershipMessage(membershipId)` per active membership with `expires_at <= NOW()`. For active memberships expiring within 30 days where `reminder_30_sent_at IS NULL`, it writes and flushes `reminder_30_sent_at` BEFORE dispatching `MembershipReminderMessage(membershipId, 30)`. Same guarantee for 7-day window.

**AC3:** `ExpireMembershipMessageHandler` calls `ExpireMembership::expire(membershipId)`.

**AC4:** All four API quality gates pass (PHPStan level max, CS Fixer, phpunit, DDD validator).

## Tasks / Subtasks

- [x] Task 1: Add `markReminder30Sent()` / `markReminder7Sent()` to `Membership` entity; create `ExpireMembershipInterface`; create message classes
- [x] Task 2: Create `CheckMembershipExpiryMessageHandler` with DBAL queries and flush-before-dispatch guarantee
- [x] Task 3: Create `ExpireMembershipMessageHandler`; update `Schedule.php`; update `services.yaml`
- [x] Task 4: Write unit tests and run all four quality gates

## Dev Notes

### Message classes

- `Membership/Application/Message/CheckMembershipExpiryMessage.php` - empty readonly (scheduler trigger)
- `Membership/Application/Message/ExpireMembershipMessage.php` - `{ string $membershipId }`
- `Membership/Application/Message/MembershipReminderMessage.php` - `{ string $membershipId, int $daysLeft }`

### `CheckMembershipExpiryMessageHandler`

- Injects `Connection $connection`, `EntityManagerInterface $entityManager`, `MessageBusInterface $bus`
- Step 1: DBAL query `memberships WHERE status='active' AND expires_at <= :now` → dispatch `ExpireMembershipMessage` per row
- Step 2: DBAL query `WHERE status='active' AND expires_at > :now AND expires_at <= :now+30d AND reminder_30_sent_at IS NULL` → for each: ORM `find()` → `markReminder30Sent($now)` → `flush()` → dispatch `MembershipReminderMessage($id, 30)`
- Step 3: same for 7-day window with `reminder_7_sent_at IS NULL` and `MembershipReminderMessage($id, 7)`
- Date params use `Types::DATETIMETZ_IMMUTABLE` for proper PostgreSQL handling

### `ExpireMembershipInterface`

- Needed because `ExpireMembership` is `final readonly` (cannot be subclassed for mocking)
- Declares `expire(string $membershipId): void`
- `services.yaml` binding: `ExpireMembershipInterface → ExpireMembership`

### Flush-before-dispatch guarantee

For reminders, the ORM flush commits `reminder_30_sent_at` / `reminder_7_sent_at` BEFORE the bus dispatch. If the dispatch crashes, the reminder was already marked → skipped on next run. If the flush crashes, dispatch never happens → re-tried on next run. At worst a reminder is skipped, never duplicated.

## File List

- `api/src/Membership/Domain/Membership.php` - modified (markReminder30Sent, markReminder7Sent)
- `api/src/Membership/Application/ExpireMembershipInterface.php` - new
- `api/src/Membership/Application/ExpireMembership.php` - modified (implements interface)
- `api/src/Membership/Application/Message/CheckMembershipExpiryMessage.php` - new
- `api/src/Membership/Application/Message/ExpireMembershipMessage.php` - new
- `api/src/Membership/Application/Message/MembershipReminderMessage.php` - new
- `api/src/Membership/Application/Handler/CheckMembershipExpiryMessageHandler.php` - new
- `api/src/Membership/Application/Handler/ExpireMembershipMessageHandler.php` - new
- `api/src/Schedule.php` - modified (add cron entry)
- `api/config/services.yaml` - modified (ExpireMembershipInterface binding)
- `api/tests/Unit/Membership/CheckMembershipExpiryMessageHandlerTest.php` - new
- `api/tests/Unit/Membership/ExpireMembershipMessageHandlerTest.php` - new

## Change Log

| Date       | Change          |
|------------|-----------------|
| 2026-05-16 | Story created   |
| 2026-05-16 | Implemented: all 4 tasks complete. Added ExpireMembershipInterface for testability. 809 tests OK, all quality gates green. |
