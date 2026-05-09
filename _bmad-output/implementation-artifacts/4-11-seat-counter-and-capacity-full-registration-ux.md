# Story 4.11 - Seat Counter and Capacity-Full Registration UX

**Status:** done  
**Validation:** ReserveRegistrationTest 9 tests, 63 assertions - ESLint registration-eligibility-gate + seat-counter clean

## Review Findings

- [x] [Review][Patch] Polling failures were ignored, so SeatCounter never entered its disconnected state and could keep displaying stale capacity as reliable [frontend/src/features/events/registration-eligibility-gate.tsx:112]

## Fix

- Added `seatCounterDisconnected` state in `RegistrationEligibilityGate`.
- The polling loop now marks the counter disconnected on failed HTTP responses, invalid payloads, or network errors.
- The counter returns to normal after the next successful eligibility payload.

## Validation Notes

- `pnpm typecheck` currently fails outside this story on `frontend/src/features/admin/admin-registration-detail.tsx` because `PaymentCard` is missing.
