# Story 35.4: Typed failures - Events (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want the three Events command services (`UploadEventCoverImageCommand`, `ManageEventGalleryCommand`,
`AdminEventRecap`) to throw the shared typed failures instead of returning outcome arrays,
so that the Events context adopts the 35.1 foundation and its admin controllers get thinner, HTTP unchanged.

## Context

Epic 35, Stage 1, fourth story. Three Events command methods (cover upload, gallery upload+delete, recap
attach) return outcome/found-errors arrays that their admin controllers branch on. Uses the existing
foundation (35.1 + `ForbiddenException` from 35.3); **no new exception type needed**.

Exact mappings to preserve:

`UploadEventCoverImageCommand::execute` (AdminEventCoverImageController):

| outcome | HTTP | code | message |
|---|---|---|---|
| `not_found` | 404 | `not_found` | Événement introuvable. |
| `storage_error` | 503 | `storage_unavailable` | Le stockage est indisponible. |
| `ok` | 200 | - | `{ data: <admin event payload> }` |

`ManageEventGalleryCommand::upload` (AdminEventGalleryController::upload):

| outcome | HTTP | code | message |
|---|---|---|---|
| `not_found` | 404 | `not_found` | Événement introuvable. |
| `gallery_full` | 422 | `gallery_full` | La galerie est pleine (max 12 photos). |
| `storage_error` | 503 | `storage_unavailable` | Le stockage est indisponible. |
| `ok` | 200 | - | `{ data: <payload> }` |

`ManageEventGalleryCommand::delete` (AdminEventGalleryController::delete):

| outcome | HTTP | code | message |
|---|---|---|---|
| `not_found` | 404 | `not_found` | Événement introuvable. |
| `invalid_index` | 404 | `not_found` | Index de galerie invalide. |
| `ok` | 204 | - | (no body) |

`AdminEventRecap::attach` (AdminEventController::attachRecap):

| outcome | HTTP | code | message |
|---|---|---|---|
| `found=false` | 404 | `not_found` | Événement introuvable. |
| `errors` non-empty | 422 | `validation_failed` | Les données de récap sont invalides. (+ field map) |
| success | 200 | - | `{ data: null, meta: { message: 'Récap attaché.' } }` |

## Acceptance Criteria

1. **AC1 - Cover.** `UploadEventCoverImageCommand::execute` throws `NotFoundException` / `ServiceUnavailableException`
   (`storage_unavailable`); returns the payload on success. `AdminEventCoverImageController` drops the two
   branches -> `{ data: <payload> }`.
2. **AC2 - Gallery.** `ManageEventGalleryCommand::upload` throws `NotFoundException` /
   `ValidationException('...', [], 'gallery_full')` / `ServiceUnavailableException`; returns the payload on
   success. `::delete` throws `NotFoundException('Événement introuvable.')` (missing) /
   `NotFoundException('Index de galerie invalide.')` (invalid index - both 404 `not_found`); returns void on
   success. `AdminEventGalleryController` drops the branches (`upload` -> `{ data }`, `delete` -> 204).
3. **AC3 - Recap.** `AdminEventRecap::attach` throws `NotFoundException` / `ValidationException('Les données
   de récap sont invalides.', $errors)`; returns void on success. `AdminEventController::attachRecap` drops
   the branches -> the `{ data: null, meta: {...} }` success body.
4. **AC4 - Contract unchanged.** Statuses/codes/messages/bodies identical. The Events functional tests
   (`AdminEventCoverImageTest`, `AdminEventGalleryTest`, the recap test) stay green unchanged.
5. **AC5 - Gates.** `composer gates` green. Controllers thinner (AC-P3/P4). No behaviour change elsewhere.

## Tasks / Subtasks

- [x] Task 1: cover (AC: 1) - `UploadEventCoverImageCommand` throws + returns payload; controller thinned.
- [x] Task 2: gallery (AC: 2) - `ManageEventGalleryCommand` upload (throws + payload) + delete (throws +
      void); `AdminEventGalleryController` thinned.
- [x] Task 3: recap (AC: 3) - `AdminEventRecap::attach` throws + void; `AdminEventController::attachRecap`
      thinned.
- [x] Task 4: verify + ship (AC: 4, 5) - `composer gates` (static + isolated Events functional). PR to `develop`.

## Dev Notes

- **`invalid_index` is 404 with code `not_found` but a distinct message** ("Index de galerie invalide.").
  Model it as `NotFoundException('Index de galerie invalide.')` - default code `not_found`, status 404.
- **Cover/gallery upload return the admin payload on success** (`adminEventDrafts->get(...)`) - keep it;
  only failure becomes typed. `delete` + `recap` are void-on-success.
- **The image controllers used raw `JsonResponse` (no `details`)**; the listener now adds `details: []`
  (per 35.2). Safe: the Events image tests assert status + `error.code`, not the full body (same as Content).
- **`gallery_full` as `ValidationException`** (422) with the custom code - the empty details keep the body
  identical.
- Controller-level request validation (mime/size checks before the command) stays as raw responses.
- House rules unchanged; no new exception type. `composer gates`; isolated phpunit for the Events tests.

### References

- Foundation: 35.1-35.3 (`Shared\Application\Exception\*`, listener emits `details`).
- Convert: `src/Events/Application/Command/{UploadEventCoverImageCommand,ManageEventGalleryCommand,AdminEventRecap}.php`
  + `src/Events/Presentation/Controller/{AdminEventCoverImageController,AdminEventGalleryController,AdminEventController}.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Cover: `UploadEventCoverImageCommand::execute` -> `?array`, throwing `NotFoundException` /
  `ServiceUnavailableException('...', 'storage_unavailable')`. Controller returns `{ data }`.
- Gallery: `upload` -> `?array`, throwing `NotFoundException` / `ValidationException('...', [], 'gallery_full')`
  / `ServiceUnavailableException`. `delete` -> `void`, throwing `NotFoundException('Événement introuvable.')`
  or `NotFoundException('Index de galerie invalide.')` (both 404 `not_found`, distinct message). Controller:
  `upload` -> `{ data }`, `delete` -> 204.
- Recap: `AdminEventRecap::attach` -> `void`, throwing `NotFoundException` / `ValidationException('Les
  données de récap sont invalides.', $errors)` (both the field-validation and the `DomainException`
  not-completed cases). Controller returns the `{ data: null, meta: { message } }` body.
- All three controllers dropped their outcome/errors branches (thinner - AC-P3/P4); their pre-command
  request validation (mime/size, JSON parsing) stays as raw responses.
- HTTP contract identical: 59 Events functional tests (cover, gallery, recap, drafts, lifecycle) pass
  unchanged - byte-exact regression proof.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1542 tests / 10538
  assertions** (isolated DB). No new exception type needed.

### File List

- `api/src/Events/Application/Command/UploadEventCoverImageCommand.php` (throws + payload)
- `api/src/Events/Application/Command/ManageEventGalleryCommand.php` (upload throws + payload; delete throws + void)
- `api/src/Events/Application/Command/AdminEventRecap.php` (throws + void)
- `api/src/Events/Presentation/Controller/AdminEventCoverImageController.php` (thinner)
- `api/src/Events/Presentation/Controller/AdminEventGalleryController.php` (thinner)
- `api/src/Events/Presentation/Controller/AdminEventController.php` (thinner attachRecap)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created (epic 35 Stage 1, 35.4). Events: 3 command methods (cover, gallery upload/delete, recap) converted onto the existing foundation; no new exception type. Mappings grounded in the 3 controllers. Status: ready-for-dev. |
| 2026-07-16 | Implemented: cover/gallery/recap commands throw + controllers thinned. `composer gates` green (1542 tests). Status: done. |
