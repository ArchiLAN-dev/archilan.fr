# Story 3.12: Admin Notes tab on the game detail page

**Status:** done
**Epic:** 3 - Admin event & game library management
**Date:** 2026-07-19
**Issue:** #303

## Story

As an admin,
I want a **Notes** tab on the game admin page (`/admin/jeux/[gameId]`) where I can jot free-text notes about a game,
so that apworld quirks, config pitfalls, and decision history are recorded for the other admins - without ever leaking to the public catalogue.

## Context

The game admin editor (`admin-game-editor.tsx`) exposes management tabs (Général, Catalogue, APWorld, Tutoriel) but no free-text space. Admins have nowhere to record explanations or caveats about a game. This adds an internal, admin-only notes field on the game, persisted in the `GameSelection` context and surfaced only through the admin detail payload.

### Decisions (the story's "points à trancher")

- **Single free-text field**, not a timestamped multi-author log. "Annoter la fiche" = one scratchpad per game; a per-note author/timestamp history is out of scope (possible follow-up).
- **Plain text**, stored and returned verbatim (trimmed; empty becomes `null`). No markdown rendering - internal notes, and it avoids any render/XSS surface. Displayed in a `<textarea>` for edit and `whitespace-pre-wrap` for read.
- **Strictly admin-internal**: exposed only in the admin `detailPayload`, saved via a dedicated admin endpoint. Never added to any public/game-selection payload (`PersonalRunGameSelection`, `RegistrationGameSelection`, public game detail) nor the public API.
- **No extra traceability** beyond the field for this iteration.

## Acceptance Criteria

1. `Game` carries a nullable `adminNotes` text field with `getAdminNotes(): ?string` and `recordAdminNotes(?string): void` (trim, empty/whitespace -> `null`), plus a reversible migration.
2. `PATCH /api/v1/admin/games/{gameId}/notes` (admin-only via `requireAuthenticatedAdmin`) saves the note and returns the updated admin detail payload; 404 for an unknown game.
3. `adminNotes` appears in the admin **detail** payload (`AdminGameLibrary::detailPayload`) only - never in the base `payload()` nor in any public / game-selection payload.
4. A non-admin (lambda or anonymous) calling the notes endpoint is rejected (401/403) and no note is saved.
5. The game admin page shows a **Notes** tab with an editable textarea and a save action; saving persists and reflects back. Graceful when empty.
6. Gates green both sides: `composer gates` (phpstan max+strict, cs-fixer, ddd, rector, phpunit) and `pnpm gates` (typecheck, lint, test, build).

## Tasks / Subtasks

- [x] **Task 1 - Domain + migration** (AC 1). `Game.adminNotes` (`?string`, `type: 'text'`, nullable) + `getAdminNotes` + `recordAdminNotes` (trim, empty/whitespace -> null). Migration `Version20260719100001` (`ALTER TABLE game ADD COLUMN admin_notes TEXT DEFAULT NULL`).
- [x] **Task 2 - Application** (AC 2, 3). `AdminGameLibrary::saveNotes(gameId, notes)` (mirror `saveTutorial`) returning the `{found, game, errors}` contract; added `'adminNotes' => $game->getAdminNotes()` to `detailPayload()` only.
- [x] **Task 3 - Presentation** (AC 2, 4). `PATCH /admin/games/{gameId}/notes` on `AdminGameLibraryController`, `requireAuthenticatedAdmin`, non-string/too-long (>20000) -> 422, found/errors mapped to 404/200.
- [x] **Task 4 - Backend tests** (AC 1, 2, 3, 4). Unit `GameAdminNotesTest` (trim/null); functional `AdminGameNotesTest` (admin save+read, blank clears to null, unknown 404, 401 anon / 403 lambda, `adminNotes` absent from the admin list = detail-only).
- [x] **Task 5 - Frontend** (AC 5). `adminNotes: string | null` on `AdminGame`; `NotesSection` (textarea + save) + `{id:"notes", label:"Notes"}` tab + panel, mirroring `InstallTutorialSection`.
- [x] **Task 6 - Gates** (AC 6). `composer gates` (phpunit isolated 1582 tests / 10717 assertions) + `pnpm gates` (196 tests, clean build) green.

## Dev Notes

- Backend templates: entity `recordLocationNames` (`Game.php`), service `saveTutorial` (`AdminGameLibrary.php:50`), controller `saveTutorial` action (`AdminGameLibraryController.php:145`), migration `Version20260719100000`.
- Frontend templates: `InstallTutorialSection` + `EDITOR_TABS` (`admin-game-editor.tsx`), `AdminGame` type + `isAdminGamePayload` (`admin-games-api.ts`).
- The admin detail payload is already admin-gated (fetched via `/admin/games/{id}`), so `adminNotes` there is safe; the invariant to keep is "not in base `payload()` / not in public payloads".

### Project Structure Notes

- `api/src/GameSelection/Domain/Entity/Game.php`
- `api/migrations/Version20260719100001.php` (new)
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php`
- `api/src/GameSelection/Presentation/Controller/AdminGameLibraryController.php`
- `frontend/src/features/admin/admin-games-api.ts`
- `frontend/src/features/admin/admin-game-editor.tsx`

### References

- [Source: GitHub issue #303]
- [Source: _bmad-output/implementation-artifacts/3-5-archipelago-game-library-management.md]
- [Source: _bmad-output/implementation-artifacts/31-1-install-steps-model-and-admin-authoring.md (tutorial tab pattern)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- Single free-text field on `Game` (`admin_notes` TEXT), admin-only. Trimmed on save; whitespace-only clears to `null`.
- Exposed only in `AdminGameLibrary::detailPayload()` (admin detail, already admin-gated). NOT in the base `payload()` (admin list) nor in any public / game-selection payload - the functional test asserts absence from the admin list, and no public payload builder references the field.
- Endpoint `PATCH /admin/games/{gameId}/notes` guards admin via `requireAuthenticatedAdmin`; rejects non-string / >20000 chars with 422.
- No markdown rendering and no per-note author/timestamp history (deliberate MVP per the story's decisions; both are possible follow-ups).

### File List

- `api/src/GameSelection/Domain/Entity/Game.php` (`adminNotes` column + `getAdminNotes`/`recordAdminNotes`)
- `api/migrations/Version20260719100001.php` (new)
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php` (`saveNotes` + `adminNotes` in `detailPayload`)
- `api/src/GameSelection/Presentation/Controller/AdminGameLibraryController.php` (`PATCH .../notes`)
- `api/tests/Unit/GameSelection/GameAdminNotesTest.php` (new)
- `api/tests/Functional/AdminGameNotesTest.php` (new)
- `frontend/src/features/admin/admin-games-api.ts` (`adminNotes` on `AdminGame`)
- `frontend/src/features/admin/admin-game-editor.tsx` (Notes tab + panel + `NotesSection`)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-07-19 | Created + implemented. Admin-only Notes tab on the game detail page: `Game.adminNotes` TEXT, admin `PATCH .../notes` endpoint, surfaced only in the admin detail payload, `NotesSection` textarea tab. Gates green both sides. Status -> done. |
