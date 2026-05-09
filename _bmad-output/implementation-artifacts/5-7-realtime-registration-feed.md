# Story 5.7 - Realtime Registration Feed

**Status:** done  
**Validation:** RealtimeTokenTest + ReserveRegistrationTest + RegistrationGameSelectionTest + RegistrationSubmitTest 40 tests, 243 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files - pnpm typecheck: 0 errors - ESLint admin-registration-dashboard clean

## Review Findings

- [x] [Review][Patch] The admin dashboard reloaded on SSE messages but ignored the feed payload, so the newly arrived registration did not receive the required short highlight animation.
- [x] [Review][Patch] SSE disconnect only fell back to polling; the backoffice did not clearly show that it was in polling fallback mode until data became stale.
- [x] [Review][Patch] `RegistrationSubmission.php` contained corrupted quote characters that prevented the Symfony container from compiling when realtime tests booted the kernel.
- [x] [Review][Patch] PHPStan exposed stale slot typing issues and unsafe mixed casts in unrelated touched areas, blocking the full quality gate.

## Fix

- Parsed realtime `registration.reserved` feed items and tracked highlighted registration ids for a 3-second row animation.
- Added `.registration-feed-highlight` keyframes in the global stylesheet.
- Added a subtle `Live deconnecte, polling actif` indicator when EventSource falls back to polling.
- Repaired `RegistrationSubmission.php` syntax and normalized slot typing issues.
- Hardened Twitch token parsing against mixed API response values.
