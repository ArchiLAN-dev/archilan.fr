# Story 7.3 - Twitch Live Status Detection

Status: done

## Review findings

- The Twitch live-status integration was implemented server-side and consumed by navigation/landing components, but tests only covered the unconfigured offline path.
- There was no direct coverage that a live Twitch response becomes a live status.
- There was no direct coverage that Twitch API unavailability falls back to offline status.
- There was no direct coverage that the status checker cache avoids repeated Twitch API calls during the refresh window.
- The consent-gated Twitch embed initialized client-only state synchronously inside an effect, tripping the React Hooks lint rule when the streaming surface was checked.

## Corrections

- Added unit coverage for `TwitchStatusChecker`:
  - live viewer count becomes `StreamStatus::live`,
  - unavailable/offline Twitch API result becomes offline status,
  - repeated checks during cache TTL call the Twitch client once.
- Kept secrets server-side: browser code still calls only `/api/v1/live/status`.
- Confirmed frontend fallback behavior remains a static Twitch channel link when live status is offline or unavailable.
- Adjusted the consent-gated Twitch embed hydration to avoid synchronous state updates inside the effect.

## Validation

- `composer test -- tests/Functional/TwitchStatusTest.php tests/Unit/Streaming/TwitchStatusCheckerTest.php`
- `php vendor/bin/phpstan analyse src/Streaming/Application/TwitchStatusChecker.php src/Streaming/Infrastructure/TwitchApiClient.php src/Streaming/Presentation/StreamingController.php tests/Functional/TwitchStatusTest.php tests/Unit/Streaming/TwitchStatusCheckerTest.php`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Streaming/Application/TwitchStatusChecker.php src/Streaming/Infrastructure/TwitchApiClient.php src/Streaming/Presentation/StreamingController.php tests/Functional/TwitchStatusTest.php tests/Unit/Streaming/TwitchStatusCheckerTest.php`
- `pnpm lint -- src/hooks/use-twitch-status.ts src/features/streaming/live-twitch-badge.tsx src/features/streaming/consent-gated-twitch-embed.tsx src/app/page.tsx`
- `pnpm typecheck`
