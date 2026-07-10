# Story 33.16: Domain Aggregate Setters -> Business Methods (api/)

Status: ready-for-dev

## Story

As a maintainer of the api's Domain layer,
I want the public `set*` setters on Domain aggregates replaced by named business methods,
so that state changes communicate intent (AC-D5: `markAsReleased()`, `publish()`, `cancel()`...), invariants have a natural home, and the validator can enforce the no-public-setters rule by construction.

## Context

Fresh scan (2026-07-10, matches the 33.5/33.6 audit count exactly): **25 public `set*` methods on 12
Domain entities**. 9 of them live in the frozen `Sessions` context (`Session` x5, `SessionSlot` x4) and
are **excluded** (Epic 32 carve-out, same as 33.11/33.15; they join the 33.20 follow-up). **In scope: 16
setters on 10 entities across 7 contexts.** Several already take `$now` and contain guard/normalization
logic - they are business methods with a setter name; for those the change is a rename.

This is a behaviour-preserving rename story: no signature semantics change, no new invariants beyond what
the setter already enforced. PHPStan (level max) catches any missed caller, so the mechanical risk is low;
the epic's coverage-first rule still applies (Task 1).

## Acceptance Criteria

1. **AC1 - Worklist + coverage confirmed first.** The worklist below (every setter, its replacement, its
   callers) is validated against the current tree at implementation start; for each setter, the covering
   test is identified, and where none exercises the behaviour (directly or through its Application
   service), a small characterization test is added BEFORE the rename.
2. **AC2 - Setters replaced, callers updated.** Every in-scope `set*` method is renamed to its business
   method (or split into an explicit pair where the null-means-clear duality warrants it); all src + tests
   callers updated. Zero behaviour change: bodies keep their exact logic (guards, trims, normalizations,
   `updatedAt` touches).
3. **AC3 - Validator enforces AC-D5.** `DddArchitectureValidator` gains a rule flagging
   `public function set{Upper}` in Domain entity classes, with `Sessions` exempt via a dedicated named
   const (pattern: `CLOCK_CONSTRUCT_EXEMPT_CONTEXTS` from 33.15). Unit tests: violation reported,
   non-setter method not reported, exempt context not reported, non-public setter not reported.
4. **AC4 - Sessions excluded.** The 9 `Session`/`SessionSlot` setters are untouched and recorded in the
   33.20 scope (Sessions taxonomy + setters in one pass once Epic 32 merges).
5. **AC5 - All gates green; zero behaviour change.** `composer gates` green (phpstan, cs-fixer src+tests,
   `app:architecture:ddd`, full phpunit on an isolated DB).

## Worklist (16 setters, 10 entities, 7 contexts)

Proposed names follow the codebase's existing verbs (`record*` as in `recordApworldCheck`, `link*` as in
`linkDiscord`, `update*` as in `update()`, `attach*`, `clear*` as in `clearCoverImageKey`). The dev may
refine a name during implementation if a better verb emerges - intent-revealing beats literal.

| # | Entity (file) | Setter | Proposed replacement | Callers (src) | Notes |
|---|---|---|---|---|---|
| 1 | `Content\Domain\Entity\Post` | `setCoverImageKey(string $key, ?\DateTimeImmutable $now = null)` | `attachCoverImage(string $key, \DateTimeImmutable $now)` | `UploadPostCoverImageCommand:41` | Sole caller always passes the clock: drop the `= null` default while renaming (still zero behaviour change). Symmetric with existing `clearCoverImageKey` - leave that one as is (already a named method). |
| 2 | `Events\Domain\Entity\Event` | `setCoverImageKey(string $key, ?\DateTimeImmutable $now = null)` | `attachCoverImage(string $key, \DateTimeImmutable $now)` | `UploadEventCoverImageCommand:39` | Same shape and same treatment as Post. |
| 3 | `Events\Domain\Entity\Event` | `setHelloassoFormSlug(?string $slug, \DateTimeImmutable $now)` | `linkHelloassoForm(?string $slug, \DateTimeImmutable $now)` | `AdminEventDrafts:87,140` | Body trims + null-normalizes; null unlinks. 5 functional tests call it directly (see test callers below). |
| 4 | `Registrations\Domain\Entity\Registration` | `setSlotPlayerYaml(string $slotId, string $playerYaml, string $apworldHash, \DateTimeImmutable $now)` | `submitSlotPlayerYaml(...)` (same params) | `RegistrationGameSelection:220` | Already a real business method: guards `isReserved()`, throws on unknown slot, touches `updatedAt`. Rename only. |
| 5 | `PersonalRuns\Domain\Entity\RunParticipant` | `setSlotPlayerYaml(string $slotId, string $playerYaml, string $apworldHash)` | `submitSlotPlayerYaml(...)` (same params) | `PersonalRunGameSelection:293` | Mirror of #4 (no `$now`, no reserved-guard). Keep the two names identical for the parallel. |
| 6 | `PersonalRuns\Domain\Entity\Run` | `setSessionId(string $sessionId)` | `attachSession(string $sessionId)` | `LaunchPersonalRunJobHandler:142` | Widest test fan-out (7 test call sites). |
| 7 | `GameSelection\Domain\Entity\GameCatalogSync` | `setApworldDeployedVersion(?string $version)` | `recordApworldDeployment(?string $version)` | `AdminGameLibrary:413` (via `getCatalogSync()?->`) | Body normalizes the `v`/`V` prefix - matches sibling `recordApworldCheck`. |
| 8 | `GameSelection\Domain\Entity\Game` | `setApworldMinioKey(string $key)` | `recordApworldMinioUpload(string $key)` | `AdminGameLibrary:306` | Called after the MinIO upload succeeds. |
| 9 | `GameSelection\Domain\Entity\Game` | `setOptionTypes(?array $optionTypes)` | `recordOptionTypes(?array $optionTypes)` | `AdminGameLibrary:307`, `BackfillGameOptionTypes:46` | Body normalizes empty -> null. Values come from apworld analysis. |
| 10 | `GameSelection\Domain\Entity\Game` | `setInstallSteps(?array $steps)` | `updateInstallSteps(?array $steps)` | `AdminGameLibrary:62,84`, `ModerateGameTutorialContribution:67`, `SeedGameTutorials:50` | Body normalizes empty -> null. |
| 11 | `GameSelection\Domain\Entity\Game` | `setAvailabilityLocked(bool $locked)` | `lockAvailability()` / `unlockAvailability()` pair, caller branches | `AdminGameLibrary:195` | Boolean-flag setter: the pair reads better and the single caller receives a parsed bool - a one-line ternary-free `if/else` at the call site. If the dev judges the branch noisier than the gain, `updateAvailabilityLock(bool)` is the fallback; do not keep `set*`. Note `updateCatalogueMetadata()` also assigns `$this->availabilityLocked` internally - internal assignment is fine, only the public setter goes. |
| 12 | `GameSelection\Domain\Entity\Game` | `setCatalogSync(GameCatalogSync $sync)` | `attachCatalogSync(GameCatalogSync $sync)` | `GameCatalogSync::__construct:46` (owning-side wiring), `AdminGameLibrary:149,206` | Doctrine bidirectional association wiring - the constructor of `GameCatalogSync` calls it on `$game`. Rename only; do NOT restructure the association or dedupe the AdminGameLibrary calls (out of scope, behaviour-preserving). |
| 13 | `Community\Domain\Entity\AchievementDefinition` | `setCustomImage(?string $key, \DateTimeImmutable $now)` | `updateCustomImage(?string $key, \DateTimeImmutable $now)` | `AdminAchievementService:120` | Null clears (doc-commented). Single caller handles both directions - keep one method. |
| 14 | `Community\Domain\Entity\AchievementDefinition` | `setActive(bool $active, \DateTimeImmutable $now)` | `activate(\DateTimeImmutable $now)` / `deactivate(\DateTimeImmutable $now)` pair | `AdminAchievementService:134` | CAUTION: `AdminAchievementService` ALSO has a public `setActive(string $id, bool $active)` method, called from `AdminAchievementController:176` and asserted in `AdminAchievementServiceTest:118,120`. That is an Application service method - OUT of AC-D5 scope, leave its name alone; only the entity method changes. `RecomputeAchievementsTest:117` calls the entity method directly. |
| 15 | `Community\Domain\Entity\CommunityProfile` | `setCustomAvatar(?string $key, \DateTimeImmutable $now)` | `uploadCustomAvatar(string $key, \DateTimeImmutable $now)` + `removeCustomAvatar(\DateTimeImmutable $now)` pair | `CommunityAvatarService:41` (set), `:59` (null = clear) | The two call sites map 1:1 onto the pair (story 30.27 semantics: upload overrides, clear falls back). Split removes the nullable-key ambiguity. |
| 16 | `Identity\Domain\Entity\User` | `setSteamProfile(?string $steamProfile)` | `updateSteamProfile(?string $steamProfile)` | `SaveSteamAccount:34` (set), `:49` (null) | Body trims + empty -> null; a single method keeps that normalization in one place (the null call is "clear", covered by the same normalization). |

**Excluded (Sessions, frozen until Epic 32 - record in 33.20):** `Session::setLastLogs`,
`setArchivedSavePath`, `setArchivedSpoilerPath`, `setGeneratedOutputKey`, `setValidationErrors`;
`SessionSlot::setSlotName`, `setChecksDone`, `setItemsReceived`, `setGoalReachedAt`.

**Test callers to update (from the same scan):** `RecomputeAchievementsTest`, `AdminApworldMinioTest`,
`SaveSteamAccountTest`, `StopPersonalRunJobHandlerTest`, `ReconcileStuckRunsHandlerTest`,
`RunParticipantTest`, `PersonalRunPatchQueryTest`, `PersonalRunSpoilerDownloadTest`, `RunCompleteTest`,
`BackfillGamePlatformsTest`, `BackfillSteamAppIdsTest`, `AdminGameContributionModerationTest`,
`SeedGameTutorialsTest`, `AdminPaymentStatusTest`, `AdminRegistrationDashboardTest`,
`AdminRegistrationExportTest`, `AdminSyncStatusTest`, `HelloAssoCheckoutTest`, `HelloAssoSyncTest`,
`OrchestratorWebhookTest`, `PersonalRunLifecycleTest`, `PersonalRunParticipantGameSelectionTest`,
`PublicGameDetailTest`, `RegistrationSlotYamlTest`, `RunnerValidatePipelineTest`, `SessionLifecycleTest`
(the `Registration` call at :433 - the `Session` entity's own setters stay).

## Tasks / Subtasks

- [ ] Task 1: Re-validate the worklist against the tree; per setter identify the covering test; add a
  characterization test where a setter's behaviour (guards / normalization / `updatedAt` touch) has no
  direct or service-level coverage - notably `Post::setCoverImageKey`, `Event::setCoverImageKey`,
  `CommunityProfile::setCustomAvatar`, `GameCatalogSync::setApworldDeployedVersion`,
  `Game::setAvailabilityLocked` did not surface in the test-caller scan (they may be covered via their
  Application service tests - confirm, and only add tests where truly uncovered) (AC: 1)
- [ ] Task 2: Migrate in per-context batches (mirrors 33.15's commit shape): GameSelection (6) ->
  Community (3) -> Events (2) -> PersonalRuns (2) -> Content + Registrations + Identity (3) - rename
  entity method, update src callers, update test callers, run the touched suites (AC: 2)
- [ ] Task 3: Add the validator rule (`public function set{Upper}` in Domain entities; Sessions exempt
  via a named const) + its unit tests; verify it passes on the migrated tree and would fail on a
  reintroduced setter (AC: 3)
- [ ] Task 4: Record the 9 Sessions setters in the 33.20 follow-up scope (epic file note or 33.20 story
  when created) (AC: 4)
- [ ] Task 5: `composer gates` on an isolated DB; PR to develop (AC: 5)

## Dev Notes

- **Pure rename mechanics.** Doctrine hydrates properties by reflection, not setters - renaming a method
  never touches mapping or migrations. No `services.yaml` change (entity methods are not services). No
  FQCN changes, so none of the 33.10/33.11 blast-radius concerns (DI autowiring, excludes) apply.
- **PHPStan is the caller-completeness net.** After each batch, `vendor/bin/phpstan analyse src tests`
  reports any missed call site as an undefined-method error. Do not rely on grep alone.
- **Validator rule placement.** New private method in `DddArchitectureValidator` (e.g.
  `validateDomainAggregateSetters`), scanning Domain layer PHP files for `public function set[A-Z]`.
  Exempt const e.g. `AGGREGATE_SETTER_EXEMPT_CONTEXTS = ['Sessions']` - dedicated const, NOT coupled to
  `UNMIGRATED_TAXONOMY_CONTEXTS` (same decision as 33.15's `CLOCK_CONSTRUCT_EXEMPT_CONTEXTS`). Scan scope:
  the whole `Domain/` layer, not just `Domain/Entity/` - Sessions' entities still sit flat
  (`src/Sessions/Domain/Session.php`), and a setter on a VO or domain service would be just as wrong.
- **Validator self-match trap (33.15 lesson).** Do not put a matchable literal like a `set{Upper}` example
  in the rule's doc-comment or in the validator's own strings - the 33.15 clock rule initially flagged its
  own source file this way. Describe the form in words.
- **Scope discipline.** Application-layer `set*` methods (e.g. `AdminAchievementService::setActive`) are
  NOT in AC-D5 scope - do not rename them, do not extend the validator to Application. If a rename
  tempts you to "improve" a setter body (dedupe the double `setCatalogSync` wiring, merge
  `setApworldMinioKey`+`setOptionTypes` into one method), don't - zero behaviour change, bounce ideas to
  the story record instead.
- **cs-fixer / test naming.** Renamed test methods referencing the old setter name (e.g.
  `testSetSlotPlayerYaml*` in `RunParticipantTest`) should be renamed to the new business name, camelCase
  (AC-T5). Keep assertions identical.
- **Windows execution lessons apply** (memory: sed/backslash replaces are silent no-ops in PowerShell) -
  prefer the Edit tool per file; batch-wide grep only to verify zero leftovers
  (`grep -rn "setSlotPlayerYaml\|setSessionId\|..." src tests` must end empty for in-scope names,
  excluding Sessions).

### Project Structure Notes

- Entities live in `Domain/Entity/` post-33.11 taxonomy (Sessions excepted, flat, frozen). Only method
  names change in this story - no file moves.
- Validator: `api/src/Shared/Application/Support/DddArchitectureValidator.php`; its tests:
  `api/tests/Unit/DddArchitectureValidatorTest.php` (3 clock-rule tests from 33.15 show the expected
  test shape).

### References

- Epic definition: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md`
  (story 33.16 entry + follow-up backlog intro)
- Audit origin: `_bmad-output/implementation-artifacts/33-6-audit-worklist.md` section D (25 setters /
  12 entities); `33-5-audit-worklist.md` section D (AC-D5 as a candidate validator rule)
- Rule text: `api/CLAUDE.md` AC-D5 ("No public setters on aggregates. State changes happen only through
  named business methods")
- Prior art for the exempt-const + rule-test pattern: story `33-15-clockinterface-migration.md`
  (Dev Agent Record: validator self-match trap, dedicated exempt const decision)

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-10 | Story created (epic-33 follow-up 33.16). Fresh scan confirms the audit's 25 setters / 12 entities; 9 Sessions setters excluded (Epic 32 freeze), 16 in scope across 7 contexts. Full worklist with proposed business names, src + test callers enumerated; validator rule (Sessions-exempt, dedicated const) specified with the 33.15 self-match lesson. Status: ready-for-dev. |
