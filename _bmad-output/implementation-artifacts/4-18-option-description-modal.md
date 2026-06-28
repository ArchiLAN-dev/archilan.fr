# Story 4.18: Full-content scrollable modal for option descriptions

**Status:** review
**Epic:** 4 - Registration & per-game randomizer option configuration
**Date:** 2026-06-27

## Story

As a player reading an option's help in the YAML editor,
I want clicking the info icon to open a scrollable modal with the **full** description,
so that long help texts (e.g. Pokemon Platinum's `game_options`, which documents every sub-setting and
the whole name character set) are actually readable instead of overflowing a tiny tooltip.

## Context

`InfoTooltip` rendered help as a fixed-width (`w-72`), `pointer-events-none` hover tooltip. For short
descriptions that is fine, but some options carry very long comment blocks: the tooltip then runs off
screen and can't be scrolled (pointer-events disabled), so the content is unreachable. (Reported by Jean
on the `game_options` help.)

This keeps the hover tooltip as a quick preview and makes a **click** open a modal dialog with the full
content, scrollable, mirroring the existing `PersonalRunYamlViewerDialog` pattern.

## Acceptance Criteria

1. Clicking the info icon opens a modal dialog (`role="dialog"`, `aria-modal`) rendered via a portal to
   `document.body`, with the option label as the title and the full description in a vertically
   scrollable body (`max-h-[85vh]`, `overflow-y-auto`).
2. The modal closes on: the close (X) button, a backdrop click, and the `Escape` key.
3. Hover/focus still shows the quick tooltip preview; for long descriptions (> 200 chars) the preview is
   height-capped and shows a "Cliquer pour tout afficher" hint. The tooltip is hidden while the modal is open.
4. All three info-icon call sites pass a meaningful modal title (game-name option label, the player-name
   field, and weighted-row labels).
5. Gates green: frontend `typecheck` / `lint` / `build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2,3). Rewrite `InfoTooltip`: add `modalOpen` state + Escape handler, portal modal
  (backdrop + header + scrollable body), cap the long-content preview with a click hint.
- [x] **Task 2** (AC 4). Add a `title` prop and pass it at all three call sites.
- [x] **Task 3** (AC 5). typecheck / lint / build green.

## Dev Notes

- Modal is portalled to `document.body` (the tooltip root is a `<span>`; a block-level dialog can't be
  nested inside it, and a portal also avoids any `overflow`/`z-index` clipping from the option list).
- Click now always opens the modal (the old click-toggles-tooltip behaviour is dropped); hover/focus is
  the preview path. `justFocused` ref removed.
- No component test added (the editor has no RTL harness); covered by typecheck/lint/build. Live
  click-test was blocked at authoring time because the test slot became "introuvable" (run cleaned up);
  the modal mirrors the already-shipped `PersonalRunYamlViewerDialog` dialog pattern.

### Project Structure Notes

- `frontend/src/features/events/yaml-option-editor.tsx` (`InfoTooltip` + the 3 call sites)

### References

- [Source: frontend/src/features/personal-runs/personal-run-yaml-viewer-dialog.tsx (modal pattern: portal-less fixed overlay, Escape, scroll)]
- [Source: frontend/src/features/events/yaml-option-editor.tsx (InfoTooltip, MiniMarkdown)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Info icon now opens a scrollable portal modal with the full description; hover keeps a capped preview
  with a "click to expand" hint for long texts. Close via X / backdrop / Escape.
- `title` prop threaded to all three call sites for a meaningful modal heading.
- typecheck / lint / build green. Frontend-only.

### File List

- `frontend/src/features/events/yaml-option-editor.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-27 | Created + implemented. Long option descriptions (e.g. game_options) overflowed the unscrollable hover tooltip; clicking the info icon now opens a scrollable modal (portal, Escape/backdrop/X close). Hover preview capped with a hint for long content. Frontend-only; gates green. Status → review. |
