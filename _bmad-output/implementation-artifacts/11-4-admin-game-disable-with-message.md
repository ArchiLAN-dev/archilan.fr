# Story: admin can disable a game with a message (not selectable in sessions)

Status: implemented (review)

Repo: `archilan.fr` (monorepo, `api/` + `frontend/`).

## Story

As an admin,
I want to temporarily disable a game and attach a short message explaining why,
so that players can see the game is not playable right now and cannot select it for a session.

As a player,
I want disabled games to stay visible but greyed-out with the admin's message,
so that I understand why I cannot pick them instead of the game silently vanishing.

## Context

Games already carry an `availability` field (`available` / `unavailable` / `experimental`) synced
from the catalogue sheet, but it carries no message, can be overwritten by the sync (unless
`availability_locked`), and encodes long-term catalogue status - not a temporary operational
outage (broken apworld, pending fix, tournament freeze...). This story adds an orthogonal,
admin-only kill switch with a message.

Selection flows affected (map from exploration):

- Event registration: `RegistrationGameSelection` (`availableGames` DTO + `validateGameIds()`),
  frontend `game-selection-gate.tsx`.
- Personal/private runs: `PersonalRunGameSelection` (`availableGames` DTO + `validateGameIds()`),
  frontend `personal-run-game-selection-page.tsx`.
- Admin: `AdminGameLibrary` facade (partial-PATCH convention), `admin-game-editor.tsx`,
  `admin-game-library-dashboard.tsx`.

Out of scope: the public catalogue page `/jeux` (badge there can be a follow-up), weekly template
admin picker (admins remain trusted to pick sensibly; the disable targets player selection).

## Acceptance Criteria

1. `game` table gains `disabled_at` (timestamptz, nullable) and `disabled_message` (varchar 500,
   nullable) via migration. `Game` entity gains `disable(?string $message, \DateTimeImmutable $now)`,
   `enable()`, `isDisabled()`, `disabledMessage()` (message trimmed, `'' -> null`, 500 max -
   same normalization as `User::moderation_reason`).
2. `PATCH /api/v1/admin/games/{gameId}` accepts optional `disabled` (bool) + `disabledMessage`
   (string|null) following the partial-PATCH convention (`array_key_exists`). Admin payloads
   (list + detail) expose `disabled` and `disabledMessage`.
3. Player-facing `availableGames` DTOs (event registration + personal runs) expose
   `disabled` (bool) + `disabledMessage` (string|null). Disabled games are NOT filtered out of the
   lists (already-selected slots must keep resolving; players must see why a game is off).
4. Server-side rejection: selecting a disabled game fails validation in both
   `RegistrationGameSelection::validateGameIds()` and `PersonalRunGameSelection::validateGameIds()`
   with a clear French message that includes the admin message when present. Already-saved slots
   referencing a newly disabled game are not destroyed - re-submitting an unchanged selection that
   contains the disabled game stays accepted (only NEW additions are blocked) if trivially
   implementable, otherwise reject all and document; at minimum reads never break.
5. Frontend admin: on `/admin/jeux/[gameId]`, a "Désactiver le jeu" toggle + message textarea
   (identity form PATCH); on `/admin/jeux`, a "Désactivé" badge on disabled games.
6. Frontend player: in both game pickers (event registration + personal runs), disabled games are
   greyed out (row opacity), their add/select button is `disabled` with the canonical
   `disabled:cursor-not-allowed disabled:opacity-40` classes, and the admin message is shown
   (inline text or tooltip). A "Désactivé" badge variant is added to the availability badge maps.
7. Tests: API functional coverage for the admin PATCH round-trip and for the selection rejection
   (both flows); frontend jest untouched or extended as needed.
8. Gates green: `composer gates` (api) + `pnpm gates` (frontend).

## Tasks / Subtasks

- [x] Task 1 - migration + `Game` entity fields/methods (AC 1).
- [x] Task 2 - `AdminGameLibrary` PATCH parsing/validation + payloads; admin list query mapping (AC 2).
- [x] Task 3 - player DTOs + rejection in both selection services (AC 3, 4).
- [x] Task 4 - admin frontend: editor toggle + message, dashboard badge, api types (AC 5).
- [x] Task 5 - player frontend: greyed rows + disabled buttons + message in both pickers (AC 6).
- [x] Task 6 - tests + gates (AC 7, 8).

## Dev Notes

- DDD: no DBAL in Application; reuse `GameRepositoryInterface` lookups already present in both
  selection services.
- `AdminGameLibrary` keeps the grandfathered facade array contract (no new Command class needed).
- Do not filter disabled games in `findByAvailabilitiesSortedByName()` - flag them in the DTO.

## Dev Agent Record

- Columns: `disabled_at` timestamptz + `disabled_message` varchar(500), migration
  `Version20260726100000`. Entity methods `disable(?message, now)` (idempotent on the timestamp,
  message normalized trim/''->null/500 cap), `enable()`, `isDisabled()`, `getDisabledMessage()`.
- Admin PATCH: tri-state `disabled` key (absent = untouched) mirroring `availability_locked`;
  `disabledMessage` editable alone while disabled. Exposed in `payload()` (list + detail) and in
  `DbalAdminGameListQuery`.
- Rejection: `validateDisabledGames()` in both selection services - count-aware allowance so a
  selection re-submitted with an already-held slot of a now-disabled game passes, while adding an
  extra copy of that game is refused. Error message embeds the admin message when present.
- AC 4 "only NEW additions blocked" implemented (not the fallback).
- Frontend: badge "Désactivé" (danger) + inline message + `disabled` add button + row opacity in
  both pickers; toggle + textarea in the admin editor identity form; badge on `/admin/jeux`
  (desktop table + mobile cards). `disabled`/`disabledMessage` optional in player-facing TS types
  (older payload shapes stay valid).

## Change Log

- 2026-07-26 - Story created.
- 2026-07-26 - Implemented (api + frontend + tests), gates run.
