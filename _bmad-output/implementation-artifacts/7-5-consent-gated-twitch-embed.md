# Story 7.5 - Consent-Gated Twitch Embed

Status: done

## Review findings

- The Twitch iframe was correctly blocked before consent.
- The placeholder included a Twitch channel fallback link.
- The iframe did not use browser lazy loading.
- Very small viewports displayed the link visually, but the hidden iframe was still mounted in the DOM and could load after consent.
- Consent banner/footer hydration used synchronous state updates inside effects, failing the React Hooks lint rule when the full consent surface was checked.

## Corrections

- The Twitch iframe now renders only after consent, only when Twitch is live, and only on usable `sm+` viewports.
- Small viewports now render only the Twitch channel link, so no hidden iframe is mounted.
- Added `loading="lazy"` to the Twitch iframe.
- Encoded Twitch channel and parent hostname before building the player URL.
- Kept accessible iframe title and fallback link text.
- Adjusted consent banner/footer hydration to avoid synchronous state updates inside effects.

## Validation

- `pnpm lint -- src/features/streaming/consent-gated-twitch-embed.tsx src/components/cookie-consent-banner.tsx src/components/consent-footer-control.tsx src/app/page.tsx`
- `pnpm typecheck`
