# Story 6.7 - HelloAsso Graceful Degradation

Status: done

## Review findings

- Event ticketing could disappear silently when an event had a HelloAsso form slug but the HelloAsso embed URL could not be built.
- The public event API did not distinguish "no checkout expected" from "checkout expected but temporarily unavailable".
- The membership unavailable state had a message but no explicit retry action.
- The shared HelloAsso iframe had timeout handling, but its retry lifecycle triggered the React Hooks `set-state-in-effect` lint rule.

## Corrections

- Added `checkoutUnavailable` to the public event API payload and frontend event model.
- Event detail pages now render a specific retryable ticketing degradation section when checkout is expected but unavailable.
- Membership checkout degradation now includes a retry action.
- `HelloAssoIframe` now remounts by `src` and resets loading state from the retry action instead of synchronously inside an effect.
- Verified admin persistent sync failures remain visible through the existing payment sync status history.
- Verified sync API failures log failed sync attempts and rethrow before order upsert, preserving local registration/payment records.

## Validation

- `composer test -- tests/Functional/HelloAssoCheckoutTest.php tests/Functional/HelloAssoSyncHandlerTest.php tests/Functional/AdminSyncStatusTest.php`
- `php vendor/bin/phpstan analyse src/Events/Application/PublicEventCatalog.php tests/Functional/HelloAssoCheckoutTest.php tests/Functional/HelloAssoSyncHandlerTest.php tests/Functional/AdminSyncStatusTest.php`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Events/Application/PublicEventCatalog.php tests/Functional/HelloAssoCheckoutTest.php tests/Functional/HelloAssoSyncHandlerTest.php tests/Functional/AdminSyncStatusTest.php`
- `pnpm lint -- src/app/evenements/[eventSlug]/page.tsx src/app/adhesion/page.tsx src/features/events/public-events-api.ts src/features/events/event-types.ts src/features/payments/helloasso-iframe.tsx`
- `pnpm typecheck`

## Residual note

- `composer phpstan` still fails globally on pre-existing Sessions/Communications typing issues outside this story scope.
