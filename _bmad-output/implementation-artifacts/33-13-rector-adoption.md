# Story 33.13: Rector Adoption (api/)

Status: ready-for-dev

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

- [ ] Task 1: `composer require --dev rector/rector` (+ `rector/rector-symfony` if Symfony sets used);
  write conservative `rector.php` (paths, PHP 8.4 sets, skips: Sessions + migrations) (AC: 1)
- [ ] Task 2: full `--dry-run`; triage the finding classes into the story record (apply vs accept vs
  skip-rule); apply the mechanical fixes in reviewable batches (AC: 2)
- [ ] Task 3: `composer rector` script + advisory CI step + `api/CLAUDE.md` mention (AC: 3)
- [ ] Task 4: full gates on isolated DB; PR to develop; adversarial review; merge on green
  (pre-authorized) (AC: 4)

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

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.13). Dev dep approved by Jean; conservative config spec'd (PHP 8.4 sets, Sessions + migrations hard-skipped, no style sets); 33.12 advisory-CI shape as prior art; cs-fixer/validator interplay lessons baked in. Status: ready-for-dev. |
