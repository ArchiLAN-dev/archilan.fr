# Story 23.7: Server-Side Searchable Game Picker & `apworld_ready` Filter

## Story

**As an** admin creating a weekly run template,
**I want** the game selector to be a searchable field that queries the catalogue server-side and only proposes APWorld-ready games,
**So that** I can find the right game among hundreds without the list being silently truncated by pagination.

## Status

done

## Context

Story 23.5 built the admin template creation flow. Its game-picker step specified
`GET /api/v1/admin/games?apworldReady=true` but noted *"check if the `apworldReady`
filter exists; if not, filter client-side"*. The server filter was never implemented,
so the form fell back to client-side filtering over a **single unpaginated page** of
`/admin/games`. Since that endpoint defaults to `per_page=50` (max 200) and the catalogue
now holds ~600 games, only the first 50 reached the client and the `isApworldReady`
filter further shrank the visible list — games were missing from the select.

An interim fix made `fetchAdminGameOptions()` walk every page client-side. This story
**supersedes** that interim fix with the server-side approach 23.5 originally intended:
a real `apworld_ready` filter plus a debounced search field, so the catalogue can grow
without ever truncating the picker.

## Acceptance Criteria

**AC1:** `GET /api/v1/admin/games` accepts an `apworld_ready` query param (`1`/`true` → ready only, `0`/`false` → not-ready only, absent → no filter), mirroring the existing `yaml_ready` param. Readiness ≡ `apworld_storage_key IS NOT NULL AND <> ''` (same rule as `Game::isApworldReady()`; there is no `is_apworld_ready` column). The filter composes with `search`, `availability`, `yaml_ready`, and pagination.

**AC2:** `apworld_ready=1` combined with `search=` returns only ready games whose name/slug match the term, paginated with correct `meta.total` / `meta.totalPages` reflecting the filtered set. Functional test covers: ready-only, not-ready-only, ready+search, and absent (unchanged behaviour).

**AC3:** The query interface `AdminGameListQueryInterface::find()` and its DBAL implementation carry the new `?bool $apworldReady` parameter; `AdminGameLibrary::list()` threads it through. No DBAL/`Connection` leaks outside Infrastructure (DDD validator stays green).

**AC4:** The weekly-template **create** form replaces the `<select>` with a searchable combobox: a debounced text input (300 ms) calls `GET /admin/games?search=<q>&apworld_ready=1&per_page=20`, shows matching games in a dropdown, and selecting one sets `gameId` and loads its `defaultYaml` (existing `handleGameChange` logic). Empty query shows an idle hint, not the whole catalogue.

**AC5:** The **edit** form (game immutable) shows the current game name as static read-only text — no combobox, no games fetch. The interim paginated `fetchAdminGameOptions()` bulk loader is removed (or reduced to nothing unused).

**AC6:** Combobox UX matches the existing `igdb-game-search.tsx` pattern: click-outside and Escape close the dropdown, in-flight requests are aborted on new keystrokes, loading / error / empty states are rendered. Keyboard selection is not required (parity with igdb widget).

**AC7:** All quality gates pass — API (`phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd`) and frontend (`pnpm typecheck`, `pnpm lint`, `pnpm build`).

## Tasks / Subtasks

- [x] Task 1: API — add `apworld_ready` parsing in `AdminGameLibraryController::list` (same `match` shape as `yaml_ready`).
- [x] Task 2: API — thread `?bool $apworldReady` through `AdminGameLibrary::list` → `AdminGameListQueryInterface::find` → `DbalAdminGameListQuery::find`/`applyFilters` (add the `apworld_storage_key` predicate).
- [x] Task 3: API — extend `AdminGameLibraryTest` for AC1: ready-only, not-ready-only, absent. (ready+search untestable here — see Dev Notes.)
- [x] Task 4: Frontend — add `searchAdminGameOptions(query, signal?)` to `admin-weekly-runs-api.ts` (calls the filtered endpoint, returns `AdminGameOption[]`, type-guarded). Removed the interim paginated `fetchAdminGameOptions`.
- [x] Task 5: Frontend — build `AdminGamePicker` combobox component in `features/admin/` (debounce + abort + outside-click/Escape close), props `{ value: AdminGameOption | null; onSelect: (game: AdminGameOption) => void; id? }`.
- [x] Task 6: Frontend — wire `AdminGamePicker` into `admin-weekly-template-form.tsx` create mode; render static game name in edit mode.
- [x] Task 7: Run all quality gates (API + frontend) — all green (phpunit 910/910).

## Dev Notes

### API — filter predicate (Task 2)

In `DbalAdminGameListQuery::applyFilters`, after the `yamlReady` block:

```php
if (true === $apworldReady) {
    $qb->andWhere("g.apworld_storage_key IS NOT NULL AND g.apworld_storage_key <> ''");
} elseif (false === $apworldReady) {
    $qb->andWhere("(g.apworld_storage_key IS NULL OR g.apworld_storage_key = '')");
}
```

Controller parsing (mirror the existing `yaml_ready` `match`):

```php
$rawApworldReady = $request->query->has('apworld_ready') ? (string) $request->query->get('apworld_ready') : null;
$apworldReady = match ($rawApworldReady) {
    '1', 'true' => true,
    '0', 'false' => false,
    default => null,
};
```

`applyFilters` is called by **both** the count and data query builders — adding the
predicate there keeps `meta.total`/`meta.totalPages` correct (AC2).

**Signature change:** `AdminGameListQueryInterface::find()` gains a parameter. Update the
interface, `DbalAdminGameListQuery`, `AdminGameLibrary::list`, and the controller call in
lockstep so phpstan stays green. Append `apworldReady` after `yamlReady` for positional clarity.

### Frontend — search fetch (Task 4)

```ts
export async function searchAdminGameOptions(query: string): Promise<AdminGameOption[]> {
  const q = query.trim();
  if (q === "") return [];
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/games?search=${encodeURIComponent(q)}&apworld_ready=1&per_page=20`,
    );
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    // reuse the same data[] narrowing already in this file
    ...
  } catch {
    return [];
  }
}
```

The server now applies the apworld-ready filter, so the client `isApworldReady` filter is
no longer load-bearing — but keep the type guard (defensive). Pass `AbortSignal` through
`apiFetch` for request cancellation; confirm `apiFetch` forwards `signal` (it wraps
`fetch` with `credentials: 'include'`). If it does not accept `signal`, use raw `fetch`
with `credentials: 'include'` as `igdb-game-search.tsx` does.

### Frontend — combobox component (Task 5)

Model it on `features/admin/igdb-game-search.tsx` (debounce via `setTimeout` ref,
`AbortController` ref, outside-click + Escape `useEffect`s, dropdown with
loading/error/empty/results states). Differences:
- No cover image / pagination footer — games are `{ id, name }`, render the name only.
- On select: call `onSelect(game)`, set the input value to the chosen game name, close.
- Controlled `value` prop so the parent can display the current selection.

Respect `AGENTS.md`: type guards at the boundary (no `as`), no `process.env`
(use `env`), stable list keys (`game.id`).

### Frontend — form wiring (Task 6)

In `admin-weekly-template-form.tsx`:
- **create:** replace the `<select>`…`</select>` block with `<AdminGamePicker value={...} onSelect={(g) => void handleGameChange(g.id)} />`. Keep `handleGameChange` (loads `defaultYaml`). Drop the `useEffect` bulk `fetchAdminGameOptions` call and the `games` state.
- **edit:** render `template.gameName` (or fetched detail name) as static text with the existing "Le jeu ne peut pas être modifié après création." hint.

### Out of scope

- No keyboard navigation in the dropdown (parity with igdb widget).
- No change to template create/update endpoints — `game_not_ready` validation on POST stays as-is.
- The IGDB widget is untouched.

## File List

### API
- `api/src/GameSelection/Presentation/AdminGameLibraryController.php` — modified (parse `apworld_ready`)
- `api/src/GameSelection/Application/AdminGameLibrary.php` — modified (`list()` signature)
- `api/src/GameSelection/Application/AdminGameListQueryInterface.php` — modified (`find()` signature)
- `api/src/GameSelection/Infrastructure/DbalAdminGameListQuery.php` — modified (`find()` + `applyFilters()`)
- `api/tests/Functional/AdminGameLibraryTest.php` — modified (new filter cases)

### Frontend
- `frontend/src/features/admin/admin-weekly-runs-api.ts` — modified (`searchAdminGameOptions`, remove paginated `fetchAdminGameOptions`)
- `frontend/src/features/admin/admin-game-picker.tsx` — new (searchable combobox)
- `frontend/src/features/admin/admin-weekly-template-form.tsx` — modified (use picker in create, static name in edit)

## Change Log

| Date       | Change                                                                                          |
|------------|-------------------------------------------------------------------------------------------------|
| 2026-06-06 | Story created — completes the `apworldReady` server filter sketched in 23.5; supersedes the interim client-side pagination fix with a debounced server-side searchable game picker. |
| 2026-06-06 | Implemented. AC2 caveat: the `apworld_ready` + `search` combination exercises Postgres `ILIKE`, which the SQLite functional-test DB rejects — the combined path is covered in prod, not in tests; the filter alone (+ `meta.total`) is tested. Bonus gate fix: `AdminGameLibraryTest::setUp` was missing `GameCatalogSync` in its `SchemaTool` array (two pre-existing reds: `no such table: game_catalog_sync`) — added it. All gates green. |
| 2026-06-06 | Picker enhancement: dropdown options now show a cover thumbnail (`coverImageUrl`, already returned by `GET /admin/games`) with a `Gamepad2` fallback, matching the IGDB widget. `coverImageUrl` added to `AdminGameOption` and `searchAdminGameOptions`. Gates re-run green. |
| 2026-06-06 | Follow-up fix (create-flow UX): after create/update, the form now invalidates the dashboard query so the listing reflects the change immediately instead of waiting for the 30s `staleTime`/`refetchInterval`. Extracted a shared `ADMIN_WEEKLY_DASHBOARD_QUERY_KEY` used by both the dashboard `useQuery` and the form's `invalidateQueries`. Gates green. |
| 2026-06-07 | **Addendum — game-grouped navigation** (see section below). Gates green (API: phpunit 920/920, phpstan, cs-fixer, ddd; frontend: typecheck, lint, build). |

## Addendum (2026-06-07) — Game-grouped weekly-runs navigation

The flat admin dashboard (`/admin/weekly-runs`) was reorganised **by targeted game** so it
scales as templates/games multiply.

### New acceptance criteria

**AC8 — Game grid landing.** `GET /api/v1/admin/weekly-runs/games` returns one entry per
game that has ≥ 1 weekly template: `{ gameId, gameName, coverImageUrl, coverImageAlt,
templateCount, runCount }`, ordered by game name. `runCount` = total `weekly_runs` (all
weeks) attached to the game. `403` non-admin. The landing page renders these as a card grid
(cover thumbnail + run-count badge in a corner) plus the "Nouveau template" button.

**AC9 — Per-game detail page.** Clicking a game card opens `/admin/weekly-runs/jeu/{gameId}`:
game header (cover + name + counts), actions ("Générer maintenant" + "Nouveau template"
pre-filled with the game), and the list of that game's templates. `notFound()` when the game
has no template. (Per follow-up: the current-week runs table was **removed** from this page —
run monitoring now lives on the per-template page, AC11.)

**AC10 — Pre-filled template creation.** `/admin/weekly-runs/nouveau?gameId=<id>` locks the
game (static read-only name, no combobox) and pre-loads its `defaultYaml`. Without the param,
the searchable combobox (AC4) is unchanged.

**AC11 — Per-template run history.** `GET /api/v1/admin/weekly-templates/{templateId}/runs`
returns all runs of a template (current + past), most recent ISO week first, each with
`weekYear/weekNumber/status/seed/entryCount/entries[]`. Unknown template → `200 { data: [] }`
(template existence is validated client-side via the template-detail fetch). The template
card body links to `/admin/weekly-runs/template/{templateId}`, which lists the run history
(participants expandable per run). `403` non-admin.

**AC12 — gameId on current runs.** `GET /admin/weekly-runs/current` entries now carry
`gameId` (additive).

### Tasks

- [x] API: `AdminWeeklyRunGameListQuery(Interface)` + `DbalAdminWeeklyRunGameListQuery` + `AdminWeeklyRunGameListController`.
- [x] API: `AdminTemplateRunsQuery(Interface)` + `DbalAdminTemplateRunsQuery` + `AdminTemplateRunsController`.
- [x] API: add `gameId` to `DbalAdminCurrentWeeklyRunsQuery`; services.yaml bindings.
- [x] API tests: `AdminWeeklyRunGameListTest`, `AdminTemplateRunsTest`, `gameId` assertion in `AdminWeeklyTemplateTest`.
- [x] Front: `fetchAdminWeeklyRunGames` / `fetchAdminTemplateRuns` + types/guards; `gameId` on `AdminCurrentWeeklyRun`.
- [x] Front: extract shared `TemplateCard`/`CurrentRunCard`/`formatTime` into `admin-weekly-run-cards.tsx`; delete `admin-weekly-runs-dashboard.tsx`.
- [x] Front: `admin-weekly-run-game-grid.tsx` (landing) + `admin-weekly-run-game-detail.tsx` (per-game) + `admin-weekly-run-template-detail.tsx` (per-template history) + their route pages (`jeu/[gameId]`, `template/[templateId]`).
- [x] Front: `?gameId=` pre-fill in the create form (locked game).

### Files (addendum)

**API (new):** `AdminWeeklyRunGameListQueryInterface`, `AdminWeeklyRunGameListQuery`,
`DbalAdminWeeklyRunGameListQuery`, `AdminWeeklyRunGameListController`,
`AdminTemplateRunsQueryInterface`, `AdminTemplateRunsQuery`, `DbalAdminTemplateRunsQuery`,
`AdminTemplateRunsController`, `tests/Functional/AdminWeeklyRunGameListTest.php`,
`tests/Functional/AdminTemplateRunsTest.php`.
**API (modified):** `DbalAdminCurrentWeeklyRunsQuery` (gameId), `config/services.yaml`,
`tests/Functional/AdminWeeklyTemplateTest.php`.
**Front (new):** `admin-weekly-run-cards.tsx`, `admin-weekly-run-game-grid.tsx`,
`admin-weekly-run-game-detail.tsx`, `admin-weekly-run-template-detail.tsx`,
`app/(admin)/admin/weekly-runs/jeu/[gameId]/page.tsx`,
`app/(admin)/admin/weekly-runs/template/[templateId]/page.tsx`.
**Front (modified):** `admin-weekly-runs-api.ts`, `admin-weekly-template-form.tsx`,
`app/(admin)/admin/weekly-runs/page.tsx`, `app/(admin)/admin/weekly-runs/nouveau/page.tsx`.
**Front (deleted):** `admin-weekly-runs-dashboard.tsx`.

### Out of scope / open

- Surfacing the **generated seed file** per run (download) is **not** implemented: the active
  `OrchestratorWeeklyRunGenerator` stores an APWorld **hash** in `weekly_runs.generated_seed_path`
  (the legacy `DockerWeeklyRunGenerator` stored a local file path), and there is no admin
  download endpoint. Pending a product/architecture decision.