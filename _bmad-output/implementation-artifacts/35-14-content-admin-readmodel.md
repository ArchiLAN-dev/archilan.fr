# Story 35.14: Typed read-view record - Content admin post read-model (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want the admin post read view (`AdminPostCatalog::list`/`get`) to be a `final readonly` `AdminPostView` record
instead of an untyped `array<string, mixed>`, so that `UploadPostCoverImageCommand::execute` - which delegates
its return to `AdminPostCatalog::get` - returns a typed record (not an array), with every admin HTTP body
unchanged.

## Context

Epic 35, Stage 2. The 35.13 scope split moved the three admin-payload uploads to their context read-model
stories. `UploadPostCoverImageCommand` (Content) is the first: it returns `$this->adminPostCatalog->get($postId)`,
so its result type is the admin post payload. That payload was the worst-typed read shape in the context -
`AdminPostCatalog::{list,get,payload}` all returned `array<string, mixed>` (loose, not even an `array{...}`
shape). Typing the command's return therefore means typing the read view it shares with `list`/`get`.

**First read-view record.** The codebase's existing `*View` classes (`CommunityProfileView`,
`ParticipantStreamsView`) are read *facades* that return `array{...}` phpstan shapes, not DTO records. This story
introduces the first read-view **record** (`AdminPostView`), the Stage-2-consistent way to type a read payload
shared by a service and a command.

`AdminPostCatalog`'s write methods (`create`/`update`/`publish`/`unpublish`) keep their `{found, errors}` /
`{id?, errors}` outcome arrays: those are the documented facade contract (api/CLAUDE.md AC-A3), not read views,
and are out of the Stage 2 command-return rule (`AdminPostCatalog` is a Service, not a Command).

## Acceptance Criteria

1. **AC1 - Record.** Add `AdminPostView` (`final readonly`, 13 fields in the current `payload()` key order:
   id, slug, title, type, status, excerpt, `list<string> body`, readingTime, `?string coverImageUrl`,
   `?string coverImageKey`, `?string publishedAt`, createdAt, updatedAt), in `Content/Application/Query/`.
2. **AC2 - Catalog read view typed.** `AdminPostCatalog::payload()` returns `AdminPostView`; `list()` returns
   `list<AdminPostView>`; `get()` returns `?AdminPostView`. `array<string,mixed>` docblocks dropped.
3. **AC3 - Command returns the record.** `UploadPostCoverImageCommand::execute` returns `?AdminPostView`;
   `array<string,mixed>|null` docblock dropped, `@throws` kept.
4. **AC4 - Controllers byte-identical.** `AdminPostController` (6 render sites: list/show/create/update/publish/
   unpublish) and `AdminPostCoverImageController` pass the view straight to `['data' => ...]`; the record(s)
   serialize via `json_encode` to the former array(s). No controller change needed; every body byte-identical.
5. **AC5 - Gates.** `composer gates` green. `AdminPostTest` + `AdminPostCoverImageTest` green unchanged.

## Tasks / Subtasks

- [x] Task 1: `AdminPostView` record (AC: 1).
- [x] Task 2: type `AdminPostCatalog::payload/list/get` (AC: 2).
- [x] Task 3: `UploadPostCoverImageCommand::execute` returns the record (AC: 3).
- [x] Task 4: confirm controllers + tests need no change (AC: 4, 5).
- [x] Task 5: verify + ship (AC: 5) - static gates + isolated full suite. PR to `develop`.

## Dev Notes

- **Shared read view, one record.** `AdminPostView` is produced by the catalog service (`list`/`get`) and
  returned by the upload command; a single colocated read-view record serves both. Placed in
  `Application/Query/` next to the `*View` naming convention (a DTO record, not a facade).
- **No controller edits.** Every consumer already does `['data' => $this->adminPostCatalog->list()]` or
  `['data' => $post]` where `$post = ...->get($id)`; `json_encode` of the record (public props in declaration
  order = former array key order) is byte-identical. Only the static types flow through - `?AdminPostView`
  instead of `?array` - which phpstan accepts with no code change (confirmed: no consumer read array keys).
- **Write outcomes untouched.** `create/update/publish/unpublish` stay `{found, errors}` facade contracts
  (AC-A3); they are not read views and `AdminPostCatalog` is a Service, outside the Stage 2 command rule.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.13 records; epic Stage 2 breakdown (35.14 = Content admin read-model).
- Convert: `src/Content/Application/Service/AdminPostCatalog.php` +
  `src/Content/Application/Command/UploadPostCoverImageCommand.php`.
- New: `src/Content/Application/Query/AdminPostView.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `AdminPostView` (`final readonly`, 13 fields) in `Content/Application/Query/`; first read-view record in the
  codebase (existing `*View` are array-returning facades).
- `AdminPostCatalog::{payload,list,get}` return the record / `list<record>` / `?record`;
  `UploadPostCoverImageCommand::execute` returns `?AdminPostView`. `array<string,mixed>` docblocks dropped.
- No controller change: both admin controllers serialize the view via `['data' => ...]`, byte-identical. phpstan
  confirmed no consumer read the array keys.
- Write-outcome facade methods (`create/update/publish/unpublish`) left as documented `{found, errors}` contracts.
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/Content/Application/Query/AdminPostView.php` (new)
- `api/src/Content/Application/Service/AdminPostCatalog.php` (list/get/payload return the record)
- `api/src/Content/Application/Command/UploadPostCoverImageCommand.php` (returns `?AdminPostView`)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). `AdminPostView` read-view record; `AdminPostCatalog` list/get/payload + `UploadPostCoverImageCommand` typed. `composer gates` green. Status: done. |
