# Story 23.11: Admin — Download a Weekly Run's Generated Seed

## Status

review

## Context

Since 23.8/23.10 the weekly multiworld is generated once and its output is persisted to
MinIO (`sessions/weekly-gen-{runId}/output/{file}`), with the key stored on the run
(`generated_output_key`). Admins reviewing a template's runs (page
`/admin/weekly-runs/template/{templateId}`) now have a real, downloadable artifact —
previously deferred because only a hash existed. This story adds a **download link per
run** on that admin page.

## Acceptance Criteria

**AC1:** `GET /api/v1/admin/weekly-runs/{weeklyRunId}/output` (admin only) streams the run's
generated multidata from MinIO as an attachment (`Content-Disposition`, basename of the
key). `404` when the run has no `generated_output_key`; `403` non-admin; `401` unauthenticated.
Serving the full `.archipelago` is acceptable here — admin-only (unlike the member patch
endpoint which forbids it).

**AC2:** `GET /api/v1/admin/weekly-templates/{templateId}/runs` exposes `hasOutput` per run
(`generated_output_key` present). Additive.

**AC3:** On the admin per-template runs page, each run with `hasOutput` shows a
"Télécharger le seed" action that downloads the file (blob → anchor, like `downloadPatch`).

**AC4:** All quality gates pass — API (`phpstan`, `php-cs-fixer`, `phpunit`,
`app:architecture:ddd`) and frontend (`pnpm typecheck`, `pnpm lint`, `pnpm build`).

## Tasks / Subtasks

- [ ] Task 1: API — `AdminWeeklyRunOutputQuery(Interface)` (`findOutputKey(runId): ?string`) +
  `DbalAdminWeeklyRunOutputQuery`; binding.
- [ ] Task 2: API — `AdminWeeklyRunOutputDownloadController` (stream from MinIO, attachment).
- [ ] Task 3: API — add `hasOutput` to `DbalAdminTemplateRunsQuery` DTO.
- [ ] Task 4: API — functional tests (download happy path via `NullMinioStorage`, 404/403/401;
  `hasOutput` in `AdminTemplateRunsTest`).
- [ ] Task 5: Frontend — `hasOutput` on `AdminTemplateRun` + `downloadAdminWeeklyRunOutput`;
  download action per run on the per-template page (via `CurrentRunCard`).
- [ ] Task 6: Quality gates.

## Dev Notes

- Mirror `WeeklyEntryPatchController::downloadFromBridge` for the streamed attachment and
  `ApworldDownloadUrlController` for injecting `MinioStorageInterface` into a controller.
- `NullMinioStorage` (test binding) is an in-memory store — seed it (`upload`) to test the
  happy path.

## File List

### API
- `src/WeeklyRuns/Application/AdminWeeklyRunOutputQuery{,Interface}.php` — new
- `src/WeeklyRuns/Infrastructure/DbalAdminWeeklyRunOutputQuery.php` — new
- `src/WeeklyRuns/Presentation/Admin/AdminWeeklyRunOutputDownloadController.php` — new
- `src/WeeklyRuns/Infrastructure/DbalAdminTemplateRunsQuery.php` — `hasOutput`
- `config/services.yaml` — binding
- `tests/Functional/AdminWeeklyRunOutputDownloadTest.php` — new; `AdminTemplateRunsTest.php` — `hasOutput`

### Frontend
- `src/features/admin/admin-weekly-runs-api.ts` — `hasOutput`, `downloadAdminWeeklyRunOutput`
- `src/features/admin/admin-weekly-run-cards.tsx` — optional download action on `CurrentRunCard`
- `src/features/admin/admin-weekly-run-template-detail.tsx` — wire the action

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-08 | Story created and implemented — admin download of a weekly run's generated seed, now that 23.8/23.10 persist the output to MinIO. |
