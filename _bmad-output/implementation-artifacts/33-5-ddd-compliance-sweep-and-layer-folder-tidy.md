# Story 33.5: DDD Compliance Sweep + Layer-Folder Tidy (api/)

Status: ready-for-dev

## Story

As a maintainer of the api's DDD architecture,
I want the `DddArchitectureValidator` to enforce the currently-unenforced `api/CLAUDE.md` rules and every class relocated to the subfolder matching its responsibility,
so that the `app:architecture:ddd` gate actually guards the documented architecture and the folder layout communicates intent - with zero behaviour change.

## Acceptance Criteria

1. **AC1 - Audit worklist committed first.** A worklist file (`_bmad-output/implementation-artifacts/33-5-audit-worklist.md`) enumerating (a) every new validator rule to add and (b) every misplaced file to relocate, is committed **before** any code change. Each entry has a file path and a one-line disposition (move to X / new rule Y / accepted as-is with rationale). The candidate lists seeded in Dev Notes below are the starting point; the audit confirms, extends, or rejects each with the final say. This worklist IS the story scope - nothing outside it gets touched.
2. **AC2 - Every worklist item resolved; validator stricter and green.** Every worklist item is either fixed or explicitly accepted with a one-line rationale recorded in the worklist. `DddArchitectureValidator` enforces at minimum: cross-context import restrictions, `*RepositoryInterface` placement in `Domain/`, `*QueryInterface` placement in `Application/`, no clock reads in Application, no `new` on Infrastructure classes in Application, and `createNativeQuery` added to the Presentation forbidden calls. Intentional exceptions are encoded as **named, commented allowlist constants inside the validator** - never as inline code suppressions (locked epic decision). `php bin/console app:architecture:ddd` exits 0 with the new rules active.
3. **AC3 - Blast-radius safety per move.** For every relocated class: namespace + all `use` statements updated (src, tests, `src/Schedule.php`), `config/services.yaml` FQCN service ids/aliases/arguments updated, `config/packages/messenger.yaml` routing keys updated for moved message classes, mirrored `tests/Unit/{Context}/` test moved with it. After the moves: `php bin/console lint:container`, `cache:clear`, `doctrine:mapping:info`, and `debug:messenger` all pass, and the affected flows are smoke-verified (see Task 5).
4. **AC4 - Sessions excluded.** The `Sessions` context is untouched by the folder tidy (Epic 32, which heavily modifies `Sessions`, has NOT merged - it is stashed). The exclusion is stated in the PR description. (New validator rules still apply to `Sessions` code as-is; if `Sessions` violates a new rule, grandfather it via a named allowlist entry with a `TODO epic-32` comment rather than moving files.)
5. **AC5 - All gates green, zero behaviour change.** `composer gates` passes (phpstan 0, cs-fixer 0, `app:architecture:ddd` exit 0 with stricter rules, phpunit green with 0 notices). `pnpm gates` unaffected (zero frontend changes). No observable behaviour change: no route, response shape, message semantics, or DB schema change - the full existing suite is the contract.

## Tasks / Subtasks

- [ ] Task 1: Produce and commit the audit worklist (AC: 1)
  - [ ] 1.1 Verify each seeded misplacement candidate (Dev Notes "Folder-tidy candidates") by reading the file: classify CQRS message vs command service vs console command (see the two-meanings-of-"Command" warning), confirm handler attributes (`#[AsMessageHandler]`), decide move vs accept.
  - [ ] 1.2 Finalize the new-rule list and each rule's exact detection pattern (text-based, consistent with the validator's existing `str_contains`/regex approach) plus its initial allowlist entries. Seeded rules in Dev Notes "New validator rules".
  - [ ] 1.3 For the cross-context rule, enumerate ALL current cross-context imports (`grep -r "use App\\\\" src/` filtered to context != file's context and != Shared) and classify each: legitimate pattern (allowlist with comment) vs violation (fix).
  - [ ] 1.4 Commit the worklist as `_bmad-output/implementation-artifacts/33-5-audit-worklist.md` before any src/ change.
- [ ] Task 2: Extend `DddArchitectureValidator` + tests (AC: 2)
  - [ ] 2.1 Add `createNativeQuery` to `FORBIDDEN_PRESENTATION_CALLS` (doc AC-P2 lists 7 calls, validator has 6 - straight bug fix).
  - [ ] 2.2 Add interface-placement rules: any `*RepositoryInterface.php` outside `{Context}/Domain/` and any `*QueryInterface.php` outside `{Context}/Application/` is a violation.
  - [ ] 2.3 Add cross-context import rule per the audit's final design (strict for Domain per AC-D2; Application/Presentation per audit classification with named allowlist, e.g. cross-context `Application\Message` imports for event-driven flows).
  - [ ] 2.4 Add Application purity rules: no clock reads (`new \DateTime()` / `new \DateTimeImmutable()` no-arg or `'now'`, `date(`, `time(` call patterns) and no `new` on `\Infrastructure\` FQCNs, in `Application/` files. Design patterns to avoid false positives (e.g. `DateTimeImmutable` built from a passed parameter is NOT a clock read); fix real violations by injecting `Psr\Clock\ClockInterface` or passing values as parameters per the no-magic rule.
  - [ ] 2.5 Encode every intentional exception as a named `private const` allowlist in the validator with a one-line comment per entry (e.g. `ALLOWED_CROSS_CONTEXT_IMPORTS`). No `@phpstan-ignore`, no inline suppression anywhere.
  - [ ] 2.6 Extend `tests/Unit/DddArchitectureValidatorTest.php` with cases per new rule (violation detected + allowlisted entry passes + clean code passes), following the existing fixture style.
- [ ] Task 3: Folder tidy - CQRS Message/Handler normalisation (AC: 2, 3)
  - [ ] 3.1 `Identity`: move the 4 handlers out of `Application/Message/` into `Application/Handler/` (`CleanupRefreshTokensHandler`, `CleanupEmailConfirmationTokensHandler`, `CleanupPasswordResetTokensHandler`, `SyncDiscordRoleMessageHandler`).
  - [ ] 3.2 `Events`: move `Application/Message/CleanupEventPrivateAccessLogHandler` to `Application/Handler/`; move flat pair `EventCapacityReachedHandler`/`EventCapacityReachedMessage` into `Handler/`/`Message/`.
  - [ ] 3.3 `Payments`: move `Application/Message/CleanupHelloAssoSyncLogHandler` to `Application/Handler/`; move flat pair `SyncHelloAssoFormHandler`/`SyncHelloAssoFormMessage` into `Handler/`/`Message/`.
  - [ ] 3.4 `Communications`: create `Application/Message/` + `Application/Handler/` and move the 6 flat handler+message pairs (`EmailConfirmation`, `PasswordReset`, `RegistrationConfirmation`, `SessionPausedWithoutSave`, `SessionRestartFailed`, `SessionRunning`).
  - [ ] 3.5 Per move batch: update namespaces + `use` statements everywhere (dispatchers in other contexts, `src/Schedule.php` L7-17, tests), update `config/packages/messenger.yaml` routing keys (L34-60) for every moved message FQCN, update any `config/services.yaml` reference.
- [ ] Task 4: Folder tidy - remaining audit items (AC: 2, 3)
  - [ ] 4.1 Resolve the audit's verdict on the borderline candidates (each move or accept-with-rationale): `Communications/Application/ArchilanMailer` (mailer impl → Infrastructure?), `CatalogSync/Application/GithubRateLimitException` (infra exception?), `Payments/Presentation/HelloAssoWebhook*` DTOs (request models - likely accept in Presentation), port interfaces in Infrastructure (`GameSelection/Infrastructure/IgdbHttpClientInterface`, `SteamWebApiClientInterface`, `Shared/Infrastructure/MinioStorageInterface` - AC-I2 says interfaces live in Application or Shared; weigh move value vs services.yaml blast radius), `Shared/Presentation/RequiresAuthTrait` (likely accept).
  - [ ] 4.2 Flat sync command/query SERVICES stay flat in `Application/` (that is the documented convention - only async Message/Handler get sub-namespaces). Record this explicitly in the worklist so nobody "tidies" them by mistake.
  - [ ] 4.3 Do NOT move `Community\Domain\{DefaultAchievementDefinitions, AchievementMetricCatalog, AchievementOperator, AchievementRuleGroup}` - they are imported by merged migrations (`Version20260618170000.php:7`, `Version20260622120000.php:7-9`) and merged migrations are immutable. They are correctly placed anyway; note the coupling in the worklist.
  - [ ] 4.4 Optional doc ride-along (doc-only, exempt): update the "Known contexts" list in `api/CLAUDE.md` (13 listed vs 18 in `DddArchitectureValidator::CONTEXTS`) and note the `Presentation/` vs "Controllers/" heading mismatch.
- [ ] Task 5: Blast-radius verification (AC: 3)
  - [ ] 5.1 `php bin/console lint:container` - catches every stale FQCN in `services.yaml`/`security.yaml`.
  - [ ] 5.2 `php bin/console cache:clear` then `php bin/console doctrine:mapping:info` - all 45 entities still discovered (no entity moves expected; this is the tripwire).
  - [ ] 5.3 `php bin/console debug:messenger` before AND after the moves - diff the handler list: same count, every moved message still mapped to its handler and transport (messenger routing failures are SILENT - a stale routing key just drops to sync, no error).
  - [ ] 5.4 Smoke the affected flows: run the full functional suite (isolated: `api/scripts/test-isolated.sh story335`), and manually verify one async flow end-to-end in dev (e.g. trigger an email confirmation → message routed to `async` → handler consumes) since moved message classes are the main risk surface.
- [ ] Task 6: Final gates + PR (AC: 4, 5)
  - [ ] 6.1 `composer gates` green (includes the now-stricter `app:architecture:ddd`); `pnpm gates` untouched/green.
  - [ ] 6.2 10-run flake-free confidence not required here (33.1 closed that), but run the full suite twice on the isolated DB to be safe after this many file moves.
  - [ ] 6.3 PR to `develop` from `feature/epic-33-story-5-ddd-sweep-layer-tidy`; PR body states the `Sessions` exclusion (AC4) and links the worklist. Commits ordered: (1) worklist, (2) validator rules + fixes, (3) folder tidy per context, (4) story/doc records.

## Dev Notes

### Execution shape

- **Branch:** `feature/epic-33-story-5-ddd-sweep-layer-tidy` from `develop`. One PR, reviewable commits per area (worklist → rules → per-context tidy).
- **Two tracks, ordered:** rules first (they define "compliant"), then folder tidy (guided by the audit worklist). The worklist commit comes before everything (AC1).
- **Zero behaviour change is the contract** (epic locked decision). Coverage is verified before moving: every moved class either already has a mirrored unit test or is exercised by the functional suite; where a moved area has neither, add a characterization test BEFORE the move.
- **Sessions is excluded from the tidy** (AC4) even though the topology audit found it already conforms - Epic 32's stash rewrites that context; do not create conflict surface. New validator rules that `Sessions` trips get grandfathered allowlist entries, not file moves.

### Current validator state (all findings verified at develop = 0c6037d)

- Validator: `api/src/Shared/Application/DddArchitectureValidator.php`; report VO `DddArchitectureReport.php`; command `src/Shared/Infrastructure/Console/ValidateDddArchitectureCommand.php` (exit 0/1, lists violations); tests `tests/Unit/DddArchitectureValidatorTest.php`.
- Contexts are a hardcoded 18-entry `CONTEXTS` const (L12-31), layers hardcoded (L34). All checks are **raw-text based** (`str_contains` + one regex) - no AST/reflection. Keep new rules in the same style; note text matching can hit comments/strings, so pattern design and the allowlist absorb edge cases.
- 7 existing rules: context dirs exist; files inside context+layer (skips `Kernel.php`/`Schedule.php`, flags starter dirs); Domain forbidden deps (only 4 Symfony namespaces + same-context upper layers); Application forbidden imports (`Connection`, `EntityManagerInterface`, `EntityFinderTrait`); Presentation forbidden imports + 6 forbidden call regexes; services.yaml Domain excludes present; doctrine.yaml mapping prefix per entity-bearing context.
- **No allowlist mechanism exists yet** - this story introduces the first one (named consts, per epic decision).
- Known gaps vs `api/CLAUDE.md` (from the validator analysis): cross-context imports unenforced (AC-D2 partial); `createNativeQuery` missing from Presentation calls (AC-P2); interface placement unenforced (AC-A2); no clock / no `new`-on-infra in Application unenforced (AC-A5, no-magic rule); AC-D1 covers only 4 Symfony namespaces; Infrastructure layer has zero rule coverage. The epic scopes THIS story to: cross-context, interface placement, clock/`new`-infra in Application (+ the `createNativeQuery` bug fix). Wider gaps (AC-D3/D4/D5, AC-A1/A3/A6, ROLE_MEMBER ban) are NOT in scope - list them in the worklist as "out of scope, candidate future rules" so they are not silently dropped.

### New validator rules (seeded design - audit finalizes)

| Rule | Detection sketch | Known allowlist seeds |
|---|---|---|
| Cross-context imports | per file in context X, flag `use App\{Y}\...` where Y != X and Y != Shared; Domain = strict (AC-D2); Application/Presentation = per audit classification | cross-context message dispatch (e.g. `Sessions` → `Communications\Application\Message\SessionPausedWithoutSaveMessage`), interface-in-one-context-impl-in-another wiring (`Identity\Application\MemberDisplayNameQueryInterface` ← `Community\Infrastructure\DbalMemberDisplayNameQuery`, services.yaml L296, also L302/L311) |
| `*RepositoryInterface` in Domain | filename suffix match + path check | none expected (topology audit found none misplaced) |
| `*QueryInterface` in Application | filename suffix match + path check | none expected |
| No clock in Application | flag `date(`, `time(`, no-arg/`'now'` `new DateTime*` in `Application/` | audit enumerates; fix = inject clock or pass parameter |
| No `new` on Infrastructure in Application | flag `new` + FQCN containing `\Infrastructure\` in `Application/` files | audit enumerates |
| `createNativeQuery` in Presentation | add to `FORBIDDEN_PRESENTATION_CALLS` regex list | none |

### Folder-tidy candidates (seeded from topology audit - audit worklist confirms each)

**Reference contexts (already conform - use as templates):** Community (131 files), Membership (59), PersonalRuns (31), WeeklyRuns (74) - `Application/Message/` + `Application/Handler/`, repo interfaces in Domain, doubles in Infrastructure, controllers in Presentation.

**Moves (high confidence):**
- `Identity/Application/Message/` → `Handler/`: `CleanupRefreshTokensHandler`, `CleanupEmailConfirmationTokensHandler`, `CleanupPasswordResetTokensHandler`, `SyncDiscordRoleMessageHandler` (all `#[AsMessageHandler]`, verified).
- `Events`: `Message/CleanupEventPrivateAccessLogHandler` → `Handler/`; flat `EventCapacityReachedHandler`+`Message` → sub-namespaces.
- `Payments`: `Message/CleanupHelloAssoSyncLogHandler` → `Handler/`; flat `SyncHelloAssoFormHandler`+`Message` → sub-namespaces.
- `Communications`: 6 flat handler+message pairs → new `Message/` + `Handler/` dirs (`EmailConfirmation`, `PasswordReset`, `RegistrationConfirmation`, `SessionPausedWithoutSave`, `SessionRestartFailed`, `SessionRunning`).

**Borderline (audit decides, move OR accept with rationale):** `Communications/Application/ArchilanMailer` (impl → Infrastructure; Communications' Domain/Infra/Presentation dirs are currently empty); `CatalogSync/Application/GithubRateLimitException` (extends `\RuntimeException`, infra concern); `Payments/Presentation/HelloAssoWebhook{Payload,OrderData,PayerData}` (request-model DTOs - defensible in Presentation); `GameSelection/Infrastructure/{IgdbHttpClientInterface,SteamWebApiClientInterface}` + `Shared/Infrastructure/MinioStorageInterface` (ports colocated with impls; AC-I2 wants them in Application/Shared-Application - but each move touches many services.yaml FQCNs); `Shared/Presentation/RequiresAuthTrait` (trait, not controller); `SessionConfig/Domain/SessionConfigOverrideStore` (concrete class in Domain, plausibly fine).

**NOT violations (record in worklist to prevent over-tidying):** flat sync command/query services in `Application/` (documented convention: only async Message/Handler are sub-namespaced); `Presentation/*Command.php` files are Symfony **console** commands, correctly in Presentation - vs `Application/` CQRS messages. Do not conflate the two "Command" meanings. `Identity/Presentation/Command/ResyncDiscordRolesCommand` sits in a `Presentation/Command/` subfolder (inconsistent with flat-Presentation elsewhere but not a layer violation - audit may accept). Empty dirs (`Legal/*` all four, `Communications/{Domain,Infrastructure,Presentation}`, `Realtime/Domain`) are validator-required scaffolds - leave them.

### Blast radius (verified hazards - the complete move checklist)

- **`config/services.yaml` is the biggest surface: 168 `App\` FQCN occurrences.** Interface→impl aliases (L81-95, L271-381...), explicit `arguments:` keyed by FQCN, `when@dev`/`when@test` doubles (L395-461: `NullRunnerGateway`, `StubIgdb*`, `SpyHub`...). Every moved class = grep services.yaml for its FQCN. The Domain excludes (L60-79) are PATH globs - untouched unless a Domain folder moves (none planned).
- **`config/packages/messenger.yaml` L34-60: 27 routing keys by message FQCN. Moving a message class and forgetting the routing key is SILENT** - the message drops to sync/default, no error. This is the single most dangerous hazard of this story; Task 5.3's before/after `debug:messenger` diff is the tripwire. Also: messages already enqueued in RabbitMQ carry the old FQCN - land the merge when queues are empty (dev/staging: fine; prod deploy note in PR).
- **`config/packages/doctrine.yaml` L16-94:** attribute mappings, `dir` + `prefix` per context Domain. No entity moves are planned; `doctrine:mapping:info` guards regressions.
- **`config/packages/security.yaml:10`** references `App\Identity\Domain\User` - not moving.
- **`src/Schedule.php` L7-17** imports 11 message FQCNs - update `use` lines for moved messages.
- **Merged migrations** import 4 `Community\Domain` classes (see Task 4.3) - immutable, do not move those classes.
- **Tests:** `tests/Unit/{Context}/` mirrors src contexts - move test files with their classes. `tests/Functional/` is flat/feature-based; its `use` imports update mechanically. No `tests/Unit/{Content,Legal,Registrations}` dirs exist (no mirrored tests there).
- **Validator `CONTEXTS` const** is the authoritative context list - no context renames planned, so untouched (except allowlist additions).

### Previous story intelligence (33.1-33.4)

- `composer gates` is the one-command api gate (33.1); isolated runs via `api/scripts/test-isolated.sh <name>` (TEST_TOKEN → `archilan_test_<name>`), Stop hook uses its own `_stophook` DB. Use an isolated DB for the repeated full-suite runs here.
- Story files stay `ready-for-review` after merge; PRs target `develop`; repo merges = merge commits; branch naming `feature/epic-33-story-5-*`.
- 33.2 pattern for audits: the enumerated audit table lives in the story/worklist and is the scope, full stop. Fresh-grep passes beat assumptions (33.2 found doc drift that way).
- 33.4 landed all minor/patch deps - the sweep runs against current deps as the epic sequencing intended.

### Project Structure Notes

- Placement convention (epic): `Domain/` = aggregates, VOs, enums, repository interfaces, domain events+exceptions; `Application/` = command+query services, read DTOs, `{Name}QueryInterface`, `Application/Message/` + `Application/Handler/`; `Infrastructure/` = DBAL/Doctrine/HTTP/MinIO impls + `Null*`/`Stub*`/`Spy*` doubles; `Presentation/` = controllers (+ console commands, per existing convention).
- Diff footprint: `api/src/**` (moves + validator), `api/tests/**` (mirrored moves + validator tests), `config/services.yaml`, `config/packages/messenger.yaml`, `src/Schedule.php`, worklist + story files, optional `api/CLAUDE.md` context-list touch-up. Zero frontend, zero migrations, zero `.github/`.
- `declare(strict_types=1)` in every new/moved file (cs-fixer won't add it); Yoda comparisons; camelCase test methods.

### References

- Epic definition: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` (branch `feature/epic-33-cleanup-and-standards`, commit 1b9e869) - story 33.5 AC, locked decisions (allowlist-in-validator, coverage-first, no behaviour change), Epic 32 ordering constraint, risks.
- Validator + tests: `api/src/Shared/Application/DddArchitectureValidator.php`, `api/tests/Unit/DddArchitectureValidatorTest.php`.
- Layer rules: `api/CLAUDE.md` (AC-D*, AC-A*, AC-I*, AC-P*, CQRS naming table).
- Hazard surfaces: `api/config/services.yaml`, `api/config/packages/messenger.yaml` (L34-60), `api/config/packages/doctrine.yaml` (L16-94), `api/config/packages/security.yaml` (L10), `api/src/Schedule.php` (L7-17), `api/migrations/Version20260618170000.php`, `api/migrations/Version20260622120000.php`.
- Epic 32 state: stashed as `stash@{1}` ("WIP epic-32 session recap") - not started, not merged → AC4 exclusion applies.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-04 | Story created (ultimate context engine analysis): validator rule gaps enumerated from code, folder topology audited across all 18 contexts (4 reference, 7 to tidy, Sessions excluded per Epic 32 constraint), full relocation blast radius mapped (services.yaml 168 FQCNs, messenger routing 27 keys - silent-failure hazard, migrations coupling). Status: ready-for-dev. |
