# Story 33.6: Symfony 7 Best-Practices Pass (api/)

Status: ready-for-review

## Story

As a maintainer of the api,
I want every concrete deviation from Symfony 7 / repo best practices enumerated and either fixed or explicitly accepted,
so that the codebase's conformance floor rises with zero behaviour change and nothing open-ended.

## Acceptance Criteria

1. **AC1 - Audit checklist committed first.** A worklist (`_bmad-output/implementation-artifacts/33-6-audit-worklist.md`) enumerating every concrete item with a file reference is committed before any code change. The pre-scanned findings in Dev Notes are the seed; the worklist confirms/extends/rejects each and IS the story scope. Nothing outside it gets touched.
2. **AC2 - Every checklist item resolved or accepted.** Each item is fixed, or accepted with a one-line rationale recorded in the worklist. At minimum the following are RESOLVED: the `MembershipVoter::voteOnAttribute()` deprecation (the only runtime deprecation - `debug:container --deprecations` reports 0 afterwards), the 3 dead `@deprecated` Identity shim classes deleted, the orphaned `config/reference.php` deleted, the 3 `final readonly` promotions applied, `final` added to the non-final ORM entities outside `Sessions` (subject to the lazy-proxy feasibility check in Task 4).
3. **AC3 - Coverage confirmed before each change; all gates green.** Behaviour-relevant changes (entity `final`, test-double refactor) are validated by the full functional suite on an isolated DB; `composer gates` and `pnpm gates` pass; zero behaviour change (no route, response shape, schema, or message semantics change).

## Tasks / Subtasks

- [x] Task 1: Produce and commit the audit worklist (AC: 1)
  - [x] 1.1 Seeded findings confirmed at develop = 8648f4c; dispositions recorded (7 fixes, 9 accepted, 2 out-of-scope). → `fa33acd`.
  - [x] 1.2 Entity-final feasibility PROVEN: DoctrineBundle 3.x forces `enable_native_lazy_objects: true` (Configuration.php:367 - "can no longer be disabled"); no proxy dir in var/cache; native lazy objects support final entities. Verdict: all 12 safe.
  - [x] 1.3 Zero references to the 3 shims confirmed (all callers use interfaces; services.yaml wires only `Dbal*`).
  - [x] 1.4 Static-mutating tests enumerated: 12 files. Deeper finding: `KernelBrowser` reboots the kernel between requests - the statics are the deliberate cross-reboot state carrier. Task 5 refactor REJECTED at audit (fallback 5.3 exercised early); accepted with rationale (worklist C1).
  - [x] 1.5 Worklist committed before any src/config change.
- [x] Task 2: Close the deprecation surface (AC: 2)
  - [x] 2.1 `?Vote $vote = null` param added to `MembershipVoter::voteOnAttribute()`; voter covered by RBAC/membership functional tests.
  - [x] 2.2 `debug:container --deprecations` → "There are no deprecations in the logs!".
- [x] Task 3: Delete dead code (AC: 2)
  - [x] 3.1 3 shims deleted.
  - [x] 3.2 `config/reference.php` removed from VCS - EXECUTION DISCOVERY: Symfony Flex regenerates it on composer operations, so it is also gitignored and the cs-fixer exclusion is KEPT (plan adjusted, worklist F3 updated).
  - [x] 3.3 `validator.yaml` dead scaffold comment deleted; other Flex scaffold comments accepted (worklist C3).
- [x] Task 4: Class-modifier tidy (AC: 2, 3)
  - [x] 4.1 `final` added to all 12 entities (Identity x8, GameSelection x4); `Session`/`SessionSlot` deferred with TODO epic-32. Prerequisite discovered and resolved: 15 `createStub(User::class)` + 3 `createStub(Game::class)` sites in unit tests (PHPUnit cannot double final classes AND they violated AC-T2) - all 18 converted to real instances via local helpers. Bonus: `User::getPassword()` narrowed to `string` (phpstan proved the null branch dead once final).
  - [x] 4.2 3 `final readonly` promotions applied.
  - [x] 4.3 Full functional suite green on isolated DB (`archilan_test_story336`).
- [x] Task 5: Static mutable state in test doubles (AC: 2, 3)
  - [x] 5.1-5.3 Resolved via the audit (1.4): refactor rejected with recorded rationale - static state is the deliberate cross-kernel-reboot carrier for multi-request functional tests; instance state would silently break them; doubles are `when@test`-only; production code has zero static mutable state (verified). Worklist C1; future candidate only if tests move to single-request patterns.
- [x] Task 6: PHPStan extensions (AC: 2) **[human approval obtained in-session]**
  - [x] 6.1 Jean approved; `phpstan/extension-installer` + `phpstan-symfony` + `phpstan-doctrine` installed (partial composer update; a local em-dash-noise stash in the vendored orchestrateur-client was needed to unblock - stash `em-dash-normalization-noise-2026-07-05` kept recoverable). No containerXmlPath/objectManagerLoader on purpose (CI has no warmed cache).
  - [x] 6.2 New-error count with extensions active: ZERO at level max. Stale TODO replaced with a comment documenting the setup and the deliberate no-kernel-binding choice.
- [x] Task 7: Final gates + PR (AC: 3)
  - [x] 7.1 phpstan 0 (extensions on), cs-fixer 0, arch gate OK, lint:container OK, unit 555/555, full suite green on isolated DB, `pnpm gates` exit 0.
  - [x] 7.2 PR opened to `develop`; worklist linked; Sessions deferrals stated. Commits: worklist → deprecation+dead code → modifiers+fixtures → phpstan extensions → story record.

## Dev Notes

### What the audit already established (scans of 2026-07-05, develop = 8648f4c)

**Already clean - no work, record as verified in the worklist:**
- Runtime container/service-locator: 0 (no `ContainerInterface`, `ServiceLocator`, `ServiceSubscriberInterface`, `#[Required]`, `ParameterBagInterface`, container `->get()`).
- All 129 controllers are plain invokables (zero `AbstractController`); all 14 console commands use `#[AsCommand]`; zero event-subscriber layer (side effects go through Messenger per AC-A4); zero superglobals; zero setter injection.
- Deprecation-prone patterns: 0 (`getDoctrine`, `ManagerRegistry` injection, docblock annotations - repo is 100% attributes with 848 uses, deprecated `Security` class, deprecated serializer interfaces). The one `->getUser()` is the correct token form (`MembershipVoter:33`).
- composer: all symfony/* pinned `7.4.*` (resolved 7.4.8-7.4.13), PHP `^8.4`, no sensio/*, no doctrine/annotations; Doctrine ORM 3.6.5 / DBAL 4.4.3. Symfony 8.1 majors exist - migrations, NOT this story (epic: framework major = own epic).
- phpunit.xml.dist: modern PHPUnit 13 config, `failOnDeprecation/Notice/Warning = true`, no deprecated `<listeners>`.

**The checklist items (= worklist seed):**
| # | Item | Disposition seed |
|---|------|------------------|
| 1 | `MembershipVoter::voteOnAttribute()` missing `Vote|null` arg - the ONLY container deprecation | Fix (Task 2) |
| 2 | 3 dead `@deprecated` shims: `Identity/Application/{DiscordBotStatusQuery, DiscordBotUsersQuery, DiscordResyncAllUsers}` (replaced by `Dbal*` in Infrastructure, wired at services.yaml:241-244, shims unwired) | Delete (Task 3) |
| 3 | `config/reference.php` - 101 KB orphan, sole repo reference is its cs-fixer exclusion | Delete (Task 3) |
| 4 | `validator.yaml` scaffold comment referencing non-existent `App\Entity\` | Delete comment; other Flex scaffold comments accepted |
| 5 | 14 non-final `#[ORM\Entity]` classes (Identity x8, GameSelection x4, Sessions x2) - 31/45 entities already final, none of the 14 subclassed | Add `final` to 12; defer Sessions x2 (Task 4, feasibility-gated) |
| 6 | 3 `final readonly` candidates: `TwitchStatusChecker`, `ParticipantStreamsView`, `RotationResult` (247 Application classes already final readonly) | Promote (Task 4) |
| 7 | 13 static mutable properties across 4 test doubles (`NullMinioStorage`, `StubIgdbHttpClient`, `StubSteamWebApiClient`, `NullRunnerGateway`) - violates the repo-wide "no static mutable properties" ban; latent test-isolation hazard | Refactor 3, defer NullRunnerGateway/Sessions (Task 5) |
| 8 | `phpstan.neon:8` stale TODO - extensions absent while the codebase now has 45 entities + heavy DI; level max runs blind on Doctrine/Symfony types | Action with approval, or re-scope with measured data (Task 6) |
| 9 | ~130 zero-arg `new \DateTimeImmutable()` in Application | OUT (recorded in 33.5 worklist section D - dedicated ClockInterface story) |
| 10 | 25 public setters on 12 Domain entities (AC-D5) | OUT - DDD rule, not in this story's epic enumeration; record as future story/validator-rule candidate (pairs with 33.5 worklist section D) |
| 11 | 3 constant-bag classes (`ReportCategory`, `ReportProblem`, `Audience`) convertible to native enums | Accept as-is (style preference, call-site churn, zero defect) unless trivially safe |
| 12 | `ValidationErrors` not readonly | Accept (genuinely mutable accumulator - by design) |
| 13 | `ArchilanEmail` abstract non-readonly base + 13 email subclasses non-readonly | Accept (readonly cannot be added to subclasses of a non-readonly parent; restructuring is churn without defect) |
| 14 | transitive symfony/polyfill-php83 on PHP 8.4 | Accept (harmless transitive dead weight) |

### Feasibility caveat on entity `final` (Task 4.1 - the one real risk)

Classic Doctrine proxies SUBCLASS the entity; a `final` entity that is the target of a lazy to-one association breaks proxy generation at runtime, not at compile time. 31 entities are already final in this repo, which suggests either native lazy objects (PHP 8.4 / ORM 3.x `enable_native_lazy_objects`) or no lazy-proxied usage of those - Task 1.2 must establish WHICH before touching the 14. The functional suite (schema + real associations, isolated DB) is the safety net either way. If classic proxies + lazy associations: accept-with-rationale beats breaking runtime.

### Sessions freeze (Epic 32 still stashed, unchanged since 33.5)

`Session`/`SessionSlot` final-tidy and `NullRunnerGateway` statics are DEFERRED with TODO epic-32 notes, mirroring 33.5's allowlist precedent. Zero Sessions file edits in this story.

### Previous story intelligence (33.5)

- Windows execution: `sed`/bash backslash rewrites silently no-op - use PowerShell literal `[string].Replace()` with anchored throw-on-missing asserts for any FQCN/namespace rewrite.
- Audit-first discipline paid off twice: the corrected-regex rescan (broken lookbehind had hidden ~130 clock reads) and the rules catching 3 violations my use-line scans missed. Verify seeded findings with content-level (not use-line) scans.
- Gates: `composer gates` legs individually + full suite twice on isolated DB (`test-isolated.sh <name>`); `debug:container`/`lint:container` after any services.yaml change.
- Repo conventions: merge commits, PR to develop, story stays `ready-for-review` after merge, `declare(strict_types=1)` manual, Yoda comparisons, camelCase test methods, no em-dashes anywhere.

### Project Structure Notes

- Diff footprint: `api/src/{Identity,GameSelection,Streaming,Membership,Shared}/**` (modifiers, voter, doubles, deletions), `api/config/reference.php` (deleted), `api/.php-cs-fixer.dist.php`, `api/config/packages/validator.yaml`, `api/phpstan.neon` (+ `composer.json`/`composer.lock` if Task 6 approved), `api/tests/Functional/**` (double rewiring), worklist + story files. Zero frontend, zero migrations, zero Sessions src edits.
- New validator rules from 33.5 now gate this work - any relocation/import mistake fails `composer arch` immediately.

### References

- Epic definition: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` (commit 1b9e869) - story 33.6 AC (audit-as-scope, coverage-first), locked decisions.
- Audit sources: DI/container scan, class-modifier scan, tech-debt/deprecation scan (2026-07-05, recorded in this story's Dev Notes) + `php bin/console debug:container --deprecations` + `composer outdated symfony/* --direct`.
- 33.5 worklist section D (future-rule candidates that items 9/10 join): `_bmad-output/implementation-artifacts/33-5-audit-worklist.md`.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Debug Log References

- Audit-time feasibility proofs: DoctrineBundle `Configuration.php:367` forces `enable_native_lazy_objects: true`; no proxy dir in var/cache → entity `final` safe. KernelBrowser reboot semantics + `NullMinioStorage` static store analysis → double refactor rejected (worklist C1).
- `debug:container --deprecations`: 1 before (MembershipVoter) → 0 after.
- phpstan with extensions active: 0 errors at level max, first run. One new error appeared from `final` alone (`User::getPassword()` unusedType) - fixed by narrowing the return type.
- Composer partial update was blocked by uncommitted em-dash-normalization noise in the vendored `archilan/orchestrateur-client` (source install); stashed as `em-dash-normalization-noise-2026-07-05` (recoverable), update then clean.
- Execution discovery: Symfony Flex regenerated `config/reference.php` during the composer update - deletion converted to deletion + gitignore + kept cs-fixer exclusion.
- Gates: phpstan 0 / cs-fixer 0 / arch OK / lint:container OK / unit 555/555 / full suite on `archilan_test_story336`: OK (1453 tests, 10236 assertions, 06:52) / `pnpm gates` exit 0.

### Completion Notes List

- All 7 worklist fixes landed; 9 acceptances recorded with rationale; 2 out-of-scope items re-recorded (ClockInterface migration, AC-D5 setters). Deprecation surface now ZERO (`debug:container --deprecations` clean).
- 12 entities now `final` (45-entity total: 43 final, 2 deferred in frozen Sessions); 18 entity-stub test sites converted to real instances - which both unblocked `final` and fixed a latent AC-T2 violation.
- PHPStan now runs with phpstan-symfony + phpstan-doctrine at level max, zero errors, zero suppression.
- Zero behaviour change: no route, response shape, schema or message change; full suite + frontend gates green.
- Sessions deferrals (TODO epic-32): `Session`/`SessionSlot` final, `NullRunnerGateway` statics.

### File List

- `_bmad-output/implementation-artifacts/33-6-audit-worklist.md` (new - scope of record) + this story file
- `api/src/Membership/Infrastructure/MembershipVoter.php` (Vote param)
- Deleted: `api/src/Identity/Application/{DiscordBotStatusQuery,DiscordBotUsersQuery,DiscordResyncAllUsers}.php`, `api/config/reference.php` (also gitignored)
- `api/.gitignore`, `api/.php-cs-fixer.dist.php`, `api/config/packages/validator.yaml`
- `final` added: `api/src/Identity/Domain/{User,RefreshToken,PasswordResetToken,EmailConfirmationToken,RoleChangeAudit,PrivacyRightsRequest,DeletionAudit,AdminCreationAudit}.php`, `api/src/GameSelection/Domain/{Game,GameCatalogSync,GameRequest,IgnoredCatalogEntry}.php` (+ `User::getPassword(): string`)
- `final readonly`: `api/src/Streaming/Application/{TwitchStatusChecker,ParticipantStreamsView}.php`, `api/src/Identity/Application/RotationResult.php`
- Tests: `api/tests/Unit/Membership/{MembershipActivatedNotificationMessageHandlerTest,MembershipExpiredNotificationMessageHandlerTest,MembershipReminderMessageHandlerTest,ProcessHelloAssoMembershipPaymentTest,SyncMemberToDolibarrMessageHandlerTest}.php`, `api/tests/Unit/WeeklyRuns/{LaunchWeeklyEntryTest,GenerateWeeklyRunsMessageHandlerTest,GenerateWeeklyRunForTemplateTest}.php`
- `api/composer.json`, `api/composer.lock`, `api/phpstan.neon` (extensions)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from three parallel code audits (DI/container patterns, class modifiers, tech-debt/deprecations) + console checks. Codebase already clean on 8 of 12 audited axes; finite 14-item checklist seeded with dispositions, one real risk flagged (entity final vs Doctrine proxy strategy), Sessions deferrals mirrored from 33.5, phpstan-extensions task gated on human approval for new dev deps. Status: ready-for-dev. |
| 2026-07-05 | Story executed: worklist first (`fa33acd`), deprecation+dead code (`d2504f9`), modifiers+real fixtures (`93beca6`), phpstan extensions with Jean's approval (`cafbce1`). Double-statics refactor rejected at audit with recorded rationale (kernel-reboot semantics); reference.php deletion adjusted to deletion+gitignore (Flex regenerates it). All gates green; deprecations 0; phpstan extensions active with 0 errors. Status → ready-for-review. |
