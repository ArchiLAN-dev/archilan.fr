# Epic 33 - Cleanup & Standards Hardening (nettoyage et durcissement des standards)

Status: planned (not started)
Date: 2026-06-26

## Goal

Pay down accumulated housekeeping debt and **raise the floor** on the codebase's conformance to its own
standards, without changing product behaviour. This is a **quality / maintenance** epic: it makes the
quality gates trustworthy, the docs match reality, the dependencies current, the layering stricter, and the
day-to-day developer (human or agent) experience faster and less error-prone.

Driving principle: **observable behaviour is preserved.** Every change is either (a) a fix to a real
correctness/standards defect, (b) a mechanical modernisation, or (c) tooling/docs. Each story lands with all
gates green and is independently reviewable - no big-bang refactors.

## The gates (enumerated, for unambiguous "green")

The repo's gates today (root `CLAUDE.md`): **api** - `phpstan analyse src tests` (0), `php-cs-fixer` over the
dist config covering **src + tests** (0), `php bin/phpunit` (0 notices/deprecations/warnings),
`app:architecture:ddd` (exit 0). **frontend** - `pnpm typecheck` (0), `pnpm lint` (0), `pnpm build` (clean),
`pnpm jest` (green). Story 33.1 adds a single `composer gates` / `pnpm gates` wrapper that runs exactly this
set so "all gates green" is one reproducible command, identical locally and in CI.

## Decisions (locked)

- **No behaviour change unless fixing a real bug.** Behaviour-affecting cleanups are bounced to their own
  product story.
- **Coverage is verified before refactoring, not assumed.** Stories that move/rewrite code (33.5/33.6/33.7)
  first establish that the touched area is covered; where it is not, characterization tests are added **before**
  the change. "The suite is the contract" only holds if the contract exists.
- **Gates stay authoritative, and we widen "green" beyond the gates for risky moves.** Behaviour-preserving
  relocations also require `php bin/console lint:container` + `cache:clear` to pass and a manual smoke of the
  affected routes, because DI autowiring by FQCN, `services.yaml` directory excludes, Doctrine mapping, and
  class-name-derived cache keys are not all covered by the standard gates.
- **A "major" dependency bump is a migration, not hygiene.** Minor/patch bumps are hygiene (33.4); major bumps
  (TypeScript 6, PHP 8.5 image, Node 26 image) are explicit migrations with their own story (33.9), one at a
  time, each with a rollback note.
- **Validator exceptions live in the validator, never as code suppressions.** `api/CLAUDE.md` bans suppression
  annotations; therefore any legitimate, intentional exception to a new DDD rule is encoded as an explicit
  allowlist **inside `DddArchitectureValidator`** (a named, commented entry), not as an inline ignore.
- **Sweeps are baseline-driven and finite.** Each best-practice/compliance story begins by producing an
  enumerated worklist (the audit); **that list becomes the story's acceptance criteria.** No open-ended
  "adopt best practices."
- **Small, per-area, reviewable stories.** Many narrow PRs over one sweeping refactor (mirrors Epic 19).

## Sizing & priority

Each story carries a size (S < ~0.5d, M ~1-2d, L > 2d) and a MoSCoW priority. The epic is **not** all-or-
nothing: the Must set (33.1, 33.2) is the real target; the rest is opportunistic and independently shippable.

## Roles: agent vs human

Some work needs elevated access and cannot be done by an agent alone - flagged per story as **[human]**:
- 33.1: creating/granting the isolated test DB (Postgres `CREATE DATABASE` privilege).
- 33.4 / 33.9: approving and merging Dependabot PRs (and any required secret/permission).
- 33.3: any GitHub org-level runner/permission change.

## Dependency on Epic 32 (ordering constraint)

**Epic 32 (session recap, currently stashed) heavily modifies the `Sessions` context.** Story 33.5's folder
tidy must therefore **either land before Epic 32 starts, or explicitly exclude `Sessions` until Epic 32 has
merged.** Doing 33.5 relocations in `Sessions` concurrently with Epic 32 guarantees painful conflicts. This is
called out in 33.5's AC.

## Scope

### In scope
- Test/CI infrastructure: parallel-safe test DB isolation, local gates aligned with CI, deprecated GitHub
  Actions bumped, one-command gate runner.
- Standards docs: correct the contradictions in `CLAUDE.md` / `api/CLAUDE.md` / `frontend/AGENTS.md` against
  the enforced tooling.
- Dependency hygiene: land the safe (minor/patch) Dependabot PRs; majors as explicit migrations.
- DDD compliance sweep: extend the validator, fix flagged violations, and tidy the layer folders so every
  class sits in the subfolder matching its responsibility (placement by content type).
- Framework best-practices passes: Symfony 7 (api) and React 19 / Next 15 App Router (frontend), each bounded
  by an enumerated audit.
- Code tech-debt: dead code, stale TODOs, and the deferred review items already recorded.

### Out of scope (open doors, not built here)
- Any new feature or product behaviour change.
- Performance re-architecture (separate epic if a real bottleneck is found).
- Visual / UX redesign.
- A **framework** major upgrade that requires a real migration plan (Symfony major, Next major) - its own epic.
  (Language/runtime/toolchain majors that Dependabot raises are handled, carefully, in 33.9.)

## Known issues this epic addresses (observed)

- **Shared test-DB flake.** Local full `php bin/phpunit` intermittently mass-fails at schema setup
  (`relation "..." does not exist` in `FunctionalTestCase::setUp`) because parallel processes race the same
  `archilan_test` schema (`DROP SCHEMA public CASCADE; CREATE SCHEMA public`). Confirmed work-around: run with
  `TEST_TOKEN` against an isolated DB. The flake makes local runs untrustworthy and pushes verification onto CI.
- **Local cs-fixer gate narrower than CI.** Documented local gate was `php-cs-fixer check src`; CI runs
  `--dry-run` over the full config (src **and** tests). A test-file violation passes locally and only fails in
  CI (this bit story 7.7: a snake_case PHPUnit method name + missing EOF newline slipped through).
- **Doc/tooling contradiction on test naming.** `api/CLAUDE.md` AC-T5 prescribes `test{scenario}_{outcome}`
  (underscore), but `php-cs-fixer`'s `php_unit_method_casing` enforces **camelCase**. The enforced rule wins.
- **Deprecated GitHub Actions runners.** CI warns that `actions/checkout@v4`, `actions/cache@v4`,
  `actions/upload-artifact@v4` target Node 20 (deprecated, force-run on Node 24).
- **Open Dependabot PRs** awaiting triage: docker `php-8.5-cli-alpine`, docker `node-26-alpine`,
  npm `typescript-6.0.3`, npm `types/node-25.9.2`, npm minor/patch group, `github_actions` group.
- **Deferred review items (story 7.7)** in `implementation-artifacts/deferred-work.md`: Twitch-outage cache TTL
  tradeoff, label-vs-host login resolution, hidden-but-loaded embed on viewport shrink, same-login collision.

## Proposed stories

> Each story's **Acceptance Criteria** are the measurable done-state. For the sweep stories (33.5-33.7) the
> first AC is "produce the audit worklist"; the remaining ACs are "every item on that worklist is resolved
> (fixed or explicitly accepted with a one-line rationale)."

- **33.1 - Test DB isolation & local/CI gate parity (api/, tooling). [M, Must] [human: DB grant]**
  - AC1: a documented, scripted way to run the full suite on an isolated DB (built on the existing `TEST_TOKEN`
    `dbname_suffix` + `scripts/setup-worktree.sh`); works locally (Docker `archilan-postgres`) and in CI.
  - AC2: the full `php bin/phpunit` run **10 times consecutively** on an isolated DB with **zero** schema-setup
    failures (flake closed for the isolated path).
  - AC3: a `composer gates` (api) and `pnpm gates` (frontend) command that runs the exact CI gate set; running
    it locally reproduces CI pass/fail.
  - AC4: documented note that parallel agents **must** use a worktree (the flake's process root cause is shared
    trees, not code) - links the worktree workflow.
  - AC5: all gates green.

- **33.2 - Standards docs reconciled with enforced tooling (docs). [S, Must]**
  - AC1: `api/CLAUDE.md` AC-T5 corrected to camelCase (matches `php_unit_method_casing`), with the rationale.
  - AC2: the documented cs-fixer gate states it covers **src + tests**; the documented gate commands match 33.1's
    `composer gates`.
  - AC3: the parallel-session worktree + `TEST_TOKEN` flow is documented in one place and cross-linked.
  - AC4: a grep for any other doc rule contradicted by the linters/validators returns nothing actionable
    (audited and listed). Doc-only, no code.

- **33.3 - GitHub Actions modernisation (CI). [S, Should] [human: org settings if needed]**
  - AC1: `actions/checkout`, `actions/cache`, `actions/upload-artifact` (+ any other deprecated action) bumped
    to a Node 24-capable major **if one is published**; otherwise the story documents the blocker and pins the
    current best version (no silent stall).
  - AC2: both workflows green with no Node-20 deprecation warning (or the residual warning is documented as
    upstream-blocked).

- **33.4 - Dependency hygiene: safe (minor/patch) bumps (api/ + frontend/). [S-M, Should] [human: merge]**
  - AC1: every **non-major** open Dependabot PR (the npm minor/patch group, `@types/node`, the `github_actions`
    group) is merged behind green gates, or closed with a reason.
  - AC2: any PR that turns out to need code changes is split out and noted; **major** bumps are explicitly
    deferred to 33.9 (not merged here).

- **33.5 - DDD compliance sweep + layer-folder tidy (api/). [L, Should]**
  Two complementary tracks; behaviour-preserving.
  - **Rules.** Extend `DddArchitectureValidator` with currently-unenforced `api/CLAUDE.md` rules (cross-context
    imports, query/repository interface placement, no clock/`new` on infra in Application); fix every flagged
    violation; encode intentional exceptions as a named allowlist **in the validator** (no code suppressions).
  - **Folder tidy (placement by responsibility).** Relocate each class into the subfolder matching what it is:
    `Domain/` = aggregates / value objects / enums / repository **interfaces** / domain events+exceptions;
    `Application/` = command + query services + read DTOs + `{Name}QueryInterface`, with consistent
    `Application/Message/` + `Application/Handler/` sub-namespaces; `Infrastructure/` = DBAL/Doctrine/HTTP/MinIO
    impls + `Null*`/`Stub*` doubles; `Presentation/` = controllers only.
  - AC1 (audit): a worklist enumerating every misplaced file and every new validator rule, committed first.
  - AC2: every worklist item resolved; `app:architecture:ddd` stricter and green.
  - AC3 (blast-radius safety): for every move, namespaces/`use`/`services.yaml` excludes/Doctrine mapping/DI
    wiring updated; **`lint:container` + `cache:clear` pass** and the affected routes smoke-tested.
  - AC4 (Epic 32): the `Sessions` context is **excluded** from the folder tidy unless Epic 32 has already
    merged (avoids guaranteed conflicts); the exclusion is stated in the PR.
  - AC5: all gates green; zero behaviour change (existing + any added characterization tests pass).

- **33.6 - Symfony 7 best-practices pass (api/). [M-L, Could]**
  - AC1 (audit): an enumerated checklist of concrete, specific changes (residual deprecations, runtime
    container/service-locator usages, missing constructor DI, non-readonly DTOs that should be, stale TODOs),
    each with a file reference. This list is the scope; nothing open-ended.
  - AC2: every checklist item resolved or explicitly accepted with a one-line rationale.
  - AC3: coverage of each touched area confirmed (or characterization tests added first); all gates green.

- **33.7 - React 19 / Next 15 best-practices pass (frontend/). [M-L, Could]**
  - AC1 (audit): an enumerated checklist of concrete violations of `frontend/AGENTS.md` (fetch-in-`useEffect`,
    hooks-purity AC-HK*, `process.env` outside `env.ts`, `as` casts at API boundaries, unstable list keys,
    dead/duplicated client code), each with a file reference.
  - AC2: every checklist item resolved or explicitly accepted; all gates green.

- **33.8 - Tech-debt cleanup & deferred-item triage (api/ + frontend/). [S-M, Should]**
  Not a dumping ground: scoped to two concrete inputs.
  - AC1: each item in `implementation-artifacts/deferred-work.md` (story 7.7) is re-triaged and **either fixed
    or formally accepted** with a recorded rationale; the file is updated to reflect the outcome.
  - AC2: dead code and stale TODOs surfaced by 33.5/33.6/33.7 audits are removed (referencing those worklists);
    anything non-trivial is bounced to its own story rather than absorbed here.
  - AC3: all gates green.

- **33.9 - Major dependency migrations (api/ + frontend/). [L, Could] [human: merge]**
  One sub-task per major, sequenced one at a time, each independently revertable: TypeScript 6, the PHP 8.5
  base image, the Node 26 base image.
  - AC1: per major - branch builds with full gates green; any code/type changes the major requires are made and
    reviewed; a rollback note (previous pin) is recorded in the PR.
  - AC2: no major is merged on red gates; a major that needs more than mechanical fixes is split into its own
    follow-up rather than forced.

- **33.10 - Layer sub-folder taxonomy: Command/Query/Service/Exception (api/). [L, Should] — DONE (PR #293)**
  Application classes sorted into `Command/`, `Query/`, `Service/` (mixed facades), `Exception/`; `Domain/Exception/`.
  Validator enforces placement via a decreasing per-context allowlist (`UNMIGRATED_TAXONOMY_CONTEXTS`).

- **33.11 - Full sub-folder taxonomy: no flat files in any layer (api/). [L, Should] — DONE (PR #294)**
  Every class in a kind sub-folder across Domain/Application/Infrastructure/Presentation (Domain/Entity with
  doctrine.yaml updated; Presentation/Controller uniform). 4 migration-pinned Community\Domain classes carved out;
  Sessions frozen; validator `validateNoFlatLayerFiles` rule added.

---

### Follow-up backlog (added 2026-07-06, to be scheduled after the taxonomy work)

Grouped into (a) new quality tooling, (b) sized code-quality debt already enumerated in the 33.5-33.11 worklists,
(c) the Sessions carve-out, (d) process. All are opt-in and independently shippable; none blocks the others.

**(a) New quality tooling**

- **33.12 - Mutation testing (Infection) (api/). [M, Should] [human: new dev dep]** *(recommended first)*
  The CI coverage floor measures how much code runs, not whether tests catch regressions - after the ~378-file
  33.10/33.11 refactors and the epic's sweeps, that matters.
  - AC1: `infection/infection` added (dev); `infection.json` configured; run scoped to the git diff (`--git-diff-lines`)
    to keep it fast; a baseline MSI (mutation score indicator) recorded.
  - AC2: an MSI floor wired into CI (advisory first, then blocking) mirroring the existing coverage-floor step;
    the floor is raised over time, never lowered.
  - AC3: the lowest-MSI hotspots surfaced are recorded as targeted test-hardening follow-ups (not all fixed here).

- **33.13 - Rector adoption (api/). [M, Could] [human: new dev dep]**
  Continuous automated modernisation + dead-code + upcoming-deprecation detection (noted absent in the 33.6 audit).
  - AC1: `rector/rector` added (dev) with a conservative `rector.php` (PHP 8.4 + Symfony sets, no risky rules);
    run in `--dry-run` advisory mode first.
  - AC2: the dry-run findings are triaged into a worklist (apply vs accept); mechanical fixes applied; a `composer rector`
    advisory gate documented. All gates green.

- **33.14 - phpstan-strict-rules (api/). [S-M, Could]**
  A cranked-up strictness layer on top of level max (loose comparisons, missing return types on closures, etc.).
  - AC1: `phpstan/phpstan-strict-rules` added (dev) and enabled; the surfaced findings enumerated as the worklist.
  - AC2: every finding fixed or explicitly baselined with a one-line rationale; all gates green.

**(b) Sized code-quality debt (from the 33.5-33.11 worklist "section D" candidates)**

- **33.15 - ClockInterface migration (api/). [L, Should]** *(recommended second - biggest recorded debt)*
  ~130 zero-arg `new \DateTimeImmutable()` in the Application layer (33.5 worklist §D) replaced by an injected
  `Psr\Clock\ClockInterface` (already a dependency; used in 33.6 for `AuthSessionSigner`/`DiscordStateToken`).
  - AC1: an enumerated worklist of every site + its context; a per-context migration plan.
  - AC2: sites migrated to injected clock (or value-as-parameter); the Application-layer no-clock validator rule
    (AC-D3 spirit, currently only `date()`/`time()`/`rand()`) extended to zero-arg `new \DateTime*`. All gates green.

- **33.16 - Domain aggregate setters -> business methods (api/). [M, Should]**
  25 public `set*` setters on 12 Domain entities (AC-D5, from the 33.5/33.6 audits) replaced by named business
  methods; the aggregate-no-public-setters rule then added to the validator.
  - AC1: worklist of every setter + its replacement business method; coverage confirmed first.
  - AC2: setters replaced, callers updated, validator rule added; zero behaviour change; all gates green.

- **33.17 - Extend the DDD validator to the remaining documented ACs (api/). [M, Could]**
  Lock in more of `api/CLAUDE.md` by construction (recorded "out of scope, candidate future rules" in 33.5 §D):
  aggregates `final` / VOs `final readonly` (AC-D4), Application services `final` (AC-A1), command-returns-void /
  query-returns-DTO (AC-A3), full `Symfony\Component\*`/`Contracts\*` domain ban (AC-D1), `ROLE_MEMBER` gating ban (AC-M1).
  - AC1: each rule added with a named allowlist for legitimate exceptions (no inline suppressions); unit tests per rule.
  - AC2: every currently-flagged violation fixed or allowlisted with rationale; all gates green.

- **33.18 - Frontend: TanStack Query migration of complex fetch-in-effect pages (frontend/). [L, Could]**
  The ~14 entangled admin/slot pages + the systemic direct-`apiFetch`-in-components (accepted in the 33.7 worklist §C/D)
  converted to TanStack Query with per-page semantics preservation.
  - AC1: a per-page worklist (retry/loading/error semantics to preserve); AC2: converted, `pnpm gates` green, no UX change.

- **33.19 - Frontend: typed SSE layer (frontend/). [S-M, Could]**
  The ~22 `JSON.parse(event.data) as DomainType` SSE casts (33.7 worklist §C) replaced by shared guards
  (`isReachabilityData`/`isHintsData`/...) and a guard-aware `useSse`. AC: casts removed, `pnpm gates` green.

**(c) Carve-out**

- **33.20 - Sessions taxonomy migration (api/). [L/XL, Should] [UNBLOCKED - Epic 32 merged 2026-07-12, PR #310]**
  > Story written: `implementation-artifacts/33-20-sessions-taxonomy-migration.md` (ready-for-dev). **It corrects five
  > stale points in the entry below** - the `.ps1` tooling was never committed and must be rebuilt; a fifth exemption
  > (`FINALITY_EXEMPT_CONTEXTS`, story 33.17) exists and 3 entities are not `final`; the "6 clock sites" are 6 *files* /
  > 16 sites; `ScheduledTask/` does NOT move so `debug:messenger` must stay byte-identical; and zero Sessions classes are
  > migration-pinned. Re-sized M → L/XL (92 file moves + 51 test call sites for the setter rename).
  Apply the 33.10 + 33.11 taxonomy to the frozen `Sessions` context once Epic 32 has merged; remove `Sessions` from
  `UNMIGRATED_TAXONOMY_CONTEXTS` and the two `ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS` entries (extract the runner
  callback port). The 33.10/33.11 tooling (`migrate-context.ps1`, `migrate-layer.ps1`, `migrate-domain.ps1`,
  `fix-uses.ps1`) replays it in one pass. Also absorbs the Sessions carve-outs from the later sweeps: the 6 zero-arg
  `new \DateTimeImmutable()` Application sites (33.15, empty `CLOCK_CONSTRUCT_EXEMPT_CONTEXTS`) and the 9 Domain
  setters - `Session::setLastLogs/setArchivedSavePath/setArchivedSpoilerPath/setGeneratedOutputKey/setValidationErrors`,
  `SessionSlot::setSlotName/setChecksDone/setItemsReceived/setGoalReachedAt` - to rename to business methods
  (33.16, empty `AGGREGATE_SETTER_EXEMPT_CONTEXTS`). AC: all gates green; `debug:messenger` diff clean.

**(d) Process**

- **33.21 - Epic 33 retrospective. [S, Should]**
  Run `bmad-retrospective` on the completed epic: the audit-first pattern, the decreasing-allowlist enforcement model,
  the Windows/refactor execution lessons (recorded in memory), and what the sweeps surfaced for future epics.

**(e) Data hygiene (added 2026-07-11)**

- **33.22 - Backfill APWorld deployed version by hash match (api/). [S-M, Should]**
  Many GitHub-tracked games show "Version inconnue" because `apworld_deployed_version` is null (configured by direct
  upload, or added before tracking). A one-shot `app:backfill-apworld-deployed-version` command infers it by matching
  the stored `apworldHash` (`sha256(rawbytes)`) against every release asset of the source repo (byte-exact ⇒ false
  negatives only; unmatched games reported, never guessed). No app behaviour change - fills an existing column.
  `--dry-run`, group-by-repo download-once, rate-limit early-stop. Reuses `ApworldVersionChecker` + the
  `check-apworld-updates` command/service shape.

## Sequencing

1. **33.1** first - a trustworthy, isolated, one-command gate makes every subsequent story safe to verify.
2. **33.2** + **33.3** - cheap docs/CI wins (33.2 depends on 33.1's final gate definition).
3. **33.4** - safe dependency bumps before the sweeps, so sweeps run against current minor/patch deps.
4. **33.5 / 33.6 / 33.7** - the substantive sweeps. **33.5 and 33.6 both touch api/ heavily and must be
   serialised (33.5 then 33.6), not run in parallel**; 33.7 (frontend) is genuinely parallel to both. 33.5 is
   gated by the Epic 32 ordering constraint above.
5. **33.8** - closes the deferred items and the dead-code/TODOs the sweeps surfaced.
6. **33.9** - major migrations, opportunistic, one at a time, whenever someone has bandwidth to babysit a major.
7. **33.10 -> 33.11** - taxonomy work, done (33.11 extends 33.10; both merged).
8. **Follow-up backlog (33.12-33.21)** - suggested order: **33.12 Infection first** (know if the tests are worth
   anything before writing more), then **33.15 ClockInterface** (biggest recorded debt, and Infection benefits from
   deterministic time), then the remaining tooling/validator/frontend stories in any order; **33.20 Sessions** waits
   on Epic 32; **33.21 retrospective** whenever. None blocks another.

## Risks / notes

- **Scope creep into product changes.** Mitigation: the locked "no behaviour change" decision; coverage-first;
  the test suite + added characterization tests as the contract.
- **33.5 blast radius.** Folder moves change FQCNs - touching DI autowiring, `services.yaml` excludes, Doctrine
  mapping, and class-name-derived cache keys. Mitigation: the AC3 safety net (`lint:container` + `cache:clear`
  + route smoke), serialise with 33.6, exclude `Sessions` until Epic 32 merges.
- **"Best practices" being unbounded.** Mitigation: the baseline-audit-as-AC rule - the worklist is the scope,
  full stop.
- **Major bumps hiding breaks.** Mitigation: 33.9 isolates them from hygiene, one at a time, with rollback.
- **Validator over-tightening.** New rules could flag established legitimate patterns (e.g. an Application
  service injecting an Infrastructure-namespaced interface, which the codebase already does and the validator
  already allows). Mitigation: encode rules against the documented ACs; grandfather via the in-validator
  allowlist (not annotations); run the full suite.
- **Flake root cause is partly process.** 33.1 fixes the **isolated** path and documents the worktree
  requirement, but cannot stop a human running parallel agents in one shared tree. AC scoped accordingly.
- **GitHub Actions bump may be upstream-blocked.** If no Node-24 major is published for an action, 33.3 pins and
  documents rather than stalling.

## Discoverability

This epic is a standalone file and is **not yet in `epic-list.md` / `index.md`** (the index is generated; the
pending Epic 32 index entry is currently stashed). Regenerate the index via `bmad-index-docs` once Epic 32's
index change is unstashed, so 32 and 33 land together and the epic is not forgotten.

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-26 | Epic planned. Housekeeping/quality epic to harden CI/tests, reconcile standards docs with enforced tooling, land dependency bumps, sweep DDD compliance + tidy layer folders, and apply Symfony/React/Next best practices - no product behaviour change. Stories 33.1-33.9 proposed; 33.1 (gate parity + test-DB isolation) sequenced first. Grounded in issues observed during epic-7 story-7.7. |
| 2026-06-26 | Adversarial-review pass applied: added per-story measurable AC; enumerated the gates; added coverage-first + validation-beyond-gates (lint:container/cache:clear/smoke) decisions; split major bumps out of hygiene into a dedicated 33.9; bounded the best-practice sweeps with a baseline-audit-as-AC rule; resolved the validator-vs-no-suppressions tension via an in-validator allowlist; made the Epic 32 / `Sessions` ordering constraint explicit; serialised 33.5/33.6; added sizing + MoSCoW; flagged human/admin-required work; noted index regeneration. |
| 2026-07-06 | 33.1-33.11 delivered and merged (33.10 layer-taxonomy, 33.11 no-flat-files added mid-epic). Follow-up backlog appended (33.12-33.21): quality tooling (Infection/Rector/phpstan-strict), the sized worklist-§D debt (ClockInterface migration, aggregate setters, remaining validator rules, frontend TanStack/SSE), the Sessions carve-out (blocked on Epic 32), and the epic retrospective. Suggested order: 33.12 Infection then 33.15 ClockInterface. |
