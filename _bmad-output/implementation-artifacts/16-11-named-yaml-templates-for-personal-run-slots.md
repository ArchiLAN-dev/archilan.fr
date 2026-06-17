# Story 16.11: Named YAML templates for personal-run slots

Status: draft

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

- [ ] **Backend: Domain** (AC: 1, 6)
  - [ ] `YamlTemplate` aggregate in `App\PersonalRuns\Domain` (`final` class, no public setters): props `id`, `userId`, `gameId`, `name`, `yaml`, `createdAt`, `updatedAt`; static `create()`; business methods `rename(string $name, \DateTimeImmutable $now)` and `updateYaml(string $yaml, \DateTimeImmutable $now)`; `isOwnedBy(string $userId): bool`; getters. ORM mapping attributes on the entity only. [Source: api/CLAUDE.md AC-D4/D5/D6]
  - [ ] `YamlTemplateRepositoryInterface` in `App\PersonalRuns\Domain`: `findById`, `findByUserAndGame(string $userId, string $gameId): list<YamlTemplate>`, `existsByUserGameName(string $userId, string $gameId, string $name): bool`, `deleteByUserId(string $userId): void`, `save`, `delete`, `flush`.
- [ ] **Backend: Migration** (AC: 1)
  - [ ] Create table `yaml_template` (`id` string PK, `user_id` string, `game_id` string, `name` string, `yaml` TEXT, `created_at`, `updated_at`) with a UNIQUE index on `(user_id, game_id, name)`; reversible `down()`. Add Doctrine mapping. [Source: api/CLAUDE.md Migration standards]
- [ ] **Backend: Infrastructure** (AC: 4, 8)
  - [ ] `DoctrineYamlTemplateRepository` implementing the interface (ORM for entity ops; `findByUserAndGame` filtered by `user_id` + `game_id`).
- [ ] **Backend: Application** (AC: 1, 2, 3, 5, 6, 7, 8)
  - [ ] Command services (return `void`): `SaveYamlTemplate`, `RenameYamlTemplate`, `UpdateYamlTemplate`, `DeleteYamlTemplate`. Each loads + ownership-checks (`isOwnedBy`), enforces name uniqueness (`template_name_taken`), and validates YAML/`apworldReady` on save/update. Reuse the existing YAML validation used by `PersonalRunGameSelection::saveSlotYaml()` - extract a small shared validator if needed, do not duplicate.
  - [ ] `YamlTemplateListQuery` (read): returns `list<array{id,name,gameId,updatedAt}>` (or with `yaml`) for a `(user, game)`. Use a query interface + DBAL impl, or the repository read - keep DBAL/ORM out of Presentation. [Source: api/CLAUDE.md AC-A2/A3]
- [ ] **Backend: Presentation** (AC: 1, 2, 4, 6, 7, 8)
  - [ ] New controller in `App\PersonalRuns\Presentation` (member-gated via `ApiAccessGuard`): `GET /api/v1/yaml-templates?gameId=...`, `POST /api/v1/yaml-templates`, `PUT /api/v1/yaml-templates/{id}`, `DELETE /api/v1/yaml-templates/{id}`. Map outcomes: 404 foreign/unknown id, 422 validation + `template_name_taken`. One Application call per action. [Source: api/CLAUDE.md AC-P3/P4]
- [ ] **Backend: Erasure** (AC: 9)
  - [ ] In `Identity\Application\DeleteAccount::delete()`, purge the user's templates via `YamlTemplateRepositoryInterface::deleteByUserId($user->getId())` (inject the interface). Templates hold no anonymizable display data → hard delete is correct.
- [ ] **Backend: tests** (AC: 1-9)
  - [ ] Unit: `YamlTemplate` (rename/updateYaml/ownership) + each command service with mocked repo/validator.
  - [ ] Functional: create, duplicate-name (422), invalid YAML (422), list scoped to owner, privacy isolation (AC 8), apply path (list returns yaml), rename, delete, foreign id (404), erasure cascade (AC 9). Extend `RbacEnforcementTest` with the four endpoints. Zero-notice gate.
- [ ] **Frontend: API layer** (AC: 1, 4, 5, 6, 7)
  - [ ] `features/personal-runs/yaml-templates-api.ts`: `fetchYamlTemplates(gameId)`, `saveYamlTemplate(...)`, `renameYamlTemplate(...)`, `updateYamlTemplate(...)`, `deleteYamlTemplate(id)` - each returns a typed result or `null`, with `is*` type guards in the same file. Base URL from `env.apiBaseUrl`. [Source: frontend/AGENTS.md AC-API1/2/3, AC-TS3/4]
- [ ] **Frontend: editor integration** (AC: 1, 5, 6, 7)
  - [ ] Layer template controls onto `YamlOptionEditor` via **additive props** (do not fork; the event-registration caller must be untouched): a template picker (apply on click → loads yaml into the editor), an "Enregistrer comme template" action with a name prompt, plus rename/delete affordances. Wire from `personal-run-slot-yaml-page.tsx` (it has `runId`, `slotId`, `gameId`, `defaultYaml`). TanStack Query for the list with explicit `staleTime`. [Source: frontend/AGENTS.md AC-API4/5, AC-CO2]
  - [ ] Empty-state ("Aucun template pour ce jeu") and inline duplicate-name validation on the name prompt (prefer live check over post-submit 422).
- [ ] **Gates** (AC: 10)
  - [ ] `php-cs-fixer`, `phpstan`, `phpunit` (0 notices), `app:architecture:ddd`; `typecheck`, `lint`, `build`.

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

### Debug Log References

### Completion Notes List

### File List
