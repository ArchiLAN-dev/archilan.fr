# Story 33.23: Tokenizer-Based Validator Rules - Kill the Raw-Lexical-Scan Debt (api/)

Status: ready-for-dev

## Story

As a maintainer of the DDD architecture gate,
I want the validator's content-scanning rules to read PHP **tokens** instead of raw file text,
so that a rule can never again match its own documentation, a docblock, or a string literal - and the
class of bug that four separate stories each paid for is gone by construction.

Origin: **epic-33 retrospective, action A3.** Deferred by story 33.16 (*"a tokenizer refactor is its
own story"*) and again by 33.17 (*"all instances of the validator-wide raw-lexical-scan design, folded
into the existing tokenizer deferred item"*). **The story was never written.** It is the epic's largest
un-owned residue.

## Context - the debt is measured, not asserted

`DddArchitectureValidator` is **986 lines, 16 rules**, and every content check is
`str_contains` / `preg_match` over `file_get_contents`: **12 raw reads, 18 raw pattern matches.** The
design has no way to tell code from a comment or a string.

**The bill, paid four separate times:**

| Story | What it cost |
|---|---|
| **33.13** | Rector rewrote the validator's own scan-pattern strings to `::class`, so the file then contained the literal FQCNs it scans for - `app:architecture:ddd` **flagged the validator for "injecting" Doctrine**. Fixed by permanently skipping `StringClassNameToClassConstantRector` (still skipped today). |
| **33.15** | The new clock rule **flagged its own source file**: its doc-comment contained the literal `new \DateTimeImmutable()` example it scans for. |
| **33.16** | Adversarial review found the brand-new setter rule reported **only the first setter per file** (`preg_match` not `preg_match_all`) and was blind to legal modifier orders (`public final function set*`). Also deferred: `public function set{Upper}` inside a doc-comment is a false positive. |
| **33.17** | **8 review patches, all lexical gaps** - despite baking 33.16's lessons in from day one. `User.php`'s doc-comment tripped the AC-M1 rule, so the rule's pattern was assembled from fragments (`'ROLE_'.'MEMBER'`) - and then **cs-fixer's `no_useless_concat_operator` folded the fragments back**, forcing a comment rewrite. |

The workaround is now **admitted in the source** (`DddArchitectureValidator.php:512-521`):

> *"The bare role string in this method cannot self-match: the pattern requires the full checker-call
> shape, which never appears here contiguously... variable/constant-form gating and security
> expressions in YAML are **beyond a lexical scan (accepted limitation, same class as the deferred
> tokenizer work)**."*

Writing rules that must not describe themselves is not a convention. It is a trap that every future
rule re-lays.

## Decisions (locked)

- **`token_get_all()`, NOT `nikic/php-parser`.** The stdlib tokenizer distinguishes `T_COMMENT`,
  `T_DOC_COMMENT` and `T_CONSTANT_ENCAPSED_STRING` from real code - which **is the entire recorded
  finding class**. It also makes multiline declarations free (tokens have no lines). Zero new
  dependency, which matters for a rule that must run on a fresh CI checkout.
  *A full AST (nikic) buys type resolution the recorded findings never asked for. It is the escalation
  if a future rule genuinely needs semantics, not a prerequisite for this one.*
- **Behaviour-identical on the real tree.** This is a refactor, not a rule change. `app:architecture:ddd`
  must stay green, and every rule must report **exactly the same violations** on a synthetic dirty
  fixture before and after. Any *new* violation the tokenizer surfaces on the real tree is a
  **triage-to-discuss**, not an auto-fix (it means a rule was silently under-reporting).
- **The 52 existing validator unit tests are the contract.** They already cover every rule's positive
  and negative cases (the six that 33.20 inverted included). The refactor is safe precisely because
  that contract exists.
- **YAML stays lexical.** `validateServicesConfig` scans `config/services.yaml`, not PHP. Out of scope.

## Scope

**9 PHP content-scanning rules → tokenized:**

`validateDomainDependencies` · `validateDomainFinality` · `validateApplicationFinality` ·
`validateApplicationEntityReturns` · `validateMembershipGating` · `validateDomainAggregateSetters` ·
`validateCrossContextLayerImports` · `validateApplicationPurity` · `validateApplicationCqrs` ·
`validatePresentationCqrs`

**6 rules untouched** (they read paths or config, never file contents):
`validateContextDirectories` · `validateSourceFiles` · `validateNoFlatLayerFiles` ·
`validateInterfacePlacement` · `validateDoctrineMappings` · `validateServicesConfig`

### Out of scope
- Changing what any rule *means*. No new rules, no relaxed rules.
- `nikic/php-parser` and anything needing type resolution.
- The YAML scan.

## Acceptance Criteria

1. **AC1 - A token facade.** A single `PhpSource` helper (Shared/Application/Support) wraps
   `token_get_all()` and exposes what the rules actually need:
   `imports(): list<string>` (FQCNs from `use` statements, **group-use aware**),
   `hasCodeText(string $needle): bool` (substring search over **code tokens only**),
   `matchesCode(string $pattern): bool` / `codeMatches(string $pattern): list<string>`,
   `classDeclarations(): list<array{name, modifiers}>` (multiline-safe),
   `publicMethodNames(): list<string>`, `returnTypes(): list<string>`.
   Comments, doc-comments and string literals are **never** part of the searched text.

2. **AC2 - The 9 rules use it.** No `file_get_contents` + `str_contains`/`preg_match` over raw PHP
   remains in those rules.

3. **AC3 - Self-match is impossible, and it is TESTED.** A new test writes a fixture whose
   **doc-comment and string literals contain every pattern the rules scan for** (`new \DateTimeImmutable()`,
   `public function setFoo`, `App\X\Infrastructure\`, the AC-M1 gating call, an entity return type)
   while the code itself is clean - and asserts **zero violations**. This test is the story's whole point:
   it fails today.

4. **AC4 - Behaviour-identical.** All 52 existing validator tests green, unchanged. `app:architecture:ddd`
   green on the real tree. Any new violation the tokenizer surfaces is triaged in the record, not
   auto-fixed.

5. **AC5 - The workarounds come out.** The fragment-assembled `'ROLE_'.'MEMBER'` and the "cannot
   self-match" comments are **deleted**, not kept - they exist only to work around the design this story
   removes. The `StringClassNameToClassConstantRector` skip in `rector.php` is re-evaluated: with the
   patterns no longer self-matching, it may no longer be needed (**verify by removing it and running
   `composer rector`** - if it stays clean, drop the skip).

6. **AC6 - All gates green.** `composer gates` (phpstan max + strict-rules, cs-fixer, DDD, phpunit) +
   full suite on an isolated DB. Zero behaviour change.

## Tasks / Subtasks

- [ ] **T1 - `PhpSource` facade + its own unit tests (AC1).** Build it first, test it in isolation
      against fixtures that put every scanned pattern inside comments/strings. This is the load-bearing
      brick - if it is right, the 9 rules are mechanical.
- [ ] **T2 - AC3's self-match test, written BEFORE the rules move (AC3).** It must **fail** against the
      current validator. A test that only passes after the change proves nothing about the change.
- [ ] **T3 - Migrate the 9 rules (AC2, AC4).** One rule per commit, 52 tests green after each. phpstan
      (max + strict) is the oracle for the facade's types.
- [ ] **T4 - Delete the workarounds (AC5).** Fragment-assembled literals, "cannot self-match" comments,
      and the Rector skip if it proves unnecessary.
- [ ] **T5 - Full battery + record the triage (AC4, AC6).** Any new violation on the real tree gets a
      line in the Dev Record explaining whether it is a real defect the old scan missed.

## Dev Notes

### The trap to NOT re-lay

**This story's own test fixtures will contain the scanned patterns.** Write them as **heredocs in the
test**, not as literals in the validator or in `api/CLAUDE.md`. The validator source must stay free of
matchable literals until the tokenizer lands - and after it lands, they are harmless by construction
(that is the point).

### `token_get_all` in practice

- Iterate tokens; skip `T_COMMENT`, `T_DOC_COMMENT`, `T_CONSTANT_ENCAPSED_STRING`, `T_ENCAPSED_AND_WHITESPACE`,
  `T_INLINE_HTML`.
- PHP 8 gives **`T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`** - FQCNs arrive as ONE token, so the
  prefix-unsafe-substring problem (33.10's `ActivateMembership` corrupting `ActivateMembershipInterface`)
  cannot recur in the matcher either.
- Group `use` (`use App\X\{A, B};`) becomes trivially visible - closing 33.17's deferred item, even
  though cs-fixer's `single_import_per_statement` currently makes it unreachable.
- `preg_match_all(...) === false` silently passing (33.17 deferred) disappears: the facade returns
  arrays, and phpstan strict-rules (story 33.14, now active) forbids the boolean-coercion that hid it.

### Regressions to watch

- **`validateMembershipGating`** is the most attribute-shaped rule (`#[IsGranted('ROLE_MEMBER')]`,
  named-arg form). Attributes ARE code tokens - the rule gets *more* accurate, so expect it to possibly
  catch something the raw scan missed. Triage, do not silently fix.
- **`validateApplicationEntityReturns`** currently tightens on `\):` because cs-fixer normalises return
  types (33.17 patch). With tokens, match the real return-type token sequence instead - and delete that
  cs-fixer-dependent hack.

### References

- Epic-33 retrospective, action **A3**: `epic-33-retro-2026-07-13.md`.
- The deferrals: `33-16-domain-setters-business-methods.md:108`,
  `33-17-ddd-validator-remaining-acs.md:142-143`, `deferred-work.md:34-35`.
- The four times the trap was paid: 33.13, 33.15, 33.16, 33.17 Dev Agent Records.
- The admitted limitation, in the source: `DddArchitectureValidator.php:512-521`.

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-14 | Story created from epic-33 retro action A3 - the refactor both 33.16 and 33.17 deferred into "its own story", which was never written. Grounded in a re-measure: 986 lines, 16 rules, 12 raw reads / 18 raw pattern matches; 9 PHP content-scanning rules in scope, 6 path/config rules untouched. Locked `token_get_all()` over `nikic/php-parser` (zero dependency, and it covers the entire recorded finding class). Status: ready-for-dev. |
