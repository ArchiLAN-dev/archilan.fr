# Story 7.1 - Realtime Infrastructure and Topic Authorization

Status: done

## Review findings

- Admin registration feed subscriptions opened `EventSource` directly against the private Mercure topic without first obtaining a Mercure authorization cookie.
- The subscribe-token endpoint returned a token in JSON but did not set the `mercureAuthorization` cookie required by browser `EventSource` with credentials.
- Realtime publication behavior was not directly covered for public seat-counter updates vs private admin registration updates.
- The public live seat counter had a React Hooks lint issue in its stale-state effect.

## Corrections

- `RealtimeController` now uses Symfony Mercure `Authorization::setCookie()` for validated admin topics.
- `AdminRegistrationDashboard` now calls `/api/v1/realtime/subscribe-token` with credentials before opening the private Mercure `EventSource`.
- Added unit coverage for `RealtimePublisher`:
  - public seat-counter topic publication,
  - private admin registration feed publication,
  - hub publish failure logging without bubbling.
- Added functional coverage that the subscribe-token response sets `mercureAuthorization`.
- Adjusted the live seat counter stale-state reset to satisfy React Hooks lint.

## Validation

- `composer test -- tests/Functional/RealtimeTokenTest.php tests/Unit/Realtime/RealtimePublisherTest.php`
- `php vendor/bin/phpstan analyse src/Realtime/Application/RealtimePublisher.php src/Realtime/Presentation/RealtimeController.php tests/Functional/RealtimeTokenTest.php tests/Unit/Realtime/RealtimePublisherTest.php`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Realtime/Application/RealtimePublisher.php src/Realtime/Presentation/RealtimeController.php tests/Functional/RealtimeTokenTest.php tests/Unit/Realtime/RealtimePublisherTest.php`
- `pnpm lint -- src/features/admin/admin-registration-dashboard.tsx src/hooks/use-sse.ts src/features/events/live-seat-counter.tsx`
- `pnpm typecheck`
