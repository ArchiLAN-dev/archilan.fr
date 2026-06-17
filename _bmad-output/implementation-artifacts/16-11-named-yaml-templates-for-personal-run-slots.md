# Story 16.11: Named YAML templates for personal-run slots

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a member who configures Archipelago YAMLs for personal runs,
I want to save my YAML configurations as named, reusable templates and apply them to a slot in one click,
so that I stop re-entering the same settings every run and can keep several presets per game.

Originally drafted as Epic 28 story 28.9; moved to Epic 16 (Personal Runs) as Story **16.11** because it
belongs to the `PersonalRuns` context, not Steam Library Coupling. Minimum viable ask: "at least be able
to name them". Sharing / import-export / cross-user templates are out of scope.

## Acceptance Criteria

1. A member can save the current slot YAML as a template by giving it a name; it then appears in their template list for that game (`POST /api/v1/yaml-templates` with `{ gameId, name, yaml }` → 201).
2. Saving a name that already exists for the same `(user, game)` is rejected with 422 code `template_name_taken` - no silent overwrite.
3. Invalid/malformed YAML cannot be saved as a template (same validation as the slot-save path) → 422.
4. `GET /api/v1/yaml-templates?gameId=...` lists only the caller's templates for that game; another member's templates are never returned.
5. Applying a template loads its `yaml` into the editor; the slot is persisted only when the member saves (apply does not auto-save - consistent with the current flow).
6. A member can rename a template and update its stored YAML (`PUT /api/v1/yaml-templates/{id}`); a foreign id returns 404, a duplicate name returns 422.
7. A member can delete a template (`DELETE /api/v1/yaml-templates/{id}` → 204); a foreign id returns 404; a deleted template can no longer be listed or applied.
8. Templates are strictly private: enforced server-side by `user_id` (a non-owner can neither read, apply, update nor delete), verified by test.
9. Deleting/erasing the account removes the member's templates (personal-data cascade in the Story 2.4 path).
10. Gates green: backend (php-cs-fixer, phpstan, phpunit 0-notice, app:architecture:ddd) and frontend (typecheck, lint, build).

## Tasks / Subtasks

- [x] **Backend: Domain** (AC: 1, 6)
  - [x] `YamlTemplate` aggregate in `App\PersonalRuns\Domain` (`final` class, no public setters): props `id`, `userId`, `gameId`, `name`, `yaml`, `createdAt`, `updatedAt`; static `create()`; business methods `rename(string $name, \DateTimeImmutable $now)` and `updateYaml(string $yaml, \DateTimeImmutable $now)`; `isOwnedBy(string $userId): bool`; getters. ORM mapping attributes on the entity only. [Source: api/CLAUDE.md AC-D4/D5/D6]
  - [x] `YamlTemplateRepositoryInterface` in `App\PersonalRuns\Domain`: `findById`, `findByUserAndGame(string $userId, string $gameId): list<YamlTemplate>`, `existsByUserGameName(string $userId, string $gameId, string $name): bool`, `deleteByUserId(string $userId): void`, `save`, `delete`, `flush`.
- [x] **Backend: Migration** (AC: 1)
  - [x] Create table `yaml_template` (`id` string PK, `user_id` string, `game_id` string, `name` string, `yaml` TEXT, `created_at`, `updated_at`) with a UNIQUE index on `(user_id, game_id, name)`; reversible `down()`. Add Doctrine mapping. [Source: api/CLAUDE.md Migration standards]
- [x] **Backend: Infrastructure** (AC: 4, 8)
  - [x] `DoctrineYamlTemplateRepository` implementing the interface (ORM for entity ops; `findByUserAndGame` filtered by `user_id` + `game_id`).
- [~] **Backend: Application** (AC: 1, 2, 3, 5, 6, 7, 8)
  - [~] **Deviation:** implemented as ONE cohesive service `PersonalRunYamlTemplates` (`list`/`save`/`update`/`delete`) returning result arrays, mirroring the local `PersonalRunGameSelection`/`PersonalRunGameConfig` style, instead of four separate `Verb*` command classes. `update` handles both rename and YAML change (single PUT). Ownership-check (`isOwnedBy`), name uniqueness (`template_name_taken`), `apworldReady` + YAML syntax validation (`Symfony\Component\Yaml`, allowed in Application — the DDD validator itself uses it).
  - [~] List read served by the same service via `YamlTemplateRepositoryInterface::findByUserAndGame` (returns `id,name,gameId,yaml,updatedAt`); no separate DBAL query interface (the repository read is sufficient and keeps DBAL/ORM out of Presentation).
- [x] **Backend: Presentation** (AC: 1, 2, 4, 6, 7, 8)
  - [~] New `YamlTemplateController` in `App\PersonalRuns\Presentation`. **Deviation:** gated on **authenticated user** (`ApiAccessGuard::requireUser` via `RequiresAuthTrait`), NOT member — personal runs themselves are `ROLE_USER`, so templates must be too. Routes `GET/POST /api/v1/yaml-templates`, `PUT/DELETE /api/v1/yaml-templates/{id}`. 404 foreign/unknown id, 422 validation + `template_name_taken`. One Application call per action.
- [x] **Backend: Erasure** (AC: 9)
  - [x] In `Identity\Application\DeleteAccount::delete()`, purge the user's templates via `YamlTemplateRepositoryInterface::deleteByUserId($user->getId())` (inject the interface). Templates hold no anonymizable display data → hard delete is correct.
- [~] **Backend: tests** (AC: 1-9)
  - [~] Unit: `YamlTemplate` aggregate (create/rename/updateYaml/ownership). The Application service is covered end-to-end by the functional suite (real repo + Postgres) rather than mocked-unit, matching the local convention.
  - [~] Functional `YamlTemplateTest` (12 cases): create+list, duplicate-name (422), invalid YAML (422), list scoped to owner, foreign-id update/delete (404), update rename+yaml, delete, unauthenticated (401), erasure cascade (AC 9). The unauthenticated case is covered here instead of extending `RbacEnforcementTest`. Zero-notice gate.
- [x] **Frontend: API layer** (AC: 1, 4, 5, 6, 7)
  - [~] `features/personal-runs/yaml-templates-api.ts`: `fetchYamlTemplates`, `createYamlTemplate`, `updateYamlTemplate` (rename+yaml via one PUT), `deleteYamlTemplate` - each returns a typed result or `null`; `isYamlTemplate` guard + `errorCodeOf` in-file; base URL from `env.apiBaseUrl`; calls via `apiFetch` (cookie auth).
- [x] **Frontend: editor integration** (AC: 1, 5, 6, 7)
  - [~] Template controls layered onto `YamlOptionEditor` via its existing `onChange`/`ref` template mode (page-driven save): `TemplatesPanel` lists templates (Appliquer = remount editor with the template YAML, Écraser, Renommer inline, Supprimer) + "Enregistrer la config actuelle". **Deviation:** the list uses `apiFetch` + `useState` (consistent with this page's existing data load) rather than TanStack Query; the slot save button moved onto the page (required by template mode).
  - [x] Empty-state ("Aucun template pour ce jeu") and inline duplicate-name validation on the name prompt (prefer live check over post-submit 422).
- [x] **Gates** (AC: 10)
  - [x] `php-cs-fixer`, `phpstan`, `phpunit` (0 notices), `app:architecture:ddd`; `typecheck`, `lint`, `build`.

## Dev Notes

### Reuse, don't reinvent
- YAML validation + the option editor already exist: the slot save path is `PersonalRunGameSelection::saveSlotYaml()` and the shared UI is `features/events/yaml-option-editor.tsx` (already reused by `personal-run-slot-yaml-page.tsx`). Templates layer on top - no new YAML engine, no new editor. [Source: api/src/PersonalRuns/Application/PersonalRunGameSelection.php:141-178, frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx:197-208]
- `apworldReady` / `defaultYaml` already on `Game`; a "Défaut du jeu" pseudo-template in the picker is a cheap nice-to-have (flag, don't block). [Source: api/src/GameSelection/Domain/Game.php]
- Account erasure is anonymization-based; related personal rows are removed/anonymized in `DeleteAccount`. Add the template purge there. [Source: api/src/Identity/Application/DeleteAccount.php:23-41]

### Architecture guardrails
- `YamlTemplate` is an aggregate: `final`, no public setters, state changes via `rename()`/`updateYaml()`; ORM attributes live on the Domain entity only. [Source: api/CLAUDE.md AC-D4/D5/D6]
- Application services are `final`, inject repository/query **interfaces** only (no `EntityManager`/`Connection`); commands return `void`, the query returns arrays. [Source: api/CLAUDE.md AC-A1/A2/A3]
- Controller pattern = deserialize → validate → one Application call → `JsonResponse`; no SQL, no business logic. Member-gate via `ApiAccessGuard::requireAuthenticatedMember()` - never `ROLE_MEMBER`. [Source: api/CLAUDE.md AC-P1/P3/P4, AC-M1/M2]
- New context member? No - `PersonalRuns` is already in `DddArchitectureValidator::CONTEXTS`; add the Doctrine mapping for the new entity.
- Frontend: `is*` guards in the same file as the fetch fn, no `as` at the boundary, no default exports in `features/`, explicit `staleTime`. [Source: frontend/AGENTS.md AC-TS3/4, AC-CO3, AC-API5]

### Scope boundaries
- Per user, per game; private by `user_id`; unique `(user_id, game_id, name)`.
- "Save as new" + explicit rename/update for the MVP; an inline "overwrite current template" button is deferred.
- No sharing, no import/export, no cross-user/admin templates.

### Open questions (resolve before/at dev start)
- Context placement: keep in `PersonalRuns` (MVP, only consumer) vs a neutral `Presets`/`Library` context to pre-empt event-registration reuse (`yaml-option-editor.tsx` is already shared). Recommendation: `PersonalRuns`, lift later.
- Does `YamlTemplateListQuery` return `yaml` inline (one call to apply) or a separate `GET /{id}` fetch on apply? MVP: include `yaml` in the list (templates are small, per-game scoped).

### Project Structure Notes
- New (api): `PersonalRuns/Domain/YamlTemplate.php`, `YamlTemplateRepositoryInterface.php`; `PersonalRuns/Infrastructure/DoctrineYamlTemplateRepository.php`; `PersonalRuns/Application/{SaveYamlTemplate,RenameYamlTemplate,UpdateYamlTemplate,DeleteYamlTemplate,YamlTemplateListQuery}.php`; `PersonalRuns/Presentation/YamlTemplateController.php`; migration; Doctrine mapping; functional + unit tests. Modified: `Identity/Application/DeleteAccount.php`, `services.yaml`, `RbacEnforcementTest`.
- New (frontend): `features/personal-runs/yaml-templates-api.ts`. Modified: `features/events/yaml-option-editor.tsx` (additive props), `features/personal-runs/personal-run-slot-yaml-page.tsx` (template controls).

### References
- Epic/story: [Source: _bmad-output/planning-artifacts/epics/epic-16-personal-runs-private-user-created-archipelago-games.md#story-1611-named-yaml-templates-for-personal-run-slots]
- Related: [Source: _bmad-output/implementation-artifacts/28-8-recently-played-games-run-selection.md] ("apply last config" future bridge)
- Slot editor: [Source: frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Implemented on branch `feature/epic-16-story-11-yaml-templates` (from `develop`).
- Backend: `YamlTemplate` aggregate + `YamlTemplateRepositoryInterface` (PersonalRuns/Domain), `DoctrineYamlTemplateRepository`, cohesive `PersonalRunYamlTemplates` app service (list/save/update/delete), `YamlTemplateController` (4 routes), migration `Version20260617200000` (`yaml_template`, unique `(user_id, game_id, name)`), service binding, and erasure cascade in `DeleteAccount`.
- Key deviations from the drafted tasks (all annotated above): one cohesive service instead of 4 command classes; single PUT for rename+yaml; **authenticated-user gating, not member** (personal runs are `ROLE_USER`); list via repository read (no separate DBAL query); YAML syntax validated with `Symfony\Component\Yaml` in the Application service; frontend list via `apiFetch`+`useState` (not TanStack), slot save moved onto the page to use the editor's template/`onChange` mode.
- Frontend: `yaml-templates-api.ts` (typed results + guard) and a `TemplatesPanel` on the slot YAML page — apply (remounts the editor with the template YAML), overwrite, inline rename, delete, and "save current config as template", all on top of the existing shared `YamlOptionEditor` (additive, event-registration caller untouched).

### Validation Results

- `vendor/bin/php-cs-fixer fix src tests --dry-run`: 0 violations.
- `vendor/bin/phpstan analyse src tests`: 0 errors (766 files).
- `php bin/console app:architecture:ddd`: exit 0.
- `php bin/phpunit`: 1120 tests, 8063 assertions, OK (0 notices/deprecations/warnings) — includes `YamlTemplateTest` (functional, 9) + `Unit\PersonalRuns\YamlTemplateTest` (3).
- `pnpm typecheck` / `pnpm lint` / `pnpm build` / `pnpm test` (jest 86): all clean.

### File List

**Added (api)**
- `api/src/PersonalRuns/Domain/YamlTemplate.php`
- `api/src/PersonalRuns/Domain/YamlTemplateRepositoryInterface.php`
- `api/src/PersonalRuns/Infrastructure/DoctrineYamlTemplateRepository.php`
- `api/src/PersonalRuns/Application/PersonalRunYamlTemplates.php`
- `api/src/PersonalRuns/Presentation/YamlTemplateController.php`
- `api/migrations/Version20260617200000.php`
- `api/tests/Functional/YamlTemplateTest.php`
- `api/tests/Unit/PersonalRuns/YamlTemplateTest.php`

**Modified (api)**
- `api/src/Identity/Application/DeleteAccount.php` (erasure cascade — inject `YamlTemplateRepositoryInterface`, `deleteByUserId`)
- `api/config/services.yaml` (repository binding)

**Added (frontend)**
- `frontend/src/features/personal-runs/yaml-templates-api.ts`

**Modified (frontend)**
- `frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx` (page-driven save + `TemplatesPanel`)
