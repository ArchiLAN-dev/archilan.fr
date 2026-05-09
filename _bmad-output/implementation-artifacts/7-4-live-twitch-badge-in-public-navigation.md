# Story 7.4 - LiveTwitchBadge in Public Navigation

Status: done

## Review findings

- `LiveTwitchBadge` was already mounted in desktop and mobile public navigation.
- The badge only rendered live/offline states and ignored the `loading` and `error` states exposed by `useTwitchStatus`.
- Offline state linked to Twitch, but screen-reader labels did not explicitly announce offline status.
- Error fallback was not visually distinguishable from a normal offline/static link.

## Corrections

- Added explicit `loading`, `error`, `live`, and `offline` rendering paths.
- Live state keeps the visible live indicator and accessible live label.
- Offline and error states both remain static links to the Twitch channel.
- Loading/error/offline states now include icon cues and screen-reader labels.
- Badge transitions use restrained color/opacity transitions to avoid jarring navigation changes.

## Validation

- `pnpm lint -- src/features/streaming/live-twitch-badge.tsx src/hooks/use-twitch-status.ts src/components/public-shell.tsx`
- `pnpm typecheck`
