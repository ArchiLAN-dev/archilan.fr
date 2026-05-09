# Story 8.6 - Cookie Consent Banner

## Review

Status: done

Acceptance criteria reviewed:

- Banner is shown when no non-functional consent choice is stored.
- Twitch embed is gated by `archilan_twitch_consent` and is not loaded before consent.
- Visitor can accept or reject consent.
- Rejection is stored and respected on future visits.
- Functional/session cookies are documented separately from Twitch consent.

Finding:

- The banner supported accept and reject, but did not provide a configuration path as required by the story.

## Corrections

- Added a `Configurer` action to the banner.
- Added a preferences view with a dedicated Twitch embed checkbox.
- Kept Twitch disabled by default in the preferences view.
- Added `Tout refuser` and `Enregistrer mes choix` actions.
- Clarified that required session/security cookies are separate from non-functional Twitch consent.
- Kept the privacy page exempt from the banner so the policy remains readable before consent.

## Validation

- `pnpm lint -- src/components/cookie-consent-banner.tsx src/components/consent-footer-control.tsx src/features/streaming/consent-gated-twitch-embed.tsx src/lib/consent.ts src/components/public-shell.tsx`
- `pnpm typecheck`

