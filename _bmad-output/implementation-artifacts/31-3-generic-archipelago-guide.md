# Story 31.3: Generic "Installer Archipelago" guide

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player new to Archipelago,
I want one generic, admin-editable "Installer Archipelago" guide (plus the client version to install),
so that the per-game tutorials don't repeat the basics and I have a single reference to get started.

Third story of Epic 31. Reuses the install-steps model (31.1) for the guide content and the
`ArchipelagoClientInfo` (31.8) for the pinned client version. Closes 31.8's deferred AC3.

## Acceptance Criteria

1. **Model.** A single admin-editable global guide holds ordered install steps (same shape as 31.1).
   Public `GET /api/v1/archipelago-guide` returns `{ steps }` (`[]` when unset). Admin
   `PUT /api/v1/admin/archipelago-guide` validates/normalizes the steps (shared `InstallStepsNormalizer`,
   http(s) links, plain-text) and persists. Admin-gated; `ROLE_MEMBER` never used.
2. **Standalone page.** `/aide/archipelago` renders the guide steps (reusing the step renderer) and the
   pinned **Archipelago client** version + launcher download (from `ArchipelagoClientInfo`, 31.8). Empty
   guide → a friendly placeholder, no broken layout. Has SEO metadata.
3. **Discovery.** A link to `/aide/archipelago` appears atop each game's Installation section on
   `/jeux/[slug]` and in the public footer. (The full generic steps are **not** inlined on every game
   page - only a link - to keep per-game tutorials focused.)
4. **Admin editing.** Admins edit the guide steps on `/admin/catalogue` (reusing `InstallStepsEditor`),
   alongside the Archipelago client settings (31.8).
5. **Reuse / no duplication.** The step rendering is extracted into a shared `InstallStepsView` used by
   both the game detail Installation section and `/aide/archipelago`.
6. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/**: `ArchipelagoGuide` single-row entity (GameSelection/Domain, id `default`, `steps` JSON) +
      `ArchipelagoGuideRepositoryInterface` + Doctrine impl + migration. `ArchipelagoGuideQuery` (read
      steps) + `UpdateArchipelagoGuide` (normalize via `InstallStepsNormalizer`). Public + admin
      controllers. `services.yaml` alias. Functional test.
- [ ] **frontend**: extract `InstallStepsView` from `game-detail.tsx` (refactor to use it);
      `archipelago-guide-api.ts` (`getArchipelagoGuide` + guard, `saveArchipelagoGuide`);
      `/aide/archipelago/page.tsx` (steps + client block, metadata); link atop the game Installation
      section + footer; `ArchipelagoGuideSettings` on `/admin/catalogue`. jest for the api guard.

## Dev Notes

- **Reuse**: install-steps model + `InstallStepsNormalizer` + `InstallStepsEditor` (31.1), step render
  extracted as `InstallStepsView`, `ArchipelagoClientInfo` for the client version (31.8). Mirror the
  `ArchipelagoClientInfo` entity/endpoints pattern for `ArchipelagoGuide`.
- **Scope decision**: the generic guide is linked (not fully inlined) from each game page; the full
  content lives on `/aide/archipelago`. Keeps per-game tutorials focused.
- Render safety: steps are plain text + http(s) links (validated by the normalizer); no raw HTML.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Prior: [Source: _bmad-output/implementation-artifacts/31-1-install-steps-model-and-admin-authoring.md], [Source: _bmad-output/implementation-artifacts/31-8-version-match-guidance.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Implemented on branch `feature/epic-31-story-3-generic-guide` (from develop).
- `ArchipelagoGuide` single-row entity (GameSelection, id `default`, `steps` JSON) + repo + migration; `ArchipelagoGuideQuery` + `UpdateArchipelagoGuide` (shared `InstallStepsNormalizer`). Public `GET /api/v1/archipelago-guide`; admin `PUT /api/v1/admin/archipelago-guide`.
- Frontend: extracted `InstallStepsView` (game-detail now consumes it); `archipelago-guide-api.ts` (+ guard reusing the exported `isGameStep`); `/aide/archipelago` page (client download block from `ArchipelagoClientInfo` + guide steps or placeholder); link atop the game Installation section + a footer link; `ArchipelagoGuideSettings` on `/admin/catalogue`.
- Generic guide is **linked** (not inlined) from each game page, per the scope decision.
- Gates green: php-cs-fixer 0, phpstan 0 (src+tests), DDD exit 0, phpunit 1277 (+4); FE typecheck/lint/build, jest 51.

### File List

**Added (api)**
- `api/src/GameSelection/Domain/ArchipelagoGuide.php`
- `api/src/GameSelection/Domain/ArchipelagoGuideRepositoryInterface.php`
- `api/src/GameSelection/Application/ArchipelagoGuideQuery.php`
- `api/src/GameSelection/Application/UpdateArchipelagoGuide.php`
- `api/src/GameSelection/Infrastructure/DoctrineArchipelagoGuideRepository.php`
- `api/src/GameSelection/Presentation/ArchipelagoGuideController.php`
- `api/src/GameSelection/Presentation/AdminArchipelagoGuideController.php`
- `api/migrations/Version20260619110000.php`
- `api/tests/Functional/ArchipelagoGuideTest.php`

**Modified (api)**
- `api/config/services.yaml` (repository alias)

**Added (frontend)**
- `frontend/src/features/games/install-steps-view.tsx`
- `frontend/src/features/games/archipelago-guide-api.ts` (+ test)
- `frontend/src/app/(public)/aide/archipelago/page.tsx`
- `frontend/src/features/admin/archipelago-guide-settings.tsx`

**Modified (frontend)**
- `frontend/src/features/games/game-detail.tsx` (use InstallStepsView + guide link)
- `frontend/src/features/games/public-games-api.ts` (export `isGameStep`)
- `frontend/src/features/admin/admin-catalogue-sync-page.tsx` (mount guide settings)
- `frontend/src/components/public-shell.tsx` (footer link)
