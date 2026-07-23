# Story 10.11: Video embeds in markdown content

**Status:** done
**Epic:** 10 - Identité visuelle, médias, pages publiques
**Date:** 2026-07-22

## Story

As an author,
I want a video URL written on its own line in any markdown field to become a playable embed,
so that guides, game and event descriptions can show a video instead of a bare link.

## Context

Story 10.10 made the long-form content fields markdown, rendered by the shared `Markdown` component. Video is currently supported in exactly one place: `InstallStepsView` renders a per-step `videoUrl` through `StepVideo`, which resolves a YouTube id and embeds `youtube-nocookie` inside a sandboxed iframe (falling back to a plain link for anything else). That logic is sound and is the model to generalise - not to reinvent.

Nothing else can show a video: a YouTube URL in a game or event description renders as a link.

### Decisions

- **Syntax: a bare video URL alone on its line.** No new markup to learn, and it degrades naturally - an unrecognised host stays a link. Detected on the paragraph node (single link child), so a URL written mid-sentence stays an inline link and does not explode into a player.
- **Allow-list of hosts, never arbitrary iframes.** YouTube first (reusing the existing id resolution), extensible. Anything else renders as a link.
- **Reuse `StepVideo`'s hardening**: `youtube-nocookie`, `sandbox`, `referrerPolicy="strict-origin-when-cross-origin"`, no autoplay.
- **Not embedded on `untrusted` surfaces** (bio, commentaires de profil, message de contribution): the URL renders as a normal link there. Rationale below - this is the one judgment call of the story and it is a product decision, not a technical constraint.
- `StepVideo` is replaced by the shared implementation so there is one video path, not two.

### The untrusted call, stated plainly

Embedding a sandboxed `youtube-nocookie` iframe from a member is not a serious *technical* risk: the host is fixed, the frame is sandboxed, and cookies are reduced. The real exposure is **moderation**: a member could drop an unwanted video into a profile comment or a bio, and it would auto-play into view for every visitor of that profile rather than sitting behind a link someone chose to click.

Images are already dropped entirely on those surfaces for the same reason. Keeping video to a link there is the consistent default. Flipping it on is a one-prop change if the moderation tooling is judged sufficient - the story does not close that door, it just does not open it silently.

## Acceptance Criteria

1. A supported video URL alone on a line renders as an embedded player in trusted markdown content.
2. The same URL written inside a sentence stays an inline link - no embed.
3. An unsupported host alone on a line stays a link, never an iframe.
4. On `untrusted` content the URL always renders as a link, never an embed.
5. The embed reuses the existing hardening (nocookie host, sandbox, referrer policy) and carries an accessible title.
6. `StepVideo` no longer duplicates the logic; the tutorial step video keeps working through the shared path.
7. Tests cover: embed on its own line, inline link untouched, unknown host, untrusted degradation.
8. `pnpm gates` green.

## Tasks / Subtasks

- [x] **Task 1 - Shared video component** (AC 1, 3, 5). Extract the YouTube id resolution and the hardened iframe into a shared `VideoEmbed`, keeping `StepVideo`'s exact security attributes.
- [x] **Task 2 - Markdown integration** (AC 1, 2, 4). Paragraph-level detection of a lone video link via the hast `node`; embed when trusted, link otherwise.
- [x] **Task 3 - Reuse in the tutorial** (AC 6). Point `InstallStepsView` at the shared component and delete the local duplicate.
- [x] **Task 4 - Tests + gates** (AC 7, 8).

## Dev Notes

- Detection must read the `node` prop rather than inspect React children: react-markdown hands the hast node to the component, so "this paragraph is exactly one link" is a structural check rather than a fragile guess at rendered elements.
- Nesting matters: an embed is a block, so it must replace the paragraph rather than render inside it, otherwise a `<div>` lands inside a `<p>`.

### Project Structure Notes

- `frontend/src/components/markdown/video-embed.tsx` (new)
- `frontend/src/components/markdown/markdown.tsx`
- `frontend/src/features/games/install-steps-view.tsx`

### References

- [Source: frontend/src/features/games/install-steps-view.tsx (StepVideo - the hardening to reuse)]
- [Source: _bmad-output/implementation-artifacts/10-10-markdown-authoring-and-rendering.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- Video was **already** implemented for tutorial steps (`StepVideo`) - this story generalised that exact implementation rather than writing a second one, and the tutorial now renders through the shared component.
- Detection reads the hast `node`, so a URL inside a sentence stays an inline link; only a paragraph that is *nothing but* the link becomes a player. Covered by a test.
- Ordering trap hit while wiring: the override has to be spread **after** `BLOCK_COMPONENTS`, which also defines `p` and would otherwise win silently.
- Untrusted surfaces link instead of embedding - see the rationale in the decisions above. One prop flips it if the moderation tooling is judged sufficient.

### File List

- `frontend/src/components/markdown/video-embed.tsx` (new - `youtubeId`, `isEmbeddableVideo`, `VideoEmbed`)
- `frontend/src/components/markdown/markdown.tsx` (`loneLinkUrl` + paragraph override)
- `frontend/src/components/markdown/markdown.test.tsx` (5 video cases)
- `frontend/src/features/games/install-steps-view.tsx` (duplicate removed, uses the shared component)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-07-22 | Created + implemented. A lone allow-listed video URL in markdown renders as a hardened embed on trusted content; the tutorial's duplicate implementation was folded into the shared one. Gates green. Status -> done. |
