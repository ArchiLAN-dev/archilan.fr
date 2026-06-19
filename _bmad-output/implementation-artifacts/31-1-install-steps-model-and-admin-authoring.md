# Story 31.1: Per-game install steps - model + admin authoring + auto-seed

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin maintaining the game library,
I want to author an ordered, structured installation tutorial for each game (and start from an
auto-generated draft based on the data we already have),
so that players will later see clear, per-game setup steps on the public game page.

First story of Epic 31. It delivers the **data model + admin authoring + auto-seed** only. The public
render on `/jeux/[slug]` and the removal of the live "Liens & ressources" Sheet section come in 31.2;
the shared generic guide in 31.3.

## Acceptance Criteria

1. **Model.** `Game` gains a nullable `install_steps` JSON column (reversible migration), holding an
   **ordered** `list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>`. `Game::getInstallSteps(): array` returns `[]` when null; `Game::setInstallSteps(?array): void` stores the normalized list (or null when empty).
2. **Step types.** Allowed `type` values are constrained to a domain enum `InstallStepType`:
   `acquire`, `apworld`, `client`, `yaml`, `connect`, `note`. An unknown/blank type is rejected
   (validation error); a step with a blank `title` is rejected.
3. **Save endpoint.** `PATCH /api/v1/admin/games/{gameId}/tutorial` (admin-gated) accepts the **whole
   ordered array** of steps, validates/normalizes them (trim, enum, links `{label,url}` with nullable
   url), persists, and returns the updated admin detail payload. Reordering = sending the array in the
   new order. 404 unknown game, 422 on validation errors.
4. **Seed endpoint.** `POST /api/v1/admin/games/{gameId}/tutorial/seed` (admin-gated) composes a **draft**
   from existing data and persists it: `bundledWithAp` → a `note` step "Rien à installer, inclus dans
   Archipelago"; else an `apworld` step "Installer l'apworld" carrying `apworldSourceUrl` (when set); the
   Google-Sheet links folded into the step's `links` (or a trailing `note`/resources step). Always
   appends a `yaml` step ("Configurer le YAML") and a `connect` step ("Se connecter"). Returns the
   seeded steps. Seeding overwrites only when the tutorial is empty unless `?force=1`.
5. **Detail payload.** The admin detail payload (`GET /api/v1/admin/games/{gameId}`) exposes
   `installSteps` in the model shape from AC1.
6. **Dependency direction.** Sheet links for the seed are obtained through a
   `GameCatalogLinksProviderInterface` defined in `GameSelection/Application` and implemented by a
   `CatalogSync` adapter (wrapping `CatalogSyncService::findEntryForNames`). `GameSelection` must not
   import `CatalogSync`. Sheet failures degrade to no links (logged), never break the seed.
7. **Admin UI.** `/admin/jeux/[gameId]` gains a "Tutoriel d'installation" section: an ordered list of
   steps (type select, title, description textarea, links list with label+url), per-step **add / remove
   / move up / move down**, a **"Générer un brouillon"** button (calls the seed endpoint), and a **Save**
   that PATCHes the whole array. No public page change in this story.
8. **Bulk seed (cold start).** A console command `app:games:seed-tutorials` seeds `install_steps` for
   **every game that has none yet** (same composition as the seed endpoint - bundled/apworld + Sheet
   links via the provider), so no page is empty at launch. **Idempotent**: games with authored steps are
   skipped; `--force` reseeds all. Per-game failures are logged and skipped; the run continues. Mirrors
   `app:games:backfill-platforms`. Reports `processed` / `seeded`.
9. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Domain: install steps on `Game`** (AC: 1, 2)
  - [ ] `InstallStepType` (`api/src/GameSelection/Domain/`) - string-backed enum or `final class` with
        the 6 constants + an `isValid(string): bool` / `values(): list<string>` helper.
  - [ ] `Game`: `#[ORM\Column(name: 'install_steps', type: 'json', nullable: true)] private ?array $installSteps = null` (mirror `option_types`), with `getInstallSteps(): array` (`[] when null`) and `setInstallSteps(?array $steps): void` (store null when empty). Docblock the list shape.
- [ ] **Migration** (AC: 1)
  - [ ] `Version20260619######.php`: `ALTER TABLE game ADD COLUMN install_steps JSON DEFAULT NULL` + `down()` drop. Timestamp one second after the latest migration.
- [ ] **Application: shared normalizer + save + seed** (AC: 2, 3, 4, 6)
  - [ ] `InstallStepsNormalizer` (`GameSelection/Application`, **first-class, shared** - consumed later by 31.6/31.7): `normalize(array $rawSteps): array` + error collection. Trims title/description, validates `type` against `InstallStepType`, coerces `links` to `list<{label: string, url: string|null}>`, **validates each `url` to http/https only (reject `javascript:`/other schemes)**, drops empty steps. Treat `description` as **plain text** (no HTML) - it is rendered safely downstream (31.2). Length caps on title/description/links.
  - [ ] `AdminGameLibrary::saveTutorial(string $gameId, array $rawSteps): array` - uses `InstallStepsNormalizer`, collects `ValidationErrors`, persists via the repo, returns `detailPayload`.
  - [ ] `seedTutorial(string $gameId, bool $force): array` - build default steps from `isBundledWithAp()` / `getApworldSourceUrl()` + `GameCatalogLinksProviderInterface::linksFor(...)`; persist when empty or `$force`; return `detailPayload`.
  - [ ] `GameCatalogLinksProviderInterface` (`GameSelection/Application`): `linksFor(?string $catalogSheetName, ?string $archipelagoGameName, string $name): list<array{label: string, url: string|null}>`.
  - [ ] Add `installSteps` to `detailPayload`.
- [ ] **Infrastructure: CatalogSync adapter** (AC: 6)
  - [ ] `CatalogSync/Infrastructure/CatalogSyncGameLinksProvider implements GameCatalogLinksProviderInterface` - wraps `CatalogSyncService::findEntryForNames(...)`, returns `$entry?->links ?? []`, try/catch → `[]` with a warning. Register in `services.yaml` (real impl; no `when@test` gating - a stub returning `[]` is fine for tests, or reuse the catalog stub).
- [ ] **Presentation** (AC: 3, 4, 5)
  - [ ] `AdminGameLibraryController`: `PATCH /api/v1/admin/games/{gameId}/tutorial` and `POST /api/v1/admin/games/{gameId}/tutorial/seed` (mirror existing admin actions: `requireAuthenticatedAdmin` → one service call → 404/422/200). Keep one Application call per action.
- [ ] **Bulk seed command** (AC: 8)
  - [ ] `Application/SeedGameTutorials`: iterate `findAllSortedByName()`, skip games with non-empty `installSteps` unless `$force`, reuse the same seed composition (`seedTutorial` logic / shared seed builder), per-game try/catch + warning log; return `{processed, seeded}`. Mirror `BackfillGamePlatforms`.
  - [ ] `Presentation/SeedGameTutorialsCommand` (`app:games:seed-tutorials`, `--force`), mirroring `BackfillGamePlatformsCommand`.
- [ ] **Frontend: reusable editor + admin section** (AC: 7)
  - [ ] Build a **reusable `InstallStepsEditor` component** (shared module, e.g. `features/games/install-steps-editor.tsx`) from the start - ordered steps editor reusing the ↑/↓ pattern from `yaml-option-editor.tsx`; type `<select>` (the 6 types), title input, description textarea, links sub-list (label + url, add/remove). It is **consumed by 31.6** (public submission) too, so keep it free of admin-only assumptions.
  - [ ] Extend the editor `AdminGame` type with `installSteps: InstallStep[]` (+ permissive payload guard already accepts new fields).
  - [ ] `InstallTutorialSection` in `admin-game-editor.tsx` (sibling of `CatalogSyncSection`) wraps `InstallStepsEditor`: "Générer un brouillon" → `POST .../tutorial/seed` then `onUpdate`; Save → `PATCH .../tutorial` with the whole array; success/error states like the other sections.
- [ ] **Tests** (AC: 9)
  - [ ] Backend functional (`AdminGameLibraryTest` or a new `AdminGameTutorialTest`): save valid steps round-trips (detail shows them, ordered); invalid type / blank title → 422; seed on a bundled game yields the "inclus" note; seed on an apworld game yields the apworld step with the source URL + folded sheet links (stub provider); seed respects empty-vs-`force`. Include `Game` in the schema. Honour the zero-notice gate.
  - [ ] Backend unit: `InstallStepsNormalizer` (trim/enum/links coercion + **rejects non-http(s) urls**, drops empty), seed composition with a stub `GameCatalogLinksProviderInterface`, and `SeedGameTutorials` (seeds only empty games, `--force` reseeds, per-game failure skipped).
  - [ ] Frontend jest: the editor type/guard accepts `installSteps`; a small reducer/helper test for add/remove/reorder if extracted.

## Dev Notes

### Reuse, don't reinvent
- **JSON-column-on-`Game` pattern**: copy `option_types` / `platforms` (column + get/set + docblock). [Source: api/src/GameSelection/Domain/Game.php, GameCatalogSync.php]
- **Admin action pattern**: `AdminGameLibrary` + `AdminGameLibraryController` already have the
  `requireAuthenticatedAdmin → service → 404/422/200` shape, `ValidationErrors`, `detailPayload`, and the
  `resync-platforms` POST is a close template for the new POST/PATCH actions. [Source: api/src/GameSelection/Application/AdminGameLibrary.php, Presentation/AdminGameLibraryController.php]
- **Cross-context provider pattern**: this story mirrors 28.9 - CatalogSync already depends on
  GameSelection and exposes `CatalogSyncService::findEntryForNames(...)`; wrap it behind a GameSelection
  interface. [Source: api/src/CatalogSync/Application/CatalogSyncService.php, api/src/CatalogSync/Application/PublicGameDetailQuery.php]
- **Ordered-list editor with ↑/↓**: the YAML option editor already does add/remove/reorder with chevrons -
  follow its interaction + token styling. [Source: frontend/src/features/events/yaml-option-editor.tsx]
- **Editor sections**: `BasicInfoSection` / `CatalogSyncSection` in `admin-game-editor.tsx` are the
  template for a new `InstallTutorialSection` (state, submit, success/error, `onUpdate`). [Source: frontend/src/features/admin/admin-game-editor.tsx]

### Architecture guardrails
- DDD: validation/normalization in Application; `InstallStepType` is pure Domain; the seed write stays in
  GameSelection, only the Sheet links cross the boundary via the interface (GameSelection ← CatalogSync,
  never the reverse). No `EntityManager`/`Connection` in Application or the controller. Controller =
  one Application call.
- PHPStan max: narrow every value coming off the request JSON (`is_string`, `is_array`); the `links`
  coercion must produce a typed `list<array{label: string, url: string|null}>`. No `(string) $mixed`.
- CS Fixer @Symfony: Yoda, `final`, `declare(strict_types=1)`.
- Frontend: no `as` at the API boundary; env via `src/lib/env.ts`; tokens-only styling; stable keys for
  the step list (index-based keys are acceptable for a reorderable admin list, or a client-side id).

### Scope boundaries
- **No public render** and **no change to `/jeux/[slug]`** in this story (31.2). The live Sheet
  "Liens & ressources" section stays until 31.2 swaps it for the rendered steps.
- No generic guide (31.3), no flow nudge (31.4), no checklist/media (31.5).
- Steps are **plain text** + links only - no HTML, no screenshot upload, no video embed (render safety:
  the `description` is stored/treated as text; public rendering in 31.2 must not use raw HTML).

### Project Structure Notes
- New (api): `Domain/InstallStepType.php`, `Application/InstallStepsNormalizer.php` (shared),
  `Application/GameCatalogLinksProviderInterface.php`,
  `CatalogSync/Infrastructure/CatalogSyncGameLinksProvider.php`,
  `Application/SeedGameTutorials.php`, `Presentation/SeedGameTutorialsCommand.php`, migration, tests.
- Modified (api): `Domain/Game.php` (column + get/set), `Application/AdminGameLibrary.php`
  (save/seed + payload), `Presentation/AdminGameLibraryController.php` (2 routes), `config/services.yaml`
  (provider + command wiring).
- New/Modified (frontend): reusable `features/games/install-steps-editor.tsx`,
  `features/admin/admin-game-editor.tsx` (type + `InstallTutorialSection` wrapping the editor).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Prior art: [Source: _bmad-output/implementation-artifacts/28-9-public-game-detail-page.md] (cross-context provider, Sheet resolution), [Source: _bmad-output/implementation-artifacts/28-6-platform-categories.md] (JSON column on Game)
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.

### File List
