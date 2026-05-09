# Story 1.6: Admin Content Publishing for News and Recaps

Status: review

## Story

As an admin,
I want to create, edit, publish, and unpublish news posts and recaps,
so that the public hub can stay active between events.

## Acceptance Criteria

1. Given an admin is authenticated, when they use the content backoffice, then they can create a draft post with title, slug, excerpt, body, type, and optional VOD/event association.
2. They can edit existing drafts or published posts.
3. They can publish and unpublish content.
4. Public pages only show published content.
5. Non-admin users cannot access content management endpoints or UI.

## Tasks / Subtasks

- [x] Domain and infrastructure contracts (done in previous phase)
  - [x] `Post` entity with `draft()`, `publish()`, `unpublish()` - complete.
  - [x] `PublicPostCatalog` restricted to published content - complete.
  - [x] `content_posts` DB table exists.

- [x] API - Admin post endpoints (AC: 1, 2, 3, 5)
  - [x] Create `api/src/Content/Application/AdminPostCatalog.php`:
    - `list(): array` - all posts (draft + published), ordered by `updatedAt DESC`
    - `get(string $id): ?array` - single post by id
    - `create(array $input, \DateTimeImmutable $now): string` - validates type, creates draft, flushes, returns id
    - `update(string $id, array $input, \DateTimeImmutable $now): bool` - patches editable fields, flushes
    - `publish(string $id, \DateTimeImmutable $now): bool` - calls `Post::publish()`, flushes
    - `unpublish(string $id, \DateTimeImmutable $now): bool` - calls `Post::unpublish()`, flushes
  - [x] Create `api/src/Content/Presentation/AdminPostController.php` - all routes require `requireAdmin()`:
    - `GET /api/v1/admin/posts` → `adminList()`
    - `POST /api/v1/admin/posts` → `adminCreate(Request $request)`
    - `GET /api/v1/admin/posts/{id}` → `adminShow(string $id)`
    - `PATCH /api/v1/admin/posts/{id}` → `adminUpdate(Request $request, string $id)`
    - `POST /api/v1/admin/posts/{id}/publish` → `adminPublish(Request $request, string $id)`
    - `POST /api/v1/admin/posts/{id}/unpublish` → `adminUnpublish(Request $request, string $id)`
  - [x] Validate `type` against `Post::TYPE_*` constants; return 422 on invalid type.
  - [x] Return 404 via `errorResponse()` when post not found.

- [x] API - Tests (AC: 1, 2, 3, 4, 5)
  - [x] Create `api/tests/Functional/AdminPostTest.php`:
    - Admin can list all posts (draft + published)
    - Admin can create a draft (slug must be unique, valid type required)
    - Admin can update title, body, excerpt, type, vodUrl, relatedEventSlug
    - Admin can publish a draft → status becomes `published`, `publishedAt` set
    - Admin can unpublish a published post → status becomes `draft`
    - Non-admin (lambda user) receives 403 on all admin endpoints
    - Unauthenticated request receives 401

- [x] Frontend - Remove placeholder and build content list (AC: 1, 2, 3, 5)
  - [x] Remove `notFound()` call from `frontend/src/app/(admin)/admin/contenu/page.tsx`
  - [x] Implement `AdminContentDashboard` component in `src/features/admin/admin-content-dashboard.tsx`:
    - Fetches `GET /api/v1/admin/posts` on mount (server component or client with SWR pattern matching other admin pages)
    - Renders a table: slug, title, type, status, updatedAt, actions (Edit / Publish / Unpublish)
    - "Nouveau post" button opens create form or navigates to create route
  - [x] Delete `src/features/content/content-admin.ts` (pure helper stubs - replaced by real API calls)

- [x] Frontend - Create / Edit form (AC: 1, 2)
  - [x] Add `frontend/src/app/(admin)/admin/contenu/nouveau/page.tsx` - create form
  - [x] Add `frontend/src/app/(admin)/admin/contenu/[postId]/page.tsx` - edit form
  - [x] Form fields: slug (create only), title, type (select: news/recap/announcement), excerpt (textarea), body (textarea, one paragraph per line), readingTime, relatedEventSlug (optional), vodUrl (optional)
  - [x] On submit: `POST /api/v1/admin/posts` (create) or `PATCH /api/v1/admin/posts/{id}` (update)
  - [x] Redirect to `/admin/contenu` on success

- [x] Frontend - Publish / Unpublish actions (AC: 3)
  - [x] Inline publish/unpublish buttons in `AdminContentDashboard` table row
  - [x] Call `POST /api/v1/admin/posts/{id}/publish` or `…/unpublish` with admin credentials
  - [x] Optimistic update or refetch after action

- [x] Validate (AC: 1–5)
  - [x] `composer test -- tests/Functional/AdminPostTest.php` - 15/15 OK
  - [x] `php vendor/bin/phpstan analyse src/Content/ tests/Functional/AdminPostTest.php` - no errors
  - [x] `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Content/ tests/Functional/AdminPostTest.php` - clean after fix
  - [x] `pnpm lint -- src/app/(admin)/admin/contenu src/features/admin/admin-content-dashboard.tsx src/features/admin/admin-post-form.tsx` - clean
  - [x] `pnpm typecheck` - clean

## Dev Notes

Epic 2 (Identity & RBAC) is complete. `ApiAccessGuard::requireAdmin()` is available and in use across all other admin controllers (see `AdminEventController`, `AdminGameLibraryController`). Use the same pattern.

The `Post` domain entity already has `draft()`, `publish()`, `unpublish()` factory/methods. Do not re-implement state transitions - call them.

Frontend admin pages use the `(admin)` route group with `admin-shell.tsx`. Follow the same pattern as `admin-event-dashboard.tsx` and `admin-user-directory.tsx` for API calls and layout.

All API calls in the frontend must use `src/lib/env.ts` (not `process.env` directly).

### References

- Admin RBAC guard: `api/src/Shared/Infrastructure/Http/ApiAccessGuard.php`
- Controller pattern: `api/src/Events/Presentation/AdminEventController.php`
- Domain entity: `api/src/Content/Domain/Post.php`
- Public catalog (read model): `api/src/Content/Application/PublicPostCatalog.php`
- Existing public controller: `api/src/Content/Presentation/PostController.php`
- Frontend admin shell: `frontend/src/components/admin-shell.tsx`
- Admin page example: `frontend/src/features/admin/admin-user-directory.tsx`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Previous phase (blocked): guarded `/admin/contenu` with `notFound()`, added domain contracts.
- Unblocked 2026-05-03: Epic 2 (Identity & RBAC) complete, `requireAdmin()` available.

### Completion Notes List

- Added `Post::update()` method to domain entity for partial field updates.
- `AdminPostCatalog` implements full CRUD + lifecycle (create, list, get, update, publish, unpublish).
- `AdminPostController` exposes 6 admin endpoints, all protected by `requireAdmin()`. Slug is write-once (create only).
- 15 functional tests cover: auth/authz, CRUD, publish/unpublish lifecycle, public filtering, error cases (404, 422, duplicate slug).
- Frontend: `AdminContentDashboard` replaces the `notFound()` placeholder; publish/unpublish via `refreshKey` pattern (no optimistic mutation, clean fetch).
- `AdminPostForm` shared between create and edit routes; `useEffect` restructured to async inner function to satisfy `react-hooks/set-state-in-effect` lint rule.
- `content-admin.ts` stub deleted; no other files imported it.

### File List

- `api/src/Content/Domain/Post.php` (modified - added `update()` method)
- `api/src/Content/Application/AdminPostCatalog.php` (new)
- `api/src/Content/Presentation/AdminPostController.php` (new)
- `api/tests/Functional/AdminPostTest.php` (new)
- `frontend/src/app/(admin)/admin/contenu/page.tsx` (modified - removed `notFound()`)
- `frontend/src/app/(admin)/admin/contenu/nouveau/page.tsx` (new)
- `frontend/src/app/(admin)/admin/contenu/[postId]/page.tsx` (new)
- `frontend/src/features/admin/admin-content-dashboard.tsx` (new)
- `frontend/src/features/admin/admin-post-form.tsx` (new)
- `frontend/src/features/content/content-admin.ts` (deleted)
