# Story 31.2: Public render of install steps on the game detail page

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor on `/jeux/[slug]`,
I want to see the game's installation tutorial as clear, ordered steps,
so that I know exactly how to install and set it up.

Second story of Epic 31. Surfaces the steps authored/seeded in 31.1 on the public game page.

## Acceptance Criteria

1. **Payload.** The public `GET /api/v1/games/{slug}` exposes `installSteps` (the model shape from 31.1:
   `list<array{type, title, description, links: list<array{label, url: string|null}>}>`; `[]` when none).
2. **Render.** When a game has steps, `/jeux/[slug]` renders an ordered "Installation" section: per step a
   type label, the title, the description **as plain text** (never raw HTML), and the links as anchors
   (`rel="noopener noreferrer"`, new tab) when a url is present, else a plain label.
3. **Graceful migration (sequencing gate).** The old live-Sheet "Liens & ressources" block is shown only
   as a **fallback when the game has no steps**; a game with steps shows the steps instead (the sheet
   links were folded into the seeded steps in 31.1). No page is ever empty; no data is lost for
   un-seeded games. (Fully dropping the live-Sheet resolution is deferred until the catalog is seeded.)
4. **Safety.** Descriptions are rendered as text (no `dangerouslySetInnerHTML`); the public payload's
   step urls were already validated http(s) by the 31.1 normalizer.
5. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/** (AC: 1)
  - [ ] `DbalGameCatalogQuery::bySlug` - select `game.install_steps`, decode it (narrowed) in
        `mapDetailRow` as `installSteps`. Update the `GameCatalogQueryInterface::bySlug` + `mapDetailRow`
        return-shape docblocks to include `installSteps`. `PublicGameDetailQuery` passes the base through
        unchanged, so the public payload gains `installSteps` automatically.
- [ ] **frontend** (AC: 2, 3, 4)
  - [ ] `public-games-api.ts`: add `GameStep` type + `installSteps: GameStep[]` to `PublicGameDetail`,
        extend `isPublicGameDetail` (guard steps + links). Update the jest `validDetail` fixture.
  - [ ] `game-detail.tsx`: render the steps section (ordered, plain-text descriptions, validated links);
        show the existing catalog "Liens & ressources" block only when `installSteps.length === 0`.
- [ ] **Tests** (AC: 5)
  - [ ] Backend functional: `GET /games/{slug}` exposes `installSteps` (seed a game, assert the steps in
        the payload).
  - [ ] Frontend jest: `getPublicGame` still passes with `installSteps` in the fixture; guard rejects a
        malformed step.

## Dev Notes

- **Reuse**: the apworld block, options, platforms, badges and Steam link already render (28.9). This
  story adds the steps section and demotes the catalog block to a fallback. [Source: frontend/src/features/games/game-detail.tsx]
- **Payload path**: base shape comes from `DbalGameCatalogQuery::bySlug`; the CatalogSync
  `PublicGameDetailQuery` returns `$base` with sheet additions, so adding `installSteps` to the base is
  enough. [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php, api/src/CatalogSync/Application/PublicGameDetailQuery.php]
- **Render safety**: plain-text descriptions (`whitespace-pre-line`), no raw HTML; urls already http(s).
- **Scope**: does not remove the live-Sheet resolution from `PublicGameDetailQuery` (kept for the
  fallback + bundled/adult badges); that cleanup happens once all games are seeded (31.1 bulk command).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Prior: [Source: _bmad-output/implementation-artifacts/31-1-install-steps-model-and-admin-authoring.md], [Source: _bmad-output/implementation-artifacts/28-9-public-game-detail-page.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Implemented on branch `feature/epic-31-story-2-public-render` (from develop).
- `DbalGameCatalogQuery::bySlug` now selects + decodes `game.install_steps` (narrowed) as `installSteps`; interface + mapRow shape docblocks updated. `PublicGameDetailQuery` passes the base through, so the public payload gains `installSteps` with no facade change.
- `game-detail.tsx` renders an ordered "Installation" section (numbered, type label, plain-text description via `whitespace-pre-line`, links as `rel="noopener noreferrer"` anchors or plain labels). The live-Sheet "Liens & ressources" block is now shown **only when `installSteps` is empty** (graceful fallback). The live-Sheet resolution itself is kept (powers the fallback + bundled/adult badges) and will be dropped once the catalog is fully seeded.
- `public-games-api.ts`: `GameStep`/`GameStepType` types + `installSteps` on `PublicGameDetail` + guard (`isGameStep`).
- Gates green: php-cs-fixer 0, phpstan 0 (src+tests), DDD exit 0, phpunit 1267 (+2); FE typecheck/lint/build, jest 43.

### File List

**Modified (api)**
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php` (bySlug select + decodeInstallSteps/decodeStepLinks)
- `api/src/GameSelection/Application/GameCatalogQueryInterface.php` (bySlug shape docblock)
- `api/tests/Functional/PublicGameDetailTest.php` (+ installSteps assertion)

**Modified (frontend)**
- `frontend/src/features/games/public-games-api.ts` (GameStep types + guard)
- `frontend/src/features/games/game-detail.tsx` (Installation section + catalog fallback)
- `frontend/src/features/games/public-games-api.test.ts` (fixture + malformed-step test)
