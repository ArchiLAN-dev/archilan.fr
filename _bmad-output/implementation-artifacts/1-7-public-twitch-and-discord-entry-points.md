# Story 1.7: Public Twitch and Discord Entry Points

Status: done

## Story

As a visitor,
I want quick access to ArchiLAN Twitch and the official Archipelago Discord,
so that I can follow or join the broader community.

## Acceptance Criteria

1. Given the public shell and landing page exist, when a visitor browses public pages, then Twitch and Discord entry points are visible in appropriate navigation or CTA areas.
2. Twitch shows a channel link when no live embed is active.
3. Discord links to the official global Archipelago Discord.
4. Outbound links are accessible, clearly labeled, and open safely.
5. This story does not implement live Twitch detection, which belongs to Epic 7.

## Tasks / Subtasks

- [x] Update shared external link configuration (AC: 2, 3, 4)
  - [x] Use the official Archipelago Discord invite.
  - [x] Keep Twitch as a channel fallback link.
  - [x] Avoid live Twitch detection.
- [x] Improve public shell entry points (AC: 1, 4)
  - [x] Keep Twitch and Discord visible in desktop and mobile navigation.
  - [x] Ensure external links open safely with clear labels.
- [x] Improve landing page CTA copy (AC: 1, 2, 3, 4, 5)
  - [x] Clarify Twitch is a channel fallback, not a live detector.
  - [x] Clarify Discord is the official Archipelago Discord.
  - [x] Keep outbound links accessible and safe.
- [x] Validate and handoff
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no realtime/Twitch live detection was added.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story is public navigation and CTA work only. Do not implement Twitch live status, Twitch API calls, embeds, polling, consent handling, SSE, or backend integration; those belong to Epic 7 and legal/consent stories.

The official global Archipelago Discord link was verified from `https://archipelago.gg/`, whose Discord navigation link redirects to `https://discord.com/invite/8Z65BR2`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.7-Public-Twitch-and-Discord-Entry-Points]
- [Source: _bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md]
- [Source: _bmad-output/implementation-artifacts/1-2-landing-page-with-archilan-identity-and-archipelago-explainer.md]
- [Source: https://archipelago.gg/]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Verified the official Archipelago Discord invite from `https://archipelago.gg/`; the site Discord link redirects to `https://discord.com/invite/8Z65BR2`.
- Kept Twitch as a plain outbound channel fallback link. No embed, polling, API call, or realtime integration was added.
- Replaced the previous Discord link with `externalLinks.archipelagoDiscord` across public CTAs and announcement CTAs.

### Completion Notes List

- Updated shared external link config to use `archipelagoDiscord`.
- Public shell desktop and mobile navigation now expose `Twitch ArchiLAN` and `Discord Archipelago`.
- External navigation links now use `rel="noopener noreferrer"` and explicit `aria-label` text for new-window behavior.
- Landing page Twitch copy now clearly describes a channel fallback when no embedded live is active.
- Landing page Discord copy now clearly points to the official global Archipelago Discord.
- Event detail announcement CTAs now use the official Archipelago Discord link.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Search confirmed no remaining `externalLinks.discord` or `discord.gg/archilan` references in `frontend/src`.
- Search confirmed no `EventSource`, `Twitch API`, `fetch(`, or `axios` usage was introduced in `frontend/src`.
- Build output remains successful with existing static and SSG public routes.

### File List

- `frontend/src/lib/external-links.ts`
- `frontend/src/components/public-shell.tsx`
- `frontend/src/app/page.tsx`
- `frontend/src/app/evenements/[eventSlug]/page.tsx`
- `_bmad-output/implementation-artifacts/1-7-public-twitch-and-discord-entry-points.md`

### Change Log

- 2026-04-25: Implemented public Twitch and official Archipelago Discord entry-point cleanup with safe outbound links and no live detection.
