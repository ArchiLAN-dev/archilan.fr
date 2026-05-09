# Story 5.1 - Admin Registration Dashboard

**Status:** done  
**Validation:** AdminRegistrationDashboardTest 10 tests, 83 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin-registration-dashboard clean

## Review Findings

- [x] [Review][Patch] A registration whose selected game no longer resolves was still marked `gameSelectionComplete: true`, hiding stale or invalid participant data from admins [api/src/Registrations/Application/AdminRegistrationDashboard.php:178]
- [x] [Review][Patch] The admin dashboard component violated the React hooks lint rule by triggering state updates through the initial effect path [frontend/src/features/admin/admin-registration-dashboard.tsx:96]

## Fix

- Missing selected games now mark the registration game selection incomplete.
- Added `AdminRegistrationDashboardTest::testMissingSelectedGameMarksSelectionIncomplete()`.
- Deferred the initial dashboard load through a microtask to satisfy the React hooks lint rule.
