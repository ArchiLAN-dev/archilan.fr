# Story 31.11: Fold tutorial step links and images into the markdown description

**Status:** done
**Epic:** 31 - Archipelago install tutorials
**Date:** 2026-07-22

## Story

As an admin authoring a tutorial,
I want a step to be a title plus one markdown description, instead of a description *and* a link list *and* an image field,
so that I write in one place and the structured leftovers stop duplicating what markdown already does.

## Context

Since story 10.10 a step description is markdown, and since 10.11 a lone video URL embeds. That makes two of the step's side fields redundant:

- `links: [{label, url}]` - `[label](url)` says the same thing inline;
- `imageKey` / `imageUrl` - `![alt](url)` renders the same image.

A step keeps `type`, `title`, `description` and `videoUrl`. `title` still drives the per-step progress checkbox (story 31.5) and `videoUrl` still gets the hardened embed, so neither is dropped.

### Decisions

- **Existing content is migrated, not discarded.** Links and images already stored are folded into the description as markdown by a `postUp()` data migration, covering both `game.install_steps` and `game_tutorial_contribution.steps`. Dropping the columns' content without this would silently lose every seeded catalogue link.
- **Image upload survives, the field does not.** Uploads keep going to MinIO but are now served under a stable URL and inserted into the description as `![](url)`, so an author can place **several** images wherever they want - which the single field could never do.
- **The seeder writes markdown.** `GameTutorialSeeder` builds its links dynamically from the catalogue (apworld source URL + sheet links); it now renders them as a markdown list inside the description.
- **`videoUrl` stays a field.** It is not redundant: the hardened `youtube-nocookie` embed is better than what a markdown link would give, and story 10.11 deliberately kept a single video path.

### Blocker found during implementation - and how it was resolved

The first attempt hit a wall, recorded here because it shaped the design.

An uploaded step image is **not** a URL in the data - it is `imageKey`, a **private MinIO object**, and `InstallStepsReader` **presigns it to a short-lived URL at read time**. The reader's own docblock states the intent: the key is round-tripped *"without persisting the expiring URL"*.

Folding it into markdown as `![](url)` would therefore bake an **expiring** URL into the description: the image would render for the length of the presign TTL and then break permanently. And dropping `imageKey` without folding would delete every uploaded tutorial image outright.

Three ways out, none of them free:

1. **Keep the image field** (what this story now does). No loss, one field survives.
2. **Move tutorial images to the public media bucket** so they get a stable URL, then fold. Blocked in turn: `MINIO_PUBLIC_MEDIA_BASE_URL` is unset, so `PublicMediaUrlResolver` falls back to presigning as well - this only becomes possible after the Epic 34 media-public ops handoff.
3. **Drop uploads entirely**, authors paste external image URLs. Loses the feature and pushes hotlinking of third-party images.

**Resolved by a fourth option**: a stable serving route. `TutorialImageServeController` exposes
`GET /api/v1/tutorial-images/{filename}` streaming the object from MinIO under a URL that never
expires, so an uploaded image can safely live inside markdown. The upload endpoint now returns that
URL instead of a presigned one. The route is public (these images render on public game pages) and
safe: the filename is validated against the exact shape the uploader generates, so it can only ever
reach `tutorials/…` objects, and the 128 random bits make a key unguessable.

The author also confirmed there is **no existing tutorial image data**, so no image backfill was
needed - the migration folds links only.

### Known consequence to accept

A community contributor can now write `![](https://any-host/img.png)` in a step description, where previously an image could only be an uploaded MinIO object. The public render only happens after an admin approves the contribution, so this is moderation-gated rather than open - but it is a real change in what an approved contribution can carry.

## Acceptance Criteria

1. A step is `{type, title, description, videoUrl}`; `links`, `imageKey` and `imageUrl` are gone from the normalizer, the reader, the API shapes and the frontend types.
2. A data migration folds existing links into the description as a markdown list, and an existing image as `![](url)`, for both games and contributions - no stored link or image is lost.
3. The seeder emits its catalogue links as markdown inside the description.
4. The tutorial editor no longer shows a link list or an image field; the image upload button inserts `![](url)` into the description at the caret.
5. The public tutorial view renders links and images from the markdown, and still renders `videoUrl` as an embed and the progress checkboxes.
6. Gates green both sides.

## Tasks / Subtasks

- [x] **Task 1 - Migration** (AC 2). `postUp()` over both JSON columns, folding links then image into the description. Idempotent-safe and non-destructive.
- [x] **Task 2 - API model** (AC 1, 3). Strip the fields from `InstallStepsNormalizer`, `InstallStepsReader` and the shapes; seeder writes markdown links.
- [x] **Task 3 - Frontend** (AC 1, 4, 5). Types, editor (drop link/image UI, upload inserts markdown), view (drop link/image render).
- [x] **Task 4 - Tests + gates** (AC 6). Normalizer drops the fields, seeder output contains markdown links, migration folding covered.

## Dev Notes

- The migration is the risky part: it rewrites JSON columns. It must read, transform in PHP and write back per row rather than attempt SQL-side JSON surgery, and must leave a step untouched when it has neither links nor image.
- `InstallStepsReader` and `InstallStepsNormalizer` both carry the shape in docblocks - both need updating, plus `GameStep` on the frontend.

### Project Structure Notes

- `api/migrations/` (new data migration)
- `api/src/GameSelection/Application/Support/InstallStepsNormalizer.php`, `InstallStepsReader.php`, `GameTutorialSeeder.php`
- `frontend/src/features/games/install-steps-editor.tsx`, `install-steps-view.tsx`, `public-games-api.ts`

### References

- [Source: _bmad-output/implementation-artifacts/10-10-markdown-authoring-and-rendering.md]
- [Source: _bmad-output/implementation-artifacts/10-11-markdown-video-embeds.md]
- [Source: _bmad-output/implementation-artifacts/31-10-tutorial-step-image-upload.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

### File List
