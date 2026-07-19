# Story 35.5: Typed failures - GameSelection (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want the four GameSelection command services to throw the shared typed failures instead of returning
outcome/found-errors arrays,
so that the context adopts the 35.1 foundation and its four admin/public controllers get thinner, HTTP
unchanged.

## Context

Epic 35, Stage 1, fifth story. Four GameSelection commands (tutorial submit + moderate, Archipelago client +
guide update) return varied `found`/`conflict`/`errors`/`id` arrays their controllers branch on. Uses the
existing foundation (35.1-35.3); **no new exception type**.

Exact mappings to preserve:

| Command method | mapping |
|---|---|
| `ModerateGameTutorialContribution::approve`/`reject` | found=false -> 404 `not_found` "Contribution introuvable." ; not-pending/race -> 409 `already_moderated` "Cette contribution a déjà été modérée." ; errors -> 422 `validation_failed` "La modération a échoué." (+ field map) ; success -> `{ meta: { message } }` (per-action) |
| `SubmitGameTutorialContribution::submit` | game missing/unavailable -> 404 `not_found` "Jeu introuvable." ; validation -> 422 `validation_failed` "La contribution contient des erreurs." (+ field map) ; success -> `{ data: { id }, meta }` 201 |
| `UpdateArchipelagoClient::update` | errors -> 422 `validation_failed` "Le client Archipelago contient des erreurs." (+ field map) ; success -> `{ data: <client>, meta }` |
| `UpdateArchipelagoGuide::update` | errors -> 422 `validation_failed` "Le guide contient des erreurs." (+ field map) ; success -> `{ data: { steps }, meta }` |

## Acceptance Criteria

1. **AC1 - Moderation.** `approve`/`reject` throw `NotFoundException` / `ConflictException('...', 'already_moderated')`
   / `ValidationException('La modération a échoué.', <map>)`; return void. `AdminGameContributionController`
   drops the shared `respond()` helper - each action calls the command then returns its own success message.
2. **AC2 - Submit.** `submit` throws `NotFoundException('Jeu introuvable.')` / `ValidationException('La
   contribution contient des erreurs.', <map>)`; returns the new `id` (string). `GameContributionController`
   drops the branches -> `{ data: { id }, meta }` 201.
3. **AC3 - Client + Guide.** `UpdateArchipelagoClient::update` / `UpdateArchipelagoGuide::update` throw
   `ValidationException` with their exact messages; return void. Both controllers drop the branch and return
   their success body.
4. **AC4 - Contract unchanged.** Statuses/codes/messages/bodies identical. The GameSelection functional tests
   (moderation, submit, client, guide) stay green unchanged.
5. **AC5 - Gates.** `composer gates` green. Controllers thinner (AC-P3/P4).

## Tasks / Subtasks

- [x] Task 1: `ModerateGameTutorialContribution` (approve/reject) throw + void; controller drops `respond()`.
- [x] Task 2: `SubmitGameTutorialContribution::submit` throws + returns id; controller thinned.
- [x] Task 3: `UpdateArchipelagoClient` + `UpdateArchipelagoGuide` throw + void; both controllers thinned.
- [x] Task 4: verify + ship - `composer gates` (static + isolated GameSelection functional). PR to `develop`.

## Dev Notes

- **Moderation success message is per-action** ("Contribution appliquée." / "Contribution refusée.") - the
  controller sets it (it knows which action), so the command is void-on-success. The shared `respond()`
  helper is removed.
- **`submit` returns the id string on success** (the controller wraps it in `{ data: { id } }`, 201). All its
  validation branches map to the same 422 message with their respective field maps; the unavailable-game
  branch is a 404.
- **`already_moderated` -> `ConflictException` (409)** with the custom code (both the not-pending check and
  the concurrent-race `DomainException`).
- No new exception type. Controller-level auth/JSON parsing stays. `composer gates`; isolated phpunit.

### References

- Foundation: 35.1-35.4 (`Shared\Application\Exception\*`, listener emits `details`).
- Convert: `src/GameSelection/Application/Command/{ModerateGameTutorialContribution,SubmitGameTutorialContribution,UpdateArchipelagoClient,UpdateArchipelagoGuide}.php`
  + `src/GameSelection/Presentation/Controller/{AdminGameContributionController,GameContributionController,AdminArchipelagoClientController,AdminArchipelagoGuideController}.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- All four commands converted: moderation (approve/reject -> void, throwing NotFound/Conflict(already_moderated)/
  Validation), submit (-> id string, throwing NotFound/Validation), client + guide update (-> void, throwing
  Validation with their exact messages).
- `AdminGameContributionController` lost its shared `respond()` helper - approve/reject now call the command
  and return their own `{ meta: { message } }`. The other three controllers dropped their branches too.
- HTTP contract identical: 37 GameSelection functional tests (moderation, submit, client, guide, library)
  pass unchanged - byte-exact regression proof.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1542 tests / 10538
  assertions** (isolated DB). No new exception type.

### File List

- `api/src/GameSelection/Application/Command/ModerateGameTutorialContribution.php`
- `api/src/GameSelection/Application/Command/SubmitGameTutorialContribution.php`
- `api/src/GameSelection/Application/Command/UpdateArchipelagoClient.php`
- `api/src/GameSelection/Application/Command/UpdateArchipelagoGuide.php`
- `api/src/GameSelection/Presentation/Controller/AdminGameContributionController.php` (dropped `respond()`)
- `api/src/GameSelection/Presentation/Controller/GameContributionController.php`
- `api/src/GameSelection/Presentation/Controller/AdminArchipelagoClientController.php`
- `api/src/GameSelection/Presentation/Controller/AdminArchipelagoGuideController.php`

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created + implemented (epic 35 Stage 1, 35.5). GameSelection: 4 commands (moderate, submit, client, guide) converted onto the existing foundation; no new exception type. `composer gates` green (1542 tests). Status: done. |
