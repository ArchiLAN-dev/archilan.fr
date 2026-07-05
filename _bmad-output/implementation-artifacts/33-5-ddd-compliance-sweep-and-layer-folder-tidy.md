# Story 33.5: DDD Compliance Sweep + Layer-Folder Tidy (api/)

Status: ready-for-review

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

- [x] Task 1: Produce and commit the audit worklist (AC: 1)
  - [x] 1.1 Verify each seeded misplacement candidate (Dev Notes "Folder-tidy candidates") by reading the file: classify CQRS message vs command service vs console command (see the two-meanings-of-"Command" warning), confirm handler attributes (`#[AsMessageHandler]`), decide move vs accept. → Done; verdicts in worklist sections B/C (ArchilanMailer, GithubRateLimitException, webhook DTOs, SessionConfigOverrideStore, RequiresAuthTrait all accepted with rationale).
  - [x] 1.2 Finalize the new-rule list and each rule's exact detection pattern. → 8 rules (R1-R8) in worklist section A; clock rule scoped to the documented letter (`date()`/`time()`/`rand()`) after discovering ~130 established zero-arg `new \DateTimeImmutable()` occurrences - that migration is recorded as a future story (section D), not absorbed here.
  - [x] 1.3 Enumerate ALL current cross-context imports and classify. → ~270 imports, ALL target other contexts' Domain/Application only; rule R1 (no cross-context Infrastructure/Presentation, Shared exempt) has zero `use`-level violations; one docblock FQCN found and fixed.
  - [x] 1.4 Commit the worklist before any src/ change. → `606826b`.
- [x] Task 2: Extend `DddArchitectureValidator` + tests (AC: 2)
  - [x] 2.1 `createNativeQuery` added to `FORBIDDEN_PRESENTATION_CALLS`.
  - [x] 2.2 Interface-placement rules added (`validateInterfacePlacement`). Baseline zero misplacements.
  - [x] 2.3 Cross-context rule added (`validateCrossContextLayerImports`: no other context's Infrastructure/Presentation, Shared exempt) + Domain upward-import check extended to ALL contexts (`forbiddenDomainDependencies` now covers the 18 contexts).
  - [x] 2.4 Application purity rules added (`validateApplicationPurity`): no Infrastructure dependency (except Shared), no `new` on Infrastructure FQCNs, no `date()`/`time()`/`rand()`/`mt_rand()`. Real violations found and fixed: `time()` in `AuthSessionSigner` + `DiscordStateToken` → `Psr\Clock\ClockInterface` injected (symfony/clock already installed, autowired, zero non-DI construction sites).
  - [x] 2.5 Named allowlist const `ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS` (2 frozen Sessions handlers, commented TODO epic-32). No inline suppressions anywhere.
  - [x] 2.6 12 new test methods in `DddArchitectureValidatorTest` (violations detected, allowlisted file passes, lookalikes like `->update(`/`strtotime(`/`new \DateTimeImmutable($param)` NOT flagged). 22/22 green.
- [x] Task 3: Folder tidy - CQRS Message/Handler normalisation (AC: 2, 3)
  - [x] 3.1 `Identity`: 4 handlers moved `Message/` → `Handler/`.
  - [x] 3.2 `Events`: `CleanupEventPrivateAccessLogHandler` → `Handler/`; `EventCapacityReachedHandler`/`Message` split into sub-namespaces.
  - [x] 3.3 `Payments`: `CleanupHelloAssoSyncLogHandler` → `Handler/`; `SyncHelloAssoFormHandler`/`Message` split into sub-namespaces.
  - [x] 3.4 `Communications`: `Message/` + `Handler/` created; 6 flat pairs moved.
  - [x] 3.5 All FQCNs rewritten (literal replace) across src/tests/config; 8 messenger.yaml routing keys updated; formerly-same-namespace short references resolved with explicit `use` (14 files); `Schedule.php` unaffected (its imports already targeted `Message\` FQCNs that did not move).
- [x] Task 4: Folder tidy - remaining audit items (AC: 2, 3)
  - [x] 4.1 Borderline verdicts (worklist B/C): ports moved to Application (`IgdbHttpClientInterface`, `SteamWebApiClientInterface` + `SteamApiException`, `DiscordOAuthClientInterface`, `TwitchApiClientInterface`); `HelloAssoClientInterface` extracted (concrete `HelloAssoHttpClient` was referenced by Application - AC-I2 violation); ArchilanMailer/GithubRateLimitException/webhook DTOs/`MinioStorageInterface`/`RequiresAuthTrait` accepted with rationale.
  - [x] 4.2 Flat sync command/query services stay flat - recorded (worklist C7).
  - [x] 4.3 Community\Domain migration-coupled classes untouched - recorded (worklist, Task 4.3 note).
  - [x] 4.4 `api/CLAUDE.md` known-contexts list aligned to the validator's 18 (authoritative list referenced).
- [x] Task 5: Blast-radius verification (AC: 3)
  - [x] 5.1 `lint:container` → OK (no stale FQCN).
  - [x] 5.2 `cache:clear` OK; `doctrine:mapping:info` → 45 entities still discovered.
  - [x] 5.3 `debug:messenger` before/after diff → perfect 1:1 rename map: 8 moved messages and 13 moved handlers all present under new FQCNs, none dropped, every message keeps exactly its handler.
  - [x] 5.4 Full functional suite on isolated DB (`test-isolated.sh story335`) + async flows exercised by CapacityNotificationTest / EmailConfirmationTest / PasswordResetTest / HelloAssoSyncHandlerTest / SessionLifecycleTest (dispatch → transport → handler on the moved classes); dev-env routing verified via the debug:messenger diff (real AMQP routing config).
- [x] Task 6: Final gates + PR (AC: 4, 5)
  - [x] 6.1 `composer gates` green (with the stricter arch gate); `pnpm gates` green (frontend untouched, regression check).
  - [x] 6.2 Full suite run twice on the isolated DB - both green.
  - [x] 6.3 PR opened to `develop`; body states the Sessions exclusion and links the worklist. Commits: worklist → rules+fixes → tidy → docs/story.

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

Claude Fable 5 (claude-fable-5)

### Debug Log References

- Audit scans (2026-07-04/05): cross-context imports (~270, all Domain/Application targets), clock calls (`date(`/`time()`/`rand(` regexes with lookbehind - initial regex was broken and hid ~130 zero-arg `new \DateTimeImmutable()` occurrences, rescanned and rescoped), interface placement (0 misplaced), `createNativeQuery` (0 usages).
- Windows note: `sed`-based FQCN rewrites silently no-op'd (backslash mangling in the exec layer); all renames redone with PowerShell literal `[string].Replace()` + per-file anchored asserts (throw on missing anchor).
- New arch gate first run on real repo: 3 genuine findings - docblock `{@see \...}` cross-context FQCN (Streaming), `time()` x2 (Identity Application) - all fixed, gate green.
- `debug:messenger` before/after diff: 1:1 rename map, 8 messages + 13 handlers, zero dropped.
- Verification: `lint:container` OK, `cache:clear` OK, `doctrine:mapping:info` 45 entities, phpstan 0, cs-fixer 0, `app:architecture:ddd` exit 0, unit suite 555/555, full suite on `archilan_test_story335` run 1: OK (1453 tests, 10236 assertions, 06:59) - run 2: OK (see Completion Notes), `pnpm gates` exit 0.

### Completion Notes List

- **Rules track (commit `dc6915a`):** 8 rules added to `DddArchitectureValidator` - cross-context Infrastructure/Presentation ban (Shared exempt), Domain upward imports vs ALL 18 contexts, `*RepositoryInterface`→Domain, `*QueryInterface`→Application, Application must not depend on / instantiate Infrastructure (named allowlist: 2 frozen Sessions handlers, TODO epic-32), no `date()`/`time()`/`rand()`/`mt_rand()` in Application, `createNativeQuery` added to Presentation forbidden calls. 12 new unit tests (violation + allowlist + lookalike-negative cases).
- **Fixes the rules forced:** 5 port files moved out of Infrastructure into Application (Igdb, SteamWebApi + SteamApiException, DiscordOAuth, TwitchApi); `HelloAssoClientInterface` extracted in Payments/Application (concrete client was injected into 2 Application services - AC-I2); `time()` → injected `Psr\Clock\ClockInterface` in `AuthSessionSigner`/`DiscordStateToken` (symfony/clock already a dependency, no new package); 1 docblock FQCN rephrased.
- **Tidy track (commit `6a29db8`):** 22 files moved into `Application/Message/` + `Application/Handler/` across Identity, Events, Payments, Communications; 8 messenger.yaml routing keys rewritten; 14 files given explicit `use` statements for formerly-same-namespace references; services.yaml alias updated.
- **Scope decision made during audit:** the epic-named "no clock in Application" rule is enforced at the documented letter (`date()`/`time()`/`rand()` - root CLAUDE.md wording). Zero-arg `new \DateTimeImmutable()` (~130 occurrences, every context) is recorded in the worklist section D as a future ClockInterface-migration story - enforcing it here would have ballooned a tooling story into a 60-file behaviour-sensitive refactor.
- **AC4 respected:** zero Sessions file moved; Sessions edits limited to import-line updates for OTHER contexts' moved classes (SessionLifecycleManager) and the two allowlist entries.
- **Zero behaviour change:** full suite green twice on an isolated DB; messenger routing verified by before/after diff (the silent-failure hazard called out in Dev Notes); no route, schema, or message semantics change.

### File List

- `_bmad-output/implementation-artifacts/33-5-audit-worklist.md` (new - the AC1 audit, scope of record)
- `_bmad-output/implementation-artifacts/33-5-ddd-compliance-sweep-and-layer-folder-tidy.md` (this story)
- `api/src/Shared/Application/DddArchitectureValidator.php` (8 new rules, allowlist const)
- `api/tests/Unit/DddArchitectureValidatorTest.php` (12 new tests)
- Moved (rules track): `api/src/GameSelection/{Infrastructure→Application}/IgdbHttpClientInterface.php`, `SteamWebApiClientInterface.php`, `SteamApiException.php`; `api/src/Identity/{Infrastructure→Application}/DiscordOAuthClientInterface.php`; `api/src/Streaming/{Infrastructure→Application}/TwitchApiClientInterface.php`
- New: `api/src/Payments/Application/HelloAssoClientInterface.php`
- Modified (rules track): `api/src/Payments/Infrastructure/HelloAssoHttpClient.php` (implements port), `api/src/Payments/Application/{HandleHelloAssoWebhook,SyncHelloAssoFormHandler}.php` (depend on port), `api/src/Identity/Application/{AuthSessionSigner,DiscordStateToken}.php` (ClockInterface), `api/src/Streaming/Infrastructure/DbalParticipantTwitchLinksQuery.php` (docblock), 7 Infrastructure impls/stubs + ~15 importers/tests (FQCN updates), `api/config/services.yaml`
- Moved (tidy track, 22 files): Identity 4 handlers → `Application/Handler/`; Events `CleanupEventPrivateAccessLogHandler` + `EventCapacityReachedHandler` → `Handler/`, `EventCapacityReachedMessage` → `Message/`; Payments `CleanupHelloAssoSyncLogHandler` + `SyncHelloAssoFormHandler` → `Handler/`, `SyncHelloAssoFormMessage` → `Message/`; Communications 6 handler+message pairs → `Handler/` + `Message/`
- Modified (tidy track): `api/config/packages/messenger.yaml` (8 routing keys), dispatchers/tests with updated imports (RequestPasswordReset, SendEmailConfirmation, RegistrationSubmission, ReserveRegistration, SessionLifecycleManager, TriggerHelloAssoSync, AdminSyncHelloAssoController + 12 test files)
- `api/CLAUDE.md` (known-contexts list aligned to the validator's 18)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-04 | Story created (ultimate context engine analysis): validator rule gaps enumerated from code, folder topology audited across all 18 contexts (4 reference, 7 to tidy, Sessions excluded per Epic 32 constraint), full relocation blast radius mapped (services.yaml 168 FQCNs, messenger routing 27 keys - silent-failure hazard, migrations coupling). Status: ready-for-dev. |
| 2026-07-05 | Story executed: worklist committed first (`606826b`), validator extended with 8 rules + fixes (`dc6915a`), Message/Handler tidy across 4 contexts (`6a29db8`), docs + story records. 3 real violations found by the new rules and fixed; clock rule scoped to documented letter with the DateTimeImmutable migration recorded as future work. All gates green (api suite 1453 tests x2 on isolated DB, frontend gates exit 0). Status → ready-for-review. |
