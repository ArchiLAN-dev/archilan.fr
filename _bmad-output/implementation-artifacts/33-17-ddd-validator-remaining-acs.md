# Story 33.17: Extend the DDD Validator to the Remaining Documented ACs (api/)

Status: ready-for-review

## Story

As a maintainer of the api's architecture gates,
I want the remaining documented-but-unenforced `api/CLAUDE.md` ACs (AC-D4, AC-A1, AC-A3, full AC-D1, AC-M1) encoded as `DddArchitectureValidator` rules with named allowlists,
so that the standards hold by construction instead of by review vigilance, closing the "candidate future rules" debt recorded in the 33.5 worklist section D.

## Context

Epic entry scope (locked): 5 rules - aggregates `final` / VOs `final readonly` (AC-D4), Application
services `final` (AC-A1), command-returns-void / query-returns-DTO (AC-A3), full
`Symfony\Component\*`/`Contracts\*` Domain ban (AC-D1), `ROLE_MEMBER` gating ban (AC-M1). Fresh audit
2026-07-11 (below): the tree is already nearly compliant - the story is mostly rule-writing plus one
mechanical VO migration and ONE doc/reality reconciliation (AC-A3, see R3, the only judgment call).

Validator state after 33.16: rules are raw-text scans over `file_get_contents` with per-rule named
consts (exempt lists / allowlists), one violation message per finding, unit-tested via project
fixtures in `tests/Unit/DddArchitectureValidatorTest.php`.

## Audit (2026-07-11, fresh scans - this is the worklist)

### R1 - AC-D4: aggregates `final`, VOs `final readonly`

- Non-final Domain classes: **only** `Sessions/Domain/Session.php` + `SessionSlot.php` (frozen, Epic 32).
- `Domain/ValueObject/` split: 10 VOs already `final readonly class` (all the constructor-bearing ones);
  **11 VOs are `final class` without `readonly`**: `GameSelection/PlatformCategory`,
  `GameSelection/ApworldUpdateStatus`, `Community/ShowcaseWidget`, `Community/AvatarFrame`,
  `Community/ReportSeverity`, `Community/Audience`, `Community/ReportProblem`,
  `Community/ReportCategory`, `Community/CommunityXp`, `Community/BannerPreset`, `Shared/SlotName`.
  All 11 are static-helper/constant-catalog classes: no `__construct`, no instance properties, no
  static properties (verified) - adding `readonly` is legal (readonly forbids static PROPERTIES only;
  statics METHODS and consts are fine) and mechanical.
- Rule: in `Domain/Entity/` every class declaration must start `final class` (or `final readonly class`);
  in `Domain/ValueObject/` it must be `final readonly class`. Scanning those two taxonomy sub-folders
  means the flat frozen Sessions files escape naturally today and get picked up automatically when
  33.20 moves them - document that in the rule comment; also add `Sessions` to a shared exempt const
  anyway so the behaviour is explicit, not accidental.

### R2 - AC-A1: Application services `final`

- **Single non-final class in all of Application**: `Communications/Application/Email/ArchilanEmail.php`
  (`abstract class`, the email template base - its subclasses are final; inheritance IS the mechanism).
- Rule: every `class` declaration in `Application/` must be `final` (abstract banned); named allowlist
  `ALLOWED_APPLICATION_NON_FINAL = ['Communications/Application/Email/ArchilanEmail.php']` with the
  rationale in the const comment.

### R3 - AC-A3: command-returns-void / query-returns-DTO - DOC/REALITY RECONCILIATION

- AC-A3 as written ("Command services return void") is contradicted by the established convention:
  ~60 public methods across the 72 `Application/Command/` classes return outcome arrays / result
  records / scalars (`execute(): array`, `reserve(): ?array`, `delete(): bool`...), and controllers
  consume those payloads for HTTP responses. Enforcing the letter would be an epic-sized refactor of
  the whole write path - out of the question for a validator story ("no behaviour change").
- **Proposed resolution (33.2 precedent: the enforced rule wins, the doc is corrected):**
  1. Amend `api/CLAUDE.md` AC-A3 to match reality: "Command services return `void` or a result
     record/outcome array. Query services return typed DTOs or arrays. Neither ever returns a raw
     Doctrine entity." (The CQRS colocation rule already acknowledges command result records.)
  2. Enforce the invariant part: **no public method of an Application class declares a Domain entity
     return type.** Textually: flag `): ?X` / `): X` where `X` is a class imported from
     `App\{Context}\Domain\Entity\`.
- Current hits of the narrowed rule: `Identity/Application/Service/CurrentUserProvider.php`
  (`userFromRequest(): ?User`) and `Identity/Application/Service/AuthenticateUser.php`
  (`authenticate(): ?User`, `findUserById(): ?User`) - auth resolvers feeding security wiring, not
  read-model queries. Named allowlist `ALLOWED_APPLICATION_ENTITY_RETURNS` with that rationale
  (or refactor if trivial - dev's call, allowlist is the default).
- **DECIDED (Jean, 2026-07-11): amend the doc + enforce the entity-return rule.** The strict-CQRS
  migration (typed exceptions, result records, command/query split in controllers) is recorded as
  future `epic-35-strict-cqrs-write-path.md` (idea status) - not this story's scope.

### R4 - AC-D1 full: Domain ban on all `Symfony\Component\*` / `Symfony\Contracts\*`

- Validator today checks only 4 namespaces (`Console`, `DependencyInjection`, `HttpFoundation`,
  `Routing`) in `forbiddenDomainDependencies()`.
- Full-prefix scan of `Domain/**`: **only** `Identity/Domain/Entity/User.php` imports Symfony
  (`Security\Core\User\UserInterface` + `PasswordAuthenticatedUserInterface`) - the mandatory Symfony
  security contract on the user entity; documented pattern.
- Rule: replace the 4 namespace entries by the two full prefixes `Symfony\Component\` and
  `Symfony\Contracts\`; named allowlist `ALLOWED_DOMAIN_SYMFONY_IMPORTS` keyed by file + import
  (`Identity/Domain/Entity/User.php` x2) with rationale.

### R5 - AC-M1: `ROLE_MEMBER` gating ban

- Gating forms (`isGranted('ROLE_MEMBER')`, `#[IsGranted('ROLE_MEMBER')]`,
  `denyAccessUnlessGranted('ROLE_MEMBER')`): **zero occurrences** in src - the rule lands clean, no
  allowlist needed.
- `in_array('ROLE_MEMBER', ...)` exists ONLY in AC-M3-sanctioned display/filter/assignment spots
  (verified): `Identity/Infrastructure/Dbal/DbalUserDirectoryQuery.php` (directory filter),
  `Identity/Application/Handler/SyncDiscordRoleMessageHandler.php` (Discord role sync, AC-M3
  explicitly), `Identity/Application/Command/AdminChangeUserRole.php::primaryRole` (display mapping),
  `Identity/Domain/Entity/User.php` (role assignment in `activateMembership`). The `in_array` form is
  therefore NOT banned - the rule targets only the three gating forms. Do NOT put the matchable
  literals in the rule's comment (33.15/33.16 self-match lesson).

## Acceptance Criteria

1. **AC1 - Five rules, named allowlists, tests.** R1-R5 implemented in `DddArchitectureValidator`,
   each with: a named, commented const for exemptions/allowlists (never inline suppressions), unit
   tests covering reported / not-reported / exempt-or-allowlisted cases (fixture pattern of the
   existing tests), and regexes that handle legal modifier orders (33.16 review lesson:
   `final`/`abstract`/`static` combinations) and report every occurrence per file (`preg_match_all`
   where multiple hits are possible).
2. **AC2 - Flagged violations fixed or allowlisted.** The 11 static-catalog VOs gain `readonly`
   (mechanical, no behaviour change); `ArchilanEmail`, `User.php` Symfony-Security imports and the
   two auth resolvers are allowlisted with rationale; nothing else in the tree trips the new rules.
3. **AC3 - AC-A3 doc reconciled.** `api/CLAUDE.md` AC-A3 amended per R3 (commands may return outcome
   records; entities never) - the doc and the enforced rule say the same thing.
4. **AC4 - Sessions stays frozen.** No Sessions file is touched; R1's exempt handling documents that
   33.20 inherits the finality rule automatically.
5. **AC5 - All gates green; zero behaviour change.** `composer gates` green on an isolated DB;
   `readonly` additions are declaration-only.

## Tasks / Subtasks

- [x] Task 1: R4 (AC-D1 full ban + `ALLOWED_DOMAIN_SYMFONY_IMPORTS`) + tests - smallest rule,
  fixes the pattern for the story (AC: 1, 2)
- [x] Task 2: R5 (AC-M1 gating ban, three forms, no allowlist) + tests (AC: 1, 2)
- [x] Task 3: R1 (AC-D4 finality) - add `readonly` to the 11 catalog VOs, then the rule
  (Entity: final; ValueObject: final readonly; Sessions exempt/documented) + tests (AC: 1, 2, 4)
- [x] Task 4: R2 (AC-A1 Application final + ArchilanEmail allowlist) + tests (AC: 1, 2)
- [x] Task 5: R3 (AC-A3) - amend `api/CLAUDE.md`, add the no-entity-return rule +
  `ALLOWED_APPLICATION_ENTITY_RETURNS`, tests (AC: 1, 2, 3)
- [x] Task 6: full gates on isolated DB; PR to develop (AC: 5)

## Dev Notes

- **Branch from develop AFTER PR #302 (33.16) is merged** - both stories touch
  `DddArchitectureValidator.php` and its test file. [Done: #302 merged as 271e99e.]
- **Regex lessons from the 33.16 adversarial review (apply from the start):** tolerate legal modifier
  orders (`(?:(?:static|final|abstract)\s+)*` style), use `preg_match_all` and emit one violation per
  hit, whitespace-normalize matched excerpts before sprintf (multiline declarations), and never put a
  matchable literal in the rule's own doc-comment or in any Domain/Application file the rule scans.
- **Class-declaration matching (R1/R2):** match declarations at line starts including prefixes:
  `final`, `final readonly`, `abstract`, `readonly`. Interfaces, traits and enums are NOT `class`
  declarations - make the regex require the `class` keyword so they pass untouched. PHP enums are
  implicitly final; do not flag them.
- **R3 return-type detection:** collect `use App\{Ctx}\Domain\Entity\{Name}` imports per file
  (optionally aliased `as`), then flag `): ?{Name}` / `): {Name}` occurrences. Aliased imports:
  handle `use ... as Alias` by matching the alias. Union/nullable beyond `?` (e.g. `X|null`) - handle
  `|` unions containing the name. Keep it lexical, consistent with the rest of the validator.
- **`readonly` additions (R1):** the 11 VOs have no instance/static properties - `final class X` ->
  `final readonly class X` is the whole change. Run the unit suites of the touching contexts
  (Community, GameSelection, Shared) after the batch.
- **Partial phpstan runs lie** (33.16 record): scoped `phpstan analyse src/{Ctx}` reports
  `property.onlyWritten` / `trait.unused` false positives for symbols used from other contexts -
  full `phpstan analyse src tests` is the only authoritative run.
- **Validator/test shape:** mirror the 33.15/33.16 additions - new private `validate*` method wired
  in `validate()`, consts grouped with the existing exempt/allowlist consts, tests using
  `createProjectFixture()` + `createDirectory()` + `file_put_contents` fixtures.
- Windows execution lessons (memory): Edit tool per file; PowerShell literal `.Replace()` only for
  bulk mechanical call-site sweeps, verified by grep afterwards.

### Project Structure Notes

- Validator: `api/src/Shared/Application/Support/DddArchitectureValidator.php`; tests:
  `api/tests/Unit/DddArchitectureValidatorTest.php` (38 tests post-33.16).
- Doc to amend (R3): `api/CLAUDE.md` section "Application layer", AC-A3 line.
- The 11 VO files are listed in the R1 audit above - no other file moves or renames.

### References

- Epic definition: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md`
  (33.17 entry; 33.2 "enforced rule wins" precedent in the epic's Known Issues)
- Candidate-rule origin: `_bmad-output/implementation-artifacts/33-5-audit-worklist.md` section D
- Rule texts: `api/CLAUDE.md` AC-D4, AC-A1, AC-A3, AC-D1, AC-M1/M2/M3
- Prior art: `33-15-clockinterface-migration.md` (exempt-const + self-match lesson),
  `33-16-domain-setters-business-methods.md` (Review Findings: modifier-order regex,
  preg_match_all, whitespace-normalized excerpts, partial-phpstan artifacts)

## Dev Agent Record

### Agent Model Used

claude-fable-5.

### Debug Log References

- The audit's entity-return scan (grep on known entity names) under-counted: the actual rule's
  first run also caught `DeletionAudit`, `?Run`, `?RunParticipant`, `?Game`, `?CommunityProfile`
  returns - all but one were PRIVATE helpers, which AC-A3 does not concern (entities may circulate
  inside the layer). The rule was made visibility-aware: each return type is attributed to the
  nearest preceding named function declaration; private/protected are skipped; closures inherit
  the enclosing declaration's visibility, which errs on the strict side inside public methods.
- The one real public hit, `DeleteAccount::delete(): DeletionAudit`, was fixed (-> `void`) rather
  than allowlisted: its sole caller (`AccountDeletionController:35`) ignored the return and no
  test consumed it.
- `User.php`'s AC-M1 doc comment contained the literal gating form and would have tripped R5 -
  reworded (the 33.15 self-match lesson, this time in a scanned file rather than the validator).
  R5's own pattern is assembled from fragments (`'ROLE_'.'MEMBER'`, callers in an array) so the
  validator never contains a matchable literal.

### Completion Notes List

- 2 commits: compliance migrations first (11 VOs -> `final readonly`, `DeleteAccount` -> void,
  User comment reword, AC-A3 doc amendment + epic-35 pointer), then the 5 rules + 12 tests.
- All 5 rules use the 33.16 review lessons from day one: modifier-order-tolerant declaration
  regexes, `preg_match_all` (every occurrence reported), whitespace-normalized excerpts.
- Allowlists as specified: `ALLOWED_DOMAIN_SYMFONY_IMPORTS` (User.php x2, stripped before scan),
  `ALLOWED_APPLICATION_NON_FINAL` (ArchilanEmail), `ALLOWED_APPLICATION_ENTITY_RETURNS`
  (AuthenticateUser, CurrentUserProvider), `FINALITY_EXEMPT_CONTEXTS` (Sessions, explicit).
- Gates: phpstan 0 (src+tests), cs-fixer 0, `app:architecture:ddd` OK (5 new rules active),
  full isolated suite **1485 tests / 10298 assertions** green (48 validator tests).

### File List

- api/src/Shared/Application/Support/DddArchitectureValidator.php (5 rules, 4 new consts,
  full-prefix Symfony ban, allowed-import stripping in validateDomainDependencies)
- api/tests/Unit/DddArchitectureValidatorTest.php (+12 tests)
- api/src/GameSelection/Domain/ValueObject/PlatformCategory.php, ApworldUpdateStatus.php (readonly)
- api/src/Community/Domain/ValueObject/ShowcaseWidget.php, AvatarFrame.php, ReportSeverity.php,
  Audience.php, ReportProblem.php, ReportCategory.php, CommunityXp.php, BannerPreset.php (readonly)
- api/src/Shared/Domain/ValueObject/SlotName.php (readonly)
- api/src/Identity/Domain/Entity/User.php (AC-M1 comment reword)
- api/src/Identity/Application/Command/DeleteAccount.php (delete(): void)
- api/CLAUDE.md (AC-A3 amended)
- _bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md (new, idea status)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.17). Fresh audit: tree nearly compliant - R1: 11 static-catalog VOs to make readonly + finality rule (Sessions escapes until 33.20); R2: only ArchilanEmail non-final (allowlist); R3: AC-A3 doc contradicted by the outcome-array convention -> amend doc + enforce no-entity-returns (2 auth resolvers allowlisted); R4: full Symfony ban trips only User.php Security imports (allowlist); R5: zero gating usages, rule lands clean. 33.16 review lessons baked into Dev Notes. Status: ready-for-dev. |
| 2026-07-11 | Implemented: 5 rules + 4 named allowlist/exempt consts + 12 tests; 11 VOs readonly, DeleteAccount void (real AC-A3 fix), User.php comment reword, AC-A3 doc amended, epic-35 recorded. Entity-return rule made visibility-aware after the first gate run caught private helpers. All gates green (isolated suite 1485 tests). Status: ready-for-review. |
