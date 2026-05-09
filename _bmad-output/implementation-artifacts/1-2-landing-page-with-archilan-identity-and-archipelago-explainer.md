# Story 1.2: Landing Page with ArchiLAN Identity and Archipelago Explainer

Status: done

## Story

As a first-time visitor,
I want the landing page to explain ArchiLAN and Archipelago clearly,
so that I understand the community and want to participate.

## Acceptance Criteria

1. Given the public shell exists, when a visitor opens the landing page, then they see ArchiLAN identity, mission, and association context.
2. They see an Archipelago explainer section designed for newcomers.
3. The explainer communicates the cross-game item mechanic visually or narratively before the end of the first scroll.
4. The page includes clear CTAs toward upcoming events, Twitch, and Discord.
5. The layout is mobile-first and remains readable at 375px width.

## Tasks / Subtasks

- [x] Implement landing identity and mission (AC: 1, 5)
  - [x] Replace shell placeholder with ArchiLAN identity.
  - [x] Explain association/community context without claiming unsupported operational data.
  - [x] Keep content mobile-first and readable at 375px.
- [x] Implement newcomer Archipelago explainer (AC: 2, 3, 5)
  - [x] Add plain-language explainer copy.
  - [x] Add first-viewport visual/narrative mechanic showing cross-game item transfer.
  - [x] Avoid relying on jargon before explaining it.
- [x] Add clear CTAs (AC: 4, 5)
  - [x] Add upcoming events CTA.
  - [x] Add Twitch CTA.
  - [x] Add Discord CTA.
- [x] Validate and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no backend files were changed by this story.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story implements the landing page only. It must not implement event listing data, event detail pages, registration forms, authentication, API clients, Twitch live detection, or Discord integration logic.

### UX Direction

Chosen landing direction:

- Left column: overline, headline, Archipelago explainer block, primary CTA, secondary stats.
- Right column: visual Archipelago mechanic / action card.
- Headline concept: "Un item de ton jeu. Le monde entier."
- The "aha moment" must be visible before the end of the first scroll.

### Content Constraints

- Do not claim a real upcoming event unless data exists.
- CTAs can link to route placeholders or external community channels.
- Twitch and Discord URLs should use `src/lib/external-links.ts`.
- Keep page copy French-first.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.2-Landing-Page-with-ArchiLAN-Identity-and-Archipelago-Explainer]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Chosen-Direction]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Implementation-Approach]
- [Source: _bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Implemented the landing page directly in `frontend/src/app/page.tsx` because Story 1.2 scope is a single public landing surface.
- Used `src/lib/external-links.ts` from the Story 1.1 review follow-up for Twitch and Discord CTAs.
- Kept event CTA generic (`/evenements`) because real event listing/data belongs to Story 1.3.
- Did not add API calls, mocked data modules, registration forms, auth behavior, or backend changes.

### Completion Notes List

- Replaced the Story 1.1 shell-safe placeholder with a real landing page.
- Added ArchiLAN identity, association context, and mission-oriented copy.
- Added the approved headline concept: "Un item de ton jeu. Le monde entier."
- Added newcomer-friendly Archipelago explainer copy.
- Added a first-viewport visual/narrative mechanic: finding a key in one game unlocks progress in another player's world.
- Added clear CTAs toward upcoming events, Twitch, and Discord.
- Kept the layout mobile-first with one-column flow before desktop split.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Search confirmed landing content includes `ArchiLAN`, association context, headline, Archipelago explainer, Hollow Knight/Stardew Valley mechanic, events CTA, Twitch CTA, and Discord CTA.
- Search for `fetch(`, `axios`, `api/v1`, `inscription`, `register`, `admin`, and `backoffice` in `frontend/src/app/page.tsx` returned no matches.
- Recent API file scan returned no files modified by this story.

### File List

- `frontend/src/app/page.tsx`
- `_bmad-output/implementation-artifacts/1-2-landing-page-with-archilan-identity-and-archipelago-explainer.md`

### Change Log

- 2026-04-25: Implemented landing page identity, newcomer Archipelago explainer, first-viewport cross-game mechanic, and event/Twitch/Discord CTAs.
