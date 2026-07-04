# Story 33.2: Standards Docs Reconciled with Enforced Tooling

Status: ready-for-review

## Story

As a developer (human or agent) relying on the repo's standards files to write conformant code,
I want every documented rule to match what the linters/validators actually enforce, and every documented command to match the real tooling,
so that following the docs never produces code the gates reject (and vice versa), and there is exactly one authoritative description of the gate/isolation workflow.

## Acceptance Criteria

1. **AC1 - AC-T5 corrected to camelCase.** `api/CLAUDE.md` AC-T5 (Testing standards, unit tests) no longer prescribes `test{scenario}_{expectedOutcome}` with an underscore. It prescribes camelCase (e.g. `testMarkAsReleasedSetsFlag`), with a one-line rationale: `php_unit_method_casing` (camel_case) is part of the @Symfony ruleset that `php-cs-fixer` enforces; the enforced rule wins. Examples in AC-T5 are updated to camelCase.
2. **AC2 - Documented gates match reality everywhere.** Every place that documents the gate set states: cs-fixer covers **src + tests** (full dist config), and the canonical invocations are `composer gates` / `pnpm gates` (matching story 33.1's definition). Known residual to fix: root `README.md` "Exigence d'ingénierie" table (omits jest in the Frontend row; does not name the one-command runners). Root `CLAUDE.md`, `api/CLAUDE.md`, `frontend/AGENTS.md` were already aligned by 33.1 - verify, do not rewrite.
3. **AC3 - One authoritative worktree/TEST_TOKEN description.** The parallel-session worktree + `TEST_TOKEN` isolation flow has exactly one full description (root `CLAUDE.md` "Sessions parallèles"); every other mention (`api/CLAUDE.md` "Parallel sessions", script headers, README if any) is a short pointer to it. Verify cross-links resolve (both directions), no contradictory duplicate explanation remains.
4. **AC4 - Audit sweep: nothing else contradicted.** An enumerated audit worklist of every doc rule checked against the enforcing tool is recorded in the Dev Agent Record. Every found mismatch is either fixed in the doc or explicitly accepted with a one-line rationale. The audit at minimum covers the "Audit worklist (seeded)" below plus a fresh grep pass (patterns given in Task 4). Doc-only: zero code, config, or tooling changes.
5. **AC5 - All gates green.** `composer gates` and `pnpm gates` pass. (Doc-only diff, so this is a regression check, not a build of new behaviour.)

## Tasks / Subtasks

- [x] Task 1: Fix AC-T5 test naming rule (AC: 1)
  - [x] 1.1 Edit `api/CLAUDE.md:115` (AC-T5): replace `test{scenario}_{expectedOutcome}` and both underscore examples with camelCase (`test{Scenario}{ExpectedOutcome}`, e.g. `testMarkAsReleasedSetsFlag`, `testMarkAsReleasedIsNoOpWhenGoalAlreadyReached`). Add the rationale sentence (enforced by `php_unit_method_casing` in @Symfony; snake_case fails CI - bit story 7.7). → Done; also added a camelCase bullet to the "CS Fixer rules" list cross-referencing AC-T5.
  - [x] 1.2 Confirmed fact (checked during story creation): the test suite already complies - `grep -rE "public function test\w+_\w+" api/tests` returns **0** matches. No test renames needed; this is purely a doc fix.
- [x] Task 2: Reconcile README gates table (AC: 2)
  - [x] 2.1 In root `README.md` "🧪 Exigence d'ingénierie": add Jest to the Frontend gates row and name the canonical commands (`composer gates` / `pnpm gates`). Keep the vitrine tone (PR #275 redesigned this README - light touch, no structural rewrite). → Jest added to the Frontend row + one sentence naming the two commands after the table.
  - [x] 2.2 Grep-verify no other file re-documents the old four-command gate blocks (patterns in Task 4.1); root `CLAUDE.md`, `api/CLAUDE.md`, `frontend/AGENTS.md` are already correct post-33.1 - leave them unless the audit finds a residual. → One residual found and fixed: `packages/CLAUDE.md:63` enumerated the api gates old-style; now says `composer gates`.
- [x] Task 3: Single-source the worktree/TEST_TOKEN flow (AC: 3)
  - [x] 3.1 Verify root `CLAUDE.md` "Sessions parallèles" is the one full description (it is, post-33.1: script usage, TEST_TOKEN mechanism, rationale paragraph, `test-isolated.sh` pointer). → Verified, unchanged.
  - [x] 3.2 Verify `api/CLAUDE.md` "Parallel sessions" note stays a summary + pointer; trim any sentence that re-explains the mechanism beyond a pointer if duplication crept in. → Trimmed: the duplicated causal explanation (schema drop/rebuild race) is now a pointer to root `CLAUDE.md` named as the single authoritative description.
  - [x] 3.3 Check `scripts/setup-worktree.sh` and `api/scripts/test-isolated.sh` headers point at the root doc section. → Both headers now carry a one-line pointer to root `CLAUDE.md` "Sessions paralleles".
- [x] Task 4: Audit sweep (AC: 4)
  - [x] 4.1 Grep pass over all `*.md` + script headers run (patterns as specified). Hits: `api/CLAUDE.md:115` (S1), `packages/CLAUDE.md:63` (new, fixed), vendor README false positive (ignored). No other stale claims.
  - [x] 4.2 Seeded worklist fully worked through - see audit table below.
  - [x] 4.3 Every "CS Fixer rules" bullet cross-checked against the vendor ruleset source (`vendor/friendsofphp/php-cs-fixer/src/RuleSet/Sets/SymfonySet.php`): `yoda_style: true` and `php_unit_method_casing: true` confirmed IN @Symfony; `declare_strict_types` confirmed ONLY in risky sets (not enabled) → strict_types bullet re-labelled as project convention.
  - [x] 4.4 Audit table recorded in Dev Agent Record.
- [x] Task 5: Final gate run (AC: 5)
  - [x] 5.1 `composer gates` green in `api/`; `pnpm gates` green in `frontend/`. → api: phpstan 0, cs-fixer 0, arch OK, phpunit `OK (1440 tests, 10214 assertions)`. frontend: exit 0 (typecheck, lint, jest, build). 2026-07-04.
  - [x] 5.2 Diff check: only `.md` files (and possibly script header comment lines) touched. Zero `src/`, `tests/`, config or CI changes. → Confirmed: `README.md`, `api/CLAUDE.md`, `packages/CLAUDE.md`, one header comment line each in `scripts/setup-worktree.sh` and `api/scripts/test-isolated.sh`, this story file.

## Dev Notes

### Why this story exists

Docs that contradict the enforced tooling send developers (especially agents, which follow docs literally) straight into red gates. Story 7.7 lost a CI round to exactly this: a snake_case PHPUnit method written per AC-T5 was rejected by `php_unit_method_casing`. Epic 33 locks the resolution rule: **the enforced rule wins; the doc moves.** [Source: epic-33 "Known issues" - doc/tooling contradiction on test naming]

### Audit worklist (seeded - confirmed during story creation, 2026-07-04)

| # | Doc claim | Location | Tooling reality | Expected resolution |
|---|-----------|----------|-----------------|---------------------|
| S1 | Test names `test{scenario}_{outcome}` (underscore) | `api/CLAUDE.md:115` (AC-T5) | `php_unit_method_casing` (camel_case) in @Symfony rejects it; suite has 0 underscore methods | Fix doc to camelCase (AC1) |
| S2 | "`declare(strict_types=1)` at the top of every file" listed under "CS Fixer rules (@Symfony preset)" | `api/CLAUDE.md` CS Fixer section | `declare_strict_types` is NOT in non-risky @Symfony; config is `['@Symfony' => true]` only. Convention holds in practice (762/763 src files; only scaffold `src/Kernel.php` lacks it) | Re-label as project convention (not cs-fixer-enforced), or move out of the CS Fixer section. Do NOT add the risky rule to the config (tooling change = out of scope, candidate for 33.5/33.6) |
| S3 | Frontend gates row omits Jest; no mention of `composer gates`/`pnpm gates` | `README.md` "Exigence d'ingénierie" (~line 93) | CI + `pnpm gates` include jest since epic 20 / story 33.1 | Fix doc (AC2) |
| S4 | Yoda style, blank-line rules, trailing whitespace bullets | `api/CLAUDE.md` CS Fixer section | `yoda_style`, `single_blank_line` rules etc. are in @Symfony | Verify each; expected match (record in audit table) |
| S5 | Worktree/TEST_TOKEN flow described in two places | root `CLAUDE.md` + `api/CLAUDE.md` | Post-33.1 both exist; root is canonical | Keep root full, api/ as pointer (AC3) |

### Constraints (binding)

- **Doc-only.** Zero changes to `src/`, `tests/`, `*.php`, `*.ts(x)`, configs, CI workflows, or linter configs. If the audit surfaces a mismatch better fixed by changing the TOOLING (e.g. actually enforcing strict_types), record it as accepted-with-rationale here and leave the enforcement change to 33.5/33.6 - that is an epic-locked decision (sweeps are baseline-driven, enforcement changes belong to the validator/sweep stories).
- **The enforced rule wins.** Never "fix" a mismatch by weakening a doc to contradict a gate that is actually green, and never document an aspiration as if enforced.
- **33.1 doc edits are settled.** Root `CLAUDE.md`, `api/CLAUDE.md`, `frontend/AGENTS.md` gate blocks and worktree notes were written by 33.1 (merged via PR #282). Verify, extend minimally, do not rewrite. Gate command names `composer gates` / `pnpm gates` are frozen.
- **No em-dashes anywhere** (root `CLAUDE.md` typography rule) - the docs being edited must come out em-dash-free.
- **README tone:** README.md is the public vitrine (redesigned in PR #275); keep edits surgical.

### Existing mechanisms - reference, do not re-derive

- Gate definitions: `api/composer.json` scripts (`gates` = phpstan, cs-fixer, arch, test), `frontend/package.json` (`gates` = typecheck, lint, test, build). CI calls the same scripts (`.github/workflows/backend.yml`, `frontend.yml`).
- cs-fixer scope proof: `api/.php-cs-fixer.dist.php` Finder is `->in(__DIR__)` excluding `var`/`vendor` - src AND tests.
- Isolation flow: root `CLAUDE.md` "Sessions parallèles" (canonical), `scripts/setup-worktree.sh`, `api/scripts/test-isolated.sh`, Stop hook on `archilan_test_stophook` (`.claude/quality-gates.sh`).

### Project Structure Notes

- Files expected in the diff: `README.md`, `api/CLAUDE.md`, possibly root `CLAUDE.md` / `frontend/AGENTS.md` (only if audit finds residuals), possibly one-line header comments in `scripts/setup-worktree.sh` / `api/scripts/test-isolated.sh`, this story file.
- Branch: `feature/epic-33-story-2-standards-docs-reconciliation` off `develop` **after PR #282 (33.1) is merged** - this story edits files 33.1 touched; branching earlier guarantees conflicts.

### Testing standards summary

- No PHPUnit/Jest tests added (doc-only story, exempt category per root `CLAUDE.md`). The "test" is AC4's recorded audit table plus AC5's full gate pass.

### Cross-story context (Epic 33)

- Depends on 33.1 (merged gate definition + doc blocks). Blocks nothing; 33.3 (GitHub Actions) and 33.4 (dependency hygiene) are independent.
- The strict_types enforcement question (S2) feeds 33.5/33.6 audits - record the finding, do not act on tooling here.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md#Proposed-stories - story 33.2 AC (file lives on branch feature/epic-33-cleanup-and-standards, commit 1b9e869; read via `git show 1b9e869:_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md`)]
- [Source: epic-33 #Known-issues - doc/tooling contradiction on test naming; local cs-fixer gate narrower than CI]
- [Source: _bmad-output/implementation-artifacts/33-1-test-db-isolation-and-local-ci-gate-parity.md - final gate definition, doc blocks already written, Stop-hook isolation]
- [Source: api/.php-cs-fixer.dist.php - `@Symfony` only, Finder = whole repo minus var/vendor]
- [Source: api/CLAUDE.md:115 - AC-T5 underscore prescription (S1)]
- [Source: README.md ~line 93 - gates table missing jest (S3)]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

### Completion Notes List

- **Audit table (AC4) - every doc rule checked against its enforcing tool (2026-07-04):**

| # | Doc claim | Location | Enforcing tool / reality | Verdict |
|---|-----------|----------|--------------------------|---------|
| S1 | Test names `test{scenario}_{outcome}` (underscore) | `api/CLAUDE.md` AC-T5 | `php_unit_method_casing: true` confirmed in vendor `SymfonySet.php:159`; suite has 0 underscore methods | **Fixed** - doc now prescribes camelCase with rationale |
| S2 | `declare(strict_types=1)` listed as a CS Fixer rule | `api/CLAUDE.md` CS Fixer section | `declare_strict_types` only in `SymfonyRiskySet` / `PhpCsFixerRiskySet` / `PHP7x0MigrationRiskySet` - none enabled (config = `['@Symfony' => true]`). Convention holds: 762/763 src files (only scaffold `src/Kernel.php` lacks it) | **Fixed** - re-labelled "Project convention, NOT enforced by the preset". Enforcement decision deferred to 33.5/33.6 (tooling change out of doc-only scope) |
| S3 | Frontend gates row omits Jest; no one-command runner named | `README.md` "Exigence d'ingénierie" | CI + `pnpm gates` run jest | **Fixed** - Jest added, `composer gates` / `pnpm gates` named |
| S4a | Yoda style enforced | `api/CLAUDE.md` CS Fixer section | `yoda_style: true` confirmed in vendor `SymfonySet.php:261` | **Match** - no change |
| S4b | Trailing whitespace / blank-line rules enforced | `api/CLAUDE.md` CS Fixer section | `no_extra_blank_lines`, `class_attributes_separation`, `blank_line_before_statement` confirmed in `SymfonySet.php` | **Match** - no change |
| S5 | Worktree/TEST_TOKEN flow described twice | root `CLAUDE.md` + `api/CLAUDE.md` | Root is canonical post-33.1 | **Fixed** - api note trimmed to summary + explicit pointer; both script headers now point at the root section |
| S6 (new) | Old-style api gate enumeration | `packages/CLAUDE.md:63` | `composer gates` is canonical since 33.1 | **Fixed** - now names `composer gates` |

- Grep pass residue: `api/vendor/react/event-loop/README.md` matched `_SETS` (vendor file, false positive, ignored). Nothing else actionable - AC4's "returns nothing actionable" holds after the fixes above.
- Zero code/config/tooling changes; the only non-`.md` edits are one comment line in each of the two script headers (doc content, per Task 3.3).

### File List

- `api/CLAUDE.md` - AC-T5 camelCase (S1), camelCase bullet + strict_types re-labelled convention (S2), parallel-sessions note trimmed to pointer (S5)
- `README.md` - Jest in Frontend gates row + canonical gate commands sentence (S3)
- `packages/CLAUDE.md` - api gates mention now `composer gates` (S6)
- `scripts/setup-worktree.sh` - header pointer line to root doc (S5)
- `api/scripts/test-isolated.sh` - header pointer line to root doc (S5)
