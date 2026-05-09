# Story 7.2 - Public Realtime Seat Counter

Status: done

## Review findings

- The public seat counter received realtime/polling data, but the visible count did not expose updates with an accessible live region.
- Count changes had no explicit non-blocking animation state.
- The SSE handler trusted every payload received on the connection instead of ignoring messages for another event id.
- Server-side publication paths for seat-counter updates were present through registration reservation and cancellation; the direct publisher behavior is covered by the realtime unit test added with Story 7.1.

## Corrections

- `SeatCounter` now uses `aria-live="polite"` and `aria-atomic="true"` on the rendered count.
- Count updates now use tabular numbers and a short `motion-safe:animate-pulse` highlight controlled by `LiveSeatCounter`.
- `LiveSeatCounter` now ignores SSE messages whose `eventId` does not match the current event.
- Realtime and polling values are clamped against the server-provided capacity before updating local display state.
- Existing server authority remains unchanged: registration reservation/cancellation still updates capacity server-side before publishing the remaining seat count.

## Validation

- `composer test -- tests/Unit/Realtime/RealtimePublisherTest.php`
- `php vendor/bin/phpstan analyse tests/Unit/Realtime/RealtimePublisherTest.php src/Realtime/Application/RealtimePublisher.php`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php tests/Unit/Realtime/RealtimePublisherTest.php src/Realtime/Application/RealtimePublisher.php`
- `pnpm lint -- src/features/events/seat-counter.tsx src/features/events/live-seat-counter.tsx src/hooks/use-sse.ts src/app/evenements/[eventSlug]/page.tsx`
- `pnpm typecheck`

## Residual note

- Broader functional registration test classes still have existing SQLite schema setup instability when run as full classes locally; this story did not change those tests.
