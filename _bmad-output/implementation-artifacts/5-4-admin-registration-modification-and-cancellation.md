# Story 5.4 - Admin Registration Modification and Cancellation

**Status:** done  
**Validation:** AdminRegistrationCancellationTest 9 tests, 41 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin registration detail and useSSE clean

## Review Findings

- [x] [Review][Patch] The story only supported admin cancellation; there was no admin endpoint to modify allowed registration fields.
- [x] [Review][Patch] Admin cancellation logs did not include an explicit action timestamp in the structured context.
- [x] [Review][Patch] The realtime subscribe controller depended directly on a missing Mercure token factory service, which could prevent the Symfony container from compiling in tests.
- [x] [Review][Patch] `useSSE` updated refs during render, violating the current React hooks lint rule.

## Fix

- Added `AdminRegistrationModification` to update selected games and per-game option values with server-side validation against event game configuration and game option schemas.
- Added `PATCH /api/v1/admin/events/{eventId}/registrations/{registrationId}` with admin RBAC, structured logging, explicit `occurredAt`, validation errors, and refreshed registration detail response.
- Added functional coverage for successful admin modification and invalid option value types.
- Added explicit `occurredAt` to admin cancellation logs.
- Fixed Mercure token creation by using the injected hub factory and returning a test token factory from `NullHub`.
- Moved `useSSE` ref updates into effects and fixed nullable hub URL narrowing.
