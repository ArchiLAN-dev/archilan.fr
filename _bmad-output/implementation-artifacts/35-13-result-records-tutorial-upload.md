# Story 35.13: Typed result record - self-contained tutorial image upload (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want `UploadTutorialImageCommand::execute` to return a `final readonly` `TutorialImageUpload` record instead of
`array{key, url}`, so that the one self-contained upload command drops its array shape with the HTTP contract
unchanged.

## Context

Epic 35, Stage 2. The epic's original "35.13 - Uploads" grouped four image-upload commands, but on inspection
three of them (`UploadPostCoverImageCommand`, `UploadEventCoverImageCommand`, `ManageEventGalleryCommand`) do
**not** return a self-contained shape - each delegates its return to an admin read-model facade
(`AdminPostCatalog::get`, `AdminEventDrafts::get`), which yields a big untyped `array<string,mixed>` admin
payload **shared** with those facades' `list/get/create/update/...` methods. Typing them cleanly means typing
the whole admin read-model (a shared `AdminPostView`/`AdminEventView` DTO reused everywhere + the admin
controller) - a materially larger chantier that belongs to the per-context read-model stories.

**Scope split (Jean, 2026-07-18):** 35.13 covers only `UploadTutorialImageCommand`, which returns a genuinely
self-contained `{key, url}` (storage key persisted on the step + a presigned preview URL). The three
admin-payload uploads move to the Content/Events admin read-model stories (35.14 / 35.15), where the upload
command returns the same shared DTO for free.

## Acceptance Criteria

1. **AC1 - Record.** Add `TutorialImageUpload` (`final readonly`, `string $key` + `string $url`, in that order),
   colocated in `GameSelection/Application/Command/`.
2. **AC2 - Command converted.** `UploadTutorialImageCommand::execute` returns `TutorialImageUpload`; `@return
   array{key, url}` docblock dropped. Behaviour (upload + presign) unchanged.
3. **AC3 - Controller byte-identical.** `TutorialImageController` already does `['data' => $data]`; the record
   serializes via `json_encode` to `{"key":...,"url":...}` = the former array, so no controller change is
   needed and the response body is byte-identical.
4. **AC4 - Gates.** `composer gates` green. `TutorialImageUploadTest` green unchanged (it asserts the HTTP body,
   not the command result).

## Tasks / Subtasks

- [x] Task 1: `TutorialImageUpload` record (AC: 1).
- [x] Task 2: convert `execute()` return + signature + docblock (AC: 2).
- [x] Task 3: confirm controller + tests need no change (AC: 3, 4).
- [x] Task 4: verify + ship (AC: 4) - static gates + isolated full suite. PR to `develop`.
- [x] Task 5: reshape the epic Stage 2 breakdown (admin-payload uploads -> read-model stories 35.14/35.15).

## Dev Notes

- **Only the self-contained upload.** `UploadTutorialImageCommand` owns its whole result (`key` from the caller,
  `url` from `minioStorage->presignedUrl`) - no query delegation - so a `{key, url}` record is the clean,
  local conversion. The three admin-payload uploads are entangled with the admin read-models and are typed
  there.
- **No controller/test edits.** The controller passes the record straight to `['data' => $data]` (json_encode =
  former array). `TutorialImageUploadTest` asserts the decoded HTTP body (`data.key`/`data.url`), which is
  byte-identical; no test reads the command result.
- **Colocation.** `TutorialImageUpload` sits in `Application/Command/` with the command (taxonomy rule).
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.12 records, epic Stage 2 breakdown (reshaped by this story).
- Convert: `src/GameSelection/Application/Command/UploadTutorialImageCommand.php`.
- New: `src/GameSelection/Application/Command/TutorialImageUpload.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `TutorialImageUpload` (`final readonly`, `key` + `url`) colocated in `Application/Command/`.
- `execute()` returns the record; `@return array{...}` docblock dropped. Upload + presign behaviour unchanged.
- No controller change (record serializes to the former array via `['data' => $data]`); no test change
  (`TutorialImageUploadTest` asserts the HTTP body, byte-identical).
- Epic breakdown reshaped: the three admin-payload uploads move to the Content (35.14) and Events (35.15) admin
  read-model stories; Sessions+CLI -> 35.17, validator rule -> 35.18.
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/GameSelection/Application/Command/TutorialImageUpload.php` (new)
- `api/src/GameSelection/Application/Command/UploadTutorialImageCommand.php` (returns `TutorialImageUpload`)
- `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (breakdown reshaped)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). `TutorialImageUpload` record; `execute()` converted; epic breakdown reshaped (admin-payload uploads -> read-model stories). `composer gates` green. Status: done. |
