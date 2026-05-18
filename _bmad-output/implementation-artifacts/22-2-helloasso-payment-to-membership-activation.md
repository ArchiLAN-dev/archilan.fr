# Story 22.2: HelloAsso Payment to Membership Activation

## Story

**As a** user who pays their cotisation via HelloAsso,
**I want** my membership to be created or renewed automatically without any manual step,
**So that** I can access member-only features immediately after payment.

## Status

review

## Acceptance Criteria

**AC1:** `HelloAssoOrderPaidMessage(helloassoOrderId, formSlug, payerEmail, paidAt)` is dispatched from `SyncHelloAssoFormHandler` exactly once per paid transition (new order with paidAt set, or existing order whose paidAt transitions from null to non-null), after the flush succeeds. `Payments/` imports nothing from `Membership/`.

**AC2:** `HelloAssoOrderPaidMessageHandler` (in `Membership/Application/Handler/`) skips the message if `formSlug` does not match `HELLOASSO_MEMBERSHIP_FORM_SLUG`, then calls `ProcessHelloAssoMembershipPayment::process()`.

**AC3:** `process()` returns early (logging `info`/`warning`) when payerEmail is null, paidAt is null, or user is not found. On success it calls `ActivateMembership::activate($userId, $paidAt, 'helloasso', $helloassoOrderId)`.

**AC4:** Duplicate `helloassoOrderId` processing catches `UniqueConstraintViolationException`, logs `membership.already_processed` at `info` level, and returns cleanly.

**AC5:** `HELLOASSO_MEMBERSHIP_FORM_SLUG=` already exists in `api/.env` - no change needed.

**AC6:** All four API quality gates pass (PHPStan level max, CS Fixer, phpunit, DDD validator).

## Tasks / Subtasks

- [x] Task 1: Add `ActivateMembershipInterface` and create `HelloAssoOrderPaidMessage`
- [x] Task 2: Modify `SyncHelloAssoFormHandler` to dispatch `HelloAssoOrderPaidMessage` on paid transitions
- [x] Task 3: Create `HelloAssoOrderPaidMessageHandler` and `ProcessHelloAssoMembershipPayment`
- [x] Task 4: Update `services.yaml` with bindings
- [x] Task 5: Write unit tests and run all four quality gates

## Dev Notes

### `HelloAssoOrderPaidMessage`

- Location: `Payments/Application/Message/`
- Fields: `string $helloassoOrderId`, `string $formSlug`, `?string $payerEmail`, `?\DateTimeImmutable $paidAt`
- Dispatched after `$em->flush()` in `SyncHelloAssoFormHandler`

### Paid transition detection

In `SyncHelloAssoFormHandler::upsertOrder()`:
- New order: if `$item['paidAt'] !== null` → return a pending `HelloAssoOrderPaidMessage`
- Existing order: if old `getPaidAt() === null` AND new `$item['paidAt'] !== null` → return a pending message
- Collect messages in `__invoke()`, dispatch only after flush succeeds (re-throw on flush failure means post-flush code only runs on success)

### `ProcessHelloAssoMembershipPayment`

- Location: `Membership/Application/`
- `process(string $helloassoOrderId, ?string $payerEmail, ?\DateTimeImmutable $paidAt): void`
- User lookup via DBAL on `"user"` table, matching `email_canonical = LOWER($payerEmail)` and `deleted_at IS NULL`
- Calls `ActivateMembershipInterface::activate()`
- Catches `Doctrine\DBAL\Exception\UniqueConstraintViolationException` → logs `membership.already_processed` at `info` level

### `ActivateMembershipInterface`

- To allow `ActivateMembership` (final readonly) to be mocked in unit tests
- `ActivateMembership` implements this interface
- `services.yaml`: `ActivateMembershipInterface: '@ActivateMembership'`

### DDD compliance

- `Payments/Application/Message/HelloAssoOrderPaidMessage.php` - Payments layer, imports nothing from Membership
- `Membership/Application/Handler/HelloAssoOrderPaidMessageHandler.php` - imports from `Payments/Application/Message/` (cross-context read-only: message is a value object, not a domain class)
- `Membership/Application/ProcessHelloAssoMembershipPayment.php` - uses DBAL `Connection` to query `user` table (no Identity context import)

## File List

- `api/src/Membership/Application/ActivateMembershipInterface.php` - new
- `api/src/Membership/Application/ActivateMembership.php` - modified (implements interface)
- `api/src/Payments/Application/Message/HelloAssoOrderPaidMessage.php` - new
- `api/src/Payments/Application/SyncHelloAssoFormHandler.php` - modified (dispatch on paid transitions)
- `api/src/Membership/Application/Handler/HelloAssoOrderPaidMessageHandler.php` - new
- `api/src/Membership/Application/ProcessHelloAssoMembershipPayment.php` - new
- `api/config/services.yaml` - modified (ActivateMembershipInterface binding, handler config)
- `api/tests/Unit/Membership/ProcessHelloAssoMembershipPaymentTest.php` - new
- `api/tests/Unit/Membership/HelloAssoOrderPaidMessageHandlerTest.php` - new
- `api/tests/Functional/HelloAssoSyncHandlerTest.php` - modified (pass bus to handler)

## Change Log

| Date       | Change                          |
|------------|---------------------------------|
| 2026-05-16 | Story created                   |
| 2026-05-16 | Implemented: all 5 tasks complete. Added ProcessHelloAssoMembershipPaymentInterface for testability. 804 tests OK, all quality gates green. |
