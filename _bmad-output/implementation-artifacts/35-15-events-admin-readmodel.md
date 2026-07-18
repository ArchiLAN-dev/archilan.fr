# Story 35.15: Typed read-view record - Events admin event read-model (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want the admin event read view (`AdminEventDrafts`) to be a `final readonly` `AdminEventView` record instead of
an `array{...}` shape, so that the two event upload commands (`UploadEventCoverImageCommand`,
`ManageEventGalleryCommand`) - which delegate their return to `AdminEventDrafts::get` - return a typed record,
with every admin HTTP body unchanged.

## Context

Epic 35, Stage 2, second read-model story (after 35.14 Content). `AdminEventDrafts` is the Events admin facade;
its `payload()` builds a 25-field admin event view returned by `list`/`get` and **embedded as the `event`** of
its `create`/`update`/`transition`/`configurePrivateAccess` outcome arrays. Both event upload commands return
`$this->adminEventDrafts->get($eventId)`, so typing them means typing that shared read view.

**Stale docblock fixed as a side effect.** The `@return array{...}` shapes declared 22 keys, but the actual
`payload()` returns 25 (they omitted `coverImageUrl`, `coverImageKey`, `photoGallery`; phpstan tolerates the
extra keys since array shapes are not sealed). The record is built from the real 25-field body, so the record
is now the single authoritative shape.

`AdminEventDrafts`'s write methods keep their `{found, errors}` / `{event?, errors}` outcome envelopes (the
documented facade contract, api/CLAUDE.md AC-A3); only the **read view inside** them (`event?`) becomes the
record. `AdminEventGameSelection` (a separate service) and its `{found, errors}` are out of scope.

## Acceptance Criteria

1. **AC1 - Record.** Add `AdminEventView` (`final readonly`, the 25 `payload()` fields in body order, incl.
   `list<string> photoGallery` and the nullable url/key fields), in `Events/Application/Query/`.
2. **AC2 - Facade read view typed.** `AdminEventDrafts::payload()` returns `AdminEventView`; `list()` returns
   `list<AdminEventView>`; `get()` returns `?AdminEventView`; the `event?` in create/update/transition/
   configurePrivateAccess docblocks becomes `AdminEventView`. Loose/stale `array{...}` docblocks dropped.
3. **AC3 - Upload commands return the record.** `UploadEventCoverImageCommand::execute` and
   `ManageEventGalleryCommand::upload` return `?AdminEventView`; `array<string,mixed>|null` docblocks dropped,
   `@throws` kept.
4. **AC4 - Controllers byte-identical.** `AdminEventController` (list/show/create/update/transition/private-
   access, each rendering the view or the `event?`), `AdminEventCoverImageController`, and
   `AdminEventGalleryController` pass the view straight to `['data' => ...]`; the record serializes via
   `json_encode` to the former array. No controller change; every body byte-identical.
5. **AC5 - Gates.** `composer gates` green. Admin event functional suites green unchanged.

## Tasks / Subtasks

- [x] Task 1: `AdminEventView` record - 25 fields (AC: 1).
- [x] Task 2: type `AdminEventDrafts` payload/list/get + the 4 write-method `event?` docblocks (AC: 2).
- [x] Task 3: `UploadEventCoverImageCommand`/`ManageEventGalleryCommand` return the record (AC: 3).
- [x] Task 4: confirm controllers + tests need no change (AC: 4, 5).
- [x] Task 5: verify + ship (AC: 5) - static gates + isolated full suite. PR to `develop`.

## Dev Notes

- **One shared read view, embedded in write outcomes.** Unlike `AdminPostCatalog` (35.14, where the write
  methods returned only `{found, errors}`), `AdminEventDrafts` embeds the payload as the `event` of its
  create/update/transition/configurePrivateAccess results. Typing `payload()` to the record propagates the
  record into those `event?` slots automatically; the docblocks change `event?: array{...}` -> `event?:
  AdminEventView`. The `{found, errors}` envelope stays (facade contract).
- **No controller edits.** Every consumer already does `['data' => ...->list()]`, `['data' => $event]` (from
  `get()` / `$result['event']`), or `['data' => $data]` (upload); `json_encode` of the record (public props in
  declaration order = former array key order) is byte-identical. phpstan confirmed no consumer read array keys.
- **25 fields, not 22.** The record mirrors the actual `payload()` body, fixing the long-stale 22-key `@return`
  docblocks by construction.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.14 (`AdminPostView`); epic Stage 2 breakdown (35.15 = Events admin read-model).
- Convert: `src/Events/Application/Service/AdminEventDrafts.php` +
  `src/Events/Application/Command/{UploadEventCoverImageCommand,ManageEventGalleryCommand}.php`.
- New: `src/Events/Application/Query/AdminEventView.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `AdminEventView` (`final readonly`, 25 fields) in `Events/Application/Query/`, mirroring the real `payload()`
  body (fixes the stale 22-key `@return` docblocks).
- `AdminEventDrafts::{payload,list,get}` return the record / `list<record>` / `?record`; the four write methods'
  `event?` docblocks now reference `AdminEventView`, envelopes (`{found, errors}`) unchanged.
- `UploadEventCoverImageCommand::execute` + `ManageEventGalleryCommand::upload` return `?AdminEventView`.
- No controller change: `AdminEventController` + the two upload controllers serialize the view via
  `['data' => ...]`, byte-identical. phpstan confirmed no consumer read the array keys.
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/Events/Application/Query/AdminEventView.php` (new)
- `api/src/Events/Application/Service/AdminEventDrafts.php` (list/get/payload + write `event?` typed)
- `api/src/Events/Application/Command/UploadEventCoverImageCommand.php` (returns `?AdminEventView`)
- `api/src/Events/Application/Command/ManageEventGalleryCommand.php` (upload returns `?AdminEventView`)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). `AdminEventView` read-view record (25 fields); `AdminEventDrafts` list/get/payload + write-outcome `event?` + both event upload commands typed. `composer gates` green. Status: done. |
