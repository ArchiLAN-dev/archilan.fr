# Story 33.20: Sessions Taxonomy Migration - Unfreeze the Last Context (api/)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a maintainer of the ArchiLAN codebase,
I want the `Sessions` bounded context migrated to the layer/kind sub-folder taxonomy and its four
validator exemptions emptied,
so that every context obeys the same enforced standard and the "frozen until Epic 32" debt - the last
carve-out in the codebase - is closed for good.

**Unblocked:** Epic 32 merged to `develop` on 2026-07-12 (PR #310). `Sessions` is no longer frozen.

This is a **pure refactor: zero behaviour change.** Every gate must be green and `debug:messenger` must
be **byte-identical** before/after.

## Context - why Sessions was left behind

Stories 33.10 (Command/Query/Exception sub-folders) and 33.11 (no flat files in any layer) migrated
**17 of 18 contexts**. `Sessions` was excluded because Epic 32 (session recap) was about to rewrite it
and concurrent moves guaranteed conflicts. The same freeze then rippled into three later stories, each
of which added a *Sessions-only* exemption rather than touching the frozen context:

| Story | Rule it introduced | Sessions escape hatch |
|---|---|---|
| 33.10/33.11 | layer/kind taxonomy | `UNMIGRATED_TAXONOMY_CONTEXTS = ['Sessions']` |
| 33.15 | inject `ClockInterface`, never `new \DateTimeImmutable()` in Application | `CLOCK_CONSTRUCT_EXEMPT_CONTEXTS = ['Sessions']` |
| 33.16 | no public setters on Domain aggregates (AC-D5) | `AGGREGATE_SETTER_EXEMPT_CONTEXTS = ['Sessions']` |
| 33.17 | Domain aggregates are `final` (AC-D4) | `FINALITY_EXEMPT_CONTEXTS = ['Sessions']` |
| 33.5 | Application must not import Infrastructure | `ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS` (2 entries) |

**This story empties all five.** Each list carries a `TODO epic-32` / `33.20` marker in the validator -
they are the checklist.

---

## ⚠️ FIVE CORRECTIONS TO THE EPIC - READ BEFORE PLANNING

The epic-33 backlog entry for 33.20 is **stale on five points**. All were verified against the code on
2026-07-12. Do not trust the epic where it conflicts with this section.

1. **The migration tooling DOES NOT EXIST.** The epic says *"The 33.10/33.11 tooling
   (`migrate-context.ps1`, `migrate-layer.ps1`, `migrate-domain.ps1`, `fix-uses.ps1`) replays it in one
   pass."* **There are zero `.ps1` files in the repository** (verified repo-wide, and `git log --all
   --diff-filter=A` shows they were never committed). 33.10's own dev record says they were *"left in
   the session scratchpad"* - which is ephemeral and long gone. **Budget for re-writing them** (spec in
   Dev Notes §5).

2. **There is a FIFTH exemption the epic never mentions: `FINALITY_EXEMPT_CONTEXTS`** (story 33.17,
   `DddArchitectureValidator.php:115-125`). Once the entities land in `Domain/Entity/`, the finality
   rule fires. **3 of the 4 Sessions entities are NOT `final`** and must become so:
   `Session` (`Domain/Session.php:11`), `SessionSlot` (`:11`), `SessionRecap` (`:21`).
   `RunAuditLog` (`:11`) is already `final`.

3. **The clock count is "6 files", not "6 sites".** The real population is **16 zero-arg
   `new \DateTimeImmutable()` sites across 6 Application files** (11 of them in `SessionLifecycleManager`
   alone). Plus **2 in Presentation** which are **NOT gated** (the clock rule only scans the Application
   layer) - see §3 for the explicit decision to leave them alone.

4. **`debug:messenger` CAN stay byte-identical - and must.** `Application/ScheduledTask/` **does not
   move**: 33.10 explicitly ruled that *"`Message/`, `Handler/`, and existing ad hoc dirs (`Email/`,
   `ScheduledTask/`) [are] unchanged"*, and the no-flat-file rule only inspects 3-segment paths, so
   4-segment files in `ScheduledTask/` pass untouched. **`messenger.yaml` therefore needs ZERO changes**
   and any `debug:messenger` diff is a bug, not an expected outcome.

5. **Zero migration-pinned classes.** `api/migrations/**` contains **no** `App\Sessions\...` reference
   (only 4 `Community\Domain` imports, the known precedent). **No `FLAT_FILE_CARVE_OUT` entry is needed
   for Sessions** - every class is free to move.

**Sizing:** the epic says `[M]`. The measured reality is **L/XL**: 92 file moves + 16 clock injections +
9 setter renames landing on **51 test call sites** + a port extraction + 5 validator constants + a
validator-test redesign. **Execute in phases, each independently green and committed** (§ Tasks).

---

## Acceptance Criteria

1. **AC1 - Taxonomy applied.** All **92** flat files in `api/src/Sessions/` move into kind sub-folders
   (inventory in Dev Notes §1). The 10 files already in `Application/{Message,Handler,ScheduledTask}/`
   **do not move**.

2. **AC2 - Doctrine mapping follows the entities.** `config/packages/doctrine.yaml` `Sessions` block
   becomes `dir: '%kernel.project_dir%/src/Sessions/Domain/Entity'` +
   `prefix: 'App\Sessions\Domain\Entity'`. `doctrine:mapping:info` still reports **45 entities**.
   *(The validator auto-derives the expected prefix from the allowlist - removing `Sessions` from
   `UNMIGRATED_TAXONOMY_CONTEXTS` mechanically forces this change; the two must land together or the
   gate is red either way.)*

3. **AC3 - Entities are `final`.** `Session`, `SessionSlot`, `SessionRecap` become `final class`
   (AC-D4). `FINALITY_EXEMPT_CONTEXTS` is emptied to `[]`.

4. **AC4 - Clock injected.** All **16** zero-arg `new \DateTimeImmutable()` sites in the 6 Application
   files are replaced by an injected `Psr\Clock\ClockInterface` (`$this->clock->now()`).
   `CLOCK_CONSTRUCT_EXEMPT_CONTEXTS` is emptied to `[]`. The 2 **Presentation** sites are **out of
   scope** (not gated - see §3).

5. **AC5 - Setters become business methods.** The 9 public Domain setters are replaced per the mapping
   in §4 (which **collapses 9 setters into 6 business methods**). All **51 test call sites** + 11 src
   call sites updated. `AGGREGATE_SETTER_EXEMPT_CONTEXTS` is emptied to `[]`.

6. **AC6 - Runner-callback port extracted.** `ArchiveRunJobHandler` and `FetchLogsJobHandler` stop
   importing the concrete `Sessions\Infrastructure\RunnerCallbackClient`; a port interface lands in
   `Application/Port/`, the concrete client implements it, `services.yaml` binds it. Both entries of
   `ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS` are **deleted** (the const becomes `[]`).

7. **AC7 - Allowlist emptied + validator tests rebuilt.** `Sessions` is removed from
   `UNMIGRATED_TAXONOMY_CONTEXTS` (→ `[]`). **`tests/Unit/DddArchitectureValidatorTest.php` has ~8
   fixtures that use `Sessions` *because* it is the frozen context and WILL break** - they are rebuilt
   against a synthetic fixture context (§6). All validator unit tests green.

8. **AC8 - Rector unfrozen.** `rector.php` drops the two skip paths (`__DIR__.'/src/Sessions'`,
   `__DIR__.'/tests/Unit/Sessions'`) and its stale "frozen until Epic 32" rationale. `composer rector`
   is clean (advisory in CI, but the standard is to keep it clean - see the 32.1 precedent).

9. **AC9 - Zero behaviour change, all gates green.**
   `composer gates` green; `lint:container` + `cache:clear` OK; `doctrine:mapping:info` = 45 entities;
   **`debug:messenger` diff is EMPTY (byte-identical)**; full suite green on an isolated DB.

---

## Tasks / Subtasks

Order matters. **Each phase = one commit, fully green before the next.** Recommended sequence
(33.11's proven order: least-coupled layer first, Domain last because `doctrine.yaml` is the riskiest
edit).

- [ ] **T0 - Rebuild the tooling (prerequisite, AC1).** Re-write `migrate-context.ps1` + `fix-uses.ps1`
      to the spec in §5 (word-boundary-safe FQCN rewrite; case-SENSITIVE; `[Environment]::CurrentDirectory`).
      **Commit them into `api/scripts/` this time** - do not leave them in a scratchpad again (that is
      exactly why this story has to redo them). Dry-run on one file first.

- [ ] **T1 - Presentation (AC1).** 32 controllers → `Presentation/Controller/`. Update the 3 `services.yaml`
      controller FQCNs (lines 181, 185, 190, 203). No `Admin/` split (there is none today - keep
      `AdminSessionController` in `Controller/`).

- [ ] **T2 - Infrastructure (AC1).** 14 files → `Doctrine/` (4), `Dbal/` (4), `Http/` (`RunnerGateway`,
      `RunnerCallbackClient`), `Adapter/` (`MinioZip*` ×2, `RawOptionValue`), `Double/` (`NullRunnerGateway`).
      Update `services.yaml` (lines 107-329 incl. the `when@test` `NullRunnerGateway` at 424-425).

- [ ] **T3 - Application moves (AC1).** 36 flat files → `Command/`, `Query/` (incl. the 4 `*QueryInterface`
      - **validator-gated**), `Service/`, `Port/`, `Support/` per §2. `Message/`, `Handler/`,
      `ScheduledTask/` **untouched**.

- [ ] **T4 - Runner-callback port (AC6).** Extract the port, rebind, delete both allowlist entries.
      *(Do this with T3 while you are already in Application.)*

- [ ] **T5 - Domain moves + finality (AC1, AC2, AC3).** 10 files → `Entity/` (4), `Repository/` (4),
      `Exception/` (2). **Same commit:** `doctrine.yaml` `dir` + `prefix`, and `final` on the 3
      non-final entities. `services.yaml:73` (`- '../src/Sessions/Domain/'`) is a **path glob - no change
      needed** (it still covers `Domain/Entity/` recursively).

- [ ] **T6 - Clock injection (AC4).** 16 sites / 6 files. Reference pattern already in the context:
      `Application/Handler/BuildSessionRecapJobHandler.php` (injects `Psr\Clock\ClockInterface`, calls
      `$this->clock->now()`). ⚠️ In `SessionLifecycleManager` the ctor ends with a **defaulted** promoted
      param (`private string $runnerPublicHost = 'localhost'`) - insert `ClockInterface` **before** it.

- [ ] **T7 - Setter → business methods (AC5).** Apply the §4 mapping. **51 test call sites in 9 files** -
      this is the bulk of the work. Push the idempotence guard into the Domain where noted.

- [ ] **T8 - Empty the allowlists + rebuild validator tests (AC3, AC4, AC5, AC6, AC7).** Empty all 5
      constants. Rebuild the ~8 `DddArchitectureValidatorTest` fixtures per §6.

- [ ] **T9 - Rector unfreeze (AC8).** Drop the 2 `rector.php` skip paths; run `composer rector`; fix what
      it reports (expect a batch - Sessions has never been analysed).

- [ ] **T10 - Full battery + PR (AC9).** §7 verification battery. `debug:messenger` diff must be empty.

---

## Dev Notes

### §1 - Measured inventory: 102 files, 92 to move

Verified on `develop` @ 2026-07-12 (post-epic-32).

| Layer | Files | To move | Target |
|---|---|---|---|
| `Domain/` | 10 (all flat) | 10 | `Entity/` 4, `Repository/` 4, `Exception/` 2 |
| `Application/` | 46 (36 flat + 10 sub-foldered) | **36** | `Command/ Query/ Service/ Port/ Support/` |
| `Infrastructure/` | 14 (all flat) | 14 | `Doctrine/ Dbal/ Http/ Adapter/ Double/` |
| `Presentation/` | 32 (all flat, all `*Controller`) | 32 | `Controller/` |
| **Total** | **102** | **92** | |

**Domain (10):**
- → `Entity/`: `Session.php`, `SessionSlot.php`, `RunAuditLog.php`, `SessionRecap.php` *(all `#[ORM\Entity]`)*
- → `Repository/`: `SessionRepositoryInterface.php`, `SessionSlotRepositoryInterface.php`,
  `RunAuditLogRepositoryInterface.php`, `SessionRecapRepositoryInterface.php`
- → `Exception/`: `SessionNotFoundException.php`, `SessionNotRunningException.php` *(validator-gated)*

**Application - the 10 that DO NOT MOVE:** `Handler/` (`ArchiveRunJobHandler`, `BuildSessionRecapJobHandler`,
`FetchLogsJobHandler`, `ResumeRunJobHandler`), `Message/` (`ArchiveRunJob`, `BuildSessionRecapJob`,
`FetchLogsJob`, `ResumeRunJob`), `ScheduledTask/` (`CleanupStaleSessionsTask`, `CleanupStaleSessionsHandler`).

**Infrastructure (14):** `Doctrine{Session,SessionSlot,RunAuditLog,SessionRecap}Repository` → `Doctrine/`;
`Dbal{ActiveRegistration,CommunityStats,Leaderboard,PlayerConnection}Query` → `Dbal/`;
`RunnerGateway` + `RunnerCallbackClient` → `Http/`; `MinioZip{Output,Spoiler}ArtifactReader` +
`RawOptionValue` → `Adapter/`; `NullRunnerGateway` → `Double/`.

### §2 - Application placement (36 flat files)

Only `*QueryInterface` → `Application/Query/` and `*Exception` → `Application/Exception/` are
**validator-gated**. Everything else is the documented convention (reviewer-enforced) - place with
judgment, per `api/CLAUDE.md`:

- **`Query/`** (`NounContext`, + their read DTOs + `{Name}QueryInterface`): `ApworldQuery`,
  `CommunityStatsQuery`, `CommunityStatsQueryInterface`, `LeaderboardQuery`, `LeaderboardQueryInterface`,
  `PlayerConnectionQueryInterface`, `ActiveRegistrationQueryInterface`, `RunResultsQuery`,
  `SessionExportQuery`, `SessionQuery`, `SessionRecapQuery`, `SessionResultsQuery`, `PlayerSessionConnection`
- **`Command/`** (`VerbNoun`): `ForceEndSessionCommand`, `NotifyAllGoalCommand`, `SendBridgeCommand`,
  `RecordSessionGeneratedOutput`, `RecordSlotGoal`
- **`Service/`** (mixed read+write facades / orchestration): `SessionLifecycleManager`,
  `SessionOrchestrator`
- **`Port/`** (infra-facing interfaces): `RunnerGatewayInterface`, `SessionSpoilerArtifactReaderInterface`,
  `SessionOutputArtifactReaderInterface`, `AchievementRecomputeTriggerInterface`,
  `PersonalRunAdvancerInterface`, `SessionReconcilerInterface`, **+ the new runner-callback port (T4)**
- **`Support/`** (helpers, builders, free DTOs): `SlotNameGenerator`, `TraefikConfigBuilder`,
  `SpoilerGraphParser`, `RecapSuperlativesCalculator`, `SpoilerArtifact`, `SessionOutputArtifact`,
  `RecapGraph`, `RecapNode`, `RecapEdge`, `RecapSuperlative`

### §3 - Clock: 16 sites, 6 files (AC4)

**Gated (Application) - all 16 MUST be fixed. None of these classes injects a clock today.**

| File | Sites | Lines |
|---|---|---|
| `Application/SessionLifecycleManager.php` | **11** | 57, 122, 226, 260, 276, 325, 512, 716, 757, 796, 836 |
| `Application/ForceEndSessionCommand.php` | 1 | 43 |
| `Application/NotifyAllGoalCommand.php` | 1 | 36 |
| `Application/SendBridgeCommand.php` | 1 | 73 |
| `Application/SessionOrchestrator.php` | 1 | 315 |
| `Application/ScheduledTask/CleanupStaleSessionsHandler.php` | 1 | 32 |

**NOT gated - DO NOT TOUCH (out of scope, deliberately):** `Presentation/LogsController.php:53` and
`Presentation/SessionActivityController.php:38`. The validator's clock rule **only scans the Application
layer** (`DddArchitectureValidator.php:798-813`). Changing them expands blast radius for zero gate
benefit. `SessionActivityController:38` is a *default-value fallback* overwritten from the request body -
converting it would be actively wrong.

### §4 - Setter → business-method mapping (AC5)

The 9 setters are the **only** holdouts in an otherwise consistent aggregate - `Session` already has
`updateHeartbeat`, `recordActivity`, `markRestarting`, `markIdle`, `markRestartFailed`, `markNotified`,
`storePendingCredentials`; `SessionSlot` already has `markAsReleased`. Follow the vocabulary the other
contexts established in 33.16: `mark*` (state/timestamp), `record*` (event/measurement), `attach*`/`clear*`,
`update*`.

| Current | → Business method | Precedent | Call sites |
|---|---|---|---|
| `Session::setLastLogs(?string)` | `recordLogs(?string)` | - | 3 src |
| `Session::setArchivedSavePath` **+** `setArchivedSpoilerPath` | **merge** → `recordArchive(?string $savePath, ?string $spoilerPath)` | always called adjacently (`SessionLifecycleManager:616-617`) | 1 src |
| `Session::setGeneratedOutputKey(?string)` | `markGenerated(string)` | **`WeeklyRun::markGenerated(string $outputKey)`** (`WeeklyRuns/Domain/Entity/WeeklyRun.php:42`) | 1 src + **3 tests** |
| `Session::setValidationErrors(?array)` | `recordValidationErrors(?array)` | - | 2 src |
| `SessionSlot::setSlotName(string)` | `assignSlotName(string)` | - | 1 src |
| `SessionSlot::setChecksDone` **+** `setItemsReceived` **+** `setGoalReachedAt` | **merge** → `recordGoal(int $checks, int $items, \DateTimeImmutable $goalAt)` **AND** `syncFromArchive(int $checks, int $items, ?\DateTimeImmutable $goalAt)` | **`WeeklyEntry::recordGoal(...)`** (`WeeklyRuns/Domain/Entity/WeeklyEntry.php:73`) | 2 src + **51 tests** |

**Why two methods for the SessionSlot trio:** `RecordSlotGoal.php:70-72` sets all three with a **non-null**
goal (a real goal event → `recordGoal`), while `SessionLifecycleManager::storeArchive` (`:634-645`) must
also be able to set goal **back to null** during archive reconciliation (→ `syncFromArchive`). One method
cannot serve both without a misleading nullable.

⚠️ **`markGenerated` tightens `?string` → `string`.** Every real call site passes a non-null string - safe
and an improvement (matches the `WeeklyRun` precedent).

⚠️ **Push the idempotence guard into the Domain.** `RecordSlotGoal.php:66-68` currently guards in
Application (`if (null !== $slot->getGoalReachedAt()) return;`). Precedent
`Community/Domain/Entity/Notification.php:56` does it in the Domain: `$this->readAt ??= $now;`. Do the
same in `recordGoal`.

⚠️ **51 test call sites, 9 files** - the dominant cost. Many set only a *subset* of the trio (e.g.
`CommunityLeaderboardTest:41` sets only `setChecksDone`), so a collapsed `recordGoal(...)` forces those
tests to pass all three args. Expect to rewrite, not sed:
`CommunityLeaderboardTest` (19), `PlayerProfileTest` (13), `RunResultsTest` (11), `AdminRunArchivalTest` (6),
`SessionRecapEndpointTest` (2), `EventGoalAchievementTest` (2), `BuildSessionRecapJobHandlerTest` (2),
`AdminServerCommandsTest` (1), `SessionSlotMarkAsReleasedTest` (1).

⚠️ **Cross-context test coupling:** `tests/Unit/PersonalRuns/PersonalRunSpoilerDownloadTest.php:125` and
`PersonalRunPatchQueryTest.php:121` reach into the `Sessions` aggregate to call `setGeneratedOutputKey`.

### §5 - Tooling spec (T0) - it does not exist, rebuild it

**`migrate-context.ps1`** (and its layer-scoped variants): `git mv` class files into kind sub-folders **and**
rewrite the FQCN (the `namespace` line + every `use`/FQCN reference across `src/` and `tests/`).

Three hard-won requirements from 33.10/33.11's dev records - **reproduce all of them or you will
reintroduce the exact bugs they hit**:

1. **Word-boundary-safe replace.** A naive FQCN `.Replace()` is prefix-unsafe: moving `ActivateMembership`
   corrupted `ActivateMembershipInterface`. Append a negative lookahead `(?![A-Za-z0-9_])` to the pattern.
2. **Case-SENSITIVE matching.** Use `-cmatch` / `-cnotmatch`, **never** `-match` (PowerShell's `-match` is
   case-insensitive - it wrongly inserted `use ...\Command\ConfirmEmail` into `User.php` because the
   method `confirmEmail()` matched).
3. **`[Environment]::CurrentDirectory`** must be set - .NET APIs in PowerShell do **not** inherit the
   PowerShell working directory.

Also: **exclude `api/config/reference.php`** (gitignored, Flex-regenerated).

**`fix-uses.ps1`**: after the moves, references that were previously *same-namespace* (needing no `use`)
become *cross-namespace* and need one inserted. Three corrections it required:
1. Insert only into the **header `use` block** - never into a heredoc's inner `use`.
2. Guard Domain + cross-context Infrastructure/Presentation to kill docblock false-positives
   (`{@see OtherCtxDbalX}`) that cs-fixer preserves.
3. **Do NOT strip comments** - an earlier version did and it hid legitimate docblock TYPE refs
   (`@param list<X>`) that genuinely need an import.

**Config files: NEVER script them.** `services.yaml` / `doctrine.yaml` are hand-edited with literal
`.Replace()` + throw-on-missing anchors, or the Edit tool. **Never `sed`** (see the repo memory on Windows
shell replaces: sed/antislash is a silent no-op and PowerShell mangles quotes).

### §6 - The validator's own tests WILL break (AC7) - the design problem

`tests/Unit/DddArchitectureValidatorTest.php` uses **`Sessions` as its "unmigrated / frozen context"
fixture** in ~8 places, precisely *because* it is the exempt one. 33.10's dev record admits this:
*"Two validator test fixtures repointed from Events (now migrated) to Sessions (frozen) so the
'unmigrated context' cases stay valid as the allowlist shrinks."*

**Once Sessions migrates there is NO frozen context left**, so these tests have nothing to point at:

| Line | Test / fixture | Breaks how |
|---|---|---|
| 273-286 | `testQueryInterfaceOutsideApplicationIsReported` - `Sessions/Infrastructure/DashboardQueryInterface.php` | asserts the **pre-taxonomy** message; validator will emit the migrated-branch message |
| 311-314 | `Sessions/Application/Handler/ArchiveRunJobHandler.php` importing `RunnerCallbackClient` | the allowlist fixture - both entries are being deleted |
| 434-435 | `Sessions/Application/SessionStamp.php` | flat-file negative case → starts violating |
| 522-540 | `testFrozenContextDomainSetterIsNotReported` (`Sessions/Domain/SessionThing.php`) | asserts **zero** setter violations → will now report one |
| 668-671 | `Sessions/Domain/Entity/Legacy.php` (non-final) | finality negative case, relies on `FINALITY_EXEMPT_CONTEXTS` |
| 915-922 | `Sessions/Domain/CapacityExceededException.php`, `Sessions/Application/DashboardQueryInterface.php` | assert **no** violation → will now violate |
| ~990 | `Sessions/Application/LooseHelper.php` | no-flat-file negative case → starts violating |
| 1039 | `'Sessions'` in the fixture-context list | - |

**Decide and record the fix.** Recommended: introduce a **synthetic fixture context** so the exemption
*mechanism* stays tested without pinning a real context. The constants are `private const` and the tests
write fixtures into a temp `projectDir`, so the cleanest route is to make the exempt-context lists
**injectable via the constructor** (defaulting to the real values) and have the tests pass their own.
That keeps the negative cases alive forever and removes the "repoint the fixture at whatever context is
currently frozen" treadmill that this story is paying for.

### §7 - Verification battery (per phase, all must pass)

```
vendor/bin/phpstan analyse src tests        # FIRST after any move - enumerates every missing `use`
vendor/bin/php-cs-fixer fix                 # THEN style (Rector/moves output is not @Symfony-styled)
php bin/console app:architecture:ddd
php bin/console lint:container
php bin/console cache:clear
php bin/console doctrine:mapping:info       # must be 45 entities
php bin/console debug:messenger             # MUST be byte-identical to the pre-change output
api/scripts/test-isolated.sh story3320      # full suite, isolated DB (never the shared one)
composer gates
```

**Capture `debug:messenger` BEFORE you start** and diff at the end. It is the single best detector of an
accidental `Message/`/`Handler/`/`ScheduledTask/` move.

**Loudest early-warning signal:** if you forget the `doctrine.yaml` edit, ~24 functional tests fail at
schema creation with `Class "App\Sessions\Domain\Entity\Session" is not a valid entity` (they call
`SchemaTool::createSchema([Session::class, ...])`).

### §8 - Blast radius (measured: 375 occurrences of `App\Sessions\` across 162 files)

**Hand-edited config (silent breakage - the compiler will NOT catch these):**
- `config/packages/doctrine.yaml:47-52` - `dir` + `prefix` **(load-bearing)**
- `config/services.yaml` - **24 FQCN occurrences** (lines 107, 109, 113, 115, 134, 145, 167, 173, 177,
  181, 185, 190, 199, 203, 212, 216, 302, 322-329, 424-425). Line 73 is a **path glob - no change**.
  Line 302 is a cross-context alias (Sessions port ← Community adapter).
- `config/packages/messenger.yaml` - **ZERO changes** (see Correction #4).
- `src/Schedule.php:15` - **ZERO changes** (`ScheduledTask/` stays).
- `rector.php:32-33` - drop the 2 skip paths (T9).
- `api/migrations/**` - **ZERO Sessions references. Nothing to do.**

**Compiler-caught (`use` rewrites):**
- **16 `src/` files outside Sessions** import `App\Sessions\...`: PersonalRuns ×9 (heaviest -
  `Domain\Session` + `Domain\SessionRepositoryInterface` are the most-imported pair), GameSelection ×2,
  Streaming ×2, WeeklyRuns ×1, Community ×1, plus root `Schedule.php`.
- **35 `tests/` files** import `App\Sessions\...` (8 in `tests/Unit/Sessions/`, 9 in other `tests/Unit/`
  contexts, 24 in `tests/Functional/`). Per AC-T4, `tests/Unit/{Context}` mirrors the context **flat** -
  **no test files need to move**, only their imports change.

### §9 - What the validator actually gates (do not over-trust CLAUDE.md)

`api/CLAUDE.md` claims `*Controller`, `Doctrine*`, `Dbal*`, `Null*/Stub*/Spy*` and `*RepositoryInterface`
placement are "validator-gated". **They are not.** The real gates are:

| Rule | Gated? |
|---|---|
| no `.php` directly in a layer folder (catch-all) | ✅ `validateNoFlatLayerFiles` |
| `*QueryInterface` → `Application/Query/` | ✅ `validateInterfacePlacement` |
| `*Exception` → `{Domain,Application}/Exception/` | ✅ `validateInterfacePlacement` |
| entity → `Domain/Entity/` | ⚠️ **indirect only** (via the doctrine `prefix` check) |
| `*RepositoryInterface` → `Domain/Repository/` | ❌ only "must be in the Domain **layer**" |
| `*Controller` → `Presentation/Controller/` | ❌ not gated |
| `Doctrine*` / `Dbal*` / `Null*` placement | ❌ not gated |

So a green `app:architecture:ddd` does **not** prove correct placement - the convention is
reviewer-enforced. Place files correctly anyway; do **not** "discover" that a shortcut passes the gate.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` § "(c) Carve-out"
  → **stale on 5 points, see the Corrections section above.**
- Prior art (the recipe + the Windows lessons): `33-10-layer-subfolder-taxonomy.md` (esp. its Dev Agent
  Record: the 2 automation bugs), `33-11-full-subfolder-taxonomy.md` (esp. the 3 `fix-uses` corrections
  and the layer-batch order).
- Carve-outs being closed: `33-15-clockinterface-migration.md`, `33-16-domain-setters-business-methods.md`,
  `33-17-ddd-validator-remaining-acs.md`.
- Standards: `api/CLAUDE.md` § "CQRS naming and layer sub-folder taxonomy", AC-D4, AC-D5, AC-A2.
- Validator: `api/src/Shared/Application/Support/DddArchitectureValidator.php` (the 5 constants are the
  checklist; each carries a `TODO epic-32` / `33.20` marker).
- Epic 32 (what just landed in Sessions and must migrate with it):
  `_bmad-output/implementation-artifacts/32-1-public-session-recap.md`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-12 | Story created. Unblocked by Epic 32 (PR #310 merged). Grounded in a full re-measure of the code: 102 Sessions files (92 to move), 16 clock sites in 6 Application files, 9 setters landing on 51 test call sites, 375 `App\Sessions\` occurrences across 162 files. **Five corrections to the epic recorded**: the migration tooling was never committed (must be rebuilt), a fifth exemption (`FINALITY_EXEMPT_CONTEXTS`) exists and 3 entities are not `final`, the "6 clock sites" are 6 *files* / 16 sites, `ScheduledTask/` does not move so `debug:messenger` must stay byte-identical, and zero Sessions classes are migration-pinned. The validator's own test suite uses Sessions as its frozen-context fixture and needs a redesign (§6). Re-sized M → L/XL, phased execution. Status: ready-for-dev. |
