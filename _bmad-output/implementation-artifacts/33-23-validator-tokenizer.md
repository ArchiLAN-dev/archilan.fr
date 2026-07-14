# Story 33.23: Tokenizer-Based Validator Rules - Kill the Raw-Lexical-Scan Debt (api/)

Status: ready-for-review

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

- [x] **T1 - `PhpSource` facade + its own unit tests (AC1).** Build it first, test it in isolation
      against fixtures that put every scanned pattern inside comments/strings. This is the load-bearing
      brick - if it is right, the 9 rules are mechanical.
- [x] **T2 - AC3's self-match test, written BEFORE the rules move (AC3).** It must **fail** against the
      current validator. A test that only passes after the change proves nothing about the change.
- [x] **T3 - Migrate the 9 rules (AC2, AC4).** One rule per commit, 52 tests green after each. phpstan
      (max + strict) is the oracle for the facade's types.
- [x] **T4 - Delete the workarounds (AC5).** Fragment-assembled literals, "cannot self-match" comments,
      and the Rector skip if it proves unnecessary.
- [x] **T5 - Full battery + record the triage (AC4, AC6).** Any new violation on the real tree gets a
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
| 2026-07-14 | Story created from epic-33 retro action A3 - the refactor both 33.16 and 33.17 deferred into "its own story", which was never written. Grounded in a re-measure: 986 lines, 16 rules, 12 raw reads / 18 raw pattern matches; 9 PHP content-scanning rules in scope, 6 path/config rules untouched. Locked `token_get_all()` over `nikic/php-parser` (zero dependency, and it covers the entire recorded finding class). Status: ready-for-review. |

---

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context)

### The design changed twice under contact - and the existing tests are why

**Start:** blank comments AND string literals, leave the rules' regexes untouched, let them scan the
blanked text. The bug was never the pattern - it was the haystack.

Right for 7 of the 9 rules. **Wrong for two**, in ways only the 52 existing tests exposed:

1. **The clock rule.** `new \DateTime('now')` **is** a wall-clock read - the rule catches it on purpose
   and lets `new \DateTime($iso)` through. Blanking `'now'` collapsed it to `new \DateTime(    )`, which
   also made every *argumented* construction look zero-arg. Two bugs at once: a false positive on the
   real tree (`HelloAssoPaymentLookup`) and a false negative on the explicit-`'now'` form.
2. **The AC-M1 gating rule.** Its violation **is** a literal value - `isGranted('ROLE_MEMBER')`. Blanking
   the role blinded the rule to the only thing it exists to catch.

**The lesson: rules do not all want the same view of a file.** Most match a code *shape*. Two match a
literal *value*. So `PhpSource` grew three views instead of one:

- `codeText()` - comments blanked, string-literal contents **filled, not emptied**. Filling keeps
  `new X('now')` looking argumented while making the prose inside a string unmatchable. **7 rules.**
- `codeWithLiterals()` - comments blanked, strings intact. For a rule that needs literal values but not
  structure.
- **Real token walks** (`firstStringArguments()`, `newExpressions()`) - for the two rules whose verdict
  turns on a literal value. This is the honest answer: a string that merely *contains*
  `isGranted('ROLE_MEMBER')` is a single token, never a call sequence. Those rules are now immune to
  prose, to string literals and to their own source **by construction** - not by a comment promising they
  are.

### AC5: one workaround deleted, one kept - and its stated reason was wrong

**Deleted:** the fragment-assembled `'ROLE_'.'MEMBER'` and the *"the bare role string in this method
cannot self-match: the pattern requires the full checker-call shape, which never appears here
contiguously"* comment (33.17's invention, which cs-fixer then fought by folding the concatenation back).
The role is a plain `GATED_ROLE` constant now. The token walk makes the guard pointless.

**Kept: the `StringClassNameToClassConstantRector` skip** - and the original rationale for it was **wrong**.
It is not that a lexical scan cannot tell code from prose (it can now). It is that **`::class` IS code**:
rewriting the validator's forbidden-import constants to `::class` would put the very FQCN sequences it
hunts for into its own *executable* source, and the import rule - which must keep catching a
fully-qualified usage that has no `use` statement - would flag itself. **A file whose job is to NAME
forbidden classes has to hold them as data.** Verified by removing the skip and running `composer rector`:
it still wants to rewrite line 47. The comment in `rector.php` now says this instead of the old half-truth.

### AC4: behaviour-identical, and actually checked

The 52 existing validator tests pass **unchanged** - and they earned their keep: they are what caught both
regressions above. The one new violation the tokenizer surfaced on the real tree (`HelloAssoPaymentLookup`)
was a **false positive of my own making**, not a defect the old scan had missed; it disappeared once
`codeText()` filled rather than blanked. No real-tree violation was auto-fixed, and none needed to be.

### Deviation from AC1, recorded

AC1 listed a rich facade (`imports()`, `classDeclarations()`, `publicMethodNames()`, `returnTypes()`).
**Not built.** The rules' existing regexes are correct once the haystack is clean, so those methods would
have had zero callers. Building unused API to satisfy the letter of an AC is waste. What the rules actually
needed - two views plus two token walks - is what exists.

### Also fixed, incidentally

`PhpSource::fromFile()` guards `is_file()` first: `file_get_contents()` on a missing path raises a PHP
warning, and `phpunit.xml.dist` sets `failOnWarning` - so an unguarded read would have turned the gate red
on a race.

### File List

**New:** `api/src/Shared/Application/Support/PhpSource.php` ·
`api/tests/Unit/Shared/PhpSourceTest.php` (9 tests) ·
`api/tests/Unit/Shared/ValidatorIgnoresCommentsAndStringsTest.php` - the AC3 test, which **fails against
the pre-refactor validator**. That is the point: a test that only passes after the change proves nothing
about the change.

**Modified:** `api/src/Shared/Application/Support/DddArchitectureValidator.php` (11 raw reads -> `PhpSource`;
gating and clock rules -> token walks; `GATED_ROLE` constant; workaround comments deleted) ·
`api/rector.php` (skip kept, rationale corrected).

**Untouched, as scoped:** the 6 path/config rules, and the `services.yaml` read (YAML is not PHP).

### Gates

`composer gates` green: phpstan (max + strict-rules) 0 · cs-fixer 0 · `app:architecture:ddd` OK ·
**1529 tests / 10 502 assertions**. `composer rector` clean.

## Change Log

| Date | Change |
|------|--------|
| 2026-07-14 | Story created from epic-33 retro action A3, then implemented. The single-view design broke two rules whose verdict turns on a literal value (clock: the explicit 'now'; AC-M1: the role) - caught by the 52 existing tests, which is exactly why they were declared the contract. Resolved with three views plus two real token walks. The AC-M1 fragment-assembly hack is deleted; the Rector skip stays, with its rationale corrected. Status: ready-for-review. |
