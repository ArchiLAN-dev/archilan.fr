# Story 31.4: Install nudge in the game-selection flow

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player who just selected games for an event registration or a personal run,
I want a reminder of how to install the games I picked,
so that I can prepare before the session instead of discovering the setup on the day.

Fourth story of Epic 31. Surfaces, right after game selection, a link to each selected game's tutorial
plus the generic "Installer Archipelago" guide.

## Acceptance Criteria

1. **Nudge component.** A reusable `InstallNudge` renders, given the selected games (name + slug):
   "Voici comment installer tes jeux sélectionnés" + a link to the generic guide (`/aide/archipelago`)
   + a link per **distinct** game to its tutorial (`/jeux/{slug}`). Renders nothing when no game is
   selected.
2. **Personal run flow.** On `/runs/{runId}/jeux`, the nudge appears under "Ma sélection", reflecting the
   currently selected games.
3. **Event registration flow.** On the event registration game-selection step, the nudge appears under
   "Ma sélection" too.
4. **No backend change.** The selection payloads already expose each game's `slug`; the nudge is built
   from existing data.
5. **Gates green:** frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] `features/games/install-nudge.tsx` - pure component (no hooks), de-dupes by slug, links to
      `/aide/archipelago` + `/jeux/{slug}`.
- [ ] Personal run page (`personal-run-game-selection-page.tsx`): derive distinct selected `{name, slug}`
      from `workingGameIds` via the game map; render `<InstallNudge>` after the selection card.
- [ ] Event gate (`game-selection-gate.tsx`): same derivation + render under the selection section.

## Dev Notes

- **Reuse**: both selection pages already build a `gameMap` (id → game with `slug`/`name`) and track
  `workingGameIds`; the nudge is derived from those. [Source: frontend/src/features/personal-runs/personal-run-game-selection-page.tsx, frontend/src/features/events/game-selection-gate.tsx]
- The nudge links to the per-game tutorials (31.2) and the generic guide (31.3) - both already shipped.
- Pure component (server/client agnostic); internal `next/link` navigation; tokens-only.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Prior: [Source: _bmad-output/implementation-artifacts/31-2-public-render-game-detail.md], [Source: _bmad-output/implementation-artifacts/31-3-generic-archipelago-guide.md]
- Standards: [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Implemented on branch `feature/epic-31-story-4-install-nudge` (from develop). Frontend-only - no API change (slugs already in the selection payloads).
- `InstallNudge` (pure, de-dupes by slug) wired into both the personal-run selection page and the event registration gate, under "Ma sélection".
- Gates green: FE typecheck / lint / build.

### File List

**Added (frontend)**
- `frontend/src/features/games/install-nudge.tsx`

**Modified (frontend)**
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx`
- `frontend/src/features/events/game-selection-gate.tsx`