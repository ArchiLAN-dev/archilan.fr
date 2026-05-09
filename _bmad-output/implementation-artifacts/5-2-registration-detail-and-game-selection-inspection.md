# Story 5.2 - Registration Detail and Game Selection Inspection

**Status:** done  
**Validation:** AdminRegistrationDetailTest 9 tests, 87 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin-registration-detail clean

## Review Findings

- [x] [Review][Patch] A registration detail whose selected game no longer resolves only appeared as a generic incomplete option state, so admins did not get a clear validation warning for stale selection data.

## Fix

- `AdminRegistrationInspector` now returns a `warnings` list per selected game and flags missing library games with an explicit admin warning.
- Added `AdminRegistrationDetailTest::testMissingSelectedGameIsReturnedWithWarning()`.
- The admin registration detail UI now renders game-level warnings above the option table and labels those rows as warnings.
