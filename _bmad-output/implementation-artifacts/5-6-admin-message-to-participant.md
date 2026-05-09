# Story 5.6 - Admin Message to Participant

**Status:** done  
**Validation:** AdminRegistrationMessageTest 4 tests, 26 assertions - RbacEnforcementTest 4 tests, 559 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin-registration-detail clean

## Review Findings

- [x] [Review][Patch] Successful admin messages were sent by email but not recorded in a persistent registration history.
- [x] [Review][Patch] Delivery failures returned `200` with `send_failed`, so admins would not get a proper failed HTTP result.
- [x] [Review][Patch] The successful response did not expose a send timestamp that the backoffice can use as confirmation metadata.

## Fix

- Added `RegistrationAdminMessage` history records with event, registration, admin, subject, and sent timestamp.
- Added migration `Version20260502000003` for `registrations_admin_messages`.
- `SendMessageToRegistrant` now receives the admin id, records history only after successful delivery, and returns `sentAt`.
- The admin message endpoint now surfaces mail delivery failure as `502 message_send_failed`.
- Extended functional coverage to assert message history persistence and send timestamp.
