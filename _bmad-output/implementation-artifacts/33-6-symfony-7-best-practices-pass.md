# Story 33.6: Symfony 7 Best-Practices Pass (api/)

Status: ready-for-dev

## Story

As a maintainer of the api,
I want every concrete deviation from Symfony 7 / repo best practices enumerated and either fixed or explicitly accepted,
so that the codebase's conformance floor rises with zero behaviour change and nothing open-ended.

## Acceptance Criteria

1. **AC1 - Audit checklist committed first.** A worklist (`_bmad-output/implementation-artifacts/33-6-audit-worklist.md`) enumerating every concrete item with a file reference is committed before any code change. The pre-scanned findings in Dev Notes are the seed; the worklist confirms/extends/rejects each and IS the story scope. Nothing outside it gets touched.
2. **AC2 - Every checklist item resolved or accepted.** Each item is fixed, or accepted with a one-line rationale recorded in the worklist. At minimum the following are RESOLVED: the `MembershipVoter::voteOnAttribute()` deprecation (the only runtime deprecation - `debug:container --deprecations` reports 0 afterwards), the 3 dead `@deprecated` Identity shim classes deleted, the orphaned `config/reference.php` deleted, the 3 `final readonly` promotions applied, `final` added to the non-final ORM entities outside `Sessions` (subject to the lazy-proxy feasibility check in Task 4).
3. **AC3 - Coverage confirmed before each change; all gates green.** Behaviour-relevant changes (entity `final`, test-double refactor) are validated by the full functional suite on an isolated DB; `composer gates` and `pnpm gates` pass; zero behaviour change (no route, response shape, schema, or message semantics change).

## Tasks / Subtasks

- [ ] Task 1: Produce and commit the audit worklist (AC: 1)
  - [ ] 1.1 Confirm each seeded finding (Dev Notes) still holds at the current develop head; record fix-vs-accept dispositions.
  - [ ] 1.2 Feasibility check for entity `final` (see Task 4 caveat): inspect `config/packages/doctrine.yaml` for native lazy objects vs classic proxies, and identify which of the 14 non-final entities are targets of lazy to-one associations. Record the verdict per entity.
  - [ ] 1.3 Verify the 3 `@deprecated` Identity shims have zero remaining references (`DiscordBotStatusQuery`, `DiscordBotUsersQuery`, `DiscordResyncAllUsers` in `Identity/Application` - replaced by `Dbal*` Infrastructure impls, not wired in services.yaml).
  - [ ] 1.4 Enumerate the functional tests that mutate the test-double statics (`StubIgdbHttpClient::$*`, `StubSteamWebApiClient::$*`, `NullMinioStorage::$*`, `NullRunnerGateway::$*`) to size Task 5; record the list.
  - [ ] 1.5 Commit the worklist before any src/config change.
- [ ] Task 2: Close the deprecation surface (AC: 2)
  - [ ] 2.1 `MembershipVoter::voteOnAttribute()`: add the `?Vote $vote = null` parameter per Symfony 7.4's `Voter` signature. Unit/functional coverage: MembershipVoter is exercised by RBAC functional tests - verify.
  - [ ] 2.2 `php bin/console debug:container --deprecations` → "Remaining deprecations (0)".
- [ ] Task 3: Delete dead code (AC: 2)
  - [ ] 3.1 Delete the 3 `@deprecated` shims in `api/src/Identity/Application/` (after 1.3's zero-reference proof).
  - [ ] 3.2 Delete `api/config/reference.php` (101 KB auto-generated dump; only reference in the repo is its own cs-fixer exclusion) and remove the `notPath('config/reference.php')` line from `.php-cs-fixer.dist.php`.
  - [ ] 3.3 `config/packages/validator.yaml` commented scaffold references non-existent `App\Entity\` - delete the dead comment block. Other Flex scaffold comments (framework/cache/security/routing yaml) are ACCEPTED as-is (standard Flex documentation comments, harmless) - record in worklist.
- [ ] Task 4: Class-modifier tidy (AC: 2, 3)
  - [ ] 4.1 Add `final` to the non-final `#[ORM\Entity]` classes OUTSIDE `Sessions` (12 of 14: Identity x8 - `User`, `RefreshToken`, `PasswordResetToken`, `EmailConfirmationToken`, `RoleChangeAudit`, `PrivacyRightsRequest`, `DeletionAudit`, `AdminCreationAudit`; GameSelection x4 - `Game`, `GameCatalogSync`, `GameRequest`, `IgnoredCatalogEntry`) - ONLY if 1.2's proxy feasibility check passes for each (classic Doctrine proxies cannot extend final classes; if an entity is lazy-proxied and native lazy objects are off, accept with rationale instead). `Session` + `SessionSlot` are DEFERRED (Sessions frozen until Epic 32 merges - record TODO epic-32).
  - [ ] 4.2 Promote to `final readonly`: `Streaming/Application/TwitchStatusChecker`, `Streaming/Application/ParticipantStreamsView`, `Identity/Application/RotationResult` (all verified: every property already readonly, no mutating methods).
  - [ ] 4.3 Full functional suite on isolated DB after 4.1 (associations + lazy loading are exactly what unit tests do not cover).
- [ ] Task 5: Static mutable state in test doubles (AC: 2, 3)
  - [ ] 5.1 Refactor `Shared/Infrastructure/NullMinioStorage`, `GameSelection/Infrastructure/StubIgdbHttpClient`, `GameSelection/Infrastructure/StubSteamWebApiClient` from static properties to instance state. The doubles are `public: true` in `when@test` services.yaml - tests fetch the instance via `static::getContainer()->get(...)` and configure it, instead of writing statics. Update every functional test enumerated in 1.4.
  - [ ] 5.2 `Sessions/Infrastructure/NullRunnerGateway` statics: DEFER (Sessions frozen, TODO epic-32) - record in worklist.
  - [ ] 5.3 Fallback clause: if 5.1 balloons past the enumerated test list (hidden couplings), stop, accept the remainder with rationale in the worklist, and record the residue as a follow-up candidate - do not let this story become open-ended.
- [ ] Task 6: PHPStan extensions (stale TODO at `api/phpstan.neon:8`) (AC: 2) **[human: new dev dependencies - ask Jean before installing]**
  - [ ] 6.1 With approval: `composer require --dev phpstan/extension-installer phpstan/phpstan-symfony phpstan/phpstan-doctrine`, wire the symfony container xml + doctrine objectManagerLoader per docs, run `composer phpstan`.
  - [ ] 6.2 If the new-error count is small (roughly <= 20), fix them here; if large, keep the extensions OFF (revert), record the count + decision in the worklist, and update the phpstan.neon TODO with the measured number so the follow-up story is sized. Either way the stale TODO is resolved (actioned or re-scoped with data).
- [ ] Task 7: Final gates + PR (AC: 3)
  - [ ] 7.1 `composer gates` green; `pnpm gates` green (frontend untouched - regression check); full suite on isolated DB (`api/scripts/test-isolated.sh story336`).
  - [ ] 7.2 PR to `develop` from `feature/epic-33-story-6-symfony-best-practices`; body links the worklist and states the Sessions deferrals. Commits: (1) worklist, (2) deprecation + dead code, (3) modifiers, (4) test-double refactor, (5) phpstan extensions (if approved), (6) story record.

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

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from three parallel code audits (DI/container patterns, class modifiers, tech-debt/deprecations) + console checks. Codebase already clean on 8 of 12 audited axes; finite 14-item checklist seeded with dispositions, one real risk flagged (entity final vs Doctrine proxy strategy), Sessions deferrals mirrored from 33.5, phpstan-extensions task gated on human approval for new dev deps. Status: ready-for-dev. |
