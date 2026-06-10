# Story 23.14: Admin - Generate a Run for a Single Template, On Demand

Status: done

## Story

As an ArchiLAN admin,
I want a "Générer la run de la semaine" button on a template's dedicated page,
so that I can trigger the current week's run for that specific template on demand -
without waiting for the Monday cron and without generating every other template too.

## Context

Today the only manual trigger is `POST /api/v1/admin/weekly-runs/generate`
(`AdminGenerateWeeklyRunsController` → `GenerateWeeklyRunsMessage`), which regenerates
**all active templates** for the current ISO week (the same path the Monday-00:00 scheduler
uses). It is idempotent: a template that already has a run for the current week is skipped.

On the per-template page (`/admin/weekly-runs/template/{templateId}`,
`AdminWeeklyRunTemplateDetail`) there is no way to (re)trigger generation for just that
template - the admin has to use the broad "Générer maintenant" button on the game page, which
touches every template of the game. This story adds a **per-template** on-demand trigger.

Decision (product): the button targets the **current ISO week** only; if a run already exists
for that week it is **disabled** (label "Run déjà générée cette semaine"). No force-regenerate.

## Acceptance Criteria

1. The template detail page shows a "Générer la run de la semaine" button (admin-only page).
2. Clicking it generates the current ISO week's run for **that template only** - not other
   templates - through the existing async pipeline: the run row is created not-launchable
   (`generated_output_key = null`), generation is dispatched to the orchestrateur, and the
   `session.generated` webhook later marks it launchable (`MarkWeeklyRunGenerated`), exactly as
   the Monday cron does.
3. When a run already exists for the current ISO week for this template, the button is
   **disabled** with the label "Run déjà générée cette semaine". The server also rejects the
   call with **409 `run_already_exists`** (defense in depth - the disabled state is not the only
   guard).
4. The endpoint `POST /api/v1/admin/weekly-templates/{templateId}/generate` is admin-only and
   validates: **404 `template_not_found`** (unknown id), **422 `template_incomplete`** (template
   has no YAML or its game has no APWorld), **409 `run_already_exists`**, **204** on success.
5. On success the page's runs list refreshes; the new run appears (as "génération en cours"
   until the webhook lands).
6. All quality gates green - API (phpstan, php-cs-fixer, phpunit, `app:architecture:ddd`) and
   frontend (typecheck, lint, build).

## Tasks / Subtasks

- [x] Task 1 - Expose whether the current ISO week already has a run (AC: 3)
  - [x] `AdminWeeklyTemplateDetailQuery::execute`: inject `WeeklyRunRepositoryInterface` +
    `ClockInterface`; compute current `weekYear`/`weekNumber` (UTC, `o`/`W`, same as the handler)
    and add `currentWeekHasRun` (bool) to the returned array via `existsByTemplateAndWeek`.
- [x] Task 2 - Single-template generate command (AC: 2, 4)
  - [x] New `App\WeeklyRuns\Application\GenerateWeeklyRunForTemplate` (final, `generate(string
    $templateId): void`). Mirrors the per-template body of `GenerateWeeklyRunsMessageHandler` but
    with **throw**-based validation instead of skip: `template_not_found`, `run_already_exists`,
    `template_incomplete` (`\DomainException` with the error code as message). On success: create
    `WeeklyRun` (active, seed = `random_int`), `runs->save`, then `generator->generate(...)`.
  - [x] Inject `WeeklyTemplateRepositoryInterface`, `WeeklyRunRepositoryInterface`,
    `GameRepositoryInterface`, `WeeklyRunGeneratorInterface`, `ClockInterface`, `LoggerInterface`.
- [x] Task 3 - Controller (AC: 2, 4)
  - [x] New `AdminGenerateWeeklyRunForTemplateController`:
    `POST /api/v1/admin/weekly-templates/{templateId}/generate`, `requireAdmin`, call the command,
    map `\DomainException` code → HTTP (`template_not_found`→404, `run_already_exists`→409,
    `template_incomplete`→422), other `\Throwable`→500 `generation_failed`, else 204.
- [x] Task 4 - Frontend API (AC: 2, 3)
  - [x] `admin-weekly-runs-api.ts`: add `currentWeekHasRun: boolean` to `AdminWeeklyTemplate`
    (map it in `fetchAdminWeeklyTemplate`, default `false`); add `generateWeeklyRunForTemplate(
    templateId)` returning `{ ok: true } | { ok: false; error: string }`.
- [x] Task 5 - Frontend button + mutation (AC: 1, 3, 5)
  - [x] `admin-weekly-run-template-detail.tsx`: header button (mirror the game-page "Générer"
    button style + `RefreshCw` spinner) wired to a `useMutation`; on success invalidate
    `["admin-weekly-template-runs", templateId]`. Disabled + relabelled when
    `template.currentWeekHasRun` or while pending. Surface the error code inline on failure.
- [x] Task 6 - Tests + gates (AC: 6)
  - [x] Unit test `GenerateWeeklyRunForTemplate` (mock interfaces): success dispatches generate;
    `run_already_exists` / `template_not_found` / `template_incomplete` throw. Run all four API
    gates + the three frontend gates.

## Dev Notes

### Touch points (verified)

- Bulk path: `AdminGenerateWeeklyRunsController` (`/admin/weekly-runs/generate`) →
  `GenerateWeeklyRunsMessage` → `GenerateWeeklyRunsMessageHandler` (loops active templates,
  idempotent skip via `existsByTemplateAndWeek`, creates run + `generator->generate`).
- Run repo already exposes `existsByTemplateAndWeek(templateId, weekYear, weekNumber): bool`,
  `save`, `flush` - reuse, no new repository method, **no migration**.
- ISO week computation must use `ClockInterface` (cross-cutting rule: no `date()`/`time()` in
  Application). Use `->setTimezone('UTC')`, `format('o')` = ISO year, `format('W')` = ISO week,
  identical to `GenerateWeeklyRunsMessageHandler`.
- Controller error convention: command throws `\DomainException('error_code')`; controller
  catches and maps to `JsonResponse(['error' => $code], status)` (see `AdminCreateWeeklyTemplate`
  + its controller).
- The detail controller (`AdminWeeklyTemplateDetailController`) serializes the whole query array,
  so `currentWeekHasRun` flows through with no controller change.
- Frontend: mirror the existing "Générer maintenant" button in `admin-weekly-run-game-detail.tsx`
  (state + `RefreshCw` spinner). The runs query key is `["admin-weekly-template-runs", templateId]`.

### Deliberate non-goals

- No force-regenerate / overwrite of an already-generated run (chosen product option).
- No gating on `isActive` - an admin may generate for the specific template they are viewing.
- Slight duplication of the per-template create+dispatch logic between the bulk handler and the
  new command is accepted (different control flow: bulk *skips* on conflict, manual *throws*).
  A future refactor may extract a shared collaborator.

### Testing standards

- Unit-test the command with mocked interfaces (`TestCase`, no kernel) per AC-T1/AC-T3.
- Frontend has no test runner - gates are typecheck + lint + build.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, bmad-dev-story workflow).

### Debug Log References

None.

### Completion Notes List

- Backend: extended `AdminWeeklyTemplateDetailQuery` with `currentWeekHasRun` (computed via
  `WeeklyRunRepositoryInterface::existsByTemplateAndWeek` + `ClockInterface`, UTC ISO week). New
  Application command `GenerateWeeklyRunForTemplate` (throw-based validation) and controller
  `AdminGenerateWeeklyRunForTemplateController` (`POST /admin/weekly-templates/{templateId}/generate`,
  maps `\DomainException` codes to 404/409/422, else 500/204). No migration, no new repo method.
- Frontend: `currentWeekHasRun?` added to `AdminWeeklyTemplate` (optional - the create/update
  endpoints share the detail type/guard and do not return it); new `generateWeeklyRunForTemplate`
  with `extractErrorCode` parsing both the flat and the `ApiAccessGuard` nested error shapes; a
  header button on the template detail page wired to a `useMutation` that invalidates the runs
  query on success, disabled+relabelled when `currentWeekHasRun === true` or pending, with inline
  error feedback (no toast system in the app).
- Chosen product behaviour: current ISO week only; disabled when a run already exists. No
  force-regenerate; no `isActive` gating.
- Gates green: phpstan (0), php-cs-fixer (0), `app:architecture:ddd` (0), phpunit (934 tests,
  incl. new `GenerateWeeklyRunForTemplateTest` - 5 tests / 23 assertions), frontend typecheck +
  lint + build.

### File List

- `api/src/WeeklyRuns/Application/AdminWeeklyTemplateDetailQuery.php` (modified - `currentWeekHasRun`)
- `api/src/WeeklyRuns/Application/GenerateWeeklyRunForTemplate.php` (new)
- `api/src/WeeklyRuns/Presentation/Admin/AdminGenerateWeeklyRunForTemplateController.php` (new)
- `api/tests/Unit/WeeklyRuns/GenerateWeeklyRunForTemplateTest.php` (new)
- `frontend/src/features/admin/admin-weekly-runs-api.ts` (modified - type + fn + error parser)
- `frontend/src/features/admin/admin-weekly-run-template-detail.tsx` (modified - button + mutation)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-08 | Story created - per-template on-demand "generate the week's run" button (epic 23). |
| 2026-06-08 | Implemented (backend command+controller+query field, frontend button+API, unit tests). All gates green. Status → review. |
| 2026-06-08 | Merged to `develop` (PR #46) + verified live in the browser (204 → run "En cours" Semaine 2026-S24, button disabled, server 409 guard). Status → done. |
