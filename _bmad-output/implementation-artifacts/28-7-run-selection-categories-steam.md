# Story 28.7: Platform categories + Steam coupling on the run game-selection page

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a member selecting games for a personal run,
I want the same platform category filter and Steam coupling as on `/jeux`,
so that I can quickly find and pick the games I own and care about.

Brings Epic 28's catalog UX (28.5/28.6 categories + 28.2/28.4 coupling) to the run game-selection page (`/runs/{runId}/jeux`), reusing the shared pieces. Requires enriching the run game-selection payload with platforms + steamAppId, and extracting the coupling UI/state into shared, reusable units.

## Acceptance Criteria

1. The run game-selection payload (`GET /runs/{runId}/participants/me/game-selection`) exposes, per `availableGames` item, `platforms: string[]` (curated families, via `PlatformCategory::families`) and `steamAppId: number | null`. No N+1: `catalogSync` is eager-loaded.
2. The run selection catalog shows **platform category chips** (multi-select, union of families + a "Steam" facet) that filter the catalog list (OR within the facet), alongside the existing name/description search.
3. A **Steam coupling** control (auto-couple from saved account/localStorage, inline save, same outcomes/messaging as `/jeux`) sits on the page; once coupled, owned games show a "Tu possèdes ce jeu" label and a **"Mes jeux"** filter is enabled.
4. The coupling UI + state are **shared** with `/jeux` (no duplication): a `SteamCoupling` presentational component and a `useSteamCoupling` hook, both reused by `GamesCatalog` and the run page. `/jeux` behaviour is unchanged (regression-free).
5. Selection/add/remove/save behaviour of the run page is preserved.
6. Gates green: backend (php-cs-fixer, phpstan, phpunit, app:architecture:ddd) and frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Backend: enrich the payload** (AC: 1)
  - [ ] `PersonalRunGameSelection::getMySlots` `availableGames` map: add `'platforms' => PlatformCategory::families($g->getPlatforms() ?? [])` and `'steamAppId' => $g->getSteamAppId()` (both already on `Game` from 28.6/28.1). Import `App\GameSelection\Domain\PlatformCategory` (cross-context Domain import - allowed; the class already imports `App\GameSelection\Domain\Game`).
  - [ ] Eager-load `catalogSync` to avoid N+1: change `DoctrineGameRepository::findByAvailabilitiesSortedByName` to an ORM `createQueryBuilder('g')->leftJoin('g.catalogSync','cs')->addSelect('cs')->where('g.availability IN (:a)')->setParameter('a', $availabilities)->orderBy('g.name','ASC')`. (ORM is the sanctioned tool in repositories; benefits all callers.)
  - [ ] Functional test: payload includes `platforms`/`steamAppId` for a run's available games (seed a game with `GameCatalogSync` platforms + steamAppId).
- [ ] **Frontend: generalise filter helpers** (AC: 2, 3)
  - [ ] In `games-filter.ts`, widen `categoriesOf`, `allCategories`, `isOwned` to a structural type `{ platforms: string[]; steamAppId: number | null }` (PublicGame and the run's AvailableGame both satisfy it). `filterAndSortGames` stays `PublicGame`-specific.
- [ ] **Frontend: extract shared coupling** (AC: 4)
  - [ ] New `features/games/steam-coupling.tsx`: move `SteamCoupling` + `CouplingNotice` + `Notice` + `STORAGE_KEY`/`STEAM_PRIVACY_URL` out of `games-catalog.tsx`.
  - [ ] New `features/games/use-steam-coupling.ts`: a hook encapsulating coupling state (steamInput, submitting, result, editing, auto-couple effect, `couple`, `handleSave`, `matchedAppIds`, `view`, `alreadySaved`, `dirty`-aware `onChange`). Returns the props `SteamCoupling` needs + `matchedAppIds`.
  - [ ] Refactor `games-catalog.tsx` to consume the hook + component (no behaviour change).
- [ ] **Frontend: run selection page** (AC: 2, 3, 5)
  - [ ] Extend `AvailableGame` with `platforms: string[]` and `steamAppId: number | null`.
  - [ ] Render `<SteamCoupling {...useSteamCoupling()} />` near the catalog; compute `matchedAppIds`, category chips via `allCategories(availableGames)`, and `ownedOnly`/`categories` state.
  - [ ] Apply category + owned filters to the existing `filteredGames` derivation (keep name/description search + pagination). Show the owned label on rows where `isOwned(game, matchedAppIds)`; gate the "Mes jeux" toggle on a successful coupling.
- [ ] **Tests** (AC: 6)
  - [ ] Backend functional (payload). Frontend: extend `games-filter.test.ts` for the generalised helpers (Public-and-AvailableGame-shaped inputs); keep existing tests green.

## Dev Notes

### Reuse, don't reinvent
- `availableGames` is built from `Game` entities; `Game::getPlatforms()`/`getSteamAppId()` already exist (28.6/28.1). Only the payload map + eager-load change. [Source: api/src/PersonalRuns/Application/PersonalRunGameSelection.php:43-75, api/src/GameSelection/Infrastructure/DoctrineGameRepository.php:55-66]
- `PlatformCategory::families` (pure domain) is the same mapper used by the public catalog. [Source: api/src/GameSelection/Domain/PlatformCategory.php]
- Coupling api (`coupleSteamLibrary`), filter helpers (`categoriesOf`/`allCategories`/`isOwned`), and the (to-be-extracted) `SteamCoupling`/`useSteamCoupling` are reused as-is. [Source: frontend/src/features/games/steam-coupling-api.ts, games-filter.ts, games-catalog.tsx]

### Architecture guardrails
- Cross-context Application→Domain import (PersonalRuns→GameSelection `PlatformCategory`/`Game`) is permitted by the DDD validator (precedent: `SaveSteamAccount` imports `GameSelection\Domain\SteamProfileReference`). [api/src/Shared/Application/DddArchitectureValidator.php]
- ORM `createQueryBuilder` in a repository is allowed (entities). Keep DBAL only for DTO read queries. PHPStan max: the fetch-join returns `list<Game>`; keep the `@return` annotation.
- Frontend: extracting `SteamCoupling`/`useSteamCoupling` must not change `/jeux` behaviour - the hook holds the identical logic currently inlined in `GamesCatalog`. No `as` at boundary; the run payload parse must validate `platforms`/`steamAppId` (extend the existing `as`-cast parse - note current run page casts `res.json() as {...}`; widen the type and keep behaviour, or add light guards).

### Scope boundaries
- No sort control on the run page (keep its current ordering); categories + owned + search only.
- The run page keeps its own add/remove/save UI and pagination; we layer filtering on top, not replace it.
- "Steam" facet derived from `steamAppId` (same as /jeux). No new backend for the coupling itself (reuses `POST /games/steam-coupling`).

### Project Structure Notes
- New (api): functional test. Modified: `PersonalRunGameSelection.php`, `DoctrineGameRepository.php`.
- New (frontend): `features/games/steam-coupling.tsx`, `features/games/use-steam-coupling.ts`. Modified: `games-catalog.tsx` (consume shared), `games-filter.ts` (generalise + test), `personal-run-game-selection-page.tsx`.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md]
- Prior: [Source: _bmad-output/implementation-artifacts/28-5-jeux-page-redesign.md], [Source: _bmad-output/implementation-artifacts/28-6-platform-categories.md]
- Run page: [Source: frontend/src/features/personal-runs/personal-run-game-selection-page.tsx]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-7-run-selection-categories-steam` (stacked on 28.6).
- Backend: enriched `availableGames` with `platforms` (families via `PlatformCategory`) + `steamAppId`; eager-loaded `catalogSync` in `findByAvailabilitiesSortedByName` (ORM fetch-join, PHPStan-clean) to avoid N+1.
- Frontend: extracted shared `SteamCoupling` component + `useSteamCoupling` hook (refactored `GamesCatalog` to consume them, no behaviour change); generalised `categoriesOf`/`allCategories`/`isOwned` to a structural `Categorizable` type. Run page now has the coupling panel, category chips, "Mes jeux" toggle, and an owned label per row, layered onto its existing add/remove/save + pagination.
- No DB migration (platforms/steamAppId already exist from 28.6/28.1). Dev DB already backfilled, so the run page shows categories/coupling immediately.
- Gates green: php-cs-fixer 0, phpstan 0, ddd exit 0, phpunit 1066; FE typecheck/lint/build, jest 86.

### File List

**Added (api)**
- `api/tests/Functional/PersonalRunGameSelectionPayloadTest.php`

**Modified (api)**
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php` (platforms + steamAppId in payload)
- `api/src/GameSelection/Infrastructure/DoctrineGameRepository.php` (eager-load catalogSync)

**Added (frontend)**
- `frontend/src/features/games/steam-coupling.tsx` (extracted presentational component)
- `frontend/src/features/games/use-steam-coupling.ts` (extracted hook)

**Modified (frontend)**
- `frontend/src/features/games/games-catalog.tsx` (consume shared coupling)
- `frontend/src/features/games/games-filter.ts` (generalise helpers to Categorizable)
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx` (coupling + categories + owned + AvailableGame fields)
