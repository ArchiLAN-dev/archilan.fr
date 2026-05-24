# Story 22.4: Email Notification Pipeline

## Story

**As a** member,
**I want** to receive emails on membership activation, 30 days before expiry, 7 days before expiry, and on the day of expiry,
**So that** I am never surprised by a loss of member access.

## Status

done

## Acceptance Criteria

**AC1:** When `MembershipActivatedNotificationMessage` is processed, an email is sent to the user with subject "Bienvenue chez ArchiLAN - Adhésion activée", expiry date formatted as `DD/MM/YYYY`, and a link to their account profile.

**AC2:** When `MembershipReminderMessage(membershipId, daysLeft: 30)` is processed, an email is sent with subject "Votre adhésion ArchiLAN expire dans 30 jours" and a direct link to the HelloAsso membership form.

**AC3:** When `MembershipReminderMessage(membershipId, daysLeft: 7)` is processed, an email is sent with subject "Plus que 7 jours - renouvelez votre adhésion ArchiLAN" and the renewal link.

**AC4:** When `MembershipExpiredNotificationMessage` is processed, an email is sent with subject "Votre adhésion ArchiLAN a expiré" informing the user their member access has been removed and providing the renewal link.

**AC5:** On SMTP error or user not found, the failure is logged at `error` level with `membershipId` or `userId` context before the exception is rethrown for Messenger retry. The role transition already committed is not rolled back.

**AC6:** All four quality gates pass.

## Tasks / Subtasks

- [x] Task 1: Create `MembershipActivatedNotificationMessage` and `MembershipExpiredNotificationMessage` message classes
- [x] Task 2: Update `ActivateMembership` and `ExpireMembership` to dispatch notification messages after flush
- [x] Task 3: Create email classes (`MembershipActivatedEmail`, `MembershipReminderEmail`, `MembershipExpiredEmail`) in `Communications/Application/Email/`
- [x] Task 4: Create handlers (`MembershipActivatedNotificationMessageHandler`, `MembershipReminderMessageHandler`, `MembershipExpiredNotificationMessageHandler`) in `Membership/Application/Handler/`
- [x] Task 5: Update `messenger.yaml` routing for new messages
- [x] Task 6: Write unit tests and run all four quality gates

## Dev Notes

### Message classes

- `Membership/Application/Message/MembershipActivatedNotificationMessage.php` - `{ string $userId, \DateTimeImmutable $expiresAt }`
- `Membership/Application/Message/MembershipExpiredNotificationMessage.php` - `{ string $userId }`
- `Membership/Application/Message/MembershipReminderMessage.php` - already exists (22.3): `{ string $membershipId, int $daysLeft }`

### Handler pattern

Each handler in `Membership/Application/Handler/` injects:
- `Connection $connection` - DBAL for user/membership lookup
- `MailerInterface $mailer` - Symfony Mailer (not ArchilanMailer, to allow catch+rethrow)
- `LoggerInterface $logger`
- `string $mailerSender` - from services.yaml global bind
- `string $siteUrl` - from services.yaml global bind

For `MembershipReminderMessageHandler`, additionally:
- `#[Autowire('%env(HELLOASSO_ORGANIZATION_SLUG)%')] string $organizationSlug`
- `#[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')] string $membershipFormSlug`
- `#[Autowire('%env(bool:HELLOASSO_SANDBOX)%')] bool $sandbox`

Renewal URL formula: `https://www.helloasso[-sandbox].com/associations/{orgSlug}/adhesions/{formSlug}`

### Error handling in handlers

On SMTP failure, handlers log at `error` level then rethrow. On user not found, handlers log at `error` level and return (no email to send, retry won't help).

### Dispatch in ActivateMembership / ExpireMembership

`MembershipActivatedNotificationMessage` and `MembershipExpiredNotificationMessage` are dispatched after `promoteToMember()` / `demoteToUser()` (which includes the ORM flush). Dispatch failures are caught, logged at error level, and swallowed - the DB operation succeeded.

### Cross-context imports

`MembershipReminderMessageHandler` builds the HelloAsso URL from env vars via `#[Autowire]` to avoid importing `Payments/Application/HelloAssoConfig`.

### User table

- Table: `"user"` (quoted reserved word) - use `$this->connection->quoteSingleIdentifier('user')`
- Relevant columns: `id`, `email`, `display_name`, `deleted_at`

## File List

- `api/src/Membership/Application/Message/MembershipActivatedNotificationMessage.php` - new
- `api/src/Membership/Application/Message/MembershipExpiredNotificationMessage.php` - new
- `api/src/Membership/Application/ActivateMembership.php` - modified (dispatch MembershipActivatedNotificationMessage after flush)
- `api/src/Membership/Application/ExpireMembership.php` - modified (dispatch MembershipExpiredNotificationMessage after flush)
- `api/src/Communications/Application/Email/MembershipActivatedEmail.php` - new
- `api/src/Communications/Application/Email/MembershipReminderEmail.php` - new
- `api/src/Communications/Application/Email/MembershipExpiredEmail.php` - new
- `api/src/Membership/Application/Handler/MembershipActivatedNotificationMessageHandler.php` - new
- `api/src/Membership/Application/Handler/MembershipReminderMessageHandler.php` - new
- `api/src/Membership/Application/Handler/MembershipExpiredNotificationMessageHandler.php` - new
- `api/config/packages/messenger.yaml` - modified (routing for 4 Membership async messages)
- `api/tests/Unit/Membership/MembershipActivatedNotificationMessageHandlerTest.php` - new (4 tests)
- `api/tests/Unit/Membership/MembershipReminderMessageHandlerTest.php` - new (4 tests)
- `api/tests/Unit/Membership/MembershipExpiredNotificationMessageHandlerTest.php` - new (4 tests)
- `api/tests/Unit/Membership/ActivateMembershipTest.php` - modified (updated expectations for 2 dispatches)
- `api/tests/Unit/Membership/ExpireMembershipTest.php` - modified (updated expectations for 2 dispatches)

## Dev Agent Record

### Completion Notes

Implémenté les 4 ACs de la story :
- AC1/AC4 : `ActivateMembership` et `ExpireMembership` dispatachent maintenant leurs messages de notification (`MembershipActivatedNotificationMessage`, `MembershipExpiredNotificationMessage`) après le flush gateway, en try-catch pour ne pas rollback le DB commit.
- AC2/AC3 : `MembershipReminderMessageHandler` gère les 30j et 7j avec subject adapté, URL HelloAsso construite depuis env vars, fallback sur `$siteUrl/adhesion` si slugs vides.
- AC5 : Les 3 handlers utilisent `MailerInterface` directement (pas `ArchilanMailer`) pour pouvoir catch+log+rethrow les erreurs SMTP.
- 821 tests OK, PHPStan 0 erreurs, CS Fixer 0 violations, DDD validator OK.

## Change Log

| Date       | Change        |
|------------|---------------|
| 2026-05-16 | Story created |
| 2026-05-16 | Implemented: all 6 tasks complete. 12 new unit tests, 821 tests total. All 4 quality gates green. |
