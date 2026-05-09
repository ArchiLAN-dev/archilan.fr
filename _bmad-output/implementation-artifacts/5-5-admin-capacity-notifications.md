# Story 5.5 - Admin Capacity Notifications

**Status:** done  
**Validation:** CapacityNotificationTest + AdminEventDraftTest + AdminEventEditTest 16 tests, 114 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin-event-dashboard clean

## Review Findings

- [x] [Review][Patch] The backoffice only displayed the numeric capacity ratio, so capacity reached was not clearly highlighted for admins.
- [x] [Review][Patch] The admin event payload did not expose an explicit capacity-reached boolean, forcing frontend code to infer state from two counters.
- [x] [Review][Patch] Capacity notification tests verified dispatch but did not assert that the anti-duplicate notification flag was marked.

## Fix

- Added `isAtCapacity` to admin event payloads.
- Added API assertions for both non-full and full admin event payloads.
- Added a clear `Complet` badge next to full-capacity events in the admin event table.
- Strengthened capacity notification coverage by asserting the event is marked as notification-sent after the final seat is claimed.
