# Story 8.7 - Persistent Consent Management

## Review

Status: done

Acceptance criteria reviewed:

- Footer consent control appears after a visitor has made a consent choice.
- Visitor can authorize or withdraw Twitch consent without logging in.
- Consent changes are persisted in `localStorage`.
- Twitch embed listens to consent events and unmounts when consent is withdrawn.
- UI confirms consent updates.

Findings:

- The footer control allowed toggling consent, but did not clearly show the current Twitch consent state.
- Consent changes made in another tab were not reflected by the footer control.

## Corrections

- Added an explicit current-state label: `Twitch: autorise/refuse`.
- Kept the contextual action to authorize or withdraw consent.
- Added `storage` event handling so another tab's consent update is reflected.
- Preserved the existing confirmation message after updates.

## Validation

- `pnpm lint -- src/components/consent-footer-control.tsx src/components/cookie-consent-banner.tsx src/features/streaming/consent-gated-twitch-embed.tsx src/lib/consent.ts src/components/public-shell.tsx`
- `pnpm typecheck`

