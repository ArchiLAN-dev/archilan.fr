# Story 3.13: Archipelago-specific description on a game

**Status:** done
**Epic:** 3 - Admin event & game library management
**Date:** 2026-07-22

## Story

As an admin,
I want a second, optional description on a game dedicated to its **Archipelago** side,
so that the public game page can separate "what this game is" from "what the randomizer does with it" instead of cramming both into one block.

## Context

`Game.description` is a required TEXT holding the general presentation of the game. Nothing today carries the Archipelago-specific angle - what gets randomized, what the goal is, which quirks the apworld has.

Two neighbouring fields exist and are **not** substitutes:

- `catalog.notes` is synced from the external Google Sheet catalogue (`CatalogSync`, resolved live in `PublicGameDetailQuery`) - we do not author it and cannot rely on it.
- `Game.adminNotes` (story 3.12) is strictly internal and must never reach a public page.

So this is a new, admin-authored, publicly displayed field.

### Decisions

- **Nullable, no backfill.** Existing games get `null`; the public page simply omits the block. `description` stays required, this one is optional.
- **Markdown, like every other content field** (story 10.10): the admin edits it with `MarkdownEditor` and the public page renders it with `Markdown`. This story is stacked on that work.
- **Excluded from SEO metadata.** `generateMetadata` keeps using `description` alone - the meta description should stay the game's general pitch, and mixing two sources would make it unpredictable.
- **Same 5000 cap** as `description`, mirrored frontend + API.
- Named `archipelagoDescription` / `archipelago_description`, next to the existing `archipelagoGameName`.

## Acceptance Criteria

1. `Game` carries a nullable `archipelagoDescription` TEXT with a getter and a named mutator that nulls an empty/whitespace value, plus a reversible migration.
2. The admin game editor exposes it (markdown editor, optional, capped at 5000) and saves it; it is absent from the payload when never set.
3. The public game page renders it as markdown in its own block **below the cover image and the general description**, and renders nothing at all when it is null or empty.
4. It never appears in `generateMetadata` output.
5. It is exposed on the admin detail payload and the public game payload - and, being public, carries no admin-only data.
6. Gates green both sides.

## Tasks / Subtasks

- [x] **Task 1 - Domain + migration** (AC 1). Field, getter, `recordArchipelagoDescription()`, migration.
- [x] **Task 2 - Application** (AC 2, 5). Parse/validate on the admin write path (5000 cap), expose in the admin detail payload and in the public game payload.
- [x] **Task 3 - Frontend admin** (AC 2). `MarkdownEditor` in the game editor (and the other two creation forms if the field is offered there), type updated.
- [x] **Task 4 - Frontend public** (AC 3, 4). Render block on the game detail page, **below the cover image and the description**, guarded on emptiness; leave `generateMetadata` untouched.
- [x] **Task 5 - Tests + gates** (AC 6). Unit for the mutator's null-on-empty, functional for save + public exposure.

## Dev Notes

- Mirrors the `adminNotes` shape from story 3.12 (nullable TEXT + `recordX`), with the opposite visibility: public rather than admin-only.
- Stacked on story 10.10's markdown components; the field is markdown from day one rather than being retrofitted.

### Project Structure Notes

- `api/src/GameSelection/Domain/Entity/Game.php`
- `api/migrations/Version2026072210xxxx.php` (new)
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php`
- the public game detail query/payload
- `frontend/src/features/admin/admin-game-editor.tsx`
- `frontend/src/features/games/game-detail.tsx`, `public-games-api.ts`

### References

- [Source: _bmad-output/implementation-artifacts/3-12-admin-game-notes.md]
- [Source: _bmad-output/implementation-artifacts/10-10-markdown-authoring-and-rendering.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- No overlap with the two neighbouring fields, checked before adding: `catalog.notes` is synced from the external Google Sheet (`CatalogSync`) and not authorable here, `adminNotes` is admin-only. This one is ours and public.
- **Partial-update hazard caught during implementation**: the catalogue section of the admin editor PATCHes a *partial* field set. Applying the mutator unconditionally would have wiped the field on every catalogue save. `update()` now only touches it when the caller actually sent the key, and a functional test pins that.
- Public page shows it in its own bordered block titled "Sur Archipelago", under the cover image and the general description, and renders nothing when null.
- Kept out of `generateMetadata` on purpose: the meta description stays the game's general pitch.
- Markdown from day one (story 10.10 components), capped at 5000 like `description`.

### File List

- `api/src/GameSelection/Domain/Entity/Game.php` (field + `getArchipelagoDescription` / `recordArchipelagoDescription`)
- `api/migrations/Version20260722100000.php` (new)
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php` (parse, validate, guarded apply, payload)
- `api/src/GameSelection/Infrastructure/Dbal/DbalGameCatalogQuery.php` (public detail select + mapping)
- `api/src/GameSelection/Application/Query/GameCatalogQueryInterface.php` (shape docblock)
- `api/tests/Unit/GameSelection/GameArchipelagoDescriptionTest.php` (new)
- `api/tests/Functional/AdminGameLibraryTest.php` (save + partial-update guard)
- `frontend/src/features/admin/admin-games-api.ts`, `admin-game-editor.tsx`
- `frontend/src/features/games/public-games-api.ts`, `game-detail.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-07-22 | Created + implemented. Optional public `archipelagoDescription` on a game, markdown-edited in admin and rendered under the description on the public page. Gates green both sides. Status -> done. |
