# Story 33.13: Rector Adoption (api/)

Status: ready-for-review

## Story

As a maintainer of the api's quality tooling,
I want `rector/rector` installed with a conservative configuration and an advisory `composer rector` gate,
so that mechanical modernisation, dead code and upcoming-deprecation detection run continuously instead of periodically by hand (the gap recorded in the 33.6 audit).

## Context

Epic entry ACs: (1) `rector/rector` as dev dep with a conservative `rector.php` (PHP-level + Symfony
sets, no risky rules), run in `--dry-run` advisory mode first; (2) dry-run findings triaged into a
worklist (apply vs accept), mechanical fixes applied, a `composer rector` advisory gate documented,
all gates green. Jean approved the new dev dependency 2026-07-11 (the epic's [human] flag).

Environment facts (audited 2026-07-11): PHP `^8.4` (local runtime 8.4.12, image PHP 8.5), Symfony
`7.4.*`, composer scripts pattern established (`composer gates` = phpstan + cs-fixer + arch + test);
33.12 is the prior art for tool adoption: dev dep + composer script + an ADVISORY CI step
(`continue-on-error: true`, comment announcing the future hard-gate flip) in
`.github/workflows/backend.yml`. `phpstan/extension-installer` + `infection/extension-installer`
already in `allow-plugins` (Rector needs no plugin).

## Acceptance Criteria

1. **AC1 - Tool installed, conservative config.** `rector/rector` (and `rector/rector-symfony` if the
   Symfony sets are adopted) in `require-dev`; `rector.php` at `api/` root with: paths `src` + `tests`;
   PHP 8.4 level sets; optionally the non-risky Symfony 7 sets; dead-code set only if its dry-run
   output is clean-appliable; NO risky/opinionated sets (no type-coverage aggressive sets, no
   `SetList::CODING_STYLE` - cs-fixer owns style). **Skips:** `src/Sessions/**` (Epic 32 freeze - same
   carve-out as every 33.x story) and `migrations/**` (merged migrations are immutable).
2. **AC2 - Findings triaged, mechanical fixes applied.** The first full `--dry-run` output is triaged
   in the story record: each finding class either APPLIED (mechanical, behaviour-preserving) or
   ACCEPTED with a one-line rationale (and, where a rule misfires systematically, the rule added to
   `->withSkip()` with a comment). Applied changes land with all gates green.
3. **AC3 - Advisory gate wired and documented.** `composer rector` script (`rector process --dry-run`);
   an advisory CI step in `backend.yml` mirroring the 33.12 Infection shape (`continue-on-error: true`
   + comment stating the flip-to-hard-gate intent); `api/CLAUDE.md` gains a one-line mention next to
   the gates (advisory, not part of `composer gates` yet).
4. **AC4 - All gates green; zero behaviour change.** `composer gates` green on an isolated DB after
   the applied fixes; Rector's own dry-run exits 0 (no pending diffs) at PR time.

## Tasks / Subtasks

- [x] Task 1: `composer require --dev rector/rector` (+ `rector/rector-symfony` if Symfony sets used);
  write conservative `rector.php` (paths, PHP 8.4 sets, skips: Sessions + migrations) (AC: 1)
- [x] Task 2: full `--dry-run`; triage the finding classes into the story record (apply vs accept vs
  skip-rule); apply the mechanical fixes in reviewable batches (AC: 2)
- [x] Task 3: `composer rector` script + advisory CI step + `api/CLAUDE.md` mention (AC: 3)
- [ ] Task 4: full gates on isolated DB; PR to develop; adversarial review; merge on green
  (pre-authorized) (AC: 4)

### Dry-run triage (AC2 - worklist of record)

First dry-run: 178 rule applications / 157 files / 13 rules. Verdicts:

| Rule (count) | Verdict |
|---|---|
| AddTypeToConstRector (96) | APPLIED - typed class constants (PHP 8.3), incl. 19 Domain entities (constants only, verified) |
| NewMethodCallWithoutParenthesesRector (35) | APPLIED - PHP 8.4 `new Foo()->bar()` chaining |
| ArrowFunctionDelegatingCallToFirstClassCallableRector (12) + FunctionFirstClassCallableRector (4) | APPLIED - first-class callables |
| ForeachToArrayAnyRector (6) + ForeachToArrayAllRector (1) | APPLIED - PHP 8.4 array_any/array_all |
| ClosureToArrowFunctionRector (5) | APPLIED |
| ReadOnlyClassRector (5) | APPLIED - Infrastructure adapters (IgdbHttpClient, SteamWebApiClient, S3MinioStorage, TwitchApiClient, Schedule); per-property readonly demoted to class-level |
| ReadOnlyAnonymousClassRector (5) | APPLIED - test doubles |
| ReadOnlyPropertyRector (4) | APPLIED - non-entity classes only (verified: no Doctrine-hydrated property touched) |
| AddOverrideAttributeToOverriddenMethodsRector (3) | APPLIED |
| TernaryToNullCoalescingRector (1) | APPLIED |
| StringClassNameToClassConstantRector (1) | **SKIPPED (rule in withSkip)** - it rewrote the DDD validator's forbidden-import scan patterns to `::class`, whose source text carries single backslashes: the validator immediately flagged ITSELF (gate red). Scan patterns must stay single-quoted strings (doubled backslashes cannot self-match). Comment in rector.php records this. |

Decision recorded: Symfony rules NOT adopted in this story - conservative baseline first. CORRECTION
(review finding): Rector 2.x BUNDLES the Symfony rules and CONFLICTS with the standalone
`rector/rector-symfony` package (composer.lock conflict entry) - the follow-up path is Rector's own
Symfony set API, never a new dependency. The story's original Dev Note claiming a separate package
was wrong.

Post-review scope correction: the Epic 32 freeze covers the context's dedicated unit tests too -
`tests/Unit/Sessions` added to `withSkip` and its 3 files reverted (Sessions-flavoured FUNCTIONAL
tests keep their typed-constant hunks: shared cross-context surface, conflict risk limited to
declaration lines - accepted).

## Dev Notes

- **Rector 2.x config style:** `RectorConfig::configure()->withPaths([...])->withPhpSets(php84: true)
  ->withSkip([...])`. Prefer `withPhpSets` + explicit set lists over `withSets(...)` grab-bags. Check
  the installed major's documented API before writing the config (breaking config changes between
  majors).
- **Symfony sets live in `rector/rector-symfony`** - separate package. Adopt only the
  deprecation/upgrade sets matching Symfony 7.4 if their dry-run is sane; otherwise defer Symfony sets
  to a follow-up (record the decision). The epic asks for conservative, not exhaustive.
- **cs-fixer interplay:** run `vendor/bin/php-cs-fixer fix` after applying Rector diffs and BEFORE
  judging them - Rector output is not @Symfony-styled (the 33.17 lesson: cs-fixer rewrites can
  invalidate assumptions, e.g. `no_useless_concat_operator` folding). Commit Rector+cs-fixer output
  together per batch.
- **Validator interplay:** applied fixes must keep `app:architecture:ddd` green - watch especially
  Domain files (the validator now enforces finality/setters/imports - 33.16/33.17 rules). A Rector
  rule that adds/renames methods in Domain could trip AC-D5-style scans; that is a triage-to-skip
  case, not an allowlist case.
- **Sessions & migrations are hard skips** in rector.php, not manual discipline.
- **CI step shape** (mirror backend.yml's Infection step): advisory `continue-on-error: true`,
  comment "raise to hard gate once the baseline stays clean", placed after the coverage step.
- **PHP 8.5 image note:** CI/image runs PHP 8.5, local 8.4; keep `withPhpSets(php84: true)` aligned
  with composer's `"php": "^8.4"` floor, NOT the image version (code must stay 8.4-compatible).
- Windows execution lessons apply (memory): `-F`/`--body-file` for messages, explicit-path staging
  (shared tree - story 33.22 WIP files are on disk, NEVER `git add -A`).

### Project Structure Notes

- `rector.php` at `api/` root (siblings: `phpstan.neon`, `.php-cs-fixer.dist.php`, `infection.json`).
- Composer scripts block in `api/composer.json`; CI in `.github/workflows/backend.yml`.

### References

- Epic entry 33.13: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md`
- Prior art tool adoption: `33-12-mutation-testing-infection.md` + backend.yml Infection step
- Gap origin: `33-6-audit-worklist.md` (Rector absence noted)

## Dev Agent Record

### Agent Model Used

claude-fable-5.

### Debug Log References

- The anticipated Rector/validator interplay materialized on the very first apply:
  `StringClassNameToClassConstantRector` turned the validator's own `FORBIDDEN_APPLICATION_IMPORTS`
  and `ALLOWED_DOMAIN_SYMFONY_IMPORTS` string entries into `::class` references, and
  `app:architecture:ddd` went red flagging the validator file for "injecting" Doctrine. Root cause:
  single-quoted `'Doctrine\\DBAL\\Connection'` has doubled backslashes in source (cannot
  str_contains-match), while `\Doctrine\DBAL\Connection::class` contains the literal FQCN sequence.
  Resolution: rule added to `withSkip` with the explanation, validator file restored and re-processed.

### Completion Notes List

- `rector/rector` ^2.4 (dev); conservative `rector.php`: paths src+tests, `withPhpSets(php84: true)`
  only, skips = `src/Sessions` (Epic 32 freeze) + `StringClassNameToClassConstantRector`;
  migrations excluded by paths.
- 172 findings applied across 157 files (see triage table); cs-fixer restyled the output in the same
  batch; `composer rector` dry-run exits 0 at PR time.
- Advisory CI step added to backend.yml after the Infection step - same advisory INTENT, different
  mechanism (step-level `continue-on-error` vs Infection's internal `|| true` swallow; the Rector
  step surfaces as an annotated neutral step, which is the better behaviour); `composer rector`
  script; `api/CLAUDE.md` advisory paragraph. The `checks` job's `defaults.run.working-directory:
  api` applies (review-verified; the step produced Rector output on the first CI run).
- Gates: phpstan 0, cs-fixer 0, `app:architecture:ddd` OK, full isolated suite
  **1489 tests / 10304 assertions** green.

### File List

- api/rector.php (new), api/composer.json + composer.lock, api/CLAUDE.md,
  .github/workflows/backend.yml
- 157 modernised files under api/src/** and api/tests/** (typed constants, PHP 8.4 new-chaining,
  first-class callables, array_any/all, readonly promotions on 5 Infrastructure adapters + test
  doubles, #[\Override] x3)

### Review Findings

Adversarial review 2026-07-11 (Blind Hunter / Edge Case Hunter, PR #306; Auditor pending at write
time). Mechanical transformations survived adversarial reading on both sides (BH walked every
conversion class in the diff; ECH replayed them against the live tree incl. the 5 readonly bodies,
services.yaml lazy/decoration absence, and CI wiring).

- [x] [Review][Patch] Epic 32 freeze extended to `tests/Unit/Sessions` (skip + 3 files reverted);
  functional Sessions-flavoured tests accepted as applied (shared surface, const-line conflicts only)
- [x] [Review][Patch] rector-symfony follow-up path corrected (Rector 2.x bundles + conflicts with
  the standalone package) in rector.php comment + story record
- [x] [Review][Patch] "Mirrors the 33.12 Infection shape" claim corrected (same intent, different
  mechanism - step-level continue-on-error, arguably better)
- [x] [Review][Patch] Triage arithmetic fixed (178 rule applications, FunctionFirstClassCallable row
  was missing)
- [x] [Review][Dismissed] CI working-directory doubt - refuted: `defaults.run.working-directory: api`
  (backend.yml:53-55) and the step produced output on the first green run
- [x] [Review][Dismissed] readonly-vs-lazy-services risk - refuted on the tree: none of the 5
  adapters is lazy/decorated/reflection-hydrated (ECH verified bodies + config); a future
  `lazy: true` on a readonly class fails loudly at container build
- [x] [Review][Defer] `decodedJsonResponse()` triple override suggests dead duplication in 3
  functional tests - follow-up worklist candidate, not this PR
- [x] [Review][Defer] Rector-generated closures are untyped/non-static vs the codebase's
  `static fn (Type $x): bool` discipline - accepted as the tool's output baseline; revisit if the
  gate flips to hard

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.13). Dev dep approved by Jean; conservative config spec'd (PHP 8.4 sets, Sessions + migrations hard-skipped, no style sets); 33.12 advisory-CI shape as prior art; cs-fixer/validator interplay lessons baked in. Status: ready-for-dev. |
| 2026-07-11 | Implemented: rector ^2.4 + conservative config; 172 findings applied / 157 files (triage table of record); StringClassNameToClassConstantRector skipped after self-matching the validator's scan patterns (gate red on first apply, documented); advisory composer script + CI step + doc. All gates green (isolated suite 1489 tests). Status: ready-for-review (Task 4 pending PR/review/merge). |
