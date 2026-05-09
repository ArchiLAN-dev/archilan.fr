# Story 5.3 - Export Participant and Game Selection Data

**Status:** done  
**Validation:** AdminRegistrationExportTest 9 tests, 86 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin registration files clean

## Review Findings

- [x] [Review][Patch] The export only included the sparse raw saved option map, so configured visible options that were required but unanswered disappeared from the export instead of being visible as missing/incomplete data.
- [x] [Review][Patch] The admin registration detail component referenced `eventId` inside `DetailBody` without receiving it as a prop, which broke the frontend typecheck once the file was included.
- [x] [Review][Patch] `AdminSyncStatusTest` had mixed array access in assertions, causing PHPStan to fail the full test analysis.

## Fix

- Added `optionDetails` to each exported game while preserving the existing raw `options` map.
- Each exported option detail now includes key, label, input type, required flag, event visibility, current value, and completion status.
- Added `AdminRegistrationExportTest::testConfiguredOptionsAreExportedWithCompletionStatus()`.
- Passed `eventId` explicitly into `DetailBody`.
- Tightened `AdminSyncStatusTest` array assertions so PHPStan can verify them.
